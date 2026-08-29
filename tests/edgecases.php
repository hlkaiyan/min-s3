<?php
/**
 * 边界与失效场景。
 *
 * 这里的用例都来自实际找出来的缺陷 —— 每一条对应一个曾经真实存在
 * 的 bug，留下来防止改动时重新引入。
 *
 * 用法: php tests/edgecases.php
 */
require __DIR__ . '/bootstrap.php';

use MinS3\Http\LimitStream;
use MinS3\Http\Response;
use MinS3\Http\Stream;
use MinS3\Multipart\MultipartUploader;
use MinS3\Promise\Promise;
use MinS3\S3Client;

function client(callable $handler, array $extra = []): S3Client
{
    return new S3Client($extra + [
        'endpoint'    => 'http://127.0.0.1:9000',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler'     => $handler,
    ]);
}

function errorXml(string $code): string
{
    return '<?xml version="1.0"?><Error><Code>' . $code
         . '</Code><Message>injected</Message></Error>';
}

echo "=== 重试的副作用 ===\n";

test('sink 为 Stream 对象时，重试不会重复写入', function () {
    $attempt = 0;
    $s3 = client(function ($request, array $options) use (&$attempt) {
        $attempt++;
        // 模拟服务端在失败前already吐了一部分数据
        if (isset($options['sink']) && $options['sink'] instanceof Stream) {
            $options['sink']->write('CHUNK');
        }

        return Promise::resolved($attempt === 1
            ? new Response(500, [], errorXml('InternalError'))
            : new Response(200, ['Content-Length' => '5'], ''));
    }, ['retries' => 2]);

    $sink = Stream::create('');
    $s3->getObject(['Bucket' => 'b', 'Key' => 'k', '@http' => ['sink' => $sink]]);

    $sink->rewind();
    $content = (string) $sink;

    assertSame(2, $attempt, '应当重试一次');
    assertSame('CHUNK', $content, '重试前必须清空 sink，否则内容会拼接成 CHUNKCHUNK');

    return "重试 {$attempt} 次，sink 内容 '{$content}'";
});

test('消息体不可定位时拒绝重试，而不是发出空内容', function () {
    $bodies = [];
    $s3 = client(function ($request, array $options) use (&$bodies) {
        $bodies[] = (string) $request->getBody();

        return Promise::resolved(new Response(503, [], errorXml('ServiceUnavailable')));
    }, ['retries' => 3]);

    $pipe = popen(PHP_OS_FAMILY === 'Windows' ? 'echo HELLO' : 'printf HELLO', 'r');
    assertTrue($pipe !== false, '需要能创建管道');

    try {
        $s3->putObject(['Bucket' => 'b', 'Key' => 'k', 'Body' => new Stream($pipe)]);
        throw new RuntimeException('应当抛出异常');
    } catch (\MinS3\Exception\S3Exception $e) {
        // 关键：只能发送一次。管道读完就没了，重发出去的是空内容，
        // 服务端会存下一个空对象而调用方以为成功了
        assertSame(1, count($bodies), '不可定位的消息体只允许发送一次');
        assertSame('HELLO', trim($bodies[0]), '首次发送的内容应完整');

        return '只发送 1 次，未产生空内容重传';
    }
});

test('可定位的消息体重试时内容完整', function () {
    $bodies = [];
    $attempt = 0;
    $s3 = client(function ($request, array $options) use (&$bodies, &$attempt) {
        $attempt++;
        $bodies[] = (string) $request->getBody();

        return Promise::resolved($attempt === 1
            ? new Response(503, [], errorXml('ServiceUnavailable'))
            : new Response(200, ['ETag' => '"e"'], ''));
    }, ['retries' => 2]);

    $s3->putObject(['Bucket' => 'b', 'Key' => 'k', 'Body' => 'HELLO']);

    assertSame(2, count($bodies), '应当发送两次');
    assertSame($bodies[0], $bodies[1], '两次内容必须一致');
    assertSame('HELLO', $bodies[1], '重试内容应完整');

    return "两次均为 '{$bodies[1]}'";
});

echo "\n=== 分页器终止条件 ===\n";

test('服务端回显相同令牌时不死循环', function () {
    $calls = 0;
    $s3 = client(function ($request, array $options) use (&$calls) {
        $calls++;

        // 有些 S3 实现在最后一页会把同一个 token 原样回显，
        // 只看 IsTruncated 会无限翻下去
        return Promise::resolved(new Response(200, [], '<?xml version="1.0"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<IsTruncated>true</IsTruncated>'
            . '<NextContinuationToken>SAME</NextContinuationToken>'
            . '<Contents><Key>k1</Key></Contents>'
            . '</ListBucketResult>'));
    });

    $pages = 0;
    foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => 'b']) as $page) {
        if (++$pages > 20) {
            throw new RuntimeException("翻了 {$pages} 页仍未终止");
        }
    }

    assertTrue($pages <= 3, "应当很快终止，实际翻了 {$pages} 页");

    return "{$pages} 页后停止";
});

