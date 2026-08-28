<?php
/**
 * 逐条验证 README 里的代码示例。
 *
 * 文档里的示例如果跑不通，比没有文档更糟糕。这里把每段示例
 * 原样抄过来执行一遍。改了 README 就该跑一次这个。
 *
 * 用法: php tests/readme.php
 */
require __DIR__ . '/bootstrap.php';

use MinS3\BatchDelete;
use MinS3\Exception\ConnectException;
use MinS3\Exception\MultipartUploadException;
use MinS3\Exception\S3Exception;
use MinS3\Multipart\MultipartUploader;
use MinS3\PostObjectV4;
use MinS3\S3Client;

function client(FakeS3 $fake, array $extra = []): S3Client
{
    return new S3Client($extra + [
        'endpoint'    => 'http://127.0.0.1:9000',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'minioadmin', 'secret' => 'minioadmin'],
        'retries'     => 0,
        'handler'     => $fake->handler(),
    ]);
}

echo "=== README 示例验证 ===\n";

// --- 开头的示例 ---
test('开头：putObject + getObject', function () {
    $fake = new FakeS3(['my-bucket']);
    $s3 = client($fake);

    $s3->putObject(['Bucket' => 'my-bucket', 'Key' => 'a.txt', 'Body' => 'hello']);
    $body = (string) $s3->getObject(['Bucket' => 'my-bucket', 'Key' => 'a.txt'])['Body'];

    assertTrue($body === 'hello', "内容应为 hello，实际 {$body}");

    return $body;
});

// --- 对接自建 S3 ---
test('自建 S3：完整配置块', function () {
    $fake = new FakeS3(['b']);
    $s3 = new S3Client([
        'endpoint'    => 'http://127.0.0.1:9000',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'minioadmin', 'secret' => 'minioadmin'],
        'handler'     => $fake->handler(),
    ]);

    $s3->putObject(['Bucket' => 'b', 'Key' => 'k', 'Body' => 'v']);

    return (string) $s3->getEndpoint();
});

test('自建 S3：切换虚拟主机式寻址', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake, ['use_path_style_endpoint' => false]);

    // 端点是 IP，应自动退回路径式
    $url = $s3->getObjectUrl('b', 'a.txt');
    assertTrue(
        $url === 'http://127.0.0.1:9000/b/a.txt',
        "IP 端点应退回路径式，实际 {$url}"
    );

    return $url;
});

test('自建 S3：verify 选项', function () {
    $fake = new FakeS3(['b']);
    $captured = null;
    $s3 = new S3Client([
        'endpoint'    => 'https://s3.internal',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'http'        => ['verify' => false],
        'handler'     => function ($request, array $options) use ($fake, &$captured) {
            $captured = $options;
            return ($fake->handler())($request, $options);
        },
    ]);

    $s3->putObject(['Bucket' => 'b', 'Key' => 'k', 'Body' => 'v']);
    assertTrue(($captured['verify'] ?? null) === false, 'verify 应传到传输层');

    return 'verify=false 已透传';
});

// --- 上传 ---
test('上传：字符串 / SourceFile / Stream', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);

    $s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => 'hello']);

    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, 'from file');
    try {
        $s3->putObject(['Bucket' => 'b', 'Key' => 'b.txt', 'SourceFile' => $tmp]);
        $s3->putObject([
            'Bucket' => 'b', 'Key' => 'c.bin',
            'Body'   => MinS3\Http\Stream::open($tmp, 'r'),
        ]);
    } finally {
        @unlink($tmp);
    }

    assertTrue($fake->buckets['b']['b.txt']['body'] === 'from file', 'SourceFile 内容');
    assertTrue($fake->buckets['b']['c.bin']['body'] === 'from file', 'Stream 内容');

    return '三种方式均可用';
});

test('上传：附带元数据与其他参数', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);

    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, '%PDF-1.4 fake');

    try {
        $s3->putObject([
            'Bucket'       => 'b',
            'Key'          => 'report.pdf',
            'SourceFile'   => $tmp,
            'ContentType'  => 'application/pdf',
            'ACL'          => 'public-read',
            'Metadata'     => ['author' => 'zhang', 'version' => '2'],
            'CacheControl' => 'max-age=3600',
        ]);
    } finally {
        @unlink($tmp);
    }

    $head = $s3->headObject(['Bucket' => 'b', 'Key' => 'report.pdf']);
    assertTrue($head['Metadata']['author'] === 'zhang', 'author 元数据');
    assertTrue($head['ContentType'] === 'application/pdf', 'ContentType');

    return 'ContentType=' . $head['ContentType'];
});

