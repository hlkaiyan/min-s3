<?php
/**
 * 与 aws-sdk-php 的行为对拍。
 *
 * 包内其他测试只能验证 min-s3 自洽 —— 请求发得出去、响应解析得回来，
 * 但验证不了「与官方 SDK 行为一致」。那需要把同一批操作同时喂给两边
 * 逐字节比对，也就是这个脚本做的事，分四层：
 *
 *  1. 签名算法：给两边完全相同的请求，比对 Authorization 头。
 *     一致即说明规范请求、待签字符串、派生密钥三步都对。
 *  2. 预签名 URL：固定 start_time，结果完全确定，逐字节比对。
 *  3. 请求构造：43 个操作 × 两种寻址方式，比对 method/path/query/头。
 *  4. 域名端点下的虚拟主机式寻址。
 *
 * 需要 aws-sdk-php 作参照，没装时自动跳过（不算失败）：
 *
 *     composer require --dev aws/aws-sdk-php
 *
 * 什么时候必须跑：改动了签名、序列化、解析、中间件寻址，
 * 或 Http\Uri / Http\Query 的编码行为之后。
 *
 * 用法: php tests/compat.php
 */
require __DIR__ . '/../autoload.php';

/**
 * 找到并加载 aws-sdk-php。
 *
 * 刻意不写进 composer.json 的 require-dev：min-s3 主打零依赖，
 * 不该让每个开发者为一个对拍脚本装 50 MB 的 SDK。需要时手动装，
 * 装了就自动被发现。
 */
$sdkAutoload = (static function (): ?string {
    $candidates = [
        __DIR__ . '/../vendor/autoload.php',      // 装在 min-s3 里
        __DIR__ . '/../../vendor/autoload.php',   // 装在上级工作目录
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            require_once $path;
            if (class_exists('Aws\S3\S3Client')) {
                return $path;
            }
        }
    }

    return null;
})();

if ($sdkAutoload === null) {
    echo "跳过对拍：未找到 aws-sdk-php\n";
    echo "  安装后重跑：composer require --dev aws/aws-sdk-php\n";
    exit(0);
}

echo '参照 SDK：' . realpath($sdkAutoload) . "\n";

const ENDPOINT = 'http://127.0.0.1:9000';
const REGION   = 'us-east-1';
const KEY      = 'AKIAIOSFODNN7EXAMPLE';
const SECRET   = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
const TOKEN    = 'FQoDYXdzEPT//////////wEXAMPLEtc764bNrC9SAPBSM22wDOk4x4HIZ8j4FZTwdQW';

$pass = 0;
$fail = 0;

function check(string $name, callable $fn): void
{
    global $pass, $fail;
    try {
        $detail = $fn();
        $pass++;
        echo "  [一致] {$name}" . ($detail ? " — {$detail}" : '') . "\n";
    } catch (\Throwable $e) {
        $fail++;
        echo "  [不符] {$name}\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
    }
}

// ---------------------------------------------------------------
// 第 1 层：签名算法本身
// ---------------------------------------------------------------
echo "=== 1. 签名算法（相同输入 → 相同签名） ===\n";

/**
 * 同一组请求要素，分别构造两边的 Request 并签名。
 */
function signBoth(
    string $method,
    string $url,
    array $headers,
    string $body,
    ?string $token = null
): array {
    $awsCreds = new Aws\Credentials\Credentials(KEY, SECRET, $token);
    $minCreds = new MinS3\Credentials(KEY, SECRET, $token);

    $awsSigner = new Aws\Signature\S3SignatureV4('s3', REGION);
    $minSigner = new MinS3\Signature\SignatureV4('s3', REGION);

    // 两边必须在同一秒内签，否则 X-Amz-Date 不同
    for ($i = 0; $i < 5; $i++) {
        $second = gmdate('s');

        $awsReq = new GuzzleHttp\Psr7\Request($method, $url, $headers, $body);
        $minReq = new MinS3\Http\Request($method, $url, $headers, $body);

        $awsSigned = $awsSigner->signRequest($awsReq, $awsCreds);
        $minSigned = $minSigner->signRequest($minReq, $minCreds);

        if ($second === gmdate('s')) {
            return [
                $awsSigned->getHeaderLine('Authorization'),
                $minSigned->getHeaderLine('Authorization'),
                $awsSigned->getHeaderLine('x-amz-content-sha256'),
                $minSigned->getHeaderLine('x-amz-content-sha256'),
            ];
        }
    }

    throw new RuntimeException('无法在同一秒内完成两次签名');
}

