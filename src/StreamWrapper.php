<?php
namespace MinS3;

use MinS3\Exception\S3Exception;
use MinS3\Http\Stream;

/**
 * s3:// 流包装器。
 *
 * 注册后可以用 PHP 原生的文件函数操作对象：
 *
 *     StreamWrapper::register($s3);
 *     file_put_contents('s3://my-bucket/a.txt', 'hello');
 *     echo file_get_contents('s3://my-bucket/a.txt');
 *     foreach (scandir('s3://my-bucket/logs/') as $name) { ... }
 *
 * 注意 S3 没有真正的目录，也不支持追加写：
 *  - 'a' 模式不可用
 *  - 写入在 fclose 时才真正提交（整体 PutObject）
 *  - 目录只是 key 的前缀，mkdir 会创建一个以 / 结尾的空对象
 */
class StreamWrapper
{
    /** @var resource|null 由 PHP 注入 */
    public $context;

    private static ?S3Client $client = null;
    private static string $protocol = 's3';

    /** @var array<string, array> url_stat 结果缓存，避免同一次 include 里重复 HeadObject */
    private static array $statCache = [];

    private string $mode = '';
    private string $bucket = '';
    private string $key = '';

    private ?Stream $body = null;

    /** 写模式下缓冲的内容，close 时提交 */
    private bool $dirty = false;

    /** @var \Iterator|null 目录遍历游标 */
    private ?\Iterator $dirIterator = null;

    /** @var string[] 已产出的目录项，用于去重 CommonPrefixes */
    private array $seenEntries = [];

    /**
     * 注册包装器。重复注册会覆盖之前的。
     */
    public static function register(S3Client $client, string $protocol = 's3'): void
    {
        if (in_array($protocol, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($protocol);
        }

        self::$client = $client;
        self::$protocol = $protocol;
        self::$statCache = [];

        stream_wrapper_register($protocol, self::class, STREAM_IS_URL);
    }

    public static function unregister(string $protocol = 's3'): void
    {
        if (in_array($protocol, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($protocol);
        }

        self::$statCache = [];
    }

    /**
     * 清空 stat 缓存。上传后立刻 stat 同一个 key 时需要。
     */
    public static function clearStatCache(?string $path = null): void
    {
        if ($path === null) {
            self::$statCache = [];
        } else {
            unset(self::$statCache[$path]);
        }
    }

    // ---- 文件流 ----

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->parsePath($path);
        $this->mode = rtrim($mode, 'bt+');

        if ($this->key === '') {
            return $this->fail($options, 's3:// 路径必须包含对象 key');
        }

        return match ($this->mode) {
            'r'     => $this->openRead($options),
            'w'     => $this->openWrite(),
            'a'     => $this->fail($options, 'S3 对象不支持追加写入（a 模式）'),
            'x'     => $this->openExclusive($options),
            default => $this->fail($options, "不支持的打开模式: {$this->mode}"),
        };
    }

    private function openRead(int $options): bool
    {
        try {
            $result = self::$client->execute(self::$client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key'    => $this->key,
            ]));
        } catch (S3Exception $e) {
            return $this->fail($options, $e->getMessage());
        }

        $body = $result['Body'];
        $this->body = $body instanceof Stream ? $body : Stream::create((string) $body);
        if ($this->body->isSeekable()) {
            $this->body->rewind();
        }

