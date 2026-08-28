<?php
namespace MinS3\Http;

/**
 * 不可变 URI。
 *
 * 与通用 PSR-7 实现的关键差异：path 按「原始字节」保存，不做任何
 * 编码或解码。SigV4 的规范请求需要逐字节还原调用方给出的 path，
 * 若在此处重新编码会导致签名与服务端计算结果不一致。
 * 编码由序列化层（RestSerializer::expandUriTemplate）负责。
 */
final class Uri implements \Stringable
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }

        $parts = parse_url($uri);
        if ($parts === false) {
            throw new \InvalidArgumentException("无法解析 URI: {$uri}");
        }

        $this->scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $this->host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->path = $parts['path'] ?? '';
        $this->query = $parts['query'] ?? '';
        $this->fragment = $parts['fragment'] ?? '';

        if (isset($parts['user'])) {
            $this->userInfo = $parts['user'];
            if (isset($parts['pass'])) {
                $this->userInfo .= ':' . $parts['pass'];
            }
        }

        $this->removeDefaultPort();
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function withScheme(string $scheme): self
    {
        $new = clone $this;
        $new->scheme = strtolower($scheme);
        $new->removeDefaultPort();

        return $new;
    }

    public function withUserInfo(string $user, ?string $password = null): self
    {
        $info = $user;
        if ($password !== null && $password !== '') {
            $info .= ':' . $password;
        }

        $new = clone $this;
        $new->userInfo = $info;

        return $new;
    }

    public function withHost(string $host): self
    {
        $new = clone $this;
        $new->host = strtolower($host);

        return $new;
    }

    public function withPort(?int $port): self
    {
        $new = clone $this;
        $new->port = $port;
        $new->removeDefaultPort();

        return $new;
    }

    public function withPath(string $path): self
    {
        $new = clone $this;
        $new->path = $path;

        return $new;
    }

    public function withQuery(string $query): self
    {
        $new = clone $this;
        $new->query = ltrim($query, '?');

        return $new;
    }

    public function withFragment(string $fragment): self
    {
        $new = clone $this;
        $new->fragment = ltrim($fragment, '#');

        return $new;
    }

    public function __toString(): string
    {
        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();
        if ($authority !== '' || $this->scheme === 'file') {
            $uri .= '//' . $authority;
        }

        $path = $this->path;
        if ($path !== '') {
            if ($path[0] !== '/') {
                if ($authority !== '') {
                    // path 必须以 / 开头才能跟在 authority 之后
                    $path = '/' . $path;
                }
            } elseif (isset($path[1]) && $path[1] === '/' && $authority === '') {
                // 以 // 开头的 path 在没有 authority 时会被误解析为 authority
                $path = '/' . ltrim($path, '/');
            }
        }
        $uri .= $path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    /**
     * 把相对引用解析到基准 URI 之上（RFC 3986 §5.2）。
     */
    public static function resolve(self $base, self $rel): self
    {
        if ((string) $rel === '') {
            return $base;
        }

        if ($rel->scheme !== '') {
            return $rel->withPath(self::removeDotSegments($rel->path));
        }

        if ($rel->getAuthority() !== '') {
            $target = $rel->withScheme($base->scheme);

            return $target->withPath(self::removeDotSegments($rel->path));
        }

        if ($rel->path === '') {
            $target = $base;
            if ($rel->query !== '') {
                $target = $target->withQuery($rel->query);
            }
        } else {
            $path = $rel->path[0] === '/'
                ? self::removeDotSegments($rel->path)
                : self::removeDotSegments(self::mergePaths($base, $rel->path));

            $target = $base->withPath($path)->withQuery($rel->query);
        }

        return $target->withFragment($rel->fragment);
    }

    private static function mergePaths(self $base, string $relPath): string
    {
        if ($base->getAuthority() !== '' && $base->path === '') {
            return '/' . $relPath;
        }

        $pos = strrpos($base->path, '/');
        if ($pos === false) {
            return $relPath;
        }

        return substr($base->path, 0, $pos + 1) . $relPath;
    }

    /**
     * RFC 3986 §5.2.4。注意：只在解析相对引用时调用，
     * 不能对已经确定的 S3 key 路径无条件执行，否则 key 里
     * 合法的 "." / ".." 片段会被吃掉。
     */
    private static function removeDotSegments(string $path): string
    {
        if ($path === '' || $path === '/') {
            return $path;
        }

        $output = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($output);
            } elseif ($segment !== '.') {
                $output[] = $segment;
            }
        }

        $result = implode('/', $output);

        // 保持首尾斜杠语义
        if (isset($path[0]) && $path[0] === '/' && (!isset($result[0]) || $result[0] !== '/')) {
            $result = '/' . $result;
        }
        if ($result !== '/' && str_ends_with($path, '/.') || str_ends_with($path, '/..')) {
            $result .= '/';
        }

        return $result;
    }

    private function removeDefaultPort(): void
    {
        if ($this->port !== null
            && isset(self::DEFAULT_PORTS[$this->scheme])
            && self::DEFAULT_PORTS[$this->scheme] === $this->port
        ) {
            $this->port = null;
        }
    }
}
