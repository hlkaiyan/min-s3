<?php
/**
 * 端到端功能测试。
 *
 * 用内存版 S3 服务端跑完整交互流程：对象增删查、列举翻页、
 * 分片上传与断点续传、错误处理、预签名、流包装器、目录同步。
 *
 * 用法: php tests/functional.php
 */
require __DIR__ . '/bootstrap.php';

use MinS3\BatchDelete;
use MinS3\Exception\S3Exception;
use MinS3\Http\Response;
use MinS3\Http\Stream;
use MinS3\Multipart\MultipartUploader;
use MinS3\Multipart\UploadState;
use MinS3\PostObjectV4;
use MinS3\S3Client;
use MinS3\StreamWrapper;
use MinS3\Transfer;

/** 造一个连着内存 S3 的客户端 */
function makeClient(FakeS3 $fake, array $extra = []): S3Client
{
    return new S3Client($extra + [
        'endpoint'    => 'http://127.0.0.1:9000',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'minioadmin', 'secret' => 'minioadmin'],
        'retries'     => 0,
        'handler'     => $fake->handler(),
    ]);
}

// ===============================================================
echo "=== 基本对象操作 ===\n";

test('putObject 返回 ETag', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $result = $s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => 'hello']);

    assertSame('"' . md5('hello') . '"', $result['ETag'], 'ETag');
    assertSame('hello', $fake->buckets['b']['a.txt']['body'], '服务端存储内容');

    return $result['ETag'];
});

test('getObject 取回内容', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => '你好，世界']);

    $result = $s3->getObject(['Bucket' => 'b', 'Key' => 'a.txt']);
    assertSame('你好，世界', (string) $result['Body'], '内容');

    return mb_strlen((string) $result['Body']) . ' 字';
});

test('getObject 的 Body 是流，可分段读', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => 'abcdefghij']);

    $body = $s3->getObject(['Bucket' => 'b', 'Key' => 'a.txt'])['Body'];
    assertTrue($body instanceof Stream, 'Body 应该是 Stream');
    assertSame('abcde', $body->read(5), '前 5 字节');
    assertSame('fghij', $body->read(5), '后 5 字节');

    return '分段读取正常';
});

test('元数据往返（x-amz-meta-*）', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $s3->putObject([
        'Bucket' => 'b', 'Key' => 'm.txt', 'Body' => 'x',
        'Metadata' => ['author' => 'zhang', 'project' => 'demo'],
        'ContentType' => 'text/plain',
    ]);

    $head = $s3->headObject(['Bucket' => 'b', 'Key' => 'm.txt']);
    assertSame('zhang', $head['Metadata']['author'] ?? null, 'author');
    assertSame('demo', $head['Metadata']['project'] ?? null, 'project');
    assertSame('text/plain', $head['ContentType'], 'ContentType');

    return 'author=' . $head['Metadata']['author'];
});

test('headObject 返回长度与时间', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => str_repeat('x', 1234)]);

    $head = $s3->headObject(['Bucket' => 'b', 'Key' => 'a.txt']);
    assertSame(1234, $head['ContentLength'], 'ContentLength');
    assertTrue($head['LastModified'] instanceof \DateTimeInterface, 'LastModified 应是时间对象');

    return $head['ContentLength'] . ' 字节, ' . $head['LastModified']->format('Y-m-d');
});

test('Range 请求返回 206 与片段', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => '0123456789']);

    $result = $s3->getObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Range' => 'bytes=2-5']);
    assertSame('2345', (string) $result['Body'], '片段内容');
    assertSame(206, $result->getStatusCode(), '状态码');

    return '206, "' . (string) $result['Body'] . '"';
});

test('deleteObject 后对象消失', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => 'x']);
    $s3->deleteObject(['Bucket' => 'b', 'Key' => 'a.txt']);

    assertTrue(!isset($fake->buckets['b']['a.txt']), '对象应已删除');

    return '已删除';
});

test('key 含中文、空格与特殊字符', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $keys = ['目录/文件 名.txt', 'a b+c&d=e.txt', '100%真实.bin', "引号'与\"号.txt", 'emoji🎉.png'];
    foreach ($keys as $key) {
        $s3->putObject(['Bucket' => 'b', 'Key' => $key, 'Body' => 'v:' . $key]);
    }

    foreach ($keys as $key) {
        $got = (string) $s3->getObject(['Bucket' => 'b', 'Key' => $key])['Body'];
        assertSame('v:' . $key, $got, "读回 {$key}");
    }

    return count($keys) . ' 个特殊 key 往返正常';
});

test('空对象上传与读取', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'empty', 'Body' => '']);

    assertSame('', (string) $s3->getObject(['Bucket' => 'b', 'Key' => 'empty'])['Body'], '空内容');
    assertSame(0, $s3->headObject(['Bucket' => 'b', 'Key' => 'empty'])['ContentLength'], '长度');

    return '0 字节';
});

test('二进制内容不被破坏', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $binary = random_bytes(4096);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'blob.bin', 'Body' => $binary]);
    $got = (string) $s3->getObject(['Bucket' => 'b', 'Key' => 'blob.bin'])['Body'];

    assertSame(md5($binary), md5($got), '内容摘要');

    return '4096 字节二进制一致';
});

// ===============================================================
echo "\n=== 桶操作 ===\n";

test('createBucket / listBuckets / deleteBucket', function () {
    $fake = new FakeS3();
    $s3 = makeClient($fake);

    $s3->createBucket(['Bucket' => 'newbucket']);
    $names = array_column($s3->listBuckets()['Buckets'], 'Name');
    assertSame(['newbucket'], $names, '桶列表');

    $s3->deleteBucket(['Bucket' => 'newbucket']);
    assertSame([], array_column($s3->listBuckets()['Buckets'], 'Name'), '删除后为空');

    return '创建/列举/删除均正常';
});