$signCases = [
    ['简单 GET', 'GET', ENDPOINT . '/my-bucket/a.txt', [], ''],
    ['带 query', 'GET', ENDPOINT . '/my-bucket?list-type=2&prefix=logs%2F&max-keys=100', [], ''],
    ['query 无值参数', 'GET', ENDPOINT . '/my-bucket?uploads', [], ''],
    ['query 需排序', 'GET', ENDPOINT . '/b?z=1&a=2&m=3&B=4', [], ''],
    ['query 含特殊字符', 'GET', ENDPOINT . '/b?prefix=a%20b%2Bc%26d&marker=x%3Dy', [], ''],
    ['PUT 带消息体', 'PUT', ENDPOINT . '/my-bucket/a.txt', ['Content-Type' => 'text/plain'], 'hello world'],
    ['空消息体 PUT', 'PUT', ENDPOINT . '/my-bucket/empty', [], ''],
    ['多个自定义头', 'PUT', ENDPOINT . '/my-bucket/m.txt',
        ['x-amz-meta-foo' => 'bar', 'x-amz-meta-baz' => 'qux', 'x-amz-acl' => 'public-read'], 'x'],
    ['头值含多余空格', 'PUT', ENDPOINT . '/my-bucket/s.txt',
        ['x-amz-meta-note' => "a   b\tc"], 'x'],
    ['中文 key', 'GET', ENDPOINT . '/my-bucket/' . rawurlencode('目录/文件 名.txt'), [], ''],
    ['key 含加号与等号', 'GET', ENDPOINT . '/my-bucket/a%2Bb%3Dc.txt', [], ''],
    ['key 含波浪号', 'GET', ENDPOINT . '/my-bucket/~tilde~.txt', [], ''],
    ['DELETE', 'DELETE', ENDPOINT . '/my-bucket/a.txt', [], ''],
    ['HEAD', 'HEAD', ENDPOINT . '/my-bucket/a.txt', [], ''],
    ['POST 带 XML', 'POST', ENDPOINT . '/my-bucket?delete',
        ['Content-Type' => 'application/xml'], '<Delete><Object><Key>a</Key></Object></Delete>'],
    ['虚拟主机式域名', 'GET', 'http://my-bucket.127.0.0.1:9000/a.txt', [], ''],
    ['HTTPS 端点', 'GET', 'https://s3.example.com/my-bucket/a.txt', [], ''],
    ['带端口非默认', 'GET', 'http://example.com:8333/my-bucket/a.txt', [], ''],
];

foreach ($signCases as [$name, $method, $url, $headers, $body]) {
    check($name, function () use ($method, $url, $headers, $body) {
        [$awsAuth, $minAuth, $awsSha, $minSha] = signBoth($method, $url, $headers, $body);

        if ($awsSha !== $minSha) {
            throw new RuntimeException("x-amz-content-sha256 不同:\naws: {$awsSha}\nmin: {$minSha}");
        }
        if ($awsAuth !== $minAuth) {
            throw new RuntimeException("Authorization 不同:\naws: {$awsAuth}\nmin: {$minAuth}");
        }

        // 摘取签名值末尾，证明确实算出了签名而不是两边都为空
        return 'sig=' . substr($minAuth, -12);
    });
}

check('临时凭证（带 session token）', function () {
    [$awsAuth, $minAuth] = signBoth('GET', ENDPOINT . '/my-bucket/a.txt', [], '', TOKEN);
    if ($awsAuth !== $minAuth) {
        throw new RuntimeException("Authorization 不同:\naws: {$awsAuth}\nmin: {$minAuth}");
    }

    return 'sig=' . substr($minAuth, -12);
});

// ---------------------------------------------------------------
// 第 2 层：预签名 URL（固定时间，结果完全确定）
// ---------------------------------------------------------------
echo "\n=== 2. 预签名 URL（固定 start_time，结果确定） ===\n";

