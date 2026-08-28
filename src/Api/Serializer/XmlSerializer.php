<?php
namespace MinS3\Api\Serializer;

use MinS3\Api\ListShape;
use MinS3\Api\MapShape;
use MinS3\Api\Operation;
use MinS3\Api\Service;
use MinS3\Api\Shape;
use MinS3\Api\StructureShape;
use MinS3\Api\TimestampShape;
use MinS3\Command;
use MinS3\Http\Query;
use MinS3\Http\Request;
use MinS3\Http\Stream;
use MinS3\Http\Uri;

/**
 * 把命令序列化成 HTTP 请求。
 *
 * 逻辑取自 aws-sdk-php 的 RestSerializer + RestXmlSerializer（S3 用的
 * rest-xml 协议），只把 Guzzle/PSR-7 换成本包的 HTTP 类，参数落位规则
 * （header / querystring / uri / body）一字未改。
 */
class XmlSerializer
{
    private const TEMPLATE_STRING_REGEX = '/\{([^\}]+)\}/';

    private Service $api;
    private Uri $endpoint;
    private XmlBody $xmlBody;

    public function __construct(Service $api, string $endpoint, ?XmlBody $xmlBody = null)
    {
        $this->api = $api;
        $this->endpoint = new Uri($endpoint);
        $this->xmlBody = $xmlBody ?? new XmlBody($api);
    }

    public function __invoke(Command $command): Request
    {
        $operation = $this->api->getOperation($command->getName());
        $commandArgs = $command->toArray();
        $opts = $this->serialize($operation, $commandArgs);
        $headers = $opts['headers'] ?? [];

        $uri = $this->buildEndpoint($operation, $commandArgs, $opts);

        return new Request(
            $operation['http']['method'],
            $uri,
            $headers,
            $opts['body'] ?? null
        );
    }

    /**
     * rest-xml 的消息体：把结构体成员写成 XML。
     */
    protected function payload(StructureShape $member, array $value, array &$opts): void
    {
        $opts['headers']['Content-Type'] = 'application/xml';
        $body = $this->getXmlBody($member, $value);
        $opts['headers']['Content-Length'] = (string) strlen($body);
        $opts['body'] = $body;
    }

    private function getXmlBody(StructureShape $member, array $value): string
    {
        $xmlBody = $this->xmlBody->build($member, $value);
        $xmlBody = str_replace("'", '&apos;', $xmlBody);
        $xmlBody = str_replace('\r', '&#13;', $xmlBody);
        $xmlBody = str_replace('\n', '&#10;', $xmlBody);

        return $xmlBody;
    }

    private function serialize(Operation $operation, array $args): array
    {
        $opts = [];
        $input = $operation->getInput();

        // payload trait：指定某个成员作为整个消息体
        if ($payload = $input['payload']) {
            $this->applyPayload($input, $payload, $args, $opts);
        }

        foreach ($args as $name => $value) {
            if ($input->hasMember($name)) {
                $member = $input->getMember($name);
                $location = $member['location'];
                if (!$payload && !$location) {
                    $bodyMembers[$name] = $value;
                } elseif ($location === 'header') {
                    $this->applyHeader($name, $member, $value, $opts);
                } elseif ($location === 'querystring') {
                    $this->applyQuery($name, $member, $value, $opts);
                } elseif ($location === 'headers') {
                    $this->applyHeaderMap($name, $member, $value, $opts);
                }
            }
        }

        if (isset($bodyMembers)) {
            $this->payload($input, $bodyMembers, $opts);
        } elseif (!isset($opts['body']) && $this->hasPayloadParam($input, $payload)) {
            $this->payload($input, [], $opts);
        }

        return $opts;
    }