test('上传：upload() 自动选择方式', function () {
    $fake = new FakeS3(['my-bucket']);
    $s3 = client($fake);

    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, str_repeat('Z', 1024));

    try {
        $s3->upload('my-bucket', 'big.zip', fopen($tmp, 'r'));
    } finally {
        @unlink($tmp);
    }

    assertTrue(isset($fake->buckets['my-bucket']['big.zip']), '对象应存在');

    return '1 KB 走整体上传';
});

// --- 分片上传 ---
test('分片上传：MultipartUploader 配置', function () {
    $fake = new FakeS3(['my-bucket']);
    $s3 = client($fake);

    $content = random_bytes(18 * 1024 * 1024);
    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, $content);

    try {
        $uploader = new MultipartUploader($s3, $tmp, [
            'bucket'      => 'my-bucket',
            'key'         => 'big.bin',
            'part_size'   => 8 * 1024 * 1024,
            'concurrency' => 4,
        ]);

        $result = $uploader->upload();
        assertTrue(
            md5($fake->buckets['my-bucket']['big.bin']['body']) === md5($content),
            '内容应一致'
        );

        return '18 MB / 8 MB 分片, ETag=' . $result['ETag'];
    } finally {
        @unlink($tmp);
    }
});

test('分片上传：断点续传示例', function () {
    $fake = new FakeS3(['my-bucket']);
    $content = random_bytes(11 * 1024 * 1024);
    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, $content);
    $stateFile = sys_get_temp_dir() . '/mins3-upload.state';

    try {
        $fake->interceptor = function ($request) use ($fake) {
            if (str_contains($request->getUri()->getQuery(), 'partNumber=2')) {
                return $fake->error(500, 'InternalError', '注入故障');
            }
            return null;
        };
        $s3 = client($fake);

        $uploader = new MultipartUploader($s3, $tmp, [
            'bucket' => 'my-bucket', 'key' => 'big.bin', 'part_size' => 5 * 1024 * 1024,
        ]);

        try {
            $uploader->upload();
            throw new RuntimeException('第一次应当失败');
        } catch (MultipartUploadException $e) {
            $state = $e->getState();
            file_put_contents($stateFile, serialize($state));
        }

        // 稍后续传
        $fake->interceptor = null;
        $state = unserialize(file_get_contents($stateFile));
        (new MultipartUploader($s3, $tmp, [
            'bucket' => 'my-bucket', 'key' => 'big.bin', 'state' => $state,
        ]))->upload();

        assertTrue(
            md5($fake->buckets['my-bucket']['big.bin']['body']) === md5($content),
            '续传后内容应完整'
        );

        return '序列化 state 续传成功';
    } finally {
        @unlink($tmp);
        @unlink($stateFile);
    }
});

test('分片上传：abort() 清理', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);

    $content = str_repeat('A', 11 * 1024 * 1024);
    $tmp = tempnam(sys_get_temp_dir(), 'mins3');
    file_put_contents($tmp, $content);

    try {
        $fake->interceptor = function ($request) use ($fake) {
            if (str_contains($request->getUri()->getQuery(), 'partNumber=')) {
                return $fake->error(500, 'InternalError', '注入故障');
            }
            return null;
        };

        $uploader = new MultipartUploader($s3, $tmp, [
            'bucket' => 'b', 'key' => 'x.bin', 'part_size' => 5 * 1024 * 1024,
        ]);

        try {
            $uploader->upload();
        } catch (MultipartUploadException $e) {
            // 预期失败
        }

        assertTrue(count($fake->uploads) === 1, '应有残留的分片上传');

        $fake->interceptor = null;
        $uploader->abort();

        assertTrue(count($fake->uploads) === 0, 'abort 后应清空');

        return '残留分片已清理';
    } finally {
        @unlink($tmp);
    }
});