$fixedTime = 1735689600; // 2025-01-01T00:00:00Z

$presignCases = [
    ['GetObject 基本', 'GetObject', ['Bucket' => 'my-bucket', 'Key' => 'a.txt'], '+20 minutes'],
    ['GetObject 中文 key', 'GetObject', ['Bucket' => 'my-bucket', 'Key' => '目录/文件 名.txt'], '+1 hour'],
    ['GetObject 特殊字符', 'GetObject', ['Bucket' => 'my-bucket', 'Key' => 'a b+c&d=e.txt'], '+1 hour'],
    ['GetObject 带版本', 'GetObject', ['Bucket' => 'my-bucket', 'Key' => 'a.txt', 'VersionId' => 'v1'], '+1 hour'],
    ['GetObject 覆盖响应头', 'GetObject', ['Bucket' => 'my-bucket', 'Key' => 'a.txt',
        'ResponseContentDisposition' => 'attachment; filename="报告.pdf"'], '+1 hour'],
    ['PutObject 预签名', 'PutObject', ['Bucket' => 'my-bucket', 'Key' => 'up.bin'], '+30 minutes'],
    ['HeadObject 预签名', 'HeadObject', ['Bucket' => 'my-bucket', 'Key' => 'a.txt'], '+10 minutes'],
    ['七天有效期', 'GetObject', ['Bucket' => 'my-bucket', 'Key' => 'a.txt'], '+7 days'],
];

foreach ([true, false] as $pathStyle) {
    $label = $pathStyle ? '路径式' : '虚拟主机式';

    foreach ($presignCases as [$name, $operation, $args, $expires]) {
        check("{$name}（{$label}）", function () use ($operation, $args, $expires, $pathStyle, $fixedTime) {
            $awsClient = new Aws\S3\S3Client([
                'endpoint'                => ENDPOINT,
                'region'                  => REGION,
                'version'                 => 'latest',
                'use_path_style_endpoint' => $pathStyle,
                'credentials'             => ['key' => KEY, 'secret' => SECRET],
            ]);
            $minClient = new MinS3\S3Client([
                'endpoint'                => ENDPOINT,
                'region'                  => REGION,
                'use_path_style_endpoint' => $pathStyle,
                'credentials'             => ['key' => KEY, 'secret' => SECRET],
            ]);

            $awsUrl = (string) $awsClient->createPresignedRequest(
                $awsClient->getCommand($operation, $args),
                $fixedTime + strtotime($expires, 0),
                ['start_time' => $fixedTime]
            )->getUri();

            $minUrl = (string) $minClient->createPresignedRequest(
                $minClient->getCommand($operation, $args),
                $fixedTime + strtotime($expires, 0),
                ['start_time' => $fixedTime]
            )->getUri();

            if ($awsUrl !== $minUrl) {
                throw new RuntimeException("URL 不同:\naws: {$awsUrl}\nmin: {$minUrl}");
            }

            parse_str(parse_url($minUrl, PHP_URL_QUERY) ?? '', $q);

            return 'sig=' . substr($q['X-Amz-Signature'] ?? '', -12);
        });
    }
}

// ---------------------------------------------------------------
// 第 3 层：请求构造（参数落位）
// ---------------------------------------------------------------
echo "\n=== 3. 请求构造（参数落位到 uri / query / header / body） ===\n";

