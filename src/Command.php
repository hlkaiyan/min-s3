<?php
namespace MinS3;

/**
 * 一次待执行的 S3 操作：操作名 + 参数。
 *
 * 参数里以 @ 开头的键不参与序列化，用于携带传输层配置：
 *   '@http' => ['timeout' => 30, 'sink' => '/tmp/a.bin', 'verify' => false]
 *   '@retries' => 0
 */
class Command implements \ArrayAccess, \IteratorAggregate, \Countable
{
    private string $name;
    private array $data;

    public function __construct(string $name, array $args = [])
    {
        $this->name = $name;
        $this->data = $args;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 全部参数，含 @ 开头的内部键。
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * 传输层选项。
     */
    public function getHttpOptions(): array
    {
        return $this->data['@http'] ?? [];
    }

    public function hasParam(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function __clone()
    {
        // 参数是标量与数组，浅拷贝即可；Body 若是流对象则共享，
        // 这与 aws-sdk-php 的行为一致
    }
}