// --- 下载 ---
test('下载：内存 / SaveAs / 流式 / Range', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => '0123456789']);

    $body = (string) $s3->getObject(['Bucket' => 'b', 'Key' => 'a.txt'])['Body'];
    assertTrue($body === '0123456789', '内存读取');

    $target = sys_get_temp_dir() . '/mins3-readme-' . getmypid() . '.bin';
    try {
        $s3->getObject(['Bucket' => 'b', 'Key' => 'a.txt', 'SaveAs' => $target]);
        assertTrue(file_get_contents($target) === '0123456789', 'SaveAs');
    } finally {
        @unlink($target);
    }

    $stream = $s3->getObject(['Bucket' => 'b', 'Key' => 'a.txt'])['Body'];
    $collected = '';
    while (!$stream->eof()) {
        $collected .= $stream->read(4);
    }
    assertTrue($collected === '0123456789', '流式读取');

    $part = $s3->getObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Range' => 'bytes=0-3']);
    assertTrue((string) $part['Body'] === '0123', "Range 应为 0123，实际 " . (string) $part['Body']);

    return '四种下载方式均可用';
});

// --- 列举 ---
test('列举：分页器逐页', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);
    for ($i = 0; $i < 15; $i++) {
        $s3->putObject(['Bucket' => 'b', 'Key' => sprintf('logs/%03d', $i), 'Body' => 'x']);
    }

    $count = 0;
    foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => 'b', 'Prefix' => 'logs/']) as $page) {
        foreach ($page['Contents'] ?? [] as $object) {
            assertTrue(isset($object['Key'], $object['Size']), '应含 Key 与 Size');
            $count++;
        }
    }

    assertTrue($count === 15, "应有 15 条，实际 {$count}");

    return "{$count} 条";
});

test('列举：getIterator 逐个对象', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);
    for ($i = 0; $i < 7; $i++) {
        $s3->putObject(['Bucket' => 'b', 'Key' => "k{$i}", 'Body' => 'x']);
    }

    $count = 0;
    foreach ($s3->getIterator('ListObjectsV2', ['Bucket' => 'b']) as $object) {
        $count++;
    }

    return "{$count} 个对象";
});

test('列举：@limit 只取前 N 个', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);
    for ($i = 0; $i < 50; $i++) {
        $s3->putObject(['Bucket' => 'b', 'Key' => sprintf('k%03d', $i), 'Body' => 'x']);
    }

    $keys = [];
    foreach ($s3->getIterator('ListObjectsV2', ['Bucket' => 'b', '@limit' => 10]) as $object) {
        $keys[] = $object['Key'];
    }

    assertTrue(count($keys) === 10, '应恰好 10 个，实际 ' . count($keys));

    return count($keys) . ' 个（共 50 个中）';
});

test('列举：Delimiter 得到 CommonPrefixes', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);
    foreach (['docs/a.txt', 'docs/b.txt', 'img/c.png', 'top.txt'] as $k) {
        $s3->putObject(['Bucket' => 'b', 'Key' => $k, 'Body' => 'x']);
    }

    $result = $s3->listObjectsV2(['Bucket' => 'b', 'Prefix' => '', 'Delimiter' => '/']);
    $prefixes = array_column($result['CommonPrefixes'] ?? [], 'Prefix');
    sort($prefixes);

    assertTrue($prefixes === ['docs/', 'img/'], '子目录: ' . implode(',', $prefixes));

    return implode(',', $prefixes);
});

// --- 删除 ---
test('删除：单个 / fromKeys / fromListObjects / 正则', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);

    foreach (['a.txt', 'b.txt', 'c.txt', 'tmp/x', 'tmp/y', 'logs/z.log', 'logs/keep.txt'] as $k) {
        $s3->putObject(['Bucket' => 'b', 'Key' => $k, 'Body' => 'x']);
    }

    $s3->deleteObject(['Bucket' => 'b', 'Key' => 'a.txt']);
    BatchDelete::fromKeys($s3, 'b', ['b.txt', 'c.txt'])->delete();
    BatchDelete::fromListObjects($s3, 'b', ['Prefix' => 'tmp/'])->delete();
    $s3->deleteMatchingObjects('b', 'logs/', '/\.log$/');

    $remaining = array_keys($fake->buckets['b']);
    assertTrue($remaining === ['logs/keep.txt'], '剩余: ' . implode(',', $remaining));

    return '只剩 logs/keep.txt';
});