    private function applyPayload(StructureShape $input, string $name, array $args, array &$opts): void
    {
        if (!isset($args[$name])) {
            return;
        }

        $m = $input->getMember($name);
        $type = $m->getType();

        if ($m['streaming'] || $type === 'string' || $type === 'blob') {
            // S3 的 Content-Type 由 Middleware 按对象内容推断，这里不设默认值
            $body = $args[$name];
            if (!$m['streaming'] && is_string($body)) {
                $opts['headers']['Content-Length'] = (string) strlen($body);
            }

            // Stream::create 对 resource 不接管所有权，用户句柄不会被提前关闭
            $opts['body'] = Stream::create($body);

            return;
        }

        $this->payload($m, $args[$name], $opts);
    }

    private function applyHeader(string $name, Shape $member, mixed $value, array &$opts): void
    {
        if ($value === null) {
            return;
        }

        if ($member instanceof ListShape) {
            if (!is_array($value)) {
                throw new \InvalidArgumentException('头部值必须是标量或标量数组');
            }

            $listMember = $member->getMember();
            $headerValues = [];

            foreach ($value as $listValue) {
                if ($listValue === null) {
                    throw new \InvalidArgumentException('头部值必须是标量或标量数组');
                }

                $tempOpts = ['headers' => []];
                $this->applyHeader('temp', $listMember, $listValue, $tempOpts);
                if (!array_key_exists('temp', $tempOpts['headers'])) {
                    throw new \InvalidArgumentException('头部值必须是标量或标量数组');
                }

                $headerValues[] = $tempOpts['headers']['temp'];
            }

            $value = $headerValues;
        } else {
            switch ($member->getType()) {
                case 'timestamp':
                    $value = TimestampShape::format($value, $member['timestampFormat'] ?? 'rfc822');
                    break;
                case 'boolean':
                    $value = $value ? 'true' : 'false';
                    break;
            }
        }

        if ($member['jsonvalue']) {
            $value = json_encode($value);
            if (empty($value) && json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException(
                    'json_encode 无法编码该值: ' . json_last_error_msg()
                );
            }

            $value = base64_encode($value);
        }

        $opts['headers'][$member['locationName'] ?: $name] = self::prepareHeaderValue($value);
    }

    /**
     * 前缀式头部映射，S3 用它承载 x-amz-meta-*。
     */
    private function applyHeaderMap(string $name, Shape $member, array $value, array &$opts): void
    {
        $prefix = $member['locationName'];
        foreach ($value as $k => $v) {
            if ($v === null) {
                continue;
            }

            $opts['headers'][$prefix . $k] = self::prepareHeaderValue($v);
        }
    }

    private static function prepareHeaderValue(mixed $value): string|array
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            if ($value === []) {
                return '';
            }

            foreach ($value as $key => $item) {
                if (!is_scalar($item)) {
                    throw new \InvalidArgumentException('头部值必须是标量或标量数组');
                }

                $value[$key] = (string) $item;
            }

