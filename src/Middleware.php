<?php
namespace MinS3;

use MinS3\Api\Operation;
use MinS3\Http\Request;
use MinS3\Http\Stream;
use MinS3\Http\Uri;

/**
 * 命令与请求的加工规则。
 *
 * 对应 aws-sdk-php 中 S3Client 注册的那一批中间件，逻辑照搬，
 * 只是收拢成静态方法而不是 handler 链 —— 本包不需要可插拔的
 * 中间件栈，直接按固定顺序调用更容易读懂执行流程。
 *
 * 未移植的是 AWS 专有部分：接入点 ARN 寻址、S3 Express 会话鉴权、
 * accelerate / dualstack / FIPS 端点。
 */
final class Middleware
{
    private function __construct()
    {
    }

    /**
     * SourceFile：直接给文件路径，省去自己开流。
     */
    public static function sourceFile(Command $command, Operation $operation): void
    {
        if (!isset($command['SourceFile'])) {
            return;
        }

        $source = $command['SourceFile'];
        unset($command['SourceFile']);

        // 只有带 streaming 载荷的操作才认这个参数
        $payload = $operation->getInput()['payload'];
        if ($payload === null) {
            return;
        }

        if (!is_readable($source)) {
            throw new \InvalidArgumentException("SourceFile 不可读: {$source}");
        }

        $command[$payload] = Stream::open($source, 'r');
    }

    /**
     * SaveAs：把响应体直接写到文件，不经过内存。
     */
    public static function saveAs(Command $command): void
    {
        if (!isset($command['SaveAs'])) {
            return;
        }

        $saveAs = $command['SaveAs'];
        unset($command['SaveAs']);

        $http = $command['@http'] ?? [];
        $http['sink'] = $saveAs;
        $command['@http'] = $http;
    }

    /**
     * SSE-C：把原始密钥编码成 S3 要求的形式。
     *
     * 密钥会随请求头发送，因此强制要求 HTTPS。
     */
    public static function sseCustomerKey(Command $command, string $endpointScheme): void
    {
        if (($command['SSECustomerKey'] || $command['CopySourceSSECustomerKey'])
            && $endpointScheme !== 'https'
        ) {
            throw new \RuntimeException(
                '使用 SSE-C 时必须通过 HTTPS 连接，否则密钥会以明文传输'
            );
        }

        if ($command['SSECustomerKey']) {
            self::prepareSseParams($command, '');
        }

        if ($command['CopySourceSSECustomerKey']) {
            self::prepareSseParams($command, 'CopySource');
        }
    }

    private static function prepareSseParams(Command $command, string $prefix): void
    {
        $key = $command[$prefix . 'SSECustomerKey'];
        $command[$prefix . 'SSECustomerKey'] = base64_encode($key);

        if ($md5 = $command[$prefix . 'SSECustomerKeyMD5']) {
            $command[$prefix . 'SSECustomerKeyMD5'] = base64_encode($md5);
        } else {
            $command[$prefix . 'SSECustomerKeyMD5'] = base64_encode(md5($key, true));
        }
    }

    /**
     * CreateBucket 在非 us-east-1 时必须带 LocationConstraint，
     * 否则服务端会拒绝。us-east-1 反过来不能带。
     */
    public static function locationConstraint(Command $command, string $region): void
    {
        if ($command->getName() !== 'CreateBucket') {
            return;
        }

        if ($region === 'us-east-1') {
            unset($command['CreateBucketConfiguration']);

            return;
        }

        if (!isset($command['CreateBucketConfiguration']['LocationConstraint'])) {
            $command['CreateBucketConfiguration'] = ['LocationConstraint' => $region];
        }
    }

