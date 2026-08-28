<?php
namespace MinS3\Http;

/**
 * 基于 PHP 流资源的数据流。
 *
 * 只实现 S3 客户端实际用到的部分：读、写、定位、求长度。
 * 上传大文件时全程按块读取，不会把整个文件读进内存。
 */
class Stream implements \Stringable
{
    // 全部给默认值：LimitStream 继承本类但完全代理内层流，
    // 不会调用 parent::__construct，无默认值会导致未初始化属性错误。
    /** @var resource|null */
    private $stream = null;

    private ?int $size = null;
    private bool $seekable = false;
    private bool $readable = false;
    private bool $writable = false;
    private array $customMetadata = [];

    /**
     * 是否由本对象负责关闭底层资源。
     * 调用方传进来的 resource 归调用方所有，析构时不能替它关闭，
     * 否则 fopen 的句柄会在 Body 被回收时意外失效。
     */
    private bool $ownsResource = true;

    /** 读模式匹配表，用于判断资源是否可读 */
    private const READABLE_MODES = '/r|a\+|ab\+|w\+|wb\+|x\+|xb\+|c\+|cb\+/';
    private const WRITABLE_MODES = '/a|w|r\+|rb\+|rw|x|c/';

    /**
     * @param resource $stream
     * @param array    $options size / metadata / owns
     */
    public function __construct($stream, array $options = [])
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Stream 需要一个流资源');
        }

        $this->stream = $stream;
        $this->size = $options['size'] ?? null;
        $this->customMetadata = $options['metadata'] ?? [];
        $this->ownsResource = $options['owns'] ?? true;

        $meta = stream_get_meta_data($stream);
        $this->seekable = $meta['seekable'];
        $this->readable = (bool) preg_match(self::READABLE_MODES, $meta['mode']);
        $this->writable = (bool) preg_match(self::WRITABLE_MODES, $meta['mode']);
    }

    /**
     * 把任意值转成流：字符串、资源、已有的 Stream、null、可迭代对象。
     */
    public static function create(mixed $body = ''): self
    {
        if ($body instanceof self) {
            return $body;
        }

        if (is_string($body)) {
            $resource = fopen('php://temp', 'r+');
            if ($body !== '') {
                fwrite($resource, $body);
                fseek($resource, 0);
            }

            return new self($resource, ['size' => strlen($body)]);
        }

        if ($body === null) {
            return new self(fopen('php://temp', 'r+'), ['size' => 0]);
        }

        if (is_resource($body)) {
            // 外部资源：只借用，不接管生命周期
            return new self($body, ['owns' => false]);
        }

        if (is_object($body) && method_exists($body, '__toString')) {
            return self::create((string) $body);
        }

        throw new \InvalidArgumentException(
            '无法把 ' . get_debug_type($body) . ' 转换为流'
        );
    }

    /**
     * 打开文件并包装为流。
     */
    public static function open(string $filename, string $mode = 'r'): self
    {
        $handle = @fopen($filename, $mode);
        if ($handle === false) {
            $error = error_get_last();
            throw new \RuntimeException(sprintf(
                '无法打开 "%s"（模式 %s）: %s',
                $filename,
                $mode,
                $error['message'] ?? '未知错误'
            ));
        }

        return new self($handle);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __toString(): string
    {
        try {
            if ($this->seekable) {
                $this->seek(0);
            }

            return $this->getContents();
        } catch (\Throwable $e) {
            // __toString 在 PHP 7.4 之前不允许抛异常，保持宽松以免掩盖真实错误
            return '';
        }
    }

    public function close(): void
    {
        if ($this->ownsResource && is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->detach();
    }

    /**
     * 解除与底层资源的关联，交还给调用方管理。
     *
     * @return resource|null
     */
    public function detach()
    {
        $result = $this->stream;
        $this->stream = null;
        $this->size = null;
        $this->readable = $this->writable = $this->seekable = false;

        return $result;
    }

    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if (!isset($this->stream)) {
            return null;
        }

        $stats = fstat($this->stream);
        if (isset($stats['size'])) {
            $this->size = $stats['size'];

            return $this->size;
        }

        return null;
    }

    public function tell(): int
    {
        $this->assertAttached();

        $result = ftell($this->stream);
        if ($result === false) {
            throw new \RuntimeException('无法获取流的当前位置');
        }

        return $result;
    }

    public function eof(): bool
    {
        $this->assertAttached();

        return feof($this->stream);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertAttached();

        if (!$this->seekable) {
            throw new \RuntimeException('该流不支持定位');
        }

        if (fseek($this->stream, $offset, $whence) === -1) {
            throw new \RuntimeException(
                "定位失败：offset={$offset}, whence={$whence}"
            );
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function read(int $length): string
    {
        $this->assertAttached();

        if (!$this->readable) {
            throw new \RuntimeException('该流不可读');
        }

        if ($length < 0) {
            throw new \RuntimeException('读取长度不能为负数');
        }

        if ($length === 0) {
            return '';
        }

        $string = fread($this->stream, $length);
        if ($string === false) {
            throw new \RuntimeException('读取流失败');
        }

        return $string;
    }

    public function write(string $string): int
    {
        $this->assertAttached();

        if (!$this->writable) {
            throw new \RuntimeException('该流不可写');
        }

        // 写入后长度未知，强制重新计算
        $this->size = null;

        $result = fwrite($this->stream, $string);
        if ($result === false) {
            throw new \RuntimeException('写入流失败');
        }

        return $result;
    }

    public function getContents(): string
    {
        $this->assertAttached();

        $contents = stream_get_contents($this->stream);
        if ($contents === false) {
            throw new \RuntimeException('读取流内容失败');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        if (!isset($this->stream)) {
            return $key ? null : [];
        }

        $meta = $this->customMetadata + stream_get_meta_data($this->stream);

        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }

    /**
     * 把源流内容拷贝到目标流，按块搬运，不占用与文件等大的内存。
     */
    public static function copyTo(self $source, self $dest, int $maxLen = -1): void
    {
        $bufferSize = 8192;

        if ($maxLen === -1) {
            while (!$source->eof()) {
                if ($dest->write($source->read($bufferSize)) === 0) {
                    break;
                }
            }

            return;
        }

        $remaining = $maxLen;
        while ($remaining > 0 && !$source->eof()) {
            $buf = $source->read(min($bufferSize, $remaining));
            if ($buf === '') {
                break;
            }
            $remaining -= strlen($buf);
            $dest->write($buf);
        }
    }

    /**
     * 流式计算摘要，避免把内容整体读入内存。
     *
     * @param bool $rawOutput true 返回二进制摘要，false 返回十六进制
     */
    public static function hash(self $stream, string $algo, bool $rawOutput = false): string
    {
        if (!$stream->isSeekable()) {
            throw new \RuntimeException('无法对不可定位的流计算摘要');
        }

        $pos = $stream->tell();
        $stream->seek(0);

        $ctx = hash_init($algo);
        while (!$stream->eof()) {
            hash_update($ctx, $stream->read(1048576));
        }
        $result = hash_final($ctx, $rawOutput);

        $stream->seek($pos);

        return $result;
    }

    private function assertAttached(): void
    {
        if (!isset($this->stream)) {
            throw new \RuntimeException('流已分离，无法操作');
        }
    }
}