$buildCases = [
    ['GetObject',    ['Bucket' => 'my-bucket', 'Key' => 'a.txt']],
    ['GetObject',    ['Bucket' => 'my-bucket', 'Key' => 'dir/sub/файл 名字.txt']],
    ['GetObject',    ['Bucket' => 'my-bucket', 'Key' => 'a b+c&d=e?f.txt']],
    ['GetObject',    ['Bucket' => 'my-bucket', 'Key' => 'x.bin', 'Range' => 'bytes=0-1023']],
    ['GetObject',    ['Bucket' => 'my-bucket', 'Key' => 'v.txt', 'VersionId' => 'abc123']],
    ['GetObject',    ['Bucket' => 'my-bucket', 'Key' => 'i.txt',
                      'IfModifiedSince' => '2024-01-01T00:00:00Z', 'IfMatch' => '"etag"']],
    ['PutObject',    ['Bucket' => 'my-bucket', 'Key' => 'a.txt', 'Body' => 'hello world']],
    ['PutObject',    ['Bucket' => 'my-bucket', 'Key' => 'm.txt', 'Body' => 'x',
                      'Metadata' => ['foo' => 'bar', 'baz' => 'qux'], 'ContentType' => 'text/plain']],
    ['PutObject',    ['Bucket' => 'my-bucket', 'Key' => 'acl.txt', 'Body' => 'x', 'ACL' => 'public-read']],
    ['PutObject',    ['Bucket' => 'my-bucket', 'Key' => 'sc.txt', 'Body' => 'x',
                      'StorageClass' => 'STANDARD_IA', 'CacheControl' => 'max-age=3600',
                      'ContentDisposition' => 'inline', 'ContentEncoding' => 'gzip']],
    ['PutObject',    ['Bucket' => 'my-bucket', 'Key' => 'tag.txt', 'Body' => 'x',
                      'Tagging' => 'a=1&b=2']],
    ['HeadObject',   ['Bucket' => 'my-bucket', 'Key' => 'a.txt']],
    ['HeadBucket',   ['Bucket' => 'my-bucket']],
    ['DeleteObject', ['Bucket' => 'my-bucket', 'Key' => 'a.txt']],
    ['DeleteObject', ['Bucket' => 'my-bucket', 'Key' => 'a.txt', 'VersionId' => 'v9']],
    ['ListBuckets',  []],
    ['ListObjects',  ['Bucket' => 'my-bucket', 'Marker' => 'm', 'MaxKeys' => 50]],
    ['ListObjectsV2', ['Bucket' => 'my-bucket', 'Prefix' => 'logs/', 'MaxKeys' => 100]],
    ['ListObjectsV2', ['Bucket' => 'my-bucket', 'Prefix' => 'a b/c', 'Delimiter' => '/',
                       'ContinuationToken' => 'tok+en/123', 'FetchOwner' => true]],
    ['ListObjectVersions', ['Bucket' => 'my-bucket', 'KeyMarker' => 'k', 'VersionIdMarker' => 'v']],
    ['ListMultipartUploads', ['Bucket' => 'my-bucket']],
    ['CreateBucket', ['Bucket' => 'my-bucket']],
    ['DeleteBucket', ['Bucket' => 'my-bucket']],
    ['CopyObject',   ['Bucket' => 'dst', 'Key' => 'k2', 'CopySource' => '/src/k1',
                      'MetadataDirective' => 'REPLACE']],
    ['CreateMultipartUpload', ['Bucket' => 'my-bucket', 'Key' => 'big.bin']],
    ['UploadPart',   ['Bucket' => 'my-bucket', 'Key' => 'big.bin', 'UploadId' => 'U1',
                      'PartNumber' => 3, 'Body' => str_repeat('A', 1024)]],
    ['UploadPartCopy', ['Bucket' => 'my-bucket', 'Key' => 'big.bin', 'UploadId' => 'U1',
                      'PartNumber' => 2, 'CopySource' => '/src/k1', 'CopySourceRange' => 'bytes=0-99']],
    ['CompleteMultipartUpload', ['Bucket' => 'my-bucket', 'Key' => 'big.bin', 'UploadId' => 'U1',
                      'MultipartUpload' => ['Parts' => [
                          ['PartNumber' => 1, 'ETag' => '"a"'],
                          ['PartNumber' => 2, 'ETag' => '"b"'],
                      ]]]],
    ['AbortMultipartUpload', ['Bucket' => 'my-bucket', 'Key' => 'big.bin', 'UploadId' => 'U1']],
    ['ListParts',    ['Bucket' => 'my-bucket', 'Key' => 'big.bin', 'UploadId' => 'U1']],
    ['DeleteObjects', ['Bucket' => 'my-bucket', 'Delete' => ['Objects' => [
                          ['Key' => 'a'], ['Key' => 'b/c d'],
                      ], 'Quiet' => false]]],
    ['PutObjectAcl', ['Bucket' => 'my-bucket', 'Key' => 'a.txt', 'ACL' => 'private']],
    ['GetObjectAcl', ['Bucket' => 'my-bucket', 'Key' => 'a.txt']],
    ['GetBucketLocation', ['Bucket' => 'my-bucket']],
    ['GetBucketVersioning', ['Bucket' => 'my-bucket']],
    ['PutBucketVersioning', ['Bucket' => 'my-bucket',
                      'VersioningConfiguration' => ['Status' => 'Enabled']]],
    ['PutBucketTagging', ['Bucket' => 'my-bucket', 'Tagging' => ['TagSet' => [
                          ['Key' => 'env', 'Value' => 'prod'],
                      ]]]],
    ['GetObjectTagging', ['Bucket' => 'my-bucket', 'Key' => 'a.txt']],
    ['PutBucketCors', ['Bucket' => 'my-bucket', 'CORSConfiguration' => ['CORSRules' => [
                          ['AllowedMethods' => ['GET', 'PUT'], 'AllowedOrigins' => ['*'],
                           'AllowedHeaders' => ['*'], 'MaxAgeSeconds' => 3000],
                      ]]]],
    ['PutBucketPolicy', ['Bucket' => 'my-bucket', 'Policy' => '{"Version":"2012-10-17"}']],
    ['PutBucketLifecycleConfiguration', ['Bucket' => 'my-bucket',
                      'LifecycleConfiguration' => ['Rules' => [
                          ['ID' => 'r1', 'Status' => 'Enabled', 'Prefix' => 'tmp/',
                           'Expiration' => ['Days' => 7]],
                      ]]]],
    ['RestoreObject', ['Bucket' => 'my-bucket', 'Key' => 'a.txt',
                       'RestoreRequest' => ['Days' => 7]]],
    ['SelectObjectContent', ['Bucket' => 'my-bucket', 'Key' => 'a.csv',
                       'Expression' => 'SELECT * FROM S3Object', 'ExpressionType' => 'SQL',
                       'InputSerialization' => ['CSV' => []], 'OutputSerialization' => ['CSV' => []]]],
];

