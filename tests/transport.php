<?php
/**
 * 真实 HTTP 传输测试：验证 CurlHandler。
 *
 * 其他测试都注入了 mock handler，curl 这一层不会被执行到。
 * 这里连本机的 PHP 内置服务器（仅 127.0.0.1，不访问外网），
 * 走完整链路：签名 → curl 发送 → 解析响应。
 *
 * 一般由 tests/run.php 自动起停服务器后调用；也可以手工：
 *   php -S 127.0.0.1:9911 tests/server.php &
 *   php tests/transport.php 9911
 */
require __DIR__ . '/bootstrap.php';

use MinS3\Exception\S3Exception;
use MinS3\Http\Stream;
use MinS3\Multipart\MultipartUploader;
use MinS3\S3Client;

$port = (int) ($argv[1] ?? 9911);
$endpoint = "http://127.0.0.1:{$port}";

$s3 = new S3Client([
    'endpoint'    => $endpoint,
    'region'      => 'us-east-1',
    'credentials' => ['key' => 'testkey', 'secret' => 'testsecret'],
    'retries'     => 1,
    'http'        => ['timeout' => 30, 'connect_timeout' => 5],
]);

echo "=== 真实 curl 传输（{$endpoint}） ===\n";

test('连通性：listBuckets', function () use ($s3) {
    $names = array_column($s3->listBuckets()['Buckets'], 'Name');

    return implode(',', $names);
});

test('putObject / getObject 往返', function () use ($s3) {
    $s3->putObject(['Bucket' => 'test', 'Key' => 'hello.txt', 'Body' => '你好，真实 HTTP']);
    $got = (string) $s3->getObject(['Bucket' => 'test', 'Key' => 'hello.txt'])['Body'];

    assertSame('你好，真实 HTTP', $got, '内容');

    return $got;
});

test('大内容往返（1 MB）', function () use ($s3) {
    $content = random_bytes(1024 * 1024);
    $s3->putObject(['Bucket' => 'test', 'Key' => 'blob.bin', 'Body' => $content]);
    $got = (string) $s3->getObject(['Bucket' => 'test', 'Key' => 'blob.bin'])['Body'];

    assertSame(md5($content), md5($got), '内容摘要');

    return '1 MB 一致';
});

test('从文件流式上传（不占内存）', function () use ($s3) {
    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    $content = str_repeat('S', 2 * 1024 * 1024);
    file_put_contents($tmp, $content);

    try {
        $before = memory_get_usage(true);
        $s3->putObject([
            'Bucket' => 'test', 'Key' => 'streamed.bin',
            'Body' => Stream::open($tmp, 'r'),
        ]);
        $growth = memory_get_usage(true) - $before;

        $got = (string) $s3->getObject(['Bucket' => 'test', 'Key' => 'streamed.bin'])['Body'];
        assertSame(md5($content), md5($got), '内容');

        return sprintf('2 MB 上传，内存增长 %.1f MB', $growth / 1048576);
    } finally {
        @unlink($tmp);
    }
});

test('headObject 取元数据', function () use ($s3) {
    $head = $s3->headObject(['Bucket' => 'test', 'Key' => 'hello.txt']);

    assertSame(strlen('你好，真实 HTTP'), $head['ContentLength'], 'ContentLength');

    return $head['ContentLength'] . ' 字节, ETag=' . $head['ETag'];
});

test('Range 请求（206）', function () use ($s3) {
    $s3->putObject(['Bucket' => 'test', 'Key' => 'range.txt', 'Body' => '0123456789']);
    $result = $s3->getObject(['Bucket' => 'test', 'Key' => 'range.txt', 'Range' => 'bytes=3-6']);

    assertSame('3456', (string) $result['Body'], '片段');
    assertSame(206, $result->getStatusCode(), '状态码');

    return '206 "3456"';
});

test('SaveAs 直接落盘', function () use ($s3) {
    $content = random_bytes(256 * 1024);
    $s3->putObject(['Bucket' => 'test', 'Key' => 'save.bin', 'Body' => $content]);

    $target = sys_get_temp_dir() . '/mins3-curl-save-' . getmypid() . '.bin';
    try {
        $s3->getObject(['Bucket' => 'test', 'Key' => 'save.bin', 'SaveAs' => $target]);
        assertSame(md5($content), md5_file($target), '落盘内容');

        return '256 KB 已落盘';
    } finally {
        @unlink($target);
    }
});

test('404 抛出 S3Exception', function () use ($s3) {
    try {
        $s3->getObject(['Bucket' => 'test', 'Key' => 'definitely-missing']);
    } catch (S3Exception $e) {
        assertSame('NoSuchKey', $e->getAwsErrorCode(), '错误码');
        assertSame(404, $e->getStatusCode(), '状态码');

        return 'NoSuchKey/404';
    }

    throw new RuntimeException('应该抛出异常');
});

test('HEAD 404（无响应体）', function () use ($s3) {
    try {
        $s3->headObject(['Bucket' => 'test', 'Key' => 'definitely-missing']);
    } catch (S3Exception $e) {
        assertSame(404, $e->getStatusCode(), '状态码');

        return 'NotFound/404';
    }

    throw new RuntimeException('应该抛出异常');
});