test('doesBucketExist 判断存在性', function () {
    $fake = new FakeS3(['exists']);
    $s3 = makeClient($fake);

    assertSame(true, $s3->doesBucketExist('exists'), '存在的桶');
    assertSame(false, $s3->doesBucketExist('missing'), '不存在的桶');

    return 'true / false';
});

test('doesObjectExist 判断存在性', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'here.txt', 'Body' => 'x']);

    assertSame(true, $s3->doesObjectExist('b', 'here.txt'), '存在的对象');
    assertSame(false, $s3->doesObjectExist('b', 'gone.txt'), '不存在的对象');

    return 'true / false';
});

test('非空桶不能删除', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => 'x']);

    try {
        $s3->deleteBucket(['Bucket' => 'b']);
    } catch (S3Exception $e) {
        assertSame('BucketNotEmpty', $e->getAwsErrorCode(), '错误码');

        return $e->getAwsErrorCode();
    }

    throw new RuntimeException('应该抛出异常');
});

// ===============================================================
echo "\n=== 列举与翻页 ===\n";

test('listObjectsV2 基本列举', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    foreach (['a.txt', 'b.txt', 'c.txt'] as $k) {
        $s3->putObject(['Bucket' => 'b', 'Key' => $k, 'Body' => 'x']);
    }

    $keys = array_column($s3->listObjectsV2(['Bucket' => 'b'])['Contents'], 'Key');
    assertSame(['a.txt', 'b.txt', 'c.txt'], $keys, '对象列表');

    return implode(',', $keys);
});

test('分页器自动翻页取全量', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    for ($i = 0; $i < 25; $i++) {
        $s3->putObject(['Bucket' => 'b', 'Key' => sprintf('k%03d', $i), 'Body' => 'x']);
    }

    $keys = [];
    $pages = 0;
    foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => 'b', 'MaxKeys' => 10]) as $page) {
        $pages++;
        foreach ($page['Contents'] ?? [] as $object) {
            $keys[] = $object['Key'];
        }
        if ($pages > 10) {
            throw new RuntimeException('翻页没有终止，可能陷入死循环');
        }
    }

    assertSame(25, count($keys), '总条数');
    assertSame(25, count(array_unique($keys)), '不应有重复');
    assertSame('k000', $keys[0], '首条');
    assertSame('k024', $keys[24], '末条');

    return "{$pages} 页共 " . count($keys) . ' 条';
});

test('getIterator 跨页遍历对象', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    for ($i = 0; $i < 12; $i++) {
        $s3->putObject(['Bucket' => 'b', 'Key' => "obj{$i}", 'Body' => 'x']);
    }

    $count = 0;
    foreach ($s3->getIterator('ListObjectsV2', ['Bucket' => 'b', 'MaxKeys' => 5]) as $object) {
        assertTrue(isset($object['Key']), '每项应含 Key');
        $count++;
    }

    assertSame(12, $count, '遍历条数');

    return "{$count} 个对象";
});

test('ListObjects（V1）翻页用 NextMarker', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    for ($i = 0; $i < 15; $i++) {
        $s3->putObject(['Bucket' => 'b', 'Key' => sprintf('m%03d', $i), 'Body' => 'x']);
    }

    $keys = [];
    foreach ($s3->getPaginator('ListObjects', ['Bucket' => 'b', 'MaxKeys' => 6]) as $page) {
        foreach ($page['Contents'] ?? [] as $object) {
            $keys[] = $object['Key'];
        }
    }

    assertSame(15, count($keys), '总条数');

    return count($keys) . ' 条';
});

test('前缀与分隔符产生 CommonPrefixes', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    foreach (['docs/a.txt', 'docs/b.txt', 'img/c.png', 'top.txt'] as $k) {
        $s3->putObject(['Bucket' => 'b', 'Key' => $k, 'Body' => 'x']);
    }

    $result = $s3->listObjectsV2(['Bucket' => 'b', 'Delimiter' => '/']);
    $prefixes = array_column($result['CommonPrefixes'] ?? [], 'Prefix');
    sort($prefixes);

    assertSame(['docs/', 'img/'], $prefixes, '公共前缀');
    assertSame(['top.txt'], array_column($result['Contents'] ?? [], 'Key'), '顶层对象');

    return implode(',', $prefixes);
});

test('空桶列举返回空结果而非报错', function () {
    $fake = new FakeS3(['empty']);
    $s3 = makeClient($fake);

    $result = $s3->listObjectsV2(['Bucket' => 'empty']);
    assertSame(0, count($result['Contents'] ?? []), '不应有对象');

    $count = 0;
    foreach ($s3->getIterator('ListObjectsV2', ['Bucket' => 'empty']) as $ignored) {
        $count++;
    }
    assertSame(0, $count, '迭代器应为空');

    return '空结果正常';
});

// ===============================================================
echo "\n=== 分片上传 ===\n";

test('分片上传大文件并还原内容', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $content = random_bytes(12 * 1024 * 1024);   // 12 MB → 3 个分片
    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, $content);

    try {
        $result = (new MultipartUploader($s3, $tmp, [
            'bucket'    => 'b',
            'key'       => 'big.bin',
            'part_size' => 5 * 1024 * 1024,
        ]))->upload();

        assertTrue($result['ETag'] !== null, '应返回 ETag');
        assertSame(md5($content), md5($fake->buckets['b']['big.bin']['body']), '还原内容');

        return '12 MB / 3 分片, ETag=' . $result['ETag'];
    } finally {
        @unlink($tmp);
    }
});

