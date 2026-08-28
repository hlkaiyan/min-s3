<?php
namespace MinS3\Signature;

use MinS3\Credentials;
use MinS3\Http\Query;
use MinS3\Http\Request;
use MinS3\Http\Stream;
use MinS3\Http\Uri;

/**
 * AWS Signature Version 4（S3 特化）。
 *
 * 算法逐行取自 aws-sdk-php 的 SignatureV4 + S3SignatureV4，只把
 * Guzzle/PSR-7 换成本包的 HTTP 类。S3 与通用 SigV4 的两点差异保留：
 *  - 规范路径不做二次编码（通用 SigV4 会 rawurlencode 两次）
 *  - 始终发送 x-amz-content-sha256，预签名时用 UNSIGNED-PAYLOAD
 *
 * 未实现 SigV4a（非对称签名）：它只用于 AWS 多区域接入点，且依赖
 * awscrt 扩展，自建 S3 场景用不到。
 *
 * @link https://docs.aws.amazon.com/general/latest/gr/signature-version-4.html
 */
class SignatureV4
{
    public const ISO8601_BASIC = 'Ymd\THis\Z';
    public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';
    public const AMZ_CONTENT_SHA256_HEADER = 'X-Amz-Content-Sha256';

    private string $service;
    private string $region;
    private bool $unsigned;

    /** @var array 派生签名密钥缓存 */
    private array $cache = [];
    private int $cacheSize = 0;

    /**
     * @param string $service 签名服务名，S3 为 s3
     * @param string $region  区域名。自建 S3 服务端通常不校验，但必须
     *                        与服务端配置一致，且不能为空
     * @param array  $options unsigned-body: 是否跳过消息体摘要
     */
    public function __construct(string $service, string $region, array $options = [])
    {
        $this->service = $service;
        $this->region = $region;
        $this->unsigned = (bool) ($options['unsigned-body'] ?? false);
    }

    /**
     * 以 Authorization 头的形式签名请求。
     */
    public function signRequest(Request $request, Credentials $credentials): Request
    {
        // S3 要求每个请求都带内容摘要
        if (!$request->hasHeader('x-amz-content-sha256')) {
            $request = $request->withHeader('x-amz-content-sha256', $this->getPayload($request));
        }

        $ldt = gmdate(self::ISO8601_BASIC);
        $sdt = substr($ldt, 0, 8);
        $parsed = $this->parseRequest($request);
        $parsed['headers']['X-Amz-Date'] = [$ldt];

        if ($token = $credentials->getSecurityToken()) {
            $parsed['headers']['X-Amz-Security-Token'] = [$token];
        }

        $cs = $this->createScope($sdt, $this->region, $this->service);
        $payload = $this->getPayload($request);

        if ($payload === self::UNSIGNED_PAYLOAD) {
            $parsed['headers'][self::AMZ_CONTENT_SHA256_HEADER] = [$payload];
        }

        $context = $this->createContext($parsed, $payload);
        $toSign = $this->createStringToSign($ldt, $cs, $context['creq']);
        $signingKey = $this->getSigningKey($sdt, $this->region, $this->service, $credentials->getSecretKey());
        $signature = hash_hmac('sha256', $toSign, $signingKey);

        $parsed['headers']['Authorization'] = [
            'AWS4-HMAC-SHA256 '
            . "Credential={$credentials->getAccessKeyId()}/{$cs}, "
            . "SignedHeaders={$context['headers']}, Signature={$signature}"
        ];

        return $this->buildRequest($parsed);
    }

    /**
     * 生成预签名请求，签名信息放在 query 里。
     *
     * @param int|string|\DateTimeInterface $expires 过期时间，可以是
     *        时间戳、'+20 minutes' 这类相对表达式或 DateTime
     * @param array $options start_time: 签名起算时间，默认当前
     */
    public function presign(
        Request $request,
        Credentials $credentials,
        int|string|\DateTimeInterface $expires,
        array $options = []
    ): Request {
        if (!$request->hasHeader('x-amz-content-sha256')) {
            $request = $request->withHeader(
                self::AMZ_CONTENT_SHA256_HEADER,
                $this->getPresignedPayload($request)
            );
        }

        $startTimestamp = isset($options['start_time'])
            ? $this->convertToTimestamp($options['start_time'], null)
            : time();
        $expiresTimestamp = $this->convertToTimestamp($expires, $startTimestamp);

        $parsed = $this->createPresignedRequest($request, $credentials);
        $payload = $this->getPresignedPayload($request);
        $httpDate = gmdate(self::ISO8601_BASIC, $startTimestamp);
        $shortDate = substr($httpDate, 0, 8);
        $scope = $this->createScope($shortDate, $this->region, $this->service);
        $credential = $credentials->getAccessKeyId() . '/' . $scope;

        if ($credentials->getSecurityToken()) {
            unset($parsed['headers']['X-Amz-Security-Token']);
        }

        $parsed['query']['X-Amz-Algorithm'] = 'AWS4-HMAC-SHA256';
        $parsed['query']['X-Amz-Credential'] = $credential;
        $parsed['query']['X-Amz-Date'] = gmdate('Ymd\THis\Z', $startTimestamp);
        $parsed['query']['X-Amz-SignedHeaders'] = implode(';', $this->getPresignHeaders($parsed['headers']));
        $parsed['query']['X-Amz-Expires'] = $this->convertExpires($expiresTimestamp, $startTimestamp);

        $context = $this->createContext($parsed, $payload);
        $stringToSign = $this->createStringToSign($httpDate, $scope, $context['creq']);
        $key = $this->getSigningKey($shortDate, $this->region, $this->service, $credentials->getSecretKey());
        $parsed['query']['X-Amz-Signature'] = hash_hmac('sha256', $stringToSign, $key);

        return $this->buildRequest($parsed);
    }

