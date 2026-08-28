<?php
namespace MinS3\Http;

/**
 * 把底层流限制在 [offset, offset+limit) 区间内。
 *
 * 分片上传靠它切出每个 part：源文件只打开一次，每个分片是同一份
 * 数据上的一个窗口，不需要把分片内容复制到临时文件。
 */
final class LimitStream extends Stream
{
    private Stream $inner;
    private int $limit;
    private int $offset;

    /**
     * @param Stream $stream 被限制的流
     * @param int    $limit  可读字节数，-1 表示直到源流结束
     * @param int    $offset 起始偏移
     */
    public function __construct(Stream $stream, int $limit = -1, int $offset = 0)
    {
        // 不调用 parent::__construct：本类完全代理 $inner，自身不持有资源
        $this->inner = $stream;
        $this->limit = $limit;
        $this->setOffset($offset);
    }

    public function __destruct()
    {
        // 覆盖父类析构：窗口销毁不应关闭共享的源流
    }

    public function __toString(): string
    {
        try {
            $this->seek(0);

            return $this->getContents();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function close(): void
    {
        // 同上：源流由创建者负责关闭
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        $length = $this->inner->getSize();
        if ($length === null) {
            return null;
        }

        $remaining = $length - $this->offset;
        if ($remaining <= 0) {
            return 0;
        }

        return $this->limit === -1 ? $remaining : min($this->limit, $remaining);
    }

    public function tell(): int
    {
        return $this->inner->tell() - $this->offset;
    }

    public function eof(): bool
    {
        if ($this->inner->eof()) {
            return true;
        }

        if ($this->limit === -1) {
            return false;
        }

        return $this->tell() >= $this->limit;
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($whence === SEEK_END) {
            $size = $this->getSize();
            if ($size === null) {
                throw new \RuntimeException('源流长度未知，无法从末尾定位');
            }
            $offset = $size + $offset;
            $whence = SEEK_SET;
        }

        if ($whence !== SEEK_SET) {
            // SEEK_CUR 转成绝对位置，避免越过窗口边界
            $offset = $this->tell() + $offset;
            $whence = SEEK_SET;
        }

        if ($this->limit !== -1 && $offset > $this->limit) {
            $offset = $this->limit;
        }

        $this->inner->seek($this->offset + $offset);
    }

    public function read(int $length): string
    {
        if ($this->limit === -1) {
            return $this->inner->read($length);
        }

        $remaining = $this->limit - $this->tell();
        if ($remaining <= 0) {
            return '';
        }

        return $this->inner->read(min($remaining, $length));
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('LimitStream 不可写');
    }

    public function getContents(): string
    {
        $buffer = '';
        while (!$this->eof()) {
            $chunk = $this->read(8192);
            if ($chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }

    private function setOffset(int $offset): void
    {
        $current = $this->inner->tell();
        if ($current !== $offset) {
            $this->inner->seek($offset);
        }

        $this->offset = $offset;
    }
}