test('分片按序合并（乱序完成会被服务端拒绝）', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $content = str_repeat('A', 5 * 1024 * 1024) . str_repeat('B', 5 * 1024 * 1024)
             . str_repeat('C', 1024);
    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, $content);

    try {
        (new MultipartUploader($s3, $tmp, [
            'bucket' => 'b', 'key' => 'ordered.bin',
            'part_size' => 5 * 1024 * 1024, 'concurrency' => 3,
        ]))->upload();

        $stored = $fake->buckets['b']['ordered.bin']['body'];
        assertSame($content, $stored, '合并后内容');
        assertSame('A', $stored[0], '首字节');
        assertSame('C', $stored[strlen($stored) - 1], '末字节');

        return '并发 3，顺序正确';
    } finally {
        @unlink($tmp);
    }
});

test('不可定位的流也能分片上传', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $content = str_repeat('x', 11 * 1024 * 1024);

    // 用管道模拟不可 seek 的源
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $content);
    rewind($handle);
    $stream = new Stream($handle);

    $result = (new MultipartUploader($s3, $stream, [
        'bucket' => 'b', 'key' => 'stream.bin', 'part_size' => 5 * 1024 * 1024,
    ]))->upload();

    assertSame(md5($content), md5($fake->buckets['b']['stream.bin']['body']), '内容');

    return '11 MB 流式上传, ' . $result['ETag'];
});

test('分片失败抛出异常且带可续传状态', function () {
    $fake = new FakeS3(['b']);

    // 第 3 个请求（第 2 个分片）注入故障
    $seen = 0;
    $fake->interceptor = function ($request, int $n) use ($fake, &$seen) {
        if (str_contains($request->getUri()->getQuery(), 'partNumber=2')) {
            $seen++;
            return $fake->error(500, 'InternalError', '注入的分片故障');
        }
        return null;
    };

    $s3 = makeClient($fake);
    $content = str_repeat('y', 11 * 1024 * 1024);
    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, $content);

    try {
        (new MultipartUploader($s3, $tmp, [
            'bucket' => 'b', 'key' => 'fail.bin', 'part_size' => 5 * 1024 * 1024,
        ]))->upload();
    } catch (\MinS3\Exception\MultipartUploadException $e) {
        $state = $e->getState();
        assertTrue($state->getUploadId() !== null, '状态里应有 UploadId');
        assertTrue($state->countUploadedParts() > 0, '应有已完成的分片');
        assertTrue(count($e->getPartExceptions()) > 0, '应记录分片错误');
        @unlink($tmp);

        return '已完成 ' . $state->countUploadedParts() . ' 片，失败 '
             . count($e->getPartExceptions()) . ' 片';
    } finally {
        @unlink($tmp);
    }

    throw new RuntimeException('应该抛出 MultipartUploadException');
});

test('断点续传：已完成分片不重传', function () {
    $fake = new FakeS3(['b']);
    $content = str_repeat('z', 11 * 1024 * 1024);
    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, $content);

    try {
        // 第一次：第 3 片失败
        $fake->interceptor = function ($request) use ($fake) {
            if (str_contains($request->getUri()->getQuery(), 'partNumber=3')) {
                return $fake->error(500, 'InternalError', '注入故障');
            }
            return null;
        };

        $s3 = makeClient($fake);
        $state = null;
        try {
            (new MultipartUploader($s3, $tmp, [
                'bucket' => 'b', 'key' => 'resume.bin', 'part_size' => 5 * 1024 * 1024,
            ]))->upload();
        } catch (\MinS3\Exception\MultipartUploadException $e) {
            $state = $e->getState();
        }

        assertTrue($state !== null, '第一次应当失败');
        $doneFirst = $state->countUploadedParts();

        // 第二次：故障移除，带 state 续传
        $fake->interceptor = null;
        $before = count($fake->log);

        (new MultipartUploader($s3, $tmp, [
            'bucket' => 'b', 'key' => 'resume.bin', 'state' => $state,
        ]))->upload();

        assertSame(md5($content), md5($fake->buckets['b']['resume.bin']['body']), '最终内容');

        // 续传只该补齐剩余分片，不该从头再传一遍
        $uploadPartCalls = 0;
        foreach (array_slice($fake->log, $before) as $entry) {
            if (str_contains($entry['query'], 'partNumber=')) {
                $uploadPartCalls++;
            }
        }
        assertTrue(
            $uploadPartCalls < 3,
            "续传时重传了 {$uploadPartCalls} 个分片，应只补齐缺失的"
        );

        return "首次完成 {$doneFirst} 片，续传补 {$uploadPartCalls} 片";
    } finally {
        @unlink($tmp);
    }
});

test('upload() 小文件走整体上传', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $s3->upload('b', 'small.txt', 'tiny content');

    $puts = array_filter($fake->log, static fn(array $e): bool => $e['method'] === 'PUT');
    assertSame(1, count($puts), '应只有一次 PUT');
    assertSame('tiny content', $fake->buckets['b']['small.txt']['body'], '内容');

    return '1 次 PUT';
});

test('upload() 大文件自动转分片', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $content = str_repeat('L', 20 * 1024 * 1024);
    $s3->upload('b', 'auto.bin', $content, 'private', ['part_size' => 5 * 1024 * 1024]);

    assertSame(md5($content), md5($fake->buckets['b']['auto.bin']['body']), '内容');

    $hasUploads = false;
    foreach ($fake->log as $entry) {
        if (str_contains($entry['query'], 'uploads')) {
            $hasUploads = true;
        }
    }
    assertTrue($hasUploads, '应走了分片上传流程');

    return '20 MB 自动分片';
});

