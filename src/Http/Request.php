<?php
namespace MinS3\Http;

/**
 * 不可变 HTTP 请求。
 */
final class Request
{
    use MessageTrait;

    private string $method;
    private Uri $uri;

    /**
     * @param string       $method  HTTP 方法
     * @param Uri|string   $uri     请求地址
     * @param array        $headers 头部
     * @param mixed        $body    消息体：字符串、资源、Stream 或 null
     */
    public function __construct(
        string $method,
        Uri|string $uri,
        array $headers = [],
        mixed $body = null,
        string $version = '1.1'
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri instanceof Uri ? $uri : new Uri($uri);
        $this->setHeaders($headers);
        $this->protocol = $version;

        if (!$this->hasHeader('Host')) {
            $this->updateHostFromUri();
        }

        if ($body !== null && $body !== '') {
            $this->stream = Stream::create($body);
        }
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): self
    {
        $new = clone $this;
        $new->method = strtoupper($method);

        return $new;
    }

    public function getUri(): Uri
    {
        return $this->uri;
    }

    public function withUri(Uri $uri, bool $preserveHost = false): self
    {
        if ($uri === $this->uri) {
            return $this;
        }

        $new = clone $this;
        $new->uri = $uri;

        if (!$preserveHost || !$this->hasHeader('Host')) {
            $new->updateHostFromUri();
        }

        return $new;
    }

    /**
     * 请求行中的目标：path + query，供 curl 与调试使用。
     */
    public function getRequestTarget(): string
    {
        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }

        $query = $this->uri->getQuery();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        return $target;
    }

    private function updateHostFromUri(): void
    {
        $host = $this->uri->getHost();
        if ($host === '') {
            return;
        }

        if (($port = $this->uri->getPort()) !== null) {
            $host .= ':' . $port;
        }

        // Host 必须排在最前：部分服务端对头部顺序敏感，
        // 且这样调试输出更接近实际线上报文
        if (isset($this->headerNames['host'])) {
            $name = $this->headerNames['host'];
            unset($this->headers[$name]);
        }
        $this->headerNames['host'] = 'Host';
        $this->headers = ['Host' => [$host]] + $this->headers;
    }
}