test('分片上传 12 MB（真实并发）', function () use ($s3) {
    $content = random_bytes(12 * 1024 * 1024);
    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, $content);

    try {
        $start = microtime(true);
        $result = (new MultipartUploader($s3, $tmp, [
            'bucket'      => 'test',
            'key'         => 'multipart.bin',
            'part_size'   => 5 * 1024 * 1024,
            'concurrency' => 3,
        ]))->upload();
        $elapsed = microtime(true) - $start;

        $got = (string) $s3->getObject(['Bucket' => 'test', 'Key' => 'multipart.bin'])['Body'];
        assertSame(md5($content), md5($got), '还原内容');

        return sprintf('3 分片, %.2fs, ETag=%s', $elapsed, $result['ETag']);
    } finally {
        @unlink($tmp);
    }
});

test('并发请求（curl_multi 真并发）', function () use ($s3) {
    $promises = [];
    $start = microtime(true);

    for ($i = 0; $i < 8; $i++) {
        $promises[] = $s3->putObjectAsync([
            'Bucket' => 'test', 'Key' => "concurrent{$i}.txt", 'Body' => "value {$i}",
        ]);
    }

    foreach ($promises as $promise) {
        $promise->wait();
    }
    $elapsed = microtime(true) - $start;

    for ($i = 0; $i < 8; $i++) {
        $got = (string) $s3->getObject(['Bucket' => 'test', 'Key' => "concurrent{$i}.txt"])['Body'];
        assertSame("value {$i}", $got, "第 {$i} 个对象");
    }

    return sprintf('8 个并发写入, %.2fs', $elapsed);
});

test('列举与分页器', function () use ($s3) {
    $keys = [];
    foreach ($s3->getIterator('ListObjectsV2', ['Bucket' => 'test', 'Prefix' => 'concurrent']) as $object) {
        $keys[] = $object['Key'];
    }
    sort($keys);

    assertSame(8, count($keys), '对象数');

    return count($keys) . ' 个对象';
});

test('deleteObject', function () use ($s3) {
    $s3->putObject(['Bucket' => 'test', 'Key' => 'to-delete.txt', 'Body' => 'x']);
    assertSame(true, $s3->doesObjectExist('test', 'to-delete.txt'), '删除前存在');

    $s3->deleteObject(['Bucket' => 'test', 'Key' => 'to-delete.txt']);
    assertSame(false, $s3->doesObjectExist('test', 'to-delete.txt'), '删除后不存在');

    return '删除成功';
});

test('批量删除 DeleteObjects（含 Content-MD5）', function () use ($s3) {
    foreach (['bd1', 'bd2', 'bd3'] as $k) {
        $s3->putObject(['Bucket' => 'test', 'Key' => $k, 'Body' => 'x']);
    }

    $result = $s3->deleteObjects([
        'Bucket' => 'test',
        'Delete' => ['Objects' => [['Key' => 'bd1'], ['Key' => 'bd2'], ['Key' => 'bd3']]],
    ]);

    assertSame(3, count($result['Deleted'] ?? []), '删除条数');

    return '3 个对象已删除';
});

test('预签名 URL 可被独立 curl 直接使用', function () use ($s3, $endpoint) {
    $content = '预签名下载测试';
    $s3->putObject(['Bucket' => 'test', 'Key' => 'presigned.txt', 'Body' => $content]);

    $url = $s3->createPresignedUrl('test', 'presigned.txt', '+10 minutes');

    // 不用本包，直接用裸 curl 请求，证明 URL 自身可用
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    assertSame(200, $status, 'HTTP 状态');
    assertSame($content, $body, '下载内容');

    return "裸 curl 取回 {$status}";
});

test('超时设置生效', function () use ($endpoint) {
    // 连一个不会应答的地址，验证 connect_timeout 起作用
    $slow = new S3Client([
        'endpoint'    => 'http://127.0.0.1:1',   // 端口 1 必定拒绝或超时
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'retries'     => 0,
        'http'        => ['connect_timeout' => 1, 'timeout' => 2],
    ]);

    $start = microtime(true);
    try {
        $slow->headBucket(['Bucket' => 'test']);
    } catch (\MinS3\Exception\ConnectException $e) {
        $elapsed = microtime(true) - $start;
        if ($elapsed > 5) {
            throw new RuntimeException(sprintf('超时未生效，耗时 %.1fs', $elapsed));
        }

        return sprintf('%.2fs 内失败并抛出 ConnectException', $elapsed);
    }

    throw new RuntimeException('应该抛出 ConnectException');
});

test('连接失败会重试', function () {
    $attempts = 0;
    $client = new S3Client([
        'endpoint'    => 'http://127.0.0.1:1',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'retries'     => 2,
        'http'        => ['connect_timeout' => 1],
        'handler'     => function ($request, array $options) use (&$attempts) {
            $attempts++;
            return (new MinS3\Http\CurlHandler())($request, $options);
        },
    ]);

    try {
        $client->headBucket(['Bucket' => 'test']);
    } catch (\MinS3\Exception\ConnectException $e) {
        assertSame(3, $attempts, '1 次原始 + 2 次重试');

        return "尝试 {$attempts} 次";
    }

    throw new RuntimeException('应该抛出 ConnectException');
});

test('s3:// 流包装器走真实 HTTP', function () use ($s3) {
    MinS3\StreamWrapper::register($s3);

    try {
        file_put_contents('s3://test/wrapper-real.txt', '流包装器真实写入');
        assertSame('流包装器真实写入', file_get_contents('s3://test/wrapper-real.txt'), '内容');

        return '读写正常';
    } finally {
        MinS3\StreamWrapper::unregister();
    }
});

exit(testSummary());