    /**
     * 上传对象时按文件名推断 Content-Type。
     */
    public static function contentType(Command $command, Request $request): Request
    {
        static $applicable = ['PutObject' => true, 'UploadPart' => true];

        if (!isset($applicable[$command->getName()]) || $request->hasHeader('Content-Type')) {
            return $request;
        }

        $uri = $command['ContentType'] ?? null;
        if (is_string($uri) && $uri !== '') {
            return $request->withHeader('Content-Type', $uri);
        }

        $filename = $request->getBody()->getMetadata('uri');
        if (!is_string($filename) || $filename === '') {
            return $request;
        }

        // 与 aws-sdk-php 一致：只要消息体有 uri 元数据就一定设置这个头，
        // 推断不出具体类型时用 application/octet-stream 兜底
        return $request->withHeader(
            'Content-Type',
            MimeType::fromFilename($filename) ?? 'application/octet-stream'
        );
    }

    /**
     * 请求校验和。
     *
     * 与 aws-sdk-php 的默认值有意不同：这里默认 when_required + md5，
     * 生成 Content-MD5 头。新版 SDK 默认对所有支持的操作发
     * x-amz-checksum-crc32，但相当一部分自建 S3（旧版 MinIO、Ceph RGW）
     * 不认这个头，DeleteObjects 之类会直接失败。Content-MD5 是所有
     * S3 兼容实现的最大公约数。
     *
     * 需要与 AWS 行为一致时把 checksum_algorithm 设为 crc32。
     */
    public static function checksum(
        Command $command,
        Request $request,
        Operation $operation,
        array $config
    ): Request {
        $name = $command->getName();

        // 显式指定 ContentSHA256 时直接用，跳过后面的计算
        if (in_array($name, ['PutObject', 'UploadPart'], true) && $command['ContentSHA256']) {
            $request = $request->withHeader('X-Amz-Content-Sha256', $command['ContentSHA256']);
        }

        if (self::hasChecksumHeader($request)) {
            return $request;
        }

        $checksumInfo = $operation['httpChecksum'] ?? [];
        $checksumRequired = (bool) ($checksumInfo['requestChecksumRequired'] ?? false);
        $memberName = $checksumInfo['requestAlgorithmMember'] ?? '';
        $requestedAlgorithm = $memberName !== '' ? ($command[$memberName] ?? null) : null;

        $mode = $config['checksum_calculation'] ?? 'when_required';
        $hasMember = $memberName !== '';

        $shouldAdd = ($mode === 'when_supported' && $hasMember)
            || ($mode === 'when_required' && ($checksumRequired || ($hasMember && $requestedAlgorithm)));

        if (!$shouldAdd) {
            return $request;
        }

        $algorithm = strtolower(
            $requestedAlgorithm ?? ($config['checksum_algorithm'] ?? 'md5')
        );

        $body = $request->getBody();
        if (!$body->isSeekable()) {
            // 不可定位的流没法先算摘要，交给服务端按 chunked 处理
            return $request;
        }

        if ($algorithm === 'md5') {
            return $request->withHeader(
                'Content-MD5',
                base64_encode(Stream::hash($body, 'md5', true))
            );
        }

        $phpAlgo = match ($algorithm) {
            'sha256' => 'sha256',
            'sha1'   => 'sha1',
            'crc32'  => 'crc32b',
            'crc32c' => null,
            default  => null,
        };

        if ($phpAlgo === null) {
            throw new \InvalidArgumentException(
                "不支持的校验算法: {$algorithm}（可用 md5、crc32、sha1、sha256）"
            );
        }

        return $request->withHeader(
            "x-amz-checksum-{$algorithm}",
            base64_encode(Stream::hash($body, $phpAlgo, true))
        );
    }

