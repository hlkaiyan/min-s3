<?php
namespace MinS3;

use MinS3\Api\Operation;
use MinS3\Api\Parser\XmlErrorParser;
use MinS3\Api\Parser\XmlResponseParser;
use MinS3\Api\Serializer\XmlSerializer;
use MinS3\Api\Service;
use MinS3\Exception\ConnectException;
use MinS3\Exception\S3Exception;
use MinS3\Http\CurlHandler;
use MinS3\Http\Request;
use MinS3\Http\Response;
use MinS3\Http\Uri;
use MinS3\Promise\Promise;
use MinS3\Signature\SignatureV4;

/**
 * S3 客户端。
 *
 * 调用方式与 aws-sdk-php 的 S3Client 一致：操作名首字母小写作为方法名，
 * 参数用关联数组，返回可当数组访问的 Result。
 *
 *     $s3 = new S3Client([
 *         'endpoint'    => 'http://127.0.0.1:9000',
 *         'region'      => 'us-east-1',
 *         'credentials' => ['key' => '...', 'secret' => '...'],
 *     ]);
 *     $s3->putObject(['Bucket' => 'b', 'Key' => 'k', 'Body' => 'hello']);
 *
 * 每个方法都有 Async 变体，返回 Promise：$s3->putObjectAsync([...])
 *
 * @method Result abortMultipartUpload(array $args = [])
 * @method Result completeMultipartUpload(array $args = [])
 * @method Result copyObject(array $args = [])
 * @method Result createBucket(array $args = [])
 * @method Result createMultipartUpload(array $args = [])
 * @method Result deleteBucket(array $args = [])
 * @method Result deleteObject(array $args = [])
 * @method Result deleteObjects(array $args = [])
 * @method Result getBucketLocation(array $args = [])
 * @method Result getObject(array $args = [])
 * @method Result getObjectAcl(array $args = [])
 * @method Result headBucket(array $args = [])
 * @method Result headObject(array $args = [])
 * @method Result listBuckets(array $args = [])
 * @method Result listMultipartUploads(array $args = [])
 * @method Result listObjects(array $args = [])
 * @method Result listObjectsV2(array $args = [])
 * @method Result listObjectVersions(array $args = [])
 * @method Result listParts(array $args = [])
 * @method Result putObject(array $args = [])
 * @method Result putObjectAcl(array $args = [])
 * @method Result uploadPart(array $args = [])
 * @method Result uploadPartCopy(array $args = [])
 */
class S3Client
{
    use S3ClientTrait;

    /** 默认可重试的 S3 错误码 */
    private const RETRYABLE_ERRORS = [
        'RequestTimeout'                   => true,
        'RequestTimeoutException'          => true,
        'RequestThrottledException'        => true,
        'Throttling'                       => true,
        'ThrottlingException'              => true,
        'SlowDown'                         => true,
        'ProvisionedThroughputExceededException' => true,
        'TooManyRequestsException'         => true,
        'InternalError'                    => true,
        'InternalServerError'              => true,
        'ServiceUnavailable'               => true,
        'BandwidthLimitExceeded'           => true,
    ];

    private array $config;
    private Uri $endpoint;
    private string $region;

    /** @var Credentials|callable */
    private $credentialsProvider;
    private ?Credentials $credentials = null;

    private Service $api;
    private XmlSerializer $serializer;
    private XmlResponseParser $parser;
    private XmlErrorParser $errorParser;
    private SignatureV4 $signature;

    /**
     * 传输实现。默认是 CurlHandler，也可以注入任意
     * function (Request $request, array $options): Promise，
     * 测试时用它替换真实网络。
     *
     * @var callable
     */
    private $handler;