function awsRequest(string $operation, array $args, bool $pathStyle)
{
    $captured = null;
    $client = new Aws\S3\S3Client([
        'endpoint'                => ENDPOINT,
        'region'                  => REGION,
        'version'                 => 'latest',
        'use_path_style_endpoint' => $pathStyle,
        'credentials'             => ['key' => KEY, 'secret' => SECRET],
        'retries'                 => 0,
        'http_handler'            => function ($request, $options) use (&$captured) {
            $captured = $request;
            return GuzzleHttp\Promise\Create::promiseFor(
                new GuzzleHttp\Psr7\Response(200, ['ETag' => '"x"'], '')
            );
        },
    ]);

    try {
        $client->execute($client->getCommand($operation, $args));
    } catch (\Throwable $e) {
        if ($captured === null) {
            throw $e;
        }
    }

    return $captured;
}

function minRequest(string $operation, array $args, bool $pathStyle)
{
    $captured = null;
    $client = new MinS3\S3Client([
        'endpoint'                => ENDPOINT,
        'region'                  => REGION,
        'use_path_style_endpoint' => $pathStyle,
        'credentials'             => ['key' => KEY, 'secret' => SECRET],
        'retries'                 => 0,
        // 对齐 aws-sdk-php 当前默认值以便比对；min-s3 自身默认是 when_required + md5
        'checksum_calculation'    => 'when_supported',
        'checksum_algorithm'      => 'crc32',
        'handler'                 => function ($request, array $options) use (&$captured) {
            $captured = $request;
            return MinS3\Promise\Promise::resolved(
                new MinS3\Http\Response(200, ['ETag' => '"x"'], '')
            );
        },
    ]);

    try {
        $client->execute($client->getCommand($operation, $args));
    } catch (\Throwable $e) {
        if ($captured === null) {
            throw $e;
        }
    }

    return $captured;
}