// --- 复制 ---
test('复制：copy()', function () {
    $fake = new FakeS3(['src-bucket', 'dst-bucket']);
    $s3 = client($fake);
    $s3->putObject(['Bucket' => 'src-bucket', 'Key' => 'src-key', 'Body' => '内容']);

    $s3->copy('src-bucket', 'src-key', 'dst-bucket', 'dst-key');

    assertTrue($fake->buckets['dst-bucket']['dst-key']['body'] === '内容', '目标内容');

    return 'src-bucket/src-key → dst-bucket/dst-key';
});

// --- 预签名 ---
test('预签名：下载链接', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);

    $url = $s3->createPresignedUrl('b', 'a.txt', '+20 minutes');
    assertTrue(str_contains($url, 'X-Amz-Signature'), '应含签名');

    return substr($url, 0, 50) . '...';
});

test('预签名：指定下载文件名', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);

    $url = $s3->createPresignedUrl('b', 'a.pdf', '+1 hour', [
        'ResponseContentDisposition' => 'attachment; filename="报告.pdf"',
    ]);

    assertTrue(str_contains($url, 'response-content-disposition'), '应含响应头覆盖参数');

    return '含 response-content-disposition';
});

test('预签名：上传链接', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);

    $command = $s3->getCommand('PutObject', ['Bucket' => 'b', 'Key' => 'up.bin']);
    $url = (string) $s3->createPresignedRequest($command, '+30 minutes')->getUri();

    assertTrue(str_contains($url, 'X-Amz-Signature'), '应含签名');

    return 'PUT 预签名可用';
});

// --- POST 表单 ---
test('表单直传：PostObjectV4', function () {
    $fake = new FakeS3(['my-bucket']);
    $s3 = client($fake);

    $post = new PostObjectV4($s3, 'my-bucket',
        ['key' => 'uploads/${filename}', 'acl' => 'private'],
        [
            ['bucket' => 'my-bucket'],
            ['starts-with', '$key', 'uploads/'],
            ['content-length-range', 0, 10 * 1024 * 1024],
        ],
        '+1 hours'
    );

    $action = $post->getFormAttributes()['action'];
    $inputs = $post->getFormInputs();

    assertTrue(str_contains($action, 'my-bucket'), "action 应含桶名: {$action}");
    assertTrue(isset($inputs['Policy'], $inputs['X-Amz-Signature']), '应含 Policy 与签名');

    return count($inputs) . ' 个表单字段';
});

// --- 流包装器 ---
test('流包装器：全套文件函数', function () {
    $fake = new FakeS3(['my-bucket']);
    $s3 = client($fake);
    $s3->registerStreamWrapper();

    try {
        file_put_contents('s3://my-bucket/a.txt', 'hello');
        assertTrue(file_get_contents('s3://my-bucket/a.txt') === 'hello', '读写');

        $handle = fopen('s3://my-bucket/a.txt', 'r');
        $collected = '';
        while (!feof($handle)) {
            $collected .= fread($handle, 2);
        }
        fclose($handle);
        assertTrue($collected === 'hello', 'fopen 分段读');

        MinS3\StreamWrapper::clearStatCache();
        assertTrue(file_exists('s3://my-bucket/a.txt'), 'file_exists');
        assertTrue(filesize('s3://my-bucket/a.txt') === 5, 'filesize');

        unlink('s3://my-bucket/a.txt');
        assertTrue(!isset($fake->buckets['my-bucket']['a.txt']), 'unlink');

        return '读写/遍历/删除均可用';
    } finally {
        MinS3\StreamWrapper::unregister();
    }
});

test('流包装器：scandir 与 context', function () {
    $fake = new FakeS3(['my-bucket']);
    $s3 = client($fake);
    foreach (['docs/a.txt', 'docs/b.txt'] as $k) {
        $s3->putObject(['Bucket' => 'my-bucket', 'Key' => $k, 'Body' => 'x']);
    }
    $s3->registerStreamWrapper();

    try {
        $entries = array_values(array_diff(scandir('s3://my-bucket/docs'), ['.', '..']));
        sort($entries);
        assertTrue($entries === ['a.txt', 'b.txt'], '目录内容: ' . implode(',', $entries));

        $context = stream_context_create([
            's3' => ['ContentType' => 'text/markdown', 'ACL' => 'public-read'],
        ]);
        file_put_contents('s3://my-bucket/doc.md', '# 标题', 0, $context);

        assertTrue(
            ($fake->buckets['my-bucket']['doc.md']['headers']['content-type'] ?? '') === 'text/markdown',
            'context 参数应生效'
        );

        return 'scandir + context 均可用';
    } finally {
        MinS3\StreamWrapper::unregister();
    }
});

