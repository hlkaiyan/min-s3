<?php
namespace MinS3\Api\Parser;

use MinS3\Api\DateTimeResult;
use MinS3\Api\Service;
use MinS3\Api\Shape;
use MinS3\Api\StructureShape;
use MinS3\Command;
use MinS3\Http\Response;
use MinS3\Http\Stream;
use MinS3\Result;

/**
 * 把 HTTP 响应按模型解析成 Result。
 *
 * 逻辑取自 aws-sdk-php 的 AbstractRestParser + RestXmlParser，
 * 只把 PSR-7 换成本包的 HTTP 类。响应各部分（消息体 / 头部 /
 * 头部映射 / 状态码）的落位规则未改。
 */
class XmlResponseParser
{
    private Service $api;
    private XmlParser $parser;

    public function __construct(Service $api, ?XmlParser $parser = null)
    {
        $this->api = $api;
        $this->parser = $parser ?? new XmlParser();
    }

    public function __invoke(Command $command, Response $response): Result
    {
        $output = $this->api->getOperation($command->getName())->getOutput();
        $result = [];

        if ($payload = $output['payload']) {
            $this->extractPayload($payload, $output, $response, $result);
        } else {
            $body = $response->getBody();
            $size = $body->getSize();
            $isEmpty = $size === null
                ? $this->getBodyContents($response) === ''
                : $size === 0;

            if (!$isEmpty && count($output->getMembers()) > 0) {
                $this->payload($response, $output, $result);
            }
        }

        foreach ($output->getMembers() as $name => $member) {
            switch ($member['location']) {
                case 'header':
                    $this->extractHeader($name, $member, $response, $result);
                    break;
                case 'headers':
                    $this->extractHeaders($name, $member, $response, $result);
                    break;
                case 'statusCode':
                    $result[$name] = $response->getStatusCode();
                    break;
            }
        }

        $result['@metadata'] = [
            'statusCode' => $response->getStatusCode(),
            'effectiveUri' => null,
            'headers' => array_change_key_case(
                array_map(static fn(array $v): string => implode(', ', $v), $response->getHeaders())
            ),
        ];

        return new Result($result);
    }

    protected function payload(Response $response, StructureShape $member, array &$result): void
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $result += $this->parseMemberFromStream($body, $member, $response);
    }

    public function parseMemberFromStream(Stream $stream, StructureShape $member, Response $response): array
    {
        return $this->parser->parse($member, $this->parseXml((string) $stream, $response));
    }

    private function extractPayload(
        string $payload,
        StructureShape $output,
        Response $response,
        array &$result
    ): void {
        $member = $output->getMember($payload);
        $body = $response->getBody();

        if ($member instanceof StructureShape) {
            $size = $body->getSize();
            $isEmpty = $size === null
                ? $this->getBodyContents($response) === ''
                : $size === 0;

            // union 至少要有一个成员非空，空体等于未设置
            if (!empty($member['union']) && $isEmpty) {
                return;
            }

            $result[$payload] = [];
            $this->payload($response, $member, $result[$payload]);
        } else {
            // 流式载荷（GetObject 的 Body）：原样交出流，不读进内存
            $result[$payload] = $body;
        }
    }

    private function extractHeader(string $name, Shape $shape, Response $response, array &$result): void
    {
        $value = $response->getHeaderLine($shape['locationName'] ?: $name);
        if ($value === '') {
            return;
        }

        switch ($shape->getType()) {
            case 'float':
            case 'double':
                $value = match ($value) {
                    'NaN', 'Infinity', '-Infinity' => $value,
                    default => (float) $value,
                };
                break;
            case 'long':
            case 'integer':
                $value = (int) $value;
                break;
            case 'boolean':
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                break;
            case 'blob':
                $value = base64_decode($value);
                break;
            case 'timestamp':
                try {
                    $value = DateTimeResult::fromTimestamp(
                        $value,
                        !empty($shape['timestampFormat']) ? $shape['timestampFormat'] : null
                    );
                    break;
                } catch (\Exception $e) {
                    // 解析不了就不放进结果，避免污染
                    return;
                }
            case 'string':
                if ($shape['jsonvalue']) {
                    try {
                        $decoded = json_decode(base64_decode($value), true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            return;
                        }
                        $value = $decoded;
                    } catch (\Exception $e) {
                        return;
                    }
                }
                break;
            case 'list':
                $listMember = $shape->getMember();
                if ($listMember->getType() === 'boolean') {
                    $value = array_map(
                        static fn(string $item): bool => filter_var($item, FILTER_VALIDATE_BOOLEAN),
                        array_map('trim', explode(',', $value))
                    );
                }
                break;
        }

        $result[$name] = $value;
    }

    /**
     * 前缀式头部映射，S3 用它还原 x-amz-meta-*。
     */
    private function extractHeaders(string $name, Shape $shape, Response $response, array &$result): void
    {
        $result[$name] = [];
        $prefix = $shape['locationName'];
        $prefixLen = $prefix !== null ? strlen($prefix) : 0;

        foreach ($response->getHeaders() as $k => $values) {
            if (!$prefixLen) {
                $result[$name][$k] = implode(', ', $values);
            } elseif (stripos($k, $prefix) === 0) {
                $result[$name][substr($k, $prefixLen)] = implode(', ', $values);
            }
        }
    }

    private function getBodyContents(Response $response): string
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return (string) $body;
    }

    protected function parseXml(string $xml, ?Response $response = null): \SimpleXMLElement
    {
        $priorSetting = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $xmlPayload = new \SimpleXMLElement($xml);
            if ($error = libxml_get_last_error()) {
                throw new \RuntimeException($error->message);
            }
        } catch (\Exception $e) {
            throw new ParserException("解析响应 XML 失败: {$e->getMessage()}", 0, $e);
        } finally {
            libxml_use_internal_errors($priorSetting);
        }

        return $xmlPayload;
    }
}
