# min-s3

[![测试](https://github.com/hlkaiyan/min-s3/actions/workflows/tests.yml/badge.svg)](https://github.com/hlkaiyan/min-s3/actions/workflows/tests.yml)
[![最新版本](https://img.shields.io/packagist/v/hlkaiyan/min-s3)](https://packagist.org/packages/hlkaiyan/min-s3)
[![PHP 版本](https://img.shields.io/packagist/dependency-v/hlkaiyan/min-s3/php)](https://packagist.org/packages/hlkaiyan/min-s3)
[![许可](https://img.shields.io/packagist/l/hlkaiyan/min-s3)](LICENSE)

面向自建 S3 服务器（MinIO / Ceph RGW / SeaweedFS / 其他 S3 兼容存储）的轻量 PHP 客户端。

**零第三方依赖**，只用 PHP 内置扩展。用法与 `aws/aws-sdk-php` 的 `S3Client` 保持一致，从原 SDK 迁移基本不用改调用代码。

```php
$s3 = new MinS3\S3Client([
    'endpoint'    => 'http://127.0.0.1:9000',
    'region'      => 'us-east-1',
    'credentials' => ['key' => 'minioadmin', 'secret' => 'minioadmin'],
]);

$s3->putObject(['Bucket' => 'my-bucket', 'Key' => 'a.txt', 'Body' => 'hello']);
echo $s3->getObject(['Bucket' => 'my-bucket', 'Key' => 'a.txt'])['Body'];
```

---

## 为什么不直接用 aws-sdk-php

以下是 `src/` 目录的实测数据：

|                | aws/aws-sdk-php | 只抽 S3 的版本 | **min-s3** |
|----------------|----------------:|--------------:|-----------:|
| 自身文件数      | 3478            | 412           | **51**     |
| 自身体积        | 47.2 MB         | 3.4 MB        | **529 KB** |
| 第三方依赖包    | 6               | 6             | **0**      |
| 第三方依赖体积  | 2.0 MB          | 2.0 MB        | **0**      |
| 装完的总体积    | 49.2 MB         | 5.4 MB        | **529 KB** |
| 支持的 S3 操作  | 116             | 116           | **116**    |

操作数一个没少：min-s3 复用了 aws-sdk-php 的 S3 接口模型文件（`src/data/api-2.json.php`，267 KB），
由它驱动请求的序列化与响应解析。因此 116 个 S3 操作的**参数名、类型、位置全部与官方 SDK 一致**，
不是手写的一小撮常用接口。

去掉的是 AWS 专有设施：区域端点表、凭证链（IMDS / ECS / SSO / AssumeRole）、
接入点 ARN 寻址、S3 Express、传输加速、双栈与 FIPS 端点、客户端加密。
这些在自建 S3 场景下用不到。

---

## 环境要求

- PHP >= 8.1
- 扩展：`curl`、`simplexml`、`json`、`xmlwriter`（一般都是默认启用的）

## 安装

```bash
composer require hlkaiyan/min-s3
```

本地目录安装（调试时用，改动立即生效）：

```json
{
    "repositories": [
        { "type": "path", "url": "./min-s3" }
    ],
    "require": {
        "hlkaiyan/min-s3": "*"
    }
}
```

### 不使用 Composer

把整个 `min-s3` 目录放进项目，引入自带的加载器即可。因为没有第三方依赖，
不需要额外准备任何东西：

```php
require __DIR__ . '/min-s3/autoload.php';

$s3 = new MinS3\S3Client([...]);
```

---

## 对接自建 S3 服务器

```php
use MinS3\S3Client;

$s3 = new S3Client([
    'endpoint'    => 'http://127.0.0.1:9000',   // 必填
    'region'      => 'us-east-1',               // 必填，见下方说明
    'credentials' => [
        'key'    => 'minioadmin',
        'secret' => 'minioadmin',
    ],
]);
```

**关于 `region`**：自建服务通常不校验区域，但它是 SigV4 签名范围的一部分，
客户端和服务端算出来的必须一致，所以不能省略。服务端没特殊配置就填 `us-east-1`。

**关于寻址方式**：min-s3 默认用**路径式**（`http://host/bucket/key`），
这与 aws-sdk-php 的默认值相反 —— 自建服务多数不支持虚拟主机式寻址。
如果你的服务配了泛域名解析，可以切换：

```php
'use_path_style_endpoint' => false,   // 变成 http://bucket.host/key
```

端点是 IP 地址时会自动退回路径式（`bucket.127.0.0.1` 无法解析），无需手动处理。

**关于自签名证书**：内网 HTTPS 常用自签证书，可以指定 CA 或关闭校验：

```php
'http' => [
    'verify' => '/path/to/ca-bundle.crt',   // 指定 CA
    // 'verify' => false,                   // 关闭校验，仅限可信内网
],
```

---

## 常用操作

### 上传

```php
// 字符串
$s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'Body' => 'hello']);

// 本地文件（不读进内存）
$s3->putObject(['Bucket' => 'b', 'Key' => 'a.txt', 'SourceFile' => '/path/a.txt']);

// 流
$s3->putObject([
    'Bucket' => 'b', 'Key' => 'a.bin',
    'Body'   => MinS3\Http\Stream::open('/path/a.bin', 'r'),
]);

// 附带元数据与其他参数
$s3->putObject([
    'Bucket'      => 'b',
    'Key'         => 'report.pdf',
    'SourceFile'  => '/path/report.pdf',
    'ContentType' => 'application/pdf',
    'ACL'         => 'public-read',
    'Metadata'    => ['author' => 'zhang', 'version' => '2'],
    'CacheControl'=> 'max-age=3600',
]);
```

`upload()` 会按大小自动选择整体上传或分片上传（默认阈值 16 MB）：

```php
$s3->upload('my-bucket', 'big.zip', fopen('/path/big.zip', 'r'));
```

### 大文件分片上传

```php
use MinS3\Multipart\MultipartUploader;

$uploader = new MultipartUploader($s3, '/path/big.bin', [
    'bucket'      => 'my-bucket',
    'key'         => 'big.bin',
    'part_size'   => 8 * 1024 * 1024,   // 分片大小，最小 5 MB
    'concurrency' => 4,                 // 并发分片数
]);

$result = $uploader->upload();
```

分片是真并发（`curl_multi`），且源文件按需分段读取，内存占用与文件大小无关。

**断点续传**：失败时从异常里取出状态，之后接着传，已完成的分片不会重传。

```php
use MinS3\Exception\MultipartUploadException;

try {
    $uploader->upload();
} catch (MultipartUploadException $e) {
    $state = $e->getState();
    file_put_contents('/tmp/upload.state', serialize($state));

    // 稍后续传
    $state = unserialize(file_get_contents('/tmp/upload.state'));
    (new MultipartUploader($s3, '/path/big.bin', [
        'bucket' => 'my-bucket', 'key' => 'big.bin', 'state' => $state,
    ]))->upload();
}
```

放弃上传时记得清理，否则残留分片会一直占用空间：

```php
$uploader->abort();
```

### 下载

```php
// 读进内存
$body = (string) $s3->getObject(['Bucket' => 'b', 'Key' => 'a.txt'])['Body'];

// 直接落盘，不经过内存
$s3->getObject(['Bucket' => 'b', 'Key' => 'big.bin', 'SaveAs' => '/path/big.bin']);

// 流式分段读
$stream = $s3->getObject(['Bucket' => 'b', 'Key' => 'big.bin'])['Body'];
while (!$stream->eof()) {
    echo $stream->read(8192);
}

// 断点续传 / 部分下载
$part = $s3->getObject(['Bucket' => 'b', 'Key' => 'big.bin', 'Range' => 'bytes=0-1023']);
```

### 列举与翻页

单次列举最多返回 1000 条，用分页器自动翻页：

```php
// 逐页
foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => 'b', 'Prefix' => 'logs/']) as $page) {
    foreach ($page['Contents'] ?? [] as $object) {
        echo $object['Key'], ' ', $object['Size'], "\n";
    }
}

// 逐个对象，省掉一层循环
foreach ($s3->getIterator('ListObjectsV2', ['Bucket' => 'b']) as $object) {
    echo $object['Key'], "\n";
}

// 只取前 100 个
foreach ($s3->getIterator('ListObjectsV2', ['Bucket' => 'b', '@limit' => 100]) as $object) {
    echo $object['Key'], "\n";
}
```

按"目录"列举：

```php
$result = $s3->listObjectsV2(['Bucket' => 'b', 'Prefix' => 'docs/', 'Delimiter' => '/']);

foreach ($result['CommonPrefixes'] ?? [] as $prefix) {
    echo "子目录: ", $prefix['Prefix'], "\n";
}
foreach ($result['Contents'] ?? [] as $object) {
    echo "文件: ", $object['Key'], "\n";
}
```

### 删除

```php
$s3->deleteObject(['Bucket' => 'b', 'Key' => 'a.txt']);

// 批量删除，自动按 1000 个一批
use MinS3\BatchDelete;

BatchDelete::fromKeys($s3, 'b', ['a.txt', 'b.txt', 'c.txt'])->delete();
BatchDelete::fromListObjects($s3, 'b', ['Prefix' => 'tmp/'])->delete();

// 按正则删除
$s3->deleteMatchingObjects('b', 'logs/', '/\.log$/');
```

### 复制

```php
$s3->copy('src-bucket', 'src-key', 'dst-bucket', 'dst-key');
```

超过 50 MB 自动改用分片复制（S3 单次复制上限 5 GB）。数据在服务端流转，不经过本机。

### 预签名 URL

生成临时链接，不暴露密钥。生成过程是纯本地计算，不发请求：

```php
// 下载链接
$url = $s3->createPresignedUrl('b', 'a.txt', '+20 minutes');

// 强制浏览器下载并指定文件名
$url = $s3->createPresignedUrl('b', 'a.pdf', '+1 hour', [
    'ResponseContentDisposition' => 'attachment; filename="报告.pdf"',
]);

// 上传链接：前端可以直接 PUT 到这个地址
$command = $s3->getCommand('PutObject', ['Bucket' => 'b', 'Key' => 'up.bin']);
$url = (string) $s3->createPresignedRequest($command, '+30 minutes')->getUri();
```

有效期上限是 7 天（SigV4 的规定）。

### 浏览器表单直传

文件从浏览器直接传到 S3，不经过你的服务器：

```php
use MinS3\PostObjectV4;

$post = new PostObjectV4($s3, 'my-bucket',
    ['key' => 'uploads/${filename}', 'acl' => 'private'],
    [
        ['bucket' => 'my-bucket'],
        ['starts-with', '$key', 'uploads/'],
        ['content-length-range', 0, 10 * 1024 * 1024],   // 限制 10 MB
    ],
    '+1 hours'
);
?>
<form action="<?= htmlspecialchars($post->getFormAttributes()['action']) ?>"
      method="POST" enctype="multipart/form-data">
    <?php foreach ($post->getFormInputs() as $name => $value): ?>
        <input type="hidden" name="<?= htmlspecialchars($name) ?>"
               value="<?= htmlspecialchars($value) ?>">
    <?php endforeach; ?>
    <input type="file" name="file">
    <button type="submit">上传</button>
</form>
```

### s3:// 流包装器

注册后可以用 PHP 原生文件函数操作对象：

```php
$s3->registerStreamWrapper();

file_put_contents('s3://my-bucket/a.txt', 'hello');
echo file_get_contents('s3://my-bucket/a.txt');

$handle = fopen('s3://my-bucket/big.bin', 'r');
while (!feof($handle)) {
    echo fread($handle, 8192);
}
fclose($handle);

file_exists('s3://my-bucket/a.txt');
filesize('s3://my-bucket/a.txt');
unlink('s3://my-bucket/a.txt');

foreach (scandir('s3://my-bucket/docs') as $name) {
    echo $name, "\n";
}
```

写入时通过 context 传额外参数：

```php
$context = stream_context_create(['s3' => ['ContentType' => 'text/markdown', 'ACL' => 'public-read']]);
file_put_contents('s3://my-bucket/doc.md', '# 标题', 0, $context);
```

注意 S3 不支持追加写，`'a'` 模式不可用；写入在 `fclose` 时才真正提交。

### 目录同步

```php
// 上传整个目录
$s3->uploadDirectory('/var/www/assets', 'my-bucket', 'assets');

// 下载到本地目录
$s3->downloadBucket('/var/www/assets', 'my-bucket', 'assets');

// 带进度输出与并发控制
$s3->uploadDirectory('/var/www/assets', 'my-bucket', 'assets', [
    'concurrency' => 10,
    'debug'       => true,
]);
```

### 异步与并发

每个操作都有 `Async` 变体，返回 Promise。多个未 `wait()` 的请求是真并发：

```php
$promises = [];
foreach ($files as $i => $file) {
    $promises[] = $s3->putObjectAsync([
        'Bucket' => 'b', 'Key' => "f{$i}", 'SourceFile' => $file,
    ]);
}
foreach ($promises as $promise) {
    $promise->wait();
}
```

支持链式处理：

```php
$size = $s3->getObjectAsync(['Bucket' => 'b', 'Key' => 'a.txt'])
    ->then(fn($result) => strlen((string) $result['Body']))
    ->otherwise(fn($e) => -1)
    ->wait();
```

### 存在性判断与等待

```php
$s3->doesBucketExist('my-bucket');
$s3->doesObjectExist('my-bucket', 'a.txt');

// 第二个参数为 true 时，403（有桶但无权限）也算存在
$s3->doesBucketExist('my-bucket', true);

// 轮询等待
$s3->waitUntil('ObjectExists', ['Bucket' => 'b', 'Key' => 'a.txt']);
$s3->waitUntil('BucketExists', ['Bucket' => 'b']);
```

### 其他操作

116 个 S3 操作都可以直接调用，方法名是操作名首字母小写：

```php
$s3->createBucket(['Bucket' => 'new-bucket']);
$s3->putBucketVersioning(['Bucket' => 'b', 'VersioningConfiguration' => ['Status' => 'Enabled']]);
$s3->putBucketPolicy(['Bucket' => 'b', 'Policy' => json_encode($policy)]);
$s3->putBucketCors(['Bucket' => 'b', 'CORSConfiguration' => ['CORSRules' => [...]]]);
$s3->putObjectTagging(['Bucket' => 'b', 'Key' => 'a.txt', 'Tagging' => ['TagSet' => [...]]]);
$s3->listObjectVersions(['Bucket' => 'b']);
```

参数名与 [AWS S3 API 文档](https://docs.aws.amazon.com/AmazonS3/latest/API/API_Operations_Amazon_Simple_Storage_Service.html)
及 aws-sdk-php 完全一致。具体某个自建服务支持哪些操作，取决于它自己的实现程度。

---

## 错误处理

```php
use MinS3\Exception\S3Exception;
use MinS3\Exception\ConnectException;

try {
    $s3->getObject(['Bucket' => 'b', 'Key' => 'a.txt']);
} catch (S3Exception $e) {
    $e->getAwsErrorCode();      // NoSuchKey、AccessDenied ...
    $e->getStatusCode();        // 404
    $e->getAwsErrorMessage();   // 服务端返回的描述
    $e->getAwsRequestId();      // 排查时提供给运维
    $e->getCommandName();       // GetObject
} catch (ConnectException $e) {
    // 网络层失败：DNS、连接超时、TLS 握手
    $e->getRequest();
}
```

`getAwsErrorCode()` 等方法名沿用 aws-sdk-php，便于迁移；也提供了
`getErrorCode()` / `getErrorMessage()` 短别名。

**自动重试**：连接错误、5xx、429 以及 `SlowDown` 等限流错误会自动重试
（默认 3 次，指数退避加抖动）。4xx 不重试。

```php
'retries' => 5,   // 全局
$s3->getObject([..., '@retries' => 0]);   // 单次请求
```

---

## 配置项

| 配置项 | 默认值 | 说明 |
|---|---|---|
| `endpoint` | 必填 | 服务地址，如 `http://127.0.0.1:9000` |
| `region` | `us-east-1` | 参与签名，不能为空 |
| `credentials` | 读环境变量 | 数组、`Credentials` 实例或返回它的可调用对象 |
| `use_path_style_endpoint` | `true` | 路径式寻址。**与 aws-sdk-php 默认值相反** |
| `retries` | `3` | 重试次数 |
| `http` | `[]` | 传输层选项，见下表 |
| `checksum_calculation` | `when_required` | `when_required` / `when_supported` |
| `checksum_algorithm` | `md5` | `md5` / `crc32` / `sha1` / `sha256` |
| `handler` | curl | 自定义传输实现，测试时可注入桩件 |

`http` 支持的选项：

| 选项 | 说明 |
|---|---|
| `timeout` | 整体超时秒数，0 为不限 |
| `connect_timeout` | 连接超时秒数，默认 10 |
| `verify` | `true` / `false` / CA 文件或目录路径 |
| `proxy` | 代理地址 |
| `cert` / `ssl_key` | 客户端证书与私钥，可写成 `[路径, 密码]` |
| `force_ip_resolve` | `'v4'` / `'v6'` |
| `curl` | 直接透传的 curl 选项，优先级最高 |

单次请求可以用 `@http` 覆盖：

```php
$s3->getObject([
    'Bucket' => 'b', 'Key' => 'big.bin',
    '@http'  => ['timeout' => 300, 'sink' => '/path/big.bin'],
]);
```

凭证也可以动态提供（临时凭证轮换场景），过期后会自动重新获取：

```php
'credentials' => function () {
    $token = fetchTokenFromSomewhere();

    return new MinS3\Credentials($token['key'], $token['secret'], $token['token'],
        time() + 3600);
},
```

未显式配置时会读 `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`
（也认 `S3_ACCESS_KEY_ID` / `S3_SECRET_ACCESS_KEY`）。

---

## 与 aws-sdk-php 的差异

调用方式一致，以下几处行为有意做了调整：

**1. `use_path_style_endpoint` 默认 `true`**

自建服务多数不支持虚拟主机式寻址。原 SDK 默认 `false`。

**2. 校验和默认发 `Content-MD5`**

新版 aws-sdk-php 默认对所有支持的操作发 `x-amz-checksum-crc32`，
但相当一部分自建 S3（旧版 MinIO、Ceph RGW）不认这个头，`DeleteObjects`
之类会直接失败。`Content-MD5` 是所有 S3 兼容实现的最大公约数。

需要与 AWS 行为一致时：

```php
'checksum_calculation' => 'when_supported',
'checksum_algorithm'   => 'crc32',
```

**3. 不支持的功能**

去掉了 AWS 专有的部分：AWS 区域端点自动解析、IMDS / ECS / SSO / AssumeRole
凭证链、接入点与 Outposts ARN、S3 Express 会话鉴权、传输加速、双栈与 FIPS 端点、
客户端加密（SSE-C 这类服务端加密仍然可用）、SigV4a 非对称签名。

**4. 方法签名的小调整**

`doesBucketExist($bucket, $accept403 = false)` 与
`doesObjectExist($bucket, $key, $includeDeleteMarkers = false, $options = [])`
合并了原 SDK 的 V1 / V2 两个版本，行为等同 V2。

---

## 验证情况

与 aws-sdk-php 的对拍，以及端到端测试的结果：

| 验证项 | 结果 |
|---|---|
| 签名算法对拍（相同输入 → 相同 Authorization） | 19/19 一致 |
| 预签名 URL 对拍（固定时间，逐字节比对） | 16/16 一致 |
| 请求构造对拍（43 个操作 × 2 种寻址） | 86/86 一致 |
| 域名端点的虚拟主机式寻址对拍 | 8/8 一致 |
| 端到端功能测试（mock 服务端） | 73/73 通过 |
| 真实 HTTP 传输测试（curl 连本地服务器） | 18/18 通过 |
| 全部 116 个操作的模型解析与序列化 | 116/116 通过 |

对拍覆盖了中文 key、含空格与 `+` `&` `=` `?` 的 key、query 参数排序、
临时凭证、虚拟主机式与路径式寻址、含点桶名退回路径式等边界情况。

流式上传实测：2 MB 文件上传过程内存增长 0.0 MB。
预签名 URL 实测：用裸 `curl`（不经过本包）请求返回 200 并取回正确内容。

### 自己跑测试

```bash
git clone https://github.com/hlkaiyan/min-s3.git
cd min-s3
php tests/run.php        # 或 composer test
```

不需要 `composer install`——测试本身也不依赖 PHPUnit 之类的第三方包。
这不是偷懒：测试只通过 `autoload.php` 加载本包，**任何一处漏用了第三方类，
测试都会因为找不到类而直接失败**，零依赖这件事因此是被持续验证的，
而不是靠人工承诺。

四组测试分别是：

| 文件 | 内容 |
|---|---|
| `tests/dependencies.php` | 反射遍历全部类型引用，确认没有指向包外的引用 |
| `tests/functional.php` | 端到端功能，跑在内存版 S3 服务端上 |
| `tests/readme.php` | 把本文档里的每段示例执行一遍 |
| `tests/transport.php` | 真实 curl 传输，自动起停本机测试服务器 |

`tests/run.php` 会依次跑完并汇总，真实传输那组的服务器只监听
`127.0.0.1`，跑完必定回收，不访问外网。

与 aws-sdk-php 的对拍脚本不在包内——它需要把 aws-sdk-php 装进来做参照，
不适合作为发布产物的一部分。

CI 在 PHP 8.1 / 8.2 / 8.3 / 8.4 上跑，另外覆盖 Windows 与 macOS。

---

## 许可

Apache-2.0。API 模型文件与签名、序列化逻辑来自
[aws/aws-sdk-php](https://github.com/aws/aws-sdk-php)（同为 Apache-2.0）。