    /**
     * 配置项：
     *  - endpoint    (string, 必填) 服务地址，如 http://127.0.0.1:9000
     *  - region      (string) 区域名，默认 us-east-1。自建服务通常不校验，
     *                但它参与签名，必须与服务端配置一致
     *  - credentials (array|Credentials|callable) key/secret，或返回
     *                Credentials 的可调用对象（用于临时凭证轮换）
     *  - use_path_style_endpoint (bool) 默认 true。注意这与 aws-sdk-php
     *                的默认值相反 —— 自建服务多数不支持虚拟主机式寻址
     *  - retries     (int) 重试次数，默认 3
     *  - http        (array) 传输层默认选项，见 CurlHandler::__invoke
     *  - checksum_calculation (string) when_required（默认）| when_supported
     *  - checksum_algorithm   (string) md5（默认）| crc32 | sha1 | sha256
     *  - signature_version    (string) 目前仅支持 v4
     *  - handler     (callable) 自定义传输实现，测试时可注入桩件
     */
    public function __construct(array $args = [])
    {
        $args += [
            'region'                  => 'us-east-1',
            'use_path_style_endpoint' => true,
            'retries'                 => 3,
            'http'                    => [],
            'checksum_calculation'    => 'when_required',
            'checksum_algorithm'      => 'md5',
            'signature_version'       => 'v4',
        ];

        if (empty($args['endpoint'])) {
            throw new \InvalidArgumentException(
                'endpoint 是必填项，例如 http://127.0.0.1:9000'
            );
        }

        if ($args['signature_version'] !== 'v4') {
            throw new \InvalidArgumentException(
                '目前仅支持 signature_version=v4'
            );
        }

        if ((string) $args['region'] === '') {
            throw new \InvalidArgumentException(
                'region 不能为空：它是 SigV4 签名范围的一部分，'
                . '自建服务若无所谓填 us-east-1 即可'
            );
        }

        $this->config = $args;
        $this->endpoint = new Uri(rtrim($args['endpoint'], '/'));
        $this->region = (string) $args['region'];

        if ($this->endpoint->getHost() === '') {
            throw new \InvalidArgumentException(
                "无法从 endpoint 解析出主机名: {$args['endpoint']}"
            );
        }

        $this->credentialsProvider = $this->normalizeCredentials($args['credentials'] ?? null);

        $this->api = $args['api'] ?? Service::s3();
        $this->serializer = new XmlSerializer($this->api, (string) $this->endpoint);
        $this->parser = new XmlResponseParser($this->api);
        $this->errorParser = new XmlErrorParser();
        $this->signature = new SignatureV4(
            $this->api->getSigningName(),
            $this->region,
            ['unsigned-body' => (bool) ($args['unsigned_payload'] ?? false)]
        );
        $this->handler = $args['handler'] ?? new CurlHandler($args['http']);
    }

    /**
     * 分发 116 个 S3 操作及其 Async 变体。
     */
    public function __call(string $name, array $args): mixed
    {
        $params = $args[0] ?? [];
        if (!is_array($params)) {
            throw new \InvalidArgumentException("{$name}() 的参数必须是数组");
        }

        $isAsync = str_ends_with($name, 'Async');
        if ($isAsync) {
            $name = substr($name, 0, -5);
        }

        $operationName = ucfirst($name);
        if (!$this->api->hasOperation($operationName)) {
            throw new \BadMethodCallException(
                "S3 没有 {$operationName} 操作（方法 {$name}）"
            );
        }

        $command = $this->getCommand($operationName, $params);

        return $isAsync ? $this->executeAsync($command) : $this->execute($command);
    }

    public function getCommand(string $name, array $args = []): Command
    {
        if (!$this->api->hasOperation($name)) {
            throw new \InvalidArgumentException("S3 没有 {$name} 操作");
        }

        return new Command($name, $args);
    }

    public function execute(Command $command): Result
    {
        return $this->executeAsync($command)->wait();
    }