    /**
     * 这些头不参与签名：经过代理或被 HTTP 客户端改写后
     * 会导致签名不匹配。
     */
    protected function getHeaderBlacklist(): array
    {
        return [
            'cache-control'         => true,
            'content-length'        => true,
            'expect'                => true,
            'max-forwards'          => true,
            'pragma'                => true,
            'range'                 => true,
            'te'                    => true,
            'if-match'              => true,
            'if-none-match'         => true,
            'if-modified-since'     => true,
            'if-unmodified-since'   => true,
            'if-range'              => true,
            'accept'                => true,
            'authorization'         => true,
            'proxy-authorization'   => true,
            'from'                  => true,
            'referer'               => true,
            'user-agent'            => true,
            'x-amzn-trace-id'       => true,
            'amz-sdk-invocation-id' => true,
            'amz-sdk-request'       => true,
        ];
    }

    /**
     * 预签名 URL 里额外要排除的头：使用者在用这个 URL 时
     * 无法可靠复现它们。
     */
    protected function getPresignHeaderDenyList(): array
    {
        return [
            'content-type'     => true,
            'x-amz-user-agent' => true,
        ];
    }

    /**
     * S3 不对规范路径做二次编码。
     */
    protected function createCanonicalizedPath(string $path): string
    {
        // key 前面本来就有一个斜杠，只去掉这一个
        if (str_starts_with($path, '/')) {
            $path = substr($path, 1);
        }

        return '/' . $path;
    }

    protected function getPayload(Request $request): string
    {
        if ($this->unsigned && $request->getUri()->getScheme() === 'https') {
            return self::UNSIGNED_PAYLOAD;
        }

        if ($request->hasHeader(self::AMZ_CONTENT_SHA256_HEADER)) {
            return $request->getHeaderLine(self::AMZ_CONTENT_SHA256_HEADER);
        }

        $body = $request->getBody();
        if (!$body->isSeekable()) {
            // 不可定位的流没法先算摘要再发送，只能声明为未签名载荷
            return self::UNSIGNED_PAYLOAD;
        }

        return Stream::hash($body, 'sha256');
    }

    /**
     * 预签名 URL 的载荷内容在签发时是未知的。
     */
    protected function getPresignedPayload(Request $request): string
    {
        return self::UNSIGNED_PAYLOAD;
    }

    /**
     * 凭证范围：日期/区域/服务/aws4_request。
     */
    public static function createScope(string $shortDate, string $region, string $service): string
    {
        return "{$shortDate}/{$region}/{$service}/aws4_request";
    }

