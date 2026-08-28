<?php
namespace MinS3;

/**
 * 操作结果。
 *
 * 用法与 aws-sdk-php 的 Result 一致：像数组一样取值。
 *   $result['ETag'], $result['Contents'], (string) $result['Body']
 *
 * '@metadata' 键里放着 statusCode 与全部响应头（头名已转小写）。
 */
class Result implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    protected array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function hasKey(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * 按路径取值，支持点号、负索引与 `||` 兜底。
     *
     *   search('Contents')            → 数组
     *   search('Contents[-1].Key')    → 最后一个对象的 Key
     *   search('NextMarker || Contents[-1].Key')
     *
     * 这是 JMESPath 的一个极小子集，刚好覆盖 S3 分页配置里出现的
     * 全部表达式，避免为此引入一个完整的查询引擎。
     */
    public function search(string $expression): mixed
    {
        foreach (explode('||', $expression) as $alternative) {
            $value = $this->evaluatePath(trim($alternative));
            if ($value !== null && $value !== []) {
                return $value;
            }
        }

        return null;
    }

    private function evaluatePath(string $path): mixed
    {
        $current = $this->data;

        foreach (explode('.', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            // 形如 Contents[-1] / Contents[0]
            $index = null;
            if (preg_match('/^(.*?)\[(-?\d+)\]$/', $segment, $m)) {
                $segment = $m[1];
                $index = (int) $m[2];
            }

            if ($segment !== '') {
                if (!is_array($current) || !array_key_exists($segment, $current)) {
                    return null;
                }
                $current = $current[$segment];
            }

            if ($index !== null) {
                if (!is_array($current)) {
                    return null;
                }
                $values = array_values($current);
                $count = count($values);
                $resolved = $index < 0 ? $count + $index : $index;
                if ($resolved < 0 || $resolved >= $count) {
                    return null;
                }
                $current = $values[$resolved];
            }
        }

        return $current;
    }

    /**
     * HTTP 状态码。
     */
    public function getStatusCode(): ?int
    {
        return $this->data['@metadata']['statusCode'] ?? null;
    }

    /**
     * 单个响应头，头名不区分大小写。
     */
    public function getHeader(string $name): ?string
    {
        return $this->data['@metadata']['headers'][strtolower($name)] ?? null;
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

    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function __toString(): string
    {
        return json_encode(
            $this->data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        ) ?: '';
    }
}