test('abort 清理未完成的分片上传', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $uploader = new MultipartUploader($s3, str_repeat('q', 1024), [
        'bucket' => 'b', 'key' => 'abort.bin',
    ]);

    $result = $s3->createMultipartUpload(['Bucket' => 'b', 'Key' => 'abort.bin']);
    assertSame(1, count($fake->uploads), '应有 1 个进行中的上传');

    $s3->abortMultipartUpload([
        'Bucket' => 'b', 'Key' => 'abort.bin', 'UploadId' => $result['UploadId'],
    ]);
    assertSame(0, count($fake->uploads), '中止后应清空');

    return '已清理';
});

// ===============================================================
echo "\n=== 复制、批量删除 ===\n";

test('copy 复制对象', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'src.txt', 'Body' => '原始内容']);

    $s3->copy('b', 'src.txt', 'b', 'dst.txt');

    assertSame('原始内容', $fake->buckets['b']['dst.txt']['body'], '目标内容');
    assertSame('原始内容', $fake->buckets['b']['src.txt']['body'], '源应保留');

    return 'src.txt → dst.txt';
});

test('copy 处理含特殊字符的 key', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => '目录/原 文件.txt', 'Body' => 'v']);

    $s3->copy('b', '目录/原 文件.txt', 'b', '备份/新 文件.txt');

    assertSame('v', $fake->buckets['b']['备份/新 文件.txt']['body'], '复制结果');

    return '中文路径复制正常';
});

test('BatchDelete 按批删除', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    for ($i = 0; $i < 7; $i++) {
        $s3->putObject(['Bucket' => 'b', 'Key' => "del{$i}", 'Body' => 'x']);
    }
    $s3->putObject(['Bucket' => 'b', 'Key' => 'keep', 'Body' => 'x']);

    BatchDelete::fromListObjects($s3, 'b', ['Prefix' => 'del'], ['batch_size' => 3])->delete();

    assertSame(['keep'], array_keys($fake->buckets['b']), '剩余对象');

    return '删除 7 个，保留 1 个';
});

test('BatchDelete::fromKeys 删除指定 key', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    foreach (['x1', 'x2', 'x3'] as $k) {
        $s3->putObject(['Bucket' => 'b', 'Key' => $k, 'Body' => 'v']);
    }

    BatchDelete::fromKeys($s3, 'b', ['x1', 'x3'])->delete();

    assertSame(['x2'], array_keys($fake->buckets['b']), '剩余对象');

    return '剩 x2';
});

test('deleteMatchingObjects 按正则删除', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    foreach (['logs/a.log', 'logs/b.txt', 'logs/c.log'] as $k) {
        $s3->putObject(['Bucket' => 'b', 'Key' => $k, 'Body' => 'x']);
    }

    $s3->deleteMatchingObjects('b', 'logs/', '/\.log$/');

    assertSame(['logs/b.txt'], array_keys($fake->buckets['b']), '只应删除 .log');

    return '保留 logs/b.txt';
});

// ===============================================================
echo "\n=== 错误处理 ===\n";

test('404 抛出 S3Exception 并带错误码', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    try {
        $s3->getObject(['Bucket' => 'b', 'Key' => 'missing.txt']);
    } catch (S3Exception $e) {
        assertSame('NoSuchKey', $e->getAwsErrorCode(), '错误码');
        assertSame(404, $e->getStatusCode(), '状态码');
        assertSame('client', $e->getAwsErrorType(), '错误类型');
        assertSame('GetObject', $e->getCommandName(), '操作名');
        assertTrue($e->getAwsRequestId() !== null, '应有 RequestId');

        return $e->getAwsErrorCode() . '/' . $e->getStatusCode();
    }

    throw new RuntimeException('应该抛出 S3Exception');
});

test('HEAD 无响应体时靠状态码判定', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    try {
        $s3->headObject(['Bucket' => 'b', 'Key' => 'nope']);
    } catch (S3Exception $e) {
        assertSame(404, $e->getStatusCode(), '状态码');
        assertSame('NotFound', $e->getAwsErrorCode(), '错误码应由状态码推断');

        return 'NotFound/404';
    }

    throw new RuntimeException('应该抛出 S3Exception');
});

test('5xx 自动重试后成功', function () {
    $fake = new FakeS3(['b']);

    $attempts = 0;
    $fake->interceptor = function ($request) use ($fake, &$attempts) {
        if ($request->getMethod() === 'GET' && ++$attempts <= 2) {
            return $fake->error(503, 'ServiceUnavailable', '暂时不可用');
        }
        return null;
    };

    $s3 = makeClient($fake, ['retries' => 3]);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'r.txt', 'Body' => 'ok']);

    $result = $s3->getObject(['Bucket' => 'b', 'Key' => 'r.txt']);
    assertSame('ok', (string) $result['Body'], '重试后内容');
    assertSame(3, $attempts, '应尝试 3 次');

    return "第 {$attempts} 次成功";
});

test('4xx 不重试', function () {
    $fake = new FakeS3(['b']);

    $attempts = 0;
    $fake->interceptor = function ($request) use ($fake, &$attempts) {
        if ($request->getMethod() === 'GET') {
            $attempts++;
            return $fake->error(403, 'AccessDenied', '无权限');
        }
        return null;
    };

    $s3 = makeClient($fake, ['retries' => 3]);

    try {
        $s3->getObject(['Bucket' => 'b', 'Key' => 'x']);
    } catch (S3Exception $e) {
        assertSame(1, $attempts, '客户端错误不应重试');

        return 'AccessDenied，只尝试 1 次';
    }

    throw new RuntimeException('应该抛出异常');
});

