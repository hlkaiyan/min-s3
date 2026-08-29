<?php
namespace MinS3\Http;

/**
 * 请求与响应共用的头部与消息体处理。
 *
 * 头名按 HTTP 规范大小写不敏感：内部用小写做索引，同时保留调用方
 * 书写的原始大小写用于输出。SigV4 计算规范请求时会统一转小写，
 * 所以这里保留原样不影响签名。
 */
trait MessageTrait
{
    /** @var array<string, string[]> 原始头名 => 值数组 */
    private array $headers = [];

    /** @var array<string, string> 小写头名 => 原始头名 */
    private array $headerNames = [];

    private string $protocol = '1.1';

    private ?Stream $stream = null;

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): static
    {
        if ($this->protocol === $version) {
            return $this;
        }

        $new = clone $this;
        $new->protocol = $version;

        return $new;
    }

    /**
     * @return array<string, string[]>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $header): bool
    {
        return isset($this->headerNames[strtolower($header)]);
    }

    /**
     * @return string[]
     */
    public function getHeader(string $header): array
    {
        $lower = strtolower($header);
        if (!isset($this->headerNames[$lower])) {
            return [];
        }

        return $this->headers[$this->headerNames[$lower]];
    }

    public function getHeaderLine(string $header): string
    {
        return implode(', ', $this->getHeader($header));
    }

    public function withHeader(string $header, mixed $value): static
    {
        self::assertHeaderName($header);
        $value = $this->normalizeHeaderValue($value);
        $lower = strtolower($header);

        $new = clone $this;
        if (isset($new->headerNames[$lower])) {
            unset($new->headers[$new->headerNames[$lower]]);
        }
        $new->headerNames[$lower] = $header;
        $new->headers[$header] = $value;

        return $new;
    }

    public function withAddedHeader(string $header, mixed $value): static
    {
        self::assertHeaderName($header);
        $value = $this->normalizeHeaderValue($value);
        $lower = strtolower($header);

        $new = clone $this;
        if (isset($new->headerNames[$lower])) {
            $name = $new->headerNames[$lower];
            $new->headers[$name] = array_merge($new->headers[$name], $value);
        } else {
            $new->headerNames[$lower] = $header;
            $new->headers[$header] = $value;
        }

        return $new;
    }

    public function withoutHeader(string $header): static
    {
        $lower = strtolower($header);
        if (!isset($this->headerNames[$lower])) {
            return $this;
        }

        $new = clone $this;
        unset($new->headers[$new->headerNames[$lower]], $new->headerNames[$lower]);

        return $new;
    }

    public function getBody(): Stream
    {
        if ($this->stream === null) {
            $this->stream = Stream::create('');
        }

        return $this->stream;
    }

    public function withBody(mixed $body): static
    {
        $body = Stream::create($body);
        if ($body === $this->stream) {
            return $this;
        }

        $new = clone $this;
        $new->stream = $body;

        return $new;
    }

    private function setHeaders(array $headers): void
    {
        $this->headerNames = $this->headers = [];

        foreach ($headers as $header => $value) {
            // 数字键会被 PHP 自动转成 int，还原为字符串
            $header = (string) $header;
            self::assertHeaderName($header);
            $value = $this->normalizeHeaderValue($value);
            $lower = strtolower($header);

            if (isset($this->headerNames[$lower])) {
                $name = $this->headerNames[$lower];
                $this->headers[$name] = array_merge($this->headers[$name], $value);
            } else {
                $this->headerNames[$lower] = $header;
                $this->headers[$header] = $value;
            }
        }
    }

    /**
     * @return string[]
     */
    private function normalizeHeaderValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [self::sanitizeHeaderValue($value)];
        }

        if ($value === []) {
            return [''];
        }

        $result = [];
        foreach ($value as $v) {
            $result[] = self::sanitizeHeaderValue($v);
        }

        return $result;
    }

    /**
     * 头值不允许出现 CR、LF、NUL。
     *
     * 这不只是规范洁癖：S3 的对象元数据（x-amz-meta-*）直接来自用户输入，
     * 若放任换行符进入头部，构造 "value\r\nX-Injected: ..." 就能伪造出
     * 额外的请求头。宁可在这里报错，也不能让它发出去。
     */
    private static function sanitizeHeaderValue(mixed $value): string
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (!is_scalar($value) && !(is_object($value) && method_exists($value, '__toString'))) {
            throw new \InvalidArgumentException(
                '头部值必须是标量或可转字符串的对象，收到 ' . get_debug_type($value)
            );
        }

        $value = (string) $value;

        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new \InvalidArgumentException(
                '头部值不能包含换行符或空字节（可能是头注入尝试）：'
                . var_export(mb_strimwidth($value, 0, 60, '…'), true)
            );
        }

        return trim($value, " \t");
    }

    /**
     * 头名必须是 RFC 7230 定义的 token。
     */
    private static function assertHeaderName(string $name): void
    {
        if ($name === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name) !== 1) {
            throw new \InvalidArgumentException(
                '非法的头部名称：' . var_export(mb_strimwidth($name, 0, 60, '…'), true)
            );
        }
    }
}