    private static function hasChecksumHeader(Request $request): bool
    {
        if ($request->hasHeader('Content-MD5')) {
            return true;
        }

        foreach (array_keys($request->getHeaders()) as $name) {
            if (stripos($name, 'x-amz-checksum-') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 存储桶寻址方式。
     *
     * 序列化后的路径总是 /{Bucket}/{Key}（路径式）。若要用虚拟主机式，
     * 就把桶名从路径挪到域名前缀。桶名含点时不能用虚拟主机式：
     * 通配符证书 *.example.com 不匹配 a.b.example.com，HTTPS 会握手失败。
     */
    public static function bucketAddressing(
        Request $request,
        Command $command,
        bool $pathStyle
    ): Request {
        $bucket = $command['Bucket'] ?? null;
        if (!is_string($bucket) || $bucket === '') {
            return $request;
        }

        if ($pathStyle || !self::isDnsCompatible($bucket)) {
            return $request;
        }

        $uri = $request->getUri();

        // 端点是 IP 地址时不能加子域名前缀：my-bucket.127.0.0.1 解析不出来。
        // aws-sdk-php 遇到这种端点也会退回路径式
        if (filter_var($uri->getHost(), FILTER_VALIDATE_IP)) {
            return $request;
        }

        $path = $uri->getPath();
        $encodedBucket = rawurlencode($bucket);

        // 路径里的桶名可能是编码过的形式
        foreach ([$bucket, $encodedBucket] as $candidate) {
            $prefix = '/' . $candidate;
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $newPath = substr($path, strlen($prefix));
                if ($newPath === '') {
                    $newPath = '/';
                }

                $host = $uri->getHost();
                if (!str_starts_with($host, $bucket . '.')) {
                    $uri = $uri->withHost($bucket . '.' . $host);
                }

                return $request->withUri($uri->withPath($newPath));
            }
        }

        return $request;
    }

    /**
     * 桶名能否作为域名的一部分。
     */
    public static function isDnsCompatible(string $bucket): bool
    {
        $length = strlen($bucket);
        if ($length < 3 || $length > 63) {
            return false;
        }

        // 含点会破坏 HTTPS 通配符证书匹配
        if (str_contains($bucket, '.')) {
            return false;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9\-]*[a-z0-9]$/', $bucket)) {
            return false;
        }

        // 形如 IP 的桶名会被当成主机地址
        return !filter_var($bucket, FILTER_VALIDATE_IP);
    }

    /**
     * 给 PutObject 结果补上对象的访问 URL，与 aws-sdk-php 一致。
     */
    public static function putObjectUrl(Command $command, Request $request, Result $result): Result
    {
        if ($command->getName() !== 'PutObject' && $command->getName() !== 'CompleteMultipartUpload') {
            return $result;
        }

        if ($result['ObjectURL'] === null) {
            $uri = $request->getUri();
            $result['ObjectURL'] = (string) $uri->withQuery('')->withFragment('');
        }

        return $result;
    }

    /**
     * ListObjects 系列自动请求 url 编码，避免 key 里的控制字符
     * 破坏 XML 解析；解析后再解码回来。
     */
    public static function encodingType(Command $command): bool
    {
        // 只对 ListObjects 生效，与 aws-sdk-php 一致。V2 及其他 List
        // 操作不自动加，避免改变用户可见的返回值
        if ($command->getName() !== 'ListObjects' || !empty($command['EncodingType'])) {
            return false;
        }

        $command['EncodingType'] = 'url';

        return true;
    }

    /**
     * 对自动添加 EncodingType 的结果做反解码。
     */
    public static function decodeListResult(Result $result): Result
    {
        if ($result['EncodingType'] !== 'url') {
            return $result;
        }

        // 解码字段与 aws-sdk-php 的 getEncodingTypeMiddleware 一致
        static $topLevel = ['Delimiter', 'Marker', 'NextMarker', 'Prefix'];
        static $nested = [['Contents', 'Key'], ['CommonPrefixes', 'Prefix']];

        $data = $result->toArray();

        foreach ($topLevel as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = urldecode($data[$key]);
            }
        }

        foreach ($nested as [$listKey, $field]) {
            if (!isset($data[$listKey]) || !is_array($data[$listKey])) {
                continue;
            }

            foreach ($data[$listKey] as $i => $item) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    $data[$listKey][$i][$field] = urldecode($item[$field]);
                }
            }
        }

        return new Result($data);
    }
}