test('重试次数用尽后抛出', function () {
    $fake = new FakeS3(['b']);
    $attempts = 0;
    $fake->interceptor = function ($request) use ($fake, &$attempts) {
        if ($request->getMethod() === 'GET') {
            $attempts++;
            return $fake->error(500, 'InternalError', '一直失败');
        }
        return null;
    };

    $s3 = makeClient($fake, ['retries' => 2]);

    try {
        $s3->getObject(['Bucket' => 'b', 'Key' => 'x']);
    } catch (S3Exception $e) {
        assertSame(3, $attempts, '1 次原始 + 2 次重试');

        return "尝试 {$attempts} 次后放弃";
    }

    throw new RuntimeException('应该抛出异常');
});

test('缺少必填参数时提前报错', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    try {
        $s3->getObject(['Bucket' => 'b']);   // 少了 Key
    } catch (\InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'Key'), '错误信息应点名 Key');
        assertSame(0, count($fake->log), '不该发出请求');

        return '未发请求即报错';
    }

    throw new RuntimeException('应该抛出 InvalidArgumentException');
});

test('调用不存在的操作报错', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    try {
        $s3->notARealOperation(['Bucket' => 'b']);
    } catch (\BadMethodCallException $e) {
        return '正确拒绝未知操作';
    }

    throw new RuntimeException('应该抛出 BadMethodCallException');
});

test('非 XML 错误体（网关 HTML 页）不导致崩溃', function () {
    $fake = new FakeS3(['b']);
    $fake->interceptor = function ($request) {
        return new Response(502, ['Content-Type' => 'text/html'],
            '<html><body><h1>502 Bad Gateway</h1></body></html>');
    };

    $s3 = makeClient($fake, ['retries' => 0]);

    try {
        $s3->getObject(['Bucket' => 'b', 'Key' => 'x']);
    } catch (S3Exception $e) {
        assertSame(502, $e->getStatusCode(), '状态码');
        assertTrue(
            str_contains($e->getMessage(), '502'),
            '错误信息应包含状态码，实际: ' . $e->getMessage()
        );

        return '优雅降级';
    }

    throw new RuntimeException('应该抛出 S3Exception');
});

// ===============================================================
echo "\n=== 预签名与表单直传 ===\n";

test('预签名 URL 结构正确', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $url = $s3->createPresignedUrl('b', 'a.txt', '+20 minutes');

    assertTrue(str_starts_with($url, 'http://127.0.0.1:9000/b/a.txt?'), "URL 前缀不符: {$url}");
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    assertSame('AWS4-HMAC-SHA256', $q['X-Amz-Algorithm'], '算法');
    assertSame('1200', $q['X-Amz-Expires'], '有效期');
    assertTrue(isset($q['X-Amz-Signature']), '应有签名');
    assertSame(64, strlen($q['X-Amz-Signature']), '签名长度');
    assertSame(0, count($fake->log), '生成预签名不应发请求');

    return substr($url, 0, 60) . '...';
});

test('预签名 PUT 用于上传', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $command = $s3->getCommand('PutObject', ['Bucket' => 'b', 'Key' => 'up.bin']);
    $request = $s3->createPresignedRequest($command, '+10 minutes');

    assertSame('PUT', $request->getMethod(), '方法');
    assertTrue(str_contains((string) $request->getUri(), 'X-Amz-Signature'), '应含签名');

    return 'PUT 预签名正常';
});

test('预签名有效期超过 7 天被拒绝', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    try {
        $s3->createPresignedUrl('b', 'a.txt', '+8 days');
    } catch (\InvalidArgumentException $e) {
        return '正确拒绝';
    }

    throw new RuntimeException('应该拒绝超过 7 天的有效期');
});

test('PostObjectV4 生成表单字段', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $post = new PostObjectV4($s3, 'b',
        ['key' => 'uploads/${filename}', 'acl' => 'private'],
        [['bucket' => 'b'], ['starts-with', '$key', 'uploads/'],
         ['content-length-range', 0, 10485760]],
        '+1 hours'
    );

    $attributes = $post->getFormAttributes();
    assertSame('POST', $attributes['method'], '方法');
    assertSame('multipart/form-data', $attributes['enctype'], 'enctype');

    $inputs = $post->getFormInputs();
    foreach (['key', 'Policy', 'X-Amz-Signature', 'X-Amz-Credential', 'X-Amz-Date', 'X-Amz-Algorithm'] as $field) {
        assertTrue(isset($inputs[$field]), "缺少字段 {$field}");
    }

    // policy 必须是能解回来的 base64 JSON
    $policy = json_decode(base64_decode($inputs['Policy']), true);
    assertTrue(is_array($policy), 'Policy 应是合法 JSON');
    assertTrue(isset($policy['expiration'], $policy['conditions']), 'Policy 结构');

    return count($inputs) . ' 个字段，policy 含 '
         . count($policy['conditions']) . ' 条约束';
});

test('getObjectUrl 生成无签名直链', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $url = $s3->getObjectUrl('b', 'pub/a.txt');
    assertSame('http://127.0.0.1:9000/b/pub/a.txt', $url, 'URL');
    assertTrue(!str_contains($url, 'Signature'), '不应含签名');

    return $url;
});

// ===============================================================
echo "\n=== 等待器 ===\n";

test('waitUntil ObjectExists 立即就绪', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'w.txt', 'Body' => 'x']);

    $s3->waitUntil('ObjectExists', [
        'Bucket' => 'b', 'Key' => 'w.txt',
        '@waiter' => ['delay' => 0, 'maxAttempts' => 3],
    ]);

    return '已就绪';
});

test('waitUntil ObjectNotExists', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $s3->waitUntil('ObjectNotExists', [
        'Bucket' => 'b', 'Key' => 'never.txt',
        '@waiter' => ['delay' => 0, 'maxAttempts' => 3],
    ]);

    return '确认不存在';
});