echo "\n=== 头部注入 ===\n";

test('元数据值含 CRLF 被拒绝', function () {
    $s3 = client(fn($r, $o) => Promise::resolved(new Response(200, ['ETag' => '"e"'], '')));

    try {
        $s3->putObject([
            'Bucket'   => 'b',
            'Key'      => 'k',
            'Body'     => 'x',
            'Metadata' => ['evil' => "value\r\nX-Injected: yes"],
        ]);
        throw new RuntimeException('应当拒绝含换行符的头部值');
    } catch (\InvalidArgumentException $e) {
        assertTrue(
            str_contains($e->getMessage(), '换行') || str_contains($e->getMessage(), '注入'),
            '异常信息应说明原因，实际: ' . $e->getMessage()
        );

        return '已拒绝';
    }
});

test('头部值含空字节被拒绝', function () {
    $request = new MinS3\Http\Request('GET', 'http://example.com');

    try {
        $request->withHeader('X-Test', "a\x00b");
        throw new RuntimeException('应当拒绝含空字节的头部值');
    } catch (\InvalidArgumentException $e) {
        return '已拒绝';
    }
});

test('非法头部名称被拒绝', function () {
    $request = new MinS3\Http\Request('GET', 'http://example.com');
    $rejected = 0;

    foreach (['X-Bad Name', "X-Bad\r\nInject", '', 'X:Bad'] as $name) {
        try {
            $request->withHeader($name, 'v');
        } catch (\InvalidArgumentException $e) {
            $rejected++;
        }
    }

    assertSame(4, $rejected, '四个非法头名都应被拒绝');

    return '4/4 已拒绝';
});

test('正常的元数据不受影响', function () {
    $captured = null;
    $s3 = client(function ($request, array $options) use (&$captured) {
        $captured = $request;

        return Promise::resolved(new Response(200, ['ETag' => '"e"'], ''));
    });

    $s3->putObject([
        'Bucket'   => 'b',
        'Key'      => 'k',
        'Body'     => 'x',
        'Metadata' => ['author' => 'zhang san', 'note' => '含空格与标点: ok!'],
    ]);

    assertSame('zhang san', $captured->getHeaderLine('x-amz-meta-author'), 'author');
    assertSame('含空格与标点: ok!', $captured->getHeaderLine('x-amz-meta-note'), 'note');

    return '带空格与标点的值正常通过';
});

echo "\n=== 响应解析边界 ===\n";

test('空响应体不崩溃', function () {
    $s3 = client(fn($r, $o) => Promise::resolved(new Response(200, [], '')));
    $result = $s3->listObjectsV2(['Bucket' => 'b']);

    return 'Contents=' . json_encode($result['Contents'] ?? null);
});