    /**
     * 派生签名密钥（无缓存版本）。
     *
     * PostObjectV4 也要用它给表单策略签名，因此暴露为静态方法，
     * 避免两处各写一遍 4 层 HMAC。
     */
    public static function createSigningKey(
        string $shortDate,
        string $region,
        string $service,
        string $secretKey
    ): string {
        $dateKey = hash_hmac('sha256', $shortDate, "AWS4{$secretKey}", true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', $service, $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    /**
     * 派生签名密钥。同一天、同区域、同服务的密钥可以复用，
     * 缓存能省掉每个请求 4 次 HMAC。
     */
    private function getSigningKey(string $shortDate, string $region, string $service, string $secretKey): string
    {
        $k = $shortDate . '_' . $region . '_' . $service . '_' . $secretKey;

        if (!isset($this->cache[$k])) {
            if (++$this->cacheSize > 50) {
                $this->cache = [];
                $this->cacheSize = 0;
            }

            $this->cache[$k] = self::createSigningKey($shortDate, $region, $service, $secretKey);
        }

        return $this->cache[$k];
    }

    private function createStringToSign(string $longDate, string $credentialScope, string $creq): string
    {
        $hash = hash('sha256', $creq);

        return "AWS4-HMAC-SHA256\n{$longDate}\n{$credentialScope}\n{$hash}";
    }

    /**
     * 构造规范请求。
     *
     * @return array{creq: string, headers: string}
     */
    private function createContext(array $parsedRequest, string $payload): array
    {
        $blacklist = $this->getHeaderBlacklist();

        $canon = $parsedRequest['method'] . "\n"
            . $this->createCanonicalizedPath($parsedRequest['path']) . "\n"
            . $this->getCanonicalizedQuery($parsedRequest['query']) . "\n";

        // 头名大小写不敏感，统一转小写后按字典序排列
        $aggregate = [];
        foreach ($parsedRequest['headers'] as $key => $values) {
            $key = strtolower($key);
            if (!isset($blacklist[$key])) {
                foreach ($values as $v) {
                    $aggregate[$key][] = $v;
                }
            }
        }

        ksort($aggregate);
        $canonHeaders = [];
        foreach ($aggregate as $k => $v) {
            if (count($v) > 0) {
                sort($v);
            }
            $canonHeaders[] = $k . ':' . preg_replace('/\s+/', ' ', implode(',', $v));
        }

        $signedHeadersString = implode(';', array_keys($aggregate));
        $canon .= implode("\n", $canonHeaders) . "\n\n"
            . $signedHeadersString . "\n"
            . $payload;

        return ['creq' => $canon, 'headers' => $signedHeadersString];
    }

    private function getCanonicalizedQuery(array $query): string
    {
        unset($query['X-Amz-Signature']);

        if (!$query) {
            return '';
        }

        $qs = '';
        // 按编码后的键排序，与服务端的计算顺序一致
        uksort($query, static fn(string $a, string $b): int => strcmp(rawurlencode($a), rawurlencode($b)));

        foreach ($query as $k => $v) {
            if (!is_array($v)) {
                $qs .= rawurlencode($k) . '=' . rawurlencode($v ?? '') . '&';
            } else {
                sort($v, SORT_STRING);
                foreach ($v as $value) {
                    $qs .= rawurlencode($k) . '=' . rawurlencode($value ?? '') . '&';
                }
            }
        }

        return substr($qs, 0, -1);
    }

    private function getPresignHeaders(array $headers): array
    {
        $presignHeaders = [];
        $blacklist = $this->getHeaderBlacklist();

        foreach ($headers as $name => $value) {
            $lName = strtolower($name);
            if (!isset($blacklist[$lName]) && $name !== self::AMZ_CONTENT_SHA256_HEADER) {
                $presignHeaders[] = $lName;
            }
        }

        sort($presignHeaders);

        return $presignHeaders;
    }

    private function createPresignedRequest(Request $request, Credentials $credentials): array
    {
        $parsedRequest = $this->parseRequest($request);

        if ($token = $credentials->getSecurityToken()) {
            $parsedRequest['headers']['X-Amz-Security-Token'] = [$token];
        }

        return $this->moveHeadersToQuery($parsedRequest);
    }

    private function moveHeadersToQuery(array $parsedRequest): array
    {
        $presignDenyList = $this->getPresignHeaderDenyList();
        $blacklist = $this->getHeaderBlacklist() + $presignDenyList;

        foreach ($parsedRequest['headers'] as $name => $header) {
            $lname = strtolower($name);

            if (str_starts_with($lname, 'x-amz') && !isset($presignDenyList[$lname])) {
                $parsedRequest['query'][$name] = $header;
            }

            if (isset($blacklist[$lname])
                || $lname === strtolower(self::AMZ_CONTENT_SHA256_HEADER)
            ) {
                unset($parsedRequest['headers'][$name]);
            }
        }

        return $parsedRequest;
    }

    private function convertToTimestamp(
        int|string|\DateTimeInterface $dateValue,
        ?int $relativeTimeBase = null
    ): int {
        if ($dateValue instanceof \DateTimeInterface) {
            return $dateValue->getTimestamp();
        }

        if (!is_numeric($dateValue)) {
            $timestamp = strtotime($dateValue, $relativeTimeBase ?? time());
            if ($timestamp === false) {
                throw new \InvalidArgumentException("无法解析时间: {$dateValue}");
            }

            return $timestamp;
        }

        return (int) $dateValue;
    }

    private function convertExpires(int $expiresTimestamp, int $startTimestamp): int
    {
        $duration = $expiresTimestamp - $startTimestamp;

        if ($duration > 604800) {
            throw new \InvalidArgumentException('预签名 URL 的有效期不能超过 7 天');
        }

        if ($duration <= 0) {
            throw new \InvalidArgumentException('预签名 URL 的有效期必须大于 0');
        }

        return $duration;
    }

    private function parseRequest(Request $request): array
    {
        // 清掉可能残留的上一次签名结果
        $request = $request
            ->withoutHeader('X-Amz-Date')
            ->withoutHeader('Date')
            ->withoutHeader('Authorization');

        $uri = $request->getUri();

        return [
            'method'  => $request->getMethod(),
            // Uri 保存的是原始未解码 path，正是规范请求需要的形式
            'path'    => $uri->getPath(),
            'query'   => Query::parse($uri->getQuery()),
            'uri'     => $uri,
            'headers' => $request->getHeaders(),
            'body'    => $request->getBody(),
            'version' => $request->getProtocolVersion(),
        ];
    }

    private function buildRequest(array $req): Request
    {
        /** @var Uri $uri */
        $uri = $req['uri'];
        if ($req['query']) {
            $uri = $uri->withQuery(Query::build($req['query']));
        }

        return new Request(
            $req['method'],
            $uri,
            $req['headers'],
            $req['body'],
            $req['version']
        );
    }
}