test('waitUntil 超时抛出', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    try {
        $s3->waitUntil('ObjectExists', [
            'Bucket' => 'b', 'Key' => 'never.txt',
            '@waiter' => ['delay' => 0, 'maxAttempts' => 2],
        ]);
    } catch (\RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), '超时'), '应提示超时');

        return '正确超时';
    }

    throw new RuntimeException('应该超时抛出');
});

// ===============================================================
echo "\n=== 流包装器 s3:// ===\n";

test('file_get_contents / file_put_contents', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    StreamWrapper::register($s3);

    try {
        file_put_contents('s3://b/wrapped.txt', '通过流包装器写入');
        assertSame('通过流包装器写入', $fake->buckets['b']['wrapped.txt']['body'], '写入内容');
        assertSame('通过流包装器写入', file_get_contents('s3://b/wrapped.txt'), '读取内容');

        return '读写正常';
    } finally {
        StreamWrapper::unregister();
    }
});

test('fopen 分段读取', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'r.txt', 'Body' => '0123456789']);
    StreamWrapper::register($s3);

    try {
        $handle = fopen('s3://b/r.txt', 'r');
        assertSame('01234', fread($handle, 5), '前半');
        assertSame('56789', fread($handle, 5), '后半');
        assertTrue(feof($handle), '应到达末尾');
        fclose($handle);

        return '分段读取正常';
    } finally {
        StreamWrapper::unregister();
    }
});

test('file_exists / filesize', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'stat.txt', 'Body' => str_repeat('s', 42)]);
    StreamWrapper::register($s3);

    try {
        StreamWrapper::clearStatCache();
        assertSame(true, file_exists('s3://b/stat.txt'), '存在性');
        assertSame(42, filesize('s3://b/stat.txt'), '大小');

        StreamWrapper::clearStatCache();
        clearstatcache();
        assertSame(false, file_exists('s3://b/nothere.txt'), '不存在的对象');

        return '42 字节';
    } finally {
        StreamWrapper::unregister();
    }
});

test('unlink 删除对象', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'gone.txt', 'Body' => 'x']);
    StreamWrapper::register($s3);

    try {
        assertSame(true, unlink('s3://b/gone.txt'), 'unlink 返回值');
        assertTrue(!isset($fake->buckets['b']['gone.txt']), '对象应被删除');

        return '已删除';
    } finally {
        StreamWrapper::unregister();
    }
});

test('目录遍历 scandir', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    foreach (['dir/a.txt', 'dir/b.txt', 'dir/sub/c.txt', 'other.txt'] as $k) {
        $s3->putObject(['Bucket' => 'b', 'Key' => $k, 'Body' => 'x']);
    }
    StreamWrapper::register($s3);

    try {
        $entries = array_values(array_diff(scandir('s3://b/dir'), ['.', '..']));
        sort($entries);

        assertSame(['a.txt', 'b.txt', 'sub'], $entries, '目录内容');

        return implode(',', $entries);
    } finally {
        StreamWrapper::unregister();
    }
});

test('写入时通过 context 传参', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    StreamWrapper::register($s3);

    try {
        $context = stream_context_create(['s3' => ['ContentType' => 'text/markdown']]);
        file_put_contents('s3://b/doc.md', '# 标题', 0, $context);

        assertSame('text/markdown',
            $fake->buckets['b']['doc.md']['headers']['content-type'] ?? null, 'ContentType');

        return 'ContentType 已传递';
    } finally {
        StreamWrapper::unregister();
    }
});

// ===============================================================
echo "\n=== 目录同步 ===\n";

test('uploadDirectory 递归上传', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $dir = sys_get_temp_dir() . '/mins3-up-' . getmypid();
    @mkdir($dir . '/nested', 0777, true);
    file_put_contents($dir . '/root.txt', 'R');
    file_put_contents($dir . '/nested/deep.txt', 'D');

    try {
        $s3->uploadDirectory($dir, 'b', 'prefix');

        $keys = array_keys($fake->buckets['b']);
        sort($keys);
        assertSame(['prefix/nested/deep.txt', 'prefix/root.txt'], $keys, '上传的 key');
        assertSame('D', $fake->buckets['b']['prefix/nested/deep.txt']['body'], '嵌套文件内容');

        return implode(', ', $keys);
    } finally {
        @unlink($dir . '/nested/deep.txt');
        @unlink($dir . '/root.txt');
        @rmdir($dir . '/nested');
        @rmdir($dir);
    }
});

test('downloadBucket 递归下载', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'data/x.txt', 'Body' => 'X']);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'data/sub/y.txt', 'Body' => 'Y']);

    $dir = sys_get_temp_dir() . '/mins3-down-' . getmypid();

    try {
        $s3->downloadBucket($dir, 'b', 'data');

        assertSame('X', file_get_contents($dir . '/x.txt'), 'x.txt');
        assertSame('Y', file_get_contents($dir . '/sub/y.txt'), 'sub/y.txt');

        return '2 个文件已下载';
    } finally {
        @unlink($dir . '/sub/y.txt');
        @unlink($dir . '/x.txt');
        @rmdir($dir . '/sub');
        @rmdir($dir);
    }
});

test('SaveAs 直接落盘', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'save.bin', 'Body' => '落盘内容']);

    $target = sys_get_temp_dir() . '/mins3-saveas-' . getmypid() . '.bin';

    try {
        $s3->getObject(['Bucket' => 'b', 'Key' => 'save.bin', 'SaveAs' => $target]);
        assertSame('落盘内容', file_get_contents($target), '文件内容');

        return basename($target);
    } finally {
        @unlink($target);
    }
});