test('响应体带 BOM 仍能解析', function () {
    // 部分反向代理会在响应前加 BOM
    $s3 = client(fn($r, $o) => Promise::resolved(new Response(200, [],
        "\xEF\xBB\xBF" . '<?xml version="1.0"?>'
        . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<IsTruncated>false</IsTruncated><Contents><Key>k</Key></Contents></ListBucketResult>')));

    $keys = array_column($s3->listObjectsV2(['Bucket' => 'b'])['Contents'] ?? [], 'Key');
    assertSame(['k'], $keys, '应正常解析出 key');

    return '正常解析';
});

test('截断的 XML 抛出解析异常而非静默返回空', function () {
    $s3 = client(
        fn($r, $o) => Promise::resolved(new Response(200, [],
            '<?xml version="1.0"?><ListBucketResult><Contents><Key>k')),
        ['retries' => 0]
    );

    try {
        $s3->listObjectsV2(['Bucket' => 'b']);
        throw new RuntimeException('应当抛出解析异常');
    } catch (\MinS3\Api\Parser\ParserException $e) {
        return 'ParserException';
    }
});

echo "\n=== 分片上传边界 ===\n";

test('part_size 小于下限时被纠正到 5 MB', function () {
    $parts = [];
    $s3 = client(function ($request, array $options) use (&$parts) {
        $query = $request->getUri()->getQuery();

        if (str_contains($query, 'uploads')) {
            return Promise::resolved(new Response(200, [], '<?xml version="1.0"?>'
                . '<InitiateMultipartUploadResult><UploadId>U</UploadId>'
                . '</InitiateMultipartUploadResult>'));
        }
        if (str_contains($query, 'partNumber')) {
            $parts[] = strlen((string) $request->getBody());

            return Promise::resolved(new Response(200, ['ETag' => '"p"'], ''));
        }

        return Promise::resolved(new Response(200, [], '<?xml version="1.0"?>'
            . '<CompleteMultipartUploadResult><ETag>"f"</ETag>'
            . '</CompleteMultipartUploadResult>'));
    });

    $tmp = tempnam(sys_get_temp_dir(), 'edge');
    file_put_contents($tmp, str_repeat('x', 6 * 1024 * 1024));

    try {
        (new MultipartUploader($s3, $tmp, [
            'bucket' => 'b', 'key' => 'k', 'part_size' => 1024,
        ]))->upload();

        // S3 只要求「除最后一片外」每片 >= 5 MB
        $exceptLast = array_slice($parts, 0, -1);
        foreach ($exceptLast as $i => $size) {
            assertTrue(
                $size >= 5 * 1024 * 1024,
                "第 " . ($i + 1) . " 片只有 {$size} 字节，小于 5 MB 下限"
            );
        }

        return implode(' + ', array_map(
            static fn(int $n): string => round($n / 1048576, 2) . 'MB',
            $parts
        ));
    } finally {
        @unlink($tmp);
    }
});

test('空源明确报错而非静默成功', function () {
    $s3 = client(fn($r, $o) => Promise::resolved(new Response(200, [], '<?xml version="1.0"?>'
        . '<InitiateMultipartUploadResult><UploadId>U</UploadId></InitiateMultipartUploadResult>')));

    try {
        (new MultipartUploader($s3, '', ['bucket' => 'b', 'key' => 'k']))->upload();
        throw new RuntimeException('空源应当报错');
    } catch (\MinS3\Exception\MultipartUploadException $e) {
        return '已报错';
    }
});

echo "\n=== Promise 行为 ===\n";

test('未落定的 promise 抛出 LogicException', function () {
    $p = new Promise(function (Promise $self): void {
        // 故意不 settle
    });

    try {
        $p->wait();
        throw new RuntimeException('应当抛出 LogicException');
    } catch (\LogicException $e) {
        return '已抛出';
    }
});

test('深层 then 链不栈溢出', function () {
    $p = Promise::resolved(0);
    for ($i = 0; $i < 2000; $i++) {
        $p = $p->then(static fn(int $v): int => $v + 1);
    }

    assertSame(2000, $p->wait(), '链式累加结果');

    return '2000 层正常';
});

test('拒绝以自身作为结果', function () {
    $p = new Promise();

    try {
        $p->resolve($p);
        throw new RuntimeException('应当拒绝');
    } catch (\LogicException $e) {
        return '已拒绝';
    }
});

echo "\n=== 流对象边界 ===\n";

test('LimitStream 代理父类方法不出错', function () {
    // LimitStream 继承 Stream 但不调用 parent::__construct，
    // 父类属性全靠默认值兜底
    $limited = new LimitStream(Stream::create('0123456789'), 5, 2);

    assertSame('23456', $limited->read(5), '窗口内容');
    assertSame(5, $limited->getSize(), '窗口长度');
    assertTrue($limited->eof(), '读满后应为 eof');

    $limited->rewind();   // 父类方法，内部调用被覆盖的 seek
    assertSame('23', $limited->read(2), 'rewind 后重新读');
    assertTrue(is_array($limited->getMetadata()), 'getMetadata 应返回数组');

    return '窗口读取与 rewind 均正常';
});

test('已关闭的流拒绝读取', function () {
    $s = Stream::create('abc');
    $s->close();

    try {
        $s->read(1);
        throw new RuntimeException('应当抛出异常');
    } catch (\RuntimeException $e) {
        return '已拒绝';
    }
});

test('truncate 清空内容', function () {
    $s = Stream::create('0123456789');
    $s->rewind();
    $s->truncate(0);
    $s->rewind();

    assertSame('', (string) $s, '截断后应为空');
    assertSame(0, $s->getSize(), '长度应为 0');

    return '已清空';
});

echo "\n=== 请求目标不被改写 ===\n";

test('bucket 名不被路径规范化悄悄改写', function () {
    $captured = null;
    $s3 = client(function ($request, array $options) use (&$captured) {
        $captured = $request;

        return Promise::resolved(new Response(200, ['ETag' => '"e"'], ''));
    });

    $s3->putObject(['Bucket' => 'b/../other', 'Key' => 'k', 'Body' => 'x']);
    $path = $captured->getUri()->getPath();

    // 关键：客户端不能把 b/../other 解析成 other —— 那会让请求打到
    // 调用方没有指定的桶上。原样编码后交给服务端拒绝才是正确行为
    assertTrue(
        !str_contains($path, '/other/') || str_contains($path, '%2F'),
        "路径被改写成了 {$path}"
    );

    return "path 保持为 {$path}";
});

exit(testSummary());
