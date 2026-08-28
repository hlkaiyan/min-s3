<?php
namespace MinS3\Api\Parser;

use MinS3\Http\Response;

/**
 * 解析 S3 返回的错误响应。
 *
 * S3 的错误体形如：
 *   <Error><Code>NoSuchBucket</Code><Message>...</Message><RequestId>..</RequestId></Error>
 * HEAD 请求没有响应体，只能退回到状态码与响应头。
 */
class XmlErrorParser
{
    /**
     * @return array{type: string, code: ?string, message: ?string, request_id: ?string, parsed: ?\SimpleXMLElement}
     */
    public function __invoke(Response $response): array
    {
        $code = (string) $response->getStatusCode();

        $data = [
            'type'       => $code[0] === '4' ? 'client' : 'server',
            'request_id' => null,
            'code'       => null,
            'message'    => null,
            'parsed'     => null,
        ];

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $rawBody = (string) $body;

        if ($rawBody !== '') {
            try {
                $this->parseBody($this->parseXml($rawBody), $data);
            } catch (ParserException $e) {
                // 错误体不是合法 XML（例如网关返回的 HTML 错误页），
                // 退回到状态码，同时保留原始内容便于排查
                $this->parseHeaders($response, $data);
                $data['message'] .= ' - 响应体: ' . $this->truncate($rawBody);
            }
        } else {
            $this->parseHeaders($response, $data);
        }

        return $data;
    }

    private function parseHeaders(Response $response, array &$data): void
    {
        if ($response->getStatusCode() === 404) {
            $data['code'] = 'NotFound';
        }

        $data['message'] = $response->getStatusCode() . ' ' . $response->getReasonPhrase();

        if ($requestId = $response->getHeaderLine('x-amz-request-id')) {
            $data['request_id'] = $requestId;
            $data['message'] .= " (Request-ID: {$requestId})";
        }
    }

    private function parseBody(\SimpleXMLElement $body, array &$data): void
    {
        $data['parsed'] = $body;
        $prefix = $this->registerNamespacePrefix($body);

        if ($tempXml = $body->xpath("//{$prefix}Code[1]")) {
            $data['code'] = (string) $tempXml[0];
        }

        if ($tempXml = $body->xpath("//{$prefix}Message[1]")) {
            $data['message'] = (string) $tempXml[0];
        }

        if ($tempXml = $body->xpath("//{$prefix}RequestId[1]")) {
            $data['request_id'] = (string) $tempXml[0];
        }
    }

    /**
     * SimpleXML 的 xpath 无法直接匹配默认命名空间下的节点，
     * 需要先给它注册一个前缀。
     */
    private function registerNamespacePrefix(\SimpleXMLElement $element): string
    {
        $namespaces = $element->getDocNamespaces();
        if (!isset($namespaces[''])) {
            return '';
        }

        $element->registerXPathNamespace('ns', $namespaces['']);

        return 'ns:';
    }

    private function parseXml(string $xml): \SimpleXMLElement
    {
        $priorSetting = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $parsed = new \SimpleXMLElement($xml);
            if ($error = libxml_get_last_error()) {
                throw new \RuntimeException($error->message);
            }
        } catch (\Exception $e) {
            throw new ParserException("解析错误响应 XML 失败: {$e->getMessage()}", 0, $e);
        } finally {
            libxml_use_internal_errors($priorSetting);
        }

        return $parsed;
    }

    private function truncate(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        return strlen($body) > 200 ? substr($body, 0, 200) . '…' : $body;
    }
}