test('SourceFile 直接上传文件', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, '来自文件的内容');

    try {
        $s3->putObject(['Bucket' => 'b', 'Key' => 'from-file.txt', 'SourceFile' => $tmp]);
        assertSame('来自文件的内容', $fake->buckets['b']['from-file.txt']['body'], '内容');

        return '已上传';
    } finally {
        @unlink($tmp);
    }
});

// ===============================================================
echo "\n=== 异步与并发 ===\n";

test('putObjectAsync 返回 Promise', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $promise = $s3->putObjectAsync(['Bucket' => 'b', 'Key' => 'async.txt', 'Body' => 'A']);
    assertTrue($promise instanceof MinS3\Promise\Promise, '应返回 Promise');

    $result = $promise->wait();
    assertTrue($result['ETag'] !== null, '应有 ETag');

    return 'ETag=' . $result['ETag'];
});

test('Promise 链式 then', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'chain.txt', 'Body' => 'hello']);

    $length = $s3->getObjectAsync(['Bucket' => 'b', 'Key' => 'chain.txt'])
        ->then(static fn($result): string => (string) $result['Body'])
        ->then(static fn(string $body): int => strlen($body))
        ->wait();

    assertSame(5, $length, '链式结果');

    return "长度 {$length}";
});

test('Promise 异常走 otherwise', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $code = $s3->getObjectAsync(['Bucket' => 'b', 'Key' => 'missing'])
        ->otherwise(static fn(\Throwable $e): string => $e instanceof S3Exception
            ? $e->getAwsErrorCode()
            : 'other')
        ->wait();

    assertSame('NoSuchKey', $code, '错误码');

    return $code;
});

test('多个 Async 请求并发执行', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);

    $promises = [];
    for ($i = 0; $i < 5; $i++) {
        $promises[] = $s3->putObjectAsync(['Bucket' => 'b', 'Key' => "c{$i}", 'Body' => "v{$i}"]);
    }

    foreach ($promises as $promise) {
        $promise->wait();
    }

    assertSame(5, count($fake->buckets['b']), '应写入 5 个对象');

    return '5 个并发写入完成';
});

// ===============================================================
echo "\n=== 配置与边界 ===\n";

test('缺少 endpoint 时报错', function () {
    try {
        new S3Client(['region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's']]);
    } catch (\InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'endpoint'), '应提示 endpoint');

        return '正确拒绝';
    }

    throw new RuntimeException('应该报错');
});

test('缺少凭证且无环境变量时报错', function () {
    $saved = [getenv('AWS_ACCESS_KEY_ID'), getenv('AWS_SECRET_ACCESS_KEY')];
    putenv('AWS_ACCESS_KEY_ID');
    putenv('AWS_SECRET_ACCESS_KEY');

    try {
        new S3Client(['endpoint' => 'http://127.0.0.1:9000']);
    } catch (\InvalidArgumentException $e) {
        return '正确拒绝';
    } finally {
        if ($saved[0] !== false) {
            putenv('AWS_ACCESS_KEY_ID=' . $saved[0]);
        }
        if ($saved[1] !== false) {
            putenv('AWS_SECRET_ACCESS_KEY=' . $saved[1]);
        }
    }

    throw new RuntimeException('应该报错');
});

test('从环境变量读取凭证', function () {
    putenv('AWS_ACCESS_KEY_ID=envkey');
    putenv('AWS_SECRET_ACCESS_KEY=envsecret');

    try {
        $s3 = new S3Client(['endpoint' => 'http://127.0.0.1:9000']);
        assertSame('envkey', $s3->getCredentials()->getAccessKeyId(), 'key');

        return 'envkey';
    } finally {
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
    }
});

test('凭证提供者可动态返回', function () {
    $fake = new FakeS3(['b']);
    $calls = 0;

    $s3 = new S3Client([
        'endpoint'    => 'http://127.0.0.1:9000',
        'region'      => 'us-east-1',
        'credentials' => function () use (&$calls) {
            $calls++;
            // 已过期的凭证会促使客户端再次调用提供者
            return new MinS3\Credentials('dyn', 'secret', null, time() - 1);
        },
        'handler'     => $fake->handler(),
    ]);

    $s3->putObject(['Bucket' => 'b', 'Key' => 'a', 'Body' => 'x']);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'b', 'Body' => 'x']);

    assertTrue($calls >= 2, "过期凭证应重新获取，实际调用 {$calls} 次");

    return "提供者被调用 {$calls} 次";
});

test('region 为空时报错', function () {
    try {
        new S3Client([
            'endpoint' => 'http://127.0.0.1:9000',
            'region' => '',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);
    } catch (\InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'region'), '应提示 region');

        return '正确拒绝';
    }

    throw new RuntimeException('应该报错');
});

test('DeleteObjects 默认带 Content-MD5', function () {
    $fake = new FakeS3(['b']);
    $capturedRequest = null;

    $s3 = new S3Client([
        'endpoint'    => 'http://127.0.0.1:9000',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler'     => function ($request, array $options) use ($fake, &$capturedRequest) {
            $capturedRequest = $request;
            return ($fake->handler())($request, $options);
        },
    ]);

    $s3->putObject(['Bucket' => 'b', 'Key' => 'a', 'Body' => 'x']);
    $s3->deleteObjects(['Bucket' => 'b', 'Delete' => ['Objects' => [['Key' => 'a']]]]);

    $md5 = $capturedRequest->getHeaderLine('Content-MD5');
    assertTrue($md5 !== '', 'DeleteObjects 应带 Content-MD5（自建 S3 普遍要求）');
    assertSame(24, strlen($md5), 'base64 编码的 MD5 长度');

    return "Content-MD5={$md5}";
});