    public function executeAsync(Command $command): Promise
    {
        $command = clone $command;
        $operation = $this->api->getOperation($command->getName());

        // 参数级加工，对应 aws-sdk-php 的 init 阶段
        Middleware::sourceFile($command, $operation);
        Middleware::saveAs($command);
        Middleware::sseCustomerKey($command, $this->endpoint->getScheme());
        Middleware::locationConstraint($command, $this->region);
        $autoEncoded = Middleware::encodingType($command);

        $this->validate($command, $operation);

        $maxAttempts = (int) ($command['@retries'] ?? $this->config['retries']);

        return new Promise(function (Promise $self) use (
            $command, $operation, $autoEncoded, $maxAttempts
        ): void {
            $attempt = 0;

            while (true) {
                $request = $this->buildRequest($command, $operation);

                try {
                    $response = $this->send($request, $command)->wait();
                    $self->resolve($this->handleResponse($command, $request, $response, $autoEncoded));

                    return;
                } catch (\Throwable $e) {
                    if ($attempt >= $maxAttempts || !$this->isRetryable($e)) {
                        throw $e;
                    }

                    $attempt++;
                    // 指数退避 + 抖动，避免多个客户端同时重试形成尖峰
                    usleep((int) (min(20000, (2 ** $attempt) * 100 + random_int(0, 100)) * 1000));
                }
            }
        });
    }

    /**
     * 生成预签名 URL。
     *
     *     $cmd = $s3->getCommand('GetObject', ['Bucket' => 'b', 'Key' => 'k']);
     *     $url = (string) $s3->createPresignedRequest($cmd, '+20 minutes')->getUri();
     *
     * @param int|string|\DateTimeInterface $expires
     */
    public function createPresignedRequest(
        Command $command,
        int|string|\DateTimeInterface $expires,
        array $options = []
    ): Request {
        $command = clone $command;
        $operation = $this->api->getOperation($command->getName());

        Middleware::sseCustomerKey($command, $this->endpoint->getScheme());

        $request = $this->buildRequest($command, $operation);

        return $this->signature->presign($request, $this->getCredentials(), $expires, $options);
    }

    /**
     * 对象的直接访问 URL（不带签名，需要对象可公开读取）。
     */
    public function getObjectUrl(string $bucket, string $key): string
    {
        $command = $this->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
        $request = $this->buildRequest($command, $this->api->getOperation('GetObject'));

        return (string) $request->getUri()->withQuery('');
    }