function normalize($request): array
{
    $uri = $request->getUri();

    $headers = [];
    foreach ($request->getHeaders() as $name => $values) {
        $lower = strtolower($name);
        // 排除签名相关头（含时间戳）与两边实现细节不同的元数据头
        if (in_array($lower, [
            'authorization', 'x-amz-date', 'user-agent', 'x-amz-user-agent',
            'expect', 'accept', 'amz-sdk-invocation-id', 'amz-sdk-request',
        ], true)) {
            continue;
        }
        $headers[$lower] = implode(', ', $values);
    }
    ksort($headers);

    $query = [];
    parse_str($uri->getQuery(), $query);
    ksort($query);

    return [
        'method'  => $request->getMethod(),
        'host'    => $uri->getHost(),
        // ListObjects 类操作 aws 侧会多一个尾部斜杠（EndpointV2 把桶名并进
        // endpoint 再解析所致），两种形式服务端都接受，比对时归一
        'path'    => rtrim($uri->getPath(), '/') ?: '/',
        'query'   => $query,
        'headers' => $headers,
        'body'    => (string) $request->getBody(),
    ];
}

foreach ([true, false] as $pathStyle) {
    $label = $pathStyle ? '路径式' : '虚拟主机式';
    echo "\n--- {$label}寻址 ---\n";

    foreach ($buildCases as [$operation, $args]) {
        $desc = $operation . ' ' . mb_substr((string) ($args['Key'] ?? $args['Bucket'] ?? '-'), 0, 20);

        check($desc, function () use ($operation, $args, $pathStyle) {
            $aws = normalize(awsRequest($operation, $args, $pathStyle));
            $min = normalize(minRequest($operation, $args, $pathStyle));

            if ($aws === $min) {
                return $min['method'] . ' ' . $min['path']
                    . ($min['query'] ? '?' . http_build_query($min['query']) : '');
            }

            $lines = [];
            foreach (['method', 'host', 'path', 'body'] as $field) {
                if ($aws[$field] !== $min[$field]) {
                    $lines[] = "{$field}:\n  aws: " . substr($aws[$field], 0, 300)
                             . "\n  min: " . substr($min[$field], 0, 300);
                }
            }
            if ($aws['query'] !== $min['query']) {
                $lines[] = "query:\n  aws: " . json_encode($aws['query'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                         . "\n  min: " . json_encode($min['query'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if ($aws['headers'] !== $min['headers']) {
                $all = array_unique(array_merge(array_keys($aws['headers']), array_keys($min['headers'])));
                sort($all);
                foreach ($all as $h) {
                    $a = $aws['headers'][$h] ?? '(无)';
                    $m = $min['headers'][$h] ?? '(无)';
                    if ($a !== $m) {
                        $lines[] = "头 {$h}:\n  aws: " . substr($a, 0, 200) . "\n  min: " . substr($m, 0, 200);
                    }
                }
            }

            throw new RuntimeException(implode("\n", $lines));
        });
    }
}

// ---------------------------------------------------------------
// 第 4 层：域名端点下的虚拟主机式寻址
//
// 前面用的是 IP 端点，两边都会退回路径式，桶名搬到域名的逻辑
// 其实没被执行到。这里换成域名端点，专门验证那条路径。
// ---------------------------------------------------------------
echo "\n=== 4. 域名端点的虚拟主机式寻址 ===\n";

function requestWithEndpoint(string $endpoint, string $operation, array $args, bool $pathStyle): array
{
    $awsCaptured = null;
    $awsClient = new Aws\S3\S3Client([
        'endpoint'                => $endpoint,
        'region'                  => REGION,
        'version'                 => 'latest',
        'use_path_style_endpoint' => $pathStyle,
        'credentials'             => ['key' => KEY, 'secret' => SECRET],
        'retries'                 => 0,
        'http_handler'            => function ($request, $options) use (&$awsCaptured) {
            $awsCaptured = $request;
            return GuzzleHttp\Promise\Create::promiseFor(new GuzzleHttp\Psr7\Response(200, [], ''));
        },
    ]);

    $minCaptured = null;
    $minClient = new MinS3\S3Client([
        'endpoint'                => $endpoint,
        'region'                  => REGION,
        'use_path_style_endpoint' => $pathStyle,
        'credentials'             => ['key' => KEY, 'secret' => SECRET],
        'retries'                 => 0,
        'checksum_calculation'    => 'when_supported',
        'checksum_algorithm'      => 'crc32',
        'handler'                 => function ($request, array $options) use (&$minCaptured) {
            $minCaptured = $request;
            return MinS3\Promise\Promise::resolved(new MinS3\Http\Response(200, [], ''));
        },
    ]);

    try {
        $awsClient->execute($awsClient->getCommand($operation, $args));
    } catch (\Throwable $e) {
        if ($awsCaptured === null) {
            throw $e;
        }
    }

    try {
        $minClient->execute($minClient->getCommand($operation, $args));
    } catch (\Throwable $e) {
        if ($minCaptured === null) {
            throw $e;
        }
    }

    return [normalize($awsCaptured), normalize($minCaptured)];
}

$hostCases = [
    // [端点, 是否路径式, 操作, 参数, 期望的 host, 期望的 path]
    ['https://s3.example.com', false, 'GetObject',
        ['Bucket' => 'my-bucket', 'Key' => 'a.txt'], 'my-bucket.s3.example.com', '/a.txt'],
    ['https://s3.example.com', false, 'PutObject',
        ['Bucket' => 'my-bucket', 'Key' => 'dir/b.txt', 'Body' => 'x'],
        'my-bucket.s3.example.com', '/dir/b.txt'],
    ['https://s3.example.com', false, 'HeadBucket',
        ['Bucket' => 'my-bucket'], 'my-bucket.s3.example.com', '/'],
    ['https://s3.example.com', false, 'ListObjectsV2',
        ['Bucket' => 'my-bucket'], 'my-bucket.s3.example.com', '/'],
    ['https://s3.example.com', true, 'GetObject',
        ['Bucket' => 'my-bucket', 'Key' => 'a.txt'], 's3.example.com', '/my-bucket/a.txt'],
    // 桶名含点：不能用虚拟主机式，通配符证书不匹配，两边都该退回路径式
    ['https://s3.example.com', false, 'GetObject',
        ['Bucket' => 'my.bucket.name', 'Key' => 'a.txt'], 's3.example.com', '/my.bucket.name/a.txt'],
    ['https://s3.example.com', false, 'ListBuckets', [], 's3.example.com', '/'],
    ['http://minio.internal:9000', false, 'GetObject',
        ['Bucket' => 'assets', 'Key' => 'logo.png'], 'assets.minio.internal', '/logo.png'],
];

foreach ($hostCases as [$endpoint, $pathStyle, $operation, $args, $expectHost, $expectPath]) {
    $label = sprintf('%s %s（%s）', $operation, $args['Bucket'] ?? '-', $pathStyle ? '路径式' : '虚拟主机式');

    check($label, function () use ($endpoint, $operation, $args, $pathStyle, $expectHost, $expectPath) {
        [$aws, $min] = requestWithEndpoint($endpoint, $operation, $args, $pathStyle);

        if ($aws !== $min) {
            $lines = [];
            foreach (['method', 'host', 'path'] as $f) {
                if ($aws[$f] !== $min[$f]) {
                    $lines[] = "{$f}:\n  aws: {$aws[$f]}\n  min: {$min[$f]}";
                }
            }
            if ($aws['query'] !== $min['query']) {
                $lines[] = 'query:  aws: ' . json_encode($aws['query'])
                         . '  min: ' . json_encode($min['query']);
            }
            if ($aws['headers'] !== $min['headers']) {
                foreach (array_unique(array_merge(array_keys($aws['headers']), array_keys($min['headers']))) as $h) {
                    $a = $aws['headers'][$h] ?? '(无)';
                    $m = $min['headers'][$h] ?? '(无)';
                    if ($a !== $m) {
                        $lines[] = "头 {$h}:\n  aws: {$a}\n  min: {$m}";
                    }
                }
            }
            throw new RuntimeException(implode("\n", $lines) ?: '存在差异');
        }

        // 除了两边一致，还要确认结果本身符合预期，避免"一起错"
        if ($min['host'] !== $expectHost) {
            throw new RuntimeException("host 不符合预期: 实际 {$min['host']}, 期望 {$expectHost}");
        }
        if ($min['path'] !== rtrim($expectPath, '/') && $min['path'] !== $expectPath) {
            throw new RuntimeException("path 不符合预期: 实际 {$min['path']}, 期望 {$expectPath}");
        }

        return "{$min['host']}{$min['path']}";
    });
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "一致 {$pass} 项，不符 {$fail} 项\n";
exit($fail > 0 ? 1 : 0);