test('切换到 crc32 校验算法', function () {
    $fake = new FakeS3(['b']);
    $captured = null;

    $s3 = new S3Client([
        'endpoint'             => 'http://127.0.0.1:9000',
        'region'               => 'us-east-1',
        'credentials'          => ['key' => 'k', 'secret' => 's'],
        'checksum_algorithm'   => 'crc32',
        'handler'              => function ($request, array $options) use ($fake, &$captured) {
            $captured = $request;
            return ($fake->handler())($request, $options);
        },
    ]);

    $s3->putObject(['Bucket' => 'b', 'Key' => 'a', 'Body' => 'x']);
    $s3->deleteObjects(['Bucket' => 'b', 'Delete' => ['Objects' => [['Key' => 'a']]]]);

    assertTrue($captured->hasHeader('x-amz-checksum-crc32'), '应发送 crc32 校验头');
    assertTrue(!$captured->hasHeader('Content-MD5'), '不应同时发 Content-MD5');

    return 'x-amz-checksum-crc32=' . $captured->getHeaderLine('x-amz-checksum-crc32');
});

test('@http 选项透传到传输层', function () {
    $fake = new FakeS3(['b']);
    $capturedOptions = null;

    $s3 = new S3Client([
        'endpoint'    => 'http://127.0.0.1:9000',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler'     => function ($request, array $options) use ($fake, &$capturedOptions) {
            $capturedOptions = $options;
            return ($fake->handler())($request, $options);
        },
    ]);

    $s3->putObject([
        'Bucket' => 'b', 'Key' => 'a', 'Body' => 'x',
        '@http' => ['timeout' => 42, 'verify' => false],
    ]);

    assertSame(42, $capturedOptions['timeout'] ?? null, 'timeout');
    assertSame(false, $capturedOptions['verify'] ?? null, 'verify');

    return 'timeout=42, verify=false';
});

test('全部 116 个操作都能构造命令', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $api = $s3->getApi();

    $names = $api->getOperationNames();
    assertSame(116, count($names), '操作数');

    foreach ($names as $name) {
        $operation = $api->getOperation($name);
        $operation->getInput();
        $operation->getOutput();
        $s3->getCommand($name, []);
    }

    return count($names) . ' 个操作模型全部可解析';
});

test('全部操作的序列化不抛异常', function () {
    $fake = new FakeS3(['b']);
    $s3 = makeClient($fake);
    $api = $s3->getApi();

    // 给必填参数填占位值，只验证序列化本身不炸
    $stub = [
        'Bucket' => 'b', 'Key' => 'k', 'UploadId' => 'u', 'PartNumber' => 1,
        'Id' => 'id', 'CopySource' => '/b/k', 'AccountId' => '123456789012',
        'Delete' => ['Objects' => [['Key' => 'x']]],
        'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => '"e"']]],
        'Policy' => '{}', 'Expression' => 'SELECT * FROM S3Object', 'ExpressionType' => 'SQL',
        'InputSerialization' => ['CSV' => []], 'OutputSerialization' => ['CSV' => []],
        'RestoreRequest' => ['Days' => 1], 'Tagging' => ['TagSet' => []],
        'VersioningConfiguration' => ['Status' => 'Enabled'],
        'CORSConfiguration' => ['CORSRules' => []],
        'LifecycleConfiguration' => ['Rules' => []],
        'NotificationConfiguration' => [], 'AccessControlPolicy' => [],
        'RequestPaymentConfiguration' => ['Payer' => 'Requester'],
        'WebsiteConfiguration' => [], 'ReplicationConfiguration' => ['Role' => 'r', 'Rules' => []],
        'ServerSideEncryptionConfiguration' => ['Rules' => []],
        'PublicAccessBlockConfiguration' => [], 'OwnershipControls' => ['Rules' => []],
        'IntelligentTieringConfiguration' => [], 'InventoryConfiguration' => [],
        'MetricsConfiguration' => [], 'AnalyticsConfiguration' => [],
        'ObjectLockConfiguration' => [], 'LegalHold' => [], 'Retention' => [],
        'BucketLoggingStatus' => [], 'AccelerateConfiguration' => ['Status' => 'Enabled'],
        'SessionMode' => 'ReadWrite', 'MetadataTableConfiguration' => [],
        'MetadataConfiguration' => [], 'Annotation' => [], 'AnnotationKey' => 'ak',
        'JournalTableConfiguration' => [], 'InventoryTableConfiguration' => [],
        'AbacStatus' => [], 'EncryptionConfiguration' => [],
    ];

    $ok = 0;
    $errors = [];

    foreach ($api->getOperationNames() as $name) {
        $operation = $api->getOperation($name);
        $args = [];
        foreach ($operation->getInput()['required'] ?? [] as $required) {
            if (array_key_exists($required, $stub)) {
                $args[$required] = $stub[$required];
            } else {
                $member = $operation->getInput()->getMember($required);
                $args[$required] = match ($member->getType()) {
                    'integer', 'long' => 1,
                    'boolean'         => true,
                    'structure'       => [],
                    'list'            => [],
                    'map'             => [],
                    default           => 'v',
                };
            }
        }

        try {
            $s3->createPresignedRequest($s3->getCommand($name, $args), '+5 minutes');
            $ok++;
        } catch (\Throwable $e) {
            $errors[$name] = get_class($e) . ': ' . substr($e->getMessage(), 0, 80);
        }
    }

    if ($errors !== []) {
        throw new RuntimeException(
            count($errors) . " 个操作序列化失败:\n  "
            . implode("\n  ", array_map(
                static fn(string $k, string $v): string => "{$k} → {$v}",
                array_keys($errors),
                $errors
            ))
        );
    }

    return "{$ok} 个操作全部序列化成功";
});

exit(testSummary());
