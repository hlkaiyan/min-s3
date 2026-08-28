<?php
namespace MinS3\Multipart;

use MinS3\Http\Stream;

/**
 * 把多个流首尾相接成一个只读流。
 *
 * 用于长度未知的源：判断是否需要分片上传时已经读掉了一段，
 * 这段不能丢，得和剩下的部分接起来再交给上传器。
 */
class ConcatStream extends Stream
{
    /** @var Stream[] */
    private array $streams;

    private int $current = 0;
    private int $position = 0;

    /**
     * @param Stream[] $streams 按顺序拼接
     */
    public function __construct(array $streams)
    {
        // 不调用 parent::__construct：本类不持有自己的资源
        $this->streams = array_values($streams);
    }

    public function __destruct()
    {
        // 子流的生命周期由创建者管理
    }

    public function close(): void
    {
    }

    public function detach()
    {
        $this->streams = [];
        $this->current = 0;
        $this->position = 0;

        return null;
    }

    public function getSize(): ?int
    {
        $total = 0;
        foreach ($this->streams as $stream) {
            $size = $stream->getSize();
            if ($size === null) {
                // 只要有一段长度未知，总长就未知
                return null;
            }
            $total += $size;
        }

        return $total;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->current >= count($this->streams);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('ConcatStream 不支持定位');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('ConcatStream 不支持回绕');
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('ConcatStream 不可写');
    }

    public function read(int $length): string
    {
        $buffer = '';
        $remaining = $length;

        while ($remaining > 0 && $this->current < count($this->streams)) {
            $chunk = $this->streams[$this->current]->read($remaining);

            if ($chunk === '') {
                // 当前流读空了，换下一个
                $this->current++;
                continue;
            }

            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        $this->position += strlen($buffer);

        return $buffer;
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

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