            return $value;
        }

        throw new \InvalidArgumentException('头部值必须是标量或标量数组');
    }

    private function applyQuery(string $name, Shape $member, mixed $value, array &$opts): void
    {
        if ($member instanceof MapShape) {
            $opts['query'] = isset($opts['query']) && is_array($opts['query'])
                ? $opts['query'] + $value
                : $value;
        } elseif ($member instanceof ListShape) {
            $listMember = $member->getMember();
            $paramName = $member['locationName'] ?: $name;

            foreach ($value as $listValue) {
                $tempOpts = ['query' => []];
                $this->applyQuery('temp', $listMember, $listValue, $tempOpts);
                $opts['query'][$paramName][] = $tempOpts['query']['temp'];
            }
        } elseif ($value !== null) {
            switch ($member->getType()) {
                case 'timestamp':
                    $value = TimestampShape::format($value, $member['timestampFormat'] ?? 'iso8601');
                    break;
                case 'boolean':
                    $value = $value ? 'true' : 'false';
                    break;
            }

            $opts['query'][$member['locationName'] ?: $name] = $value;
        }
    }

    private function buildEndpoint(Operation $operation, array $args, array $opts): Uri
    {
        $relativeUri = $this->expandUriTemplate($operation, $args);

        if (!empty($opts['query'])) {
            $relativeUri = $this->appendQuery($opts['query'], $relativeUri);
        }

        // 含 . / .. 片段或以 / 开头的 key，走 URI 解析会被规范化掉，
        // 只能直接拼接字符串保留原始路径
        if (isset($args['Key']) && $this->shouldPreservePath($args['Key'])) {
            return new Uri($this->endpoint . $relativeUri);
        }

        return $this->resolveUri($relativeUri, $opts);
    }

    private function expandUriTemplate(Operation $operation, array $args): string
    {
        $varDefinitions = $this->getVarDefinitions($operation, $args);

        return preg_replace_callback(
            self::TEMPLATE_STRING_REGEX,
            static function (array $matches) use ($varDefinitions): string {
                $isGreedy = str_ends_with($matches[1], '+');
                $varName = $isGreedy ? substr($matches[1], 0, -1) : $matches[1];

                if (!isset($varDefinitions[$varName])) {
                    return '';
                }

                $value = $varDefinitions[$varName];

                // greedy 变量（如 {Key+}）保留斜杠，代表多级路径
                if ($isGreedy) {
                    return str_replace('%2F', '/', rawurlencode($value));
                }

                return rawurlencode($value);
            },
            $operation['http']['requestUri']
        );
    }

    private function shouldPreservePath(string $key): bool
    {
        if (str_contains($key, '.')) {
            foreach (explode('/', $key) as $segment) {
                if ($segment === '.' || $segment === '..') {
                    return true;
                }
            }
        }

        return str_starts_with($key, '/');
    }

    private function resolveUri(string $relativeUri, array $opts): Uri
    {
        $basePath = $this->endpoint->getPath();

        // endpoint 带路径前缀时（如 http://host/s3），要把它保留在最终 URI 里
        if (!empty($basePath) && $basePath !== '/') {
            if ($relativeUri === '/' || empty($relativeUri)) {
                return $this->endpoint->withPath(rtrim($basePath, '/'));
            }

            if (empty($opts['query']) && str_starts_with($relativeUri, '/?')) {
                return $this->endpoint->withQuery(substr($relativeUri, 2));
            }

            if (!str_ends_with($basePath, '/')) {
                $this->endpoint = $this->endpoint->withPath($basePath . '/');
            }

            if (str_starts_with($relativeUri, '/')) {
                $relativeUri = substr($relativeUri, 1);
            }
        }

        return Uri::resolve($this->endpoint, new Uri($relativeUri));
    }

    private function hasPayloadParam(StructureShape $input, mixed $payload): bool
    {
        if ($payload) {
            $potentiallyEmptyTypes = ['blob', 'string'];
            if ($this->api->getProtocol() === 'rest-xml') {
                $potentiallyEmptyTypes[] = 'structure';
            }

            $payloadMember = $input->getMember($payload);
            if (!empty($payloadMember['union'])
                || in_array($payloadMember['type'], $potentiallyEmptyTypes, true)
            ) {
                return false;
            }
        }

        foreach ($input->getMembers() as $member) {
            if (!isset($member['location'])) {
                return true;
            }
        }

        return false;
    }

    private function appendQuery(array $query, string $relativeUri): string
    {
        $append = Query::build($query);

        return $relativeUri . (str_contains($relativeUri, '?') ? "&{$append}" : "?{$append}");
    }

    private function getVarDefinitions(Operation $operation, array $args): array
    {
        $varDefinitions = [];

        foreach ($operation->getInput()->getMembers() as $name => $member) {
            if ($member['location'] === 'uri') {
                $value = $args[$name] ?? null;
                if ($value !== null) {
                    switch ($member->getType()) {
                        case 'timestamp':
                            $value = TimestampShape::format($value, $member['timestampFormat'] ?? 'iso8601');
                            break;
                        case 'boolean':
                            $value = $value ? 'true' : 'false';
                            break;
                    }
                }

                $varDefinitions[$member['locationName'] ?: $name] = $value;
            }
        }

        return $varDefinitions;
    }
}