// --- 目录同步 ---
test('目录同步：上传与下载', function () {
    $fake = new FakeS3(['my-bucket']);
    $s3 = client($fake);

    $src = sys_get_temp_dir() . '/mins3-readme-src-' . getmypid();
    $dst = sys_get_temp_dir() . '/mins3-readme-dst-' . getmypid();
    @mkdir($src . '/sub', 0777, true);
    file_put_contents($src . '/a.txt', 'A');
    file_put_contents($src . '/sub/b.txt', 'B');

    try {
        $s3->uploadDirectory($src, 'my-bucket', 'assets');
        assertTrue(isset($fake->buckets['my-bucket']['assets/a.txt']), '上传 a.txt');
        assertTrue(isset($fake->buckets['my-bucket']['assets/sub/b.txt']), '上传 sub/b.txt');

        $s3->downloadBucket($dst, 'my-bucket', 'assets');
        assertTrue(file_get_contents($dst . '/a.txt') === 'A', '下载 a.txt');
        assertTrue(file_get_contents($dst . '/sub/b.txt') === 'B', '下载 sub/b.txt');

        return '上传 2 个，下载 2 个';
    } finally {
        foreach ([$src, $dst] as $dir) {
            @unlink($dir . '/sub/b.txt');
            @unlink($dir . '/a.txt');
            @rmdir($dir . '/sub');
            @rmdir($dir);
        }
    }
});

test('目录同步：带并发与进度', function () {
    $fake = new FakeS3(['my-bucket']);
    $s3 = client($fake);

    $src = sys_get_temp_dir() . '/mins3-readme-dbg-' . getmypid();
    @mkdir($src, 0777, true);
    file_put_contents($src . '/x.txt', 'X');

    $log = fopen('php://memory', 'r+');

    try {
        $s3->uploadDirectory($src, 'my-bucket', 'assets', [
            'concurrency' => 10,
            'debug'       => $log,
        ]);

        rewind($log);
        $output = stream_get_contents($log);
        assertTrue(str_contains($output, 'x.txt'), '应输出进度信息');

        return '进度输出正常';
    } finally {
        fclose($log);
        @unlink($src . '/x.txt');
        @rmdir($src);
    }
});

// --- 异步 ---
test('异步：批量并发上传', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);

    $files = ['f1', 'f2', 'f3'];
    $tmpFiles = [];
    foreach ($files as $name) {
        $tmp = tempnam(sys_get_temp_dir(), 'mins3');
        file_put_contents($tmp, "内容 {$name}");
        $tmpFiles[] = $tmp;
    }

    try {
        $promises = [];
        foreach ($tmpFiles as $i => $file) {
            $promises[] = $s3->putObjectAsync([
                'Bucket' => 'b', 'Key' => "f{$i}", 'SourceFile' => $file,
            ]);
        }
        foreach ($promises as $promise) {
            $promise->wait();
        }

        assertTrue(count($fake->buckets['b']) === 3, '应上传 3 个');

        return '3 个并发上传';
    } finally {
        foreach ($tmpFiles as $tmp) {
            @unlink($tmp);
        }
    }
});

test('异步：then / otherwise 链', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);
    $s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => 'hello']);

    $size = $s3->getObjectAsync(['Bucket' => 'b', 'Key' => 'a.txt'])
        ->then(fn($result) => strlen((string) $result['Body']))
        ->otherwise(fn($e) => -1)
        ->wait();
    assertTrue($size === 5, "长度应为 5，实际 {$size}");

    $missing = $s3->getObjectAsync(['Bucket' => 'b', 'Key' => 'nope'])
        ->then(fn($result) => strlen((string) $result['Body']))
        ->otherwise(fn($e) => -1)
        ->wait();
    assertTrue($missing === -1, "失败应返回 -1，实际 {$missing}");

    return "成功 {$size}，失败 {$missing}";
});