        return true;
    }

    private function openWrite(): bool
    {
        $this->body = Stream::create('');
        $this->dirty = true;

        return true;
    }

    private function openExclusive(int $options): bool
    {
        try {
            self::$client->execute(self::$client->getCommand('HeadObject', [
                'Bucket' => $this->bucket,
                'Key'    => $this->key,
            ]));
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return $this->openWrite();
            }

            return $this->fail($options, $e->getMessage());
        }

        return $this->fail($options, "对象已存在: s3://{$this->bucket}/{$this->key}");
    }

    public function stream_read(int $count): string
    {
        return $this->body === null ? '' : $this->body->read($count);
    }

    public function stream_write(string $data): int
    {
        if ($this->body === null) {
            return 0;
        }

        $this->dirty = true;

        return $this->body->write($data);
    }

    public function stream_eof(): bool
    {
        return $this->body === null || $this->body->eof();
    }

    public function stream_tell(): int
    {
        return $this->body === null ? 0 : $this->body->tell();
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->body === null || !$this->body->isSeekable()) {
            return false;
        }

        try {
            $this->body->seek($offset, $whence);

            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public function stream_flush(): bool
    {
        if (!$this->dirty || $this->body === null) {
            return true;
        }

        $this->body->rewind();

        try {
            self::$client->execute(self::$client->getCommand('PutObject', $this->contextParams() + [
                'Bucket' => $this->bucket,
                'Key'    => $this->key,
                'Body'   => $this->body,
            ]));
        } catch (S3Exception $e) {
            trigger_error("写入 s3://{$this->bucket}/{$this->key} 失败: " . $e->getMessage(), E_USER_WARNING);

            return false;
        }

        $this->dirty = false;
        self::clearStatCache("s3://{$this->bucket}/{$this->key}");

        return true;
    }

    public function stream_close(): void
    {
        $this->stream_flush();
        $this->body = null;
    }

    public function stream_stat(): array
    {
        $size = $this->body?->getSize() ?? 0;

        return $this->buildStat(0100777, $size, 0);
    }

    /**
     * 让 stream_select 之类的函数能拿到底层句柄。
     *
     * @return resource|false
     */
    public function stream_cast(int $castAs)
    {
        return false;
    }

    // ---- 路径操作 ----

    public function url_stat(string $path, int $flags): array|false
    {
        if (isset(self::$statCache[$path])) {
            return self::$statCache[$path];
        }

        $this->parsePath($path);

        // 桶本身当作目录
        if ($this->key === '') {
            try {
                self::$client->execute(self::$client->getCommand('HeadBucket', [
                    'Bucket' => $this->bucket,
                ]));

                return self::$statCache[$path] = $this->buildStat(0040777, 0, 0);
            } catch (S3Exception $e) {
                return $this->statFail($flags, $e);
            }
        }

        // 显式的目录写法
        if (str_ends_with($this->key, '/')) {
            return self::$statCache[$path] = $this->buildStat(0040777, 0, 0);
        }

        try {
            $result = self::$client->execute(self::$client->getCommand('HeadObject', [
                'Bucket' => $this->bucket,
                'Key'    => $this->key,
            ]));

            $modified = $result['LastModified'];
            $mtime = $modified instanceof \DateTimeInterface ? $modified->getTimestamp() : 0;

            return self::$statCache[$path] = $this->buildStat(
                0100777,
                (int) $result['ContentLength'],
                $mtime
            );
        } catch (S3Exception $e) {
            if ($e->getStatusCode() !== 404) {
                return $this->statFail($flags, $e);
            }
        }

        // 不是对象，再看它是不是某些 key 的前缀（虚拟目录）
        try {
            $result = self::$client->execute(self::$client->getCommand('ListObjectsV2', [
                'Bucket'  => $this->bucket,
                'Prefix'  => rtrim($this->key, '/') . '/',
                'MaxKeys' => 1,
            ]));

            if (!empty($result['Contents']) || !empty($result['CommonPrefixes'])) {
                return self::$statCache[$path] = $this->buildStat(0040777, 0, 0);
            }
        } catch (S3Exception $e) {
            return $this->statFail($flags, $e);
        }

        if (!($flags & STREAM_URL_STAT_QUIET)) {
            trigger_error("找不到 {$path}", E_USER_WARNING);
        }

        return false;
    }

    public function unlink(string $path): bool
    {
        $this->parsePath($path);

        try {
            self::$client->execute(self::$client->getCommand('DeleteObject', [
                'Bucket' => $this->bucket,
                'Key'    => $this->key,
            ]));
            self::clearStatCache($path);

            return true;
        } catch (S3Exception $e) {
            trigger_error("删除 {$path} 失败: " . $e->getMessage(), E_USER_WARNING);

            return false;
        }
    }

    public function rename(string $from, string $to): bool
    {
        $this->parsePath($from);
        $sourceBucket = $this->bucket;
        $sourceKey = $this->key;

        $this->parsePath($to);

        try {
            // S3 没有 rename，只能复制后删除
            (new ObjectCopier(
                self::$client,
                ['Bucket' => $sourceBucket, 'Key' => $sourceKey],
                ['Bucket' => $this->bucket, 'Key' => $this->key],
                ''
            ))->copy();

            self::$client->execute(self::$client->getCommand('DeleteObject', [
                'Bucket' => $sourceBucket,
                'Key'    => $sourceKey,
            ]));

            self::clearStatCache($from);
            self::clearStatCache($to);

            return true;
        } catch (\Throwable $e) {
            trigger_error("重命名失败: " . $e->getMessage(), E_USER_WARNING);

            return false;
        }
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        $this->parsePath($path);

        try {
            if ($this->key === '') {
                self::$client->execute(self::$client->getCommand('CreateBucket', [
                    'Bucket' => $this->bucket,
                ]));

                return true;
            }

            // 以 / 结尾的空对象充当目录标记
            self::$client->execute(self::$client->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key'    => rtrim($this->key, '/') . '/',
                'Body'   => '',
            ]));

            return true;
        } catch (S3Exception $e) {
            trigger_error("创建 {$path} 失败: " . $e->getMessage(), E_USER_WARNING);

            return false;
        }
    }

    public function rmdir(string $path, int $options): bool
    {
        $this->parsePath($path);

        try {
            if ($this->key === '') {
                self::$client->execute(self::$client->getCommand('DeleteBucket', [
                    'Bucket' => $this->bucket,
                ]));

                return true;
            }

            $prefix = rtrim($this->key, '/') . '/';

            // 非空目录不允许删除，语义与本地文件系统保持一致
            $result = self::$client->execute(self::$client->getCommand('ListObjectsV2', [
                'Bucket'  => $this->bucket,
                'Prefix'  => $prefix,
                'MaxKeys' => 2,
            ]));

            $contents = $result['Contents'] ?? [];
            foreach ($contents as $object) {
                if ($object['Key'] !== $prefix) {
                    trigger_error("目录非空: {$path}", E_USER_WARNING);

                    return false;
                }
            }

            self::$client->execute(self::$client->getCommand('DeleteObject', [
                'Bucket' => $this->bucket,
                'Key'    => $prefix,
            ]));

            return true;
        } catch (S3Exception $e) {
            trigger_error("删除目录 {$path} 失败: " . $e->getMessage(), E_USER_WARNING);

            return false;
        }
    }

    // ---- 目录遍历 ----

    public function dir_opendir(string $path, int $options): bool
    {
        $this->parsePath($path);
        $this->seenEntries = [];

        $prefix = $this->key === '' ? '' : rtrim($this->key, '/') . '/';

        $args = ['Bucket' => $this->bucket, 'Delimiter' => '/'];
        if ($prefix !== '') {
            $args['Prefix'] = $prefix;
        }

        $client = self::$client;

        $this->dirIterator = (static function () use ($client, $args, $prefix): \Generator {
            foreach ($client->getPaginator('ListObjectsV2', $args) as $page) {
                // 先给子目录，再给文件，顺序更接近本地 scandir
                foreach ($page['CommonPrefixes'] ?? [] as $commonPrefix) {
                    $name = rtrim(substr($commonPrefix['Prefix'], strlen($prefix)), '/');
                    if ($name !== '') {
                        yield $name;
                    }
                }

                foreach ($page['Contents'] ?? [] as $object) {
                    $name = substr($object['Key'], strlen($prefix));
                    // 目录标记对象本身不作为条目返回
                    if ($name !== '' && $name !== '/') {
                        yield rtrim($name, '/');
                    }
                }
            }
        })();

        $this->dirIterator->rewind();

        return true;
    }

    public function dir_readdir(): string|false
    {
        if ($this->dirIterator === null) {
            return false;
        }

        while ($this->dirIterator->valid()) {
            $name = $this->dirIterator->current();
            $this->dirIterator->next();

            // CommonPrefixes 与 Contents 可能给出同名项
            if (!isset($this->seenEntries[$name])) {
                $this->seenEntries[$name] = true;

                return $name;
            }
        }

        return false;
    }

    public function dir_rewinddir(): bool
    {
        $this->seenEntries = [];

        // 生成器不能重绕，重新发起列举
        return $this->dir_opendir("s3://{$this->bucket}/{$this->key}", 0);
    }

    public function dir_closedir(): bool
    {
        $this->dirIterator = null;
        $this->seenEntries = [];

        return true;
    }

    // ---- 内部辅助 ----

    private function parsePath(string $path): void
    {
        $protocol = self::$protocol . '://';
        if (!str_starts_with($path, $protocol)) {
            throw new \InvalidArgumentException("不是 {$protocol} 路径: {$path}");
        }

        $rest = substr($path, strlen($protocol));
        $slash = strpos($rest, '/');

        if ($slash === false) {
            $this->bucket = $rest;
            $this->key = '';
        } else {
            $this->bucket = substr($rest, 0, $slash);
            $this->key = substr($rest, $slash + 1);
        }
    }

    /**
     * 从 stream_context 里取额外的 PutObject 参数，
     * 用法：stream_context_create(['s3' => ['ACL' => 'public-read']])
     */
    private function contextParams(): array
    {
        if ($this->context === null) {
            return [];
        }

        $options = stream_context_get_options($this->context);

        return $options[self::$protocol] ?? [];
    }

    private function buildStat(int $mode, int $size, int $mtime): array
    {
        $stat = [
            'dev' => 0, 'ino' => 0, 'mode' => $mode, 'nlink' => 0,
            'uid' => 0, 'gid' => 0, 'rdev' => 0, 'size' => $size,
            'atime' => $mtime, 'mtime' => $mtime, 'ctime' => $mtime,
            'blksize' => 0, 'blocks' => 0,
        ];

        // stat 数组要求同时有数字下标和字符串键
        return array_merge(array_values($stat), $stat);
    }

    private function statFail(int $flags, S3Exception $e): false
    {
        if (!($flags & STREAM_URL_STAT_QUIET)) {
            trigger_error($e->getMessage(), E_USER_WARNING);
        }

        return false;
    }

    private function fail(int $options, string $message): bool
    {
        if ($options & STREAM_REPORT_ERRORS) {
            trigger_error($message, E_USER_WARNING);
        }

        return false;
    }
}