    public function getEndpoint(): Uri
    {
        return $this->endpoint;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function getApi(): Service
    {
        return $this->api;
    }

    public function getConfig(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? null;
    }

    public function getCredentials(): Credentials
    {
        if ($this->credentials === null || $this->credentials->isExpired()) {
            $provider = $this->credentialsProvider;
            $this->credentials = $provider instanceof Credentials ? $provider : $provider();

            if (!$this->credentials instanceof Credentials) {
                throw new \RuntimeException('凭证提供者必须返回 Credentials 实例');
            }
        }

        return $this->credentials;
    }

    /**
     * 命令 → 已签名之前的请求。
     */
    private function buildRequest(Command $command, Operation $operation): Request
    {
        $request = ($this->serializer)($command);

        $request = Middleware::bucketAddressing(
            $request,
            $command,
            (bool) $this->config['use_path_style_endpoint']
        );
        $request = Middleware::contentType($command, $request);
        $request = Middleware::checksum($command, $request, $operation, $this->config);

        // Content-Length 必须准确：S3 用它判断消息体边界。
        // 无消息体的请求不加这个头，GET 带 Content-Length: 0 会被某些网关拒绝
        if (!$request->hasHeader('Content-Length')) {
            $size = $request->getBody()->getSize();
            if ($size !== null && $size > 0) {
                $request = $request->withHeader('Content-Length', (string) $size);
            }
        }

        if (!$request->hasHeader('User-Agent')) {
            $request = $request->withHeader('User-Agent', 'min-s3/1.0 php/' . PHP_VERSION);
        }

        return $request;
    }

    private function send(Request $request, Command $command): Promise
    {
        $signed = $this->signature->signRequest($request, $this->getCredentials());

        $options = $command->getHttpOptions() + $this->config['http'];

        return ($this->handler)($signed, $options);
    }

    private function handleResponse(
        Command $command,
        Request $request,
        Response $response,
        bool $autoEncoded
    ): Result {
        $status = $response->getStatusCode();

        if ($status >= 300) {
            throw $this->createException($command, $request, $response);
        }

        $result = ($this->parser)($command, $response);

        // 有些自建网关对错误也返回 200，同时在体里放 <Error>，
        // 这里按 aws-sdk-php 的做法额外查一次
        if ($this->looksLikeError($command, $result)) {
            throw $this->createException($command, $request, $response);
        }

        if ($autoEncoded) {
            $result = Middleware::decodeListResult($result);
        }

        return Middleware::putObjectUrl($command, $request, $result);
    }

    /**
     * CompleteMultipartUpload 可能在 200 响应体里返回错误。
     */
    private function looksLikeError(Command $command, Result $result): bool
    {
        if ($command->getName() !== 'CompleteMultipartUpload') {
            return false;
        }

        // 正常完成一定带 ETag；没有 ETag 又没有 Location 说明是错误体
        return $result['ETag'] === null && $result['Location'] === null;
    }

    private function createException(Command $command, Request $request, Response $response): S3Exception
    {
        $data = ($this->errorParser)($response);

        $message = sprintf(
            '执行 %s 失败: %s (状态码 %d%s)',
            $command->getName(),
            $data['message'] ?? '未知错误',
            $response->getStatusCode(),
            $data['code'] !== null ? ", 错误码 {$data['code']}" : ''
        );

        return new S3Exception($message, $command, $data + [
            'response' => $response,
            'request'  => $request,
        ]);
    }

    private function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof ConnectException) {
            return true;
        }

        if (!$e instanceof S3Exception) {
            return false;
        }

        $status = $e->getStatusCode();
        if ($status !== null && ($status >= 500 || $status === 429)) {
            return true;
        }

        $code = $e->getAwsErrorCode();

        return $code !== null && isset(self::RETRYABLE_ERRORS[$code]);
    }

    /**
     * 检查必填参数，尽早报错而不是等服务端返回 400。
     */
    private function validate(Command $command, Operation $operation): void
    {
        $input = $operation->getInput();
        $required = $input['required'] ?? [];

        $missing = [];
        foreach ($required as $name) {
            $value = $command[$name];
            if ($value === null || $value === '') {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                '%s 缺少必填参数: %s',
                $command->getName(),
                implode(', ', $missing)
            ));
        }
    }

    /**
     * @return Credentials|callable
     */
    private function normalizeCredentials(mixed $credentials)
    {
        if ($credentials instanceof Credentials) {
            return $credentials;
        }

        if (is_array($credentials)) {
            return Credentials::fromArray($credentials);
        }

        if (is_callable($credentials)) {
            return $credentials;
        }

        if ($credentials === null) {
            // 退回环境变量，方便容器部署时不把密钥写进代码
            $key = getenv('AWS_ACCESS_KEY_ID') ?: getenv('S3_ACCESS_KEY_ID');
            $secret = getenv('AWS_SECRET_ACCESS_KEY') ?: getenv('S3_SECRET_ACCESS_KEY');

            if ($key !== false && $key !== '' && $secret !== false && $secret !== '') {
                $token = getenv('AWS_SESSION_TOKEN') ?: null;

                return new Credentials($key, $secret, $token ?: null);
            }

            throw new \InvalidArgumentException(
                '未提供 credentials，且环境变量 AWS_ACCESS_KEY_ID / '
                . 'AWS_SECRET_ACCESS_KEY 也未设置'
            );
        }

        throw new \InvalidArgumentException(
            'credentials 必须是数组、Credentials 实例或可调用对象'
        );
    }
}