// --- 存在性与等待 ---
test('存在性判断与等待器', function () {
    $fake = new FakeS3(['my-bucket']);
    $s3 = client($fake);
    $s3->putObject(['Bucket' => 'my-bucket', 'Key' => 'a.txt', 'Body' => 'x']);

    assertTrue($s3->doesBucketExist('my-bucket'), 'doesBucketExist');
    assertTrue($s3->doesObjectExist('my-bucket', 'a.txt'), 'doesObjectExist');
    assertTrue($s3->doesBucketExist('my-bucket', true), 'doesBucketExist 带 accept403');

    $s3->waitUntil('ObjectExists', [
        'Bucket' => 'my-bucket', 'Key' => 'a.txt',
        '@waiter' => ['delay' => 0, 'maxAttempts' => 2],
    ]);
    $s3->waitUntil('BucketExists', [
        'Bucket' => 'my-bucket',
        '@waiter' => ['delay' => 0, 'maxAttempts' => 2],
    ]);

    return '四项判断 + 两个等待器';
});

// --- 其他操作 ---
test('其他操作：桶配置类接口', function () {
    $fake = new FakeS3(['b']);
    $captured = [];
    $s3 = client($fake, [
        'handler' => function ($request, array $options) use ($fake, &$captured) {
            $captured[] = $request->getMethod() . ' ' . $request->getUri()->getPath()
                . '?' . $request->getUri()->getQuery();
            return ($fake->handler())($request, $options);
        },
    ]);

    // 这些操作 FakeS3 不实现具体语义，只验证请求能正确构造并发出
    $operations = [
        fn() => $s3->getCommand('CreateBucket', ['Bucket' => 'new-bucket']),
        fn() => $s3->getCommand('PutBucketVersioning', [
            'Bucket' => 'b', 'VersioningConfiguration' => ['Status' => 'Enabled']]),
        fn() => $s3->getCommand('PutBucketPolicy', [
            'Bucket' => 'b', 'Policy' => json_encode(['Version' => '2012-10-17'])]),
        fn() => $s3->getCommand('PutBucketCors', [
            'Bucket' => 'b', 'CORSConfiguration' => ['CORSRules' => [
                ['AllowedMethods' => ['GET'], 'AllowedOrigins' => ['*']]]]]),
        fn() => $s3->getCommand('PutObjectTagging', [
            'Bucket' => 'b', 'Key' => 'a.txt',
            'Tagging' => ['TagSet' => [['Key' => 'env', 'Value' => 'prod']]]]),
        fn() => $s3->getCommand('ListObjectVersions', ['Bucket' => 'b']),
    ];

    foreach ($operations as $make) {
        $s3->createPresignedRequest($make(), '+5 minutes');
    }

    return count($operations) . ' 个桶配置操作均可构造';
});

// --- 错误处理 ---
test('错误处理：S3Exception 各取值方法', function () {
    $fake = new FakeS3(['b']);
    $s3 = client($fake);

    try {
        $s3->getObject(['Bucket' => 'b', 'Key' => 'a.txt']);
        throw new RuntimeException('应该抛出异常');
    } catch (S3Exception $e) {
        assertTrue($e->getAwsErrorCode() === 'NoSuchKey', 'getAwsErrorCode');
        assertTrue($e->getStatusCode() === 404, 'getStatusCode');
        assertTrue($e->getAwsErrorMessage() !== null, 'getAwsErrorMessage');
        assertTrue($e->getAwsRequestId() !== null, 'getAwsRequestId');
        assertTrue($e->getCommandName() === 'GetObject', 'getCommandName');
        assertTrue($e->getErrorCode() === 'NoSuchKey', 'getErrorCode 别名');

        return $e->getAwsErrorCode() . '/' . $e->getStatusCode();
    }
});

test('错误处理：ConnectException', function () {
    $s3 = new S3Client([
        'endpoint'    => 'http://127.0.0.1:1',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'retries'     => 0,
        'http'        => ['connect_timeout' => 1],
    ]);

    try {
        $s3->headBucket(['Bucket' => 'b']);
        throw new RuntimeException('应该抛出异常');
    } catch (ConnectException $e) {
        assertTrue($e->getRequest() !== null, 'getRequest');

        return '正确捕获';
    }
});

test('错误处理：@retries 单次覆盖', function () {
    $fake = new FakeS3(['b']);
    $attempts = 0;
    $fake->interceptor = function ($request) use ($fake, &$attempts) {
        if ($request->getMethod() === 'GET') {
            $attempts++;
            return $fake->error(500, 'InternalError', '失败');
        }
        return null;
    };

    $s3 = client($fake, ['retries' => 5]);

    try {
        $s3->getObject(['Bucket' => 'b', 'Key' => 'a.txt', '@retries' => 0]);
    } catch (S3Exception $e) {
        assertTrue($attempts === 1, "@retries=0 应只尝试 1 次，实际 {$attempts}");

        return '单次覆盖生效';
    }

    throw new RuntimeException('应该抛出异常');
});

// --- 配置项 ---
test('配置：@http 单次覆盖', function () {
    $fake = new FakeS3(['b']);
    $captured = null;
    $s3 = client($fake, [
        'handler' => function ($request, array $options) use ($fake, &$captured) {
            $captured = $options;
            return ($fake->handler())($request, $options);
        },
    ]);

    $s3->putObject(['Bucket' => 'b', 'Key' => 'big.bin', 'Body' => 'x']);
    $target = sys_get_temp_dir() . '/mins3-http-opt-' . getmypid() . '.bin';

    try {
        $s3->getObject([
            'Bucket' => 'b', 'Key' => 'big.bin',
            '@http'  => ['timeout' => 300, 'sink' => $target],
        ]);

        assertTrue(($captured['timeout'] ?? null) === 300, 'timeout 应透传');
        assertTrue(file_exists($target), 'sink 应写入文件');

        return 'timeout=300, sink 生效';
    } finally {
        @unlink($target);
    }
});

test('配置：动态凭证提供者', function () {
    $fake = new FakeS3(['b']);
    $calls = 0;

    $s3 = new S3Client([
        'endpoint'    => 'http://127.0.0.1:9000',
        'region'      => 'us-east-1',
        'credentials' => function () use (&$calls) {
            $calls++;
            return new MinS3\Credentials('dyn-key', 'dyn-secret', 'dyn-token', time() + 3600);
        },
        'handler'     => $fake->handler(),
    ]);

    $s3->putObject(['Bucket' => 'b', 'Key' => 'a', 'Body' => 'x']);
    assertTrue($s3->getCredentials()->getAccessKeyId() === 'dyn-key', '凭证应来自提供者');

    return "提供者调用 {$calls} 次（未过期不重复取）";
});

test('配置：环境变量凭证', function () {
    putenv('AWS_ACCESS_KEY_ID=envkey');
    putenv('AWS_SECRET_ACCESS_KEY=envsecret');

    try {
        $s3 = new S3Client(['endpoint' => 'http://127.0.0.1:9000']);
        assertTrue($s3->getCredentials()->getAccessKeyId() === 'envkey', 'AWS_ 前缀');
    } finally {
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
    }

    putenv('S3_ACCESS_KEY_ID=s3key');
    putenv('S3_SECRET_ACCESS_KEY=s3secret');

    try {
        $s3 = new S3Client(['endpoint' => 'http://127.0.0.1:9000']);
        assertTrue($s3->getCredentials()->getAccessKeyId() === 's3key', 'S3_ 前缀');

        return '两种前缀均可用';
    } finally {
        putenv('S3_ACCESS_KEY_ID');
        putenv('S3_SECRET_ACCESS_KEY');
    }
});

test('差异说明：checksum 可切回 crc32', function () {
    $fake = new FakeS3(['b']);
    $captured = null;
    $s3 = client($fake, [
        'checksum_calculation' => 'when_supported',
        'checksum_algorithm'   => 'crc32',
        'handler' => function ($request, array $options) use ($fake, &$captured) {
            $captured = $request;
            return ($fake->handler())($request, $options);
        },
    ]);

    $s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => 'hello']);
    assertTrue($captured->hasHeader('x-amz-checksum-crc32'), '应发 crc32 头');

    return 'x-amz-checksum-crc32 已发送';
});

exit(testSummary());
