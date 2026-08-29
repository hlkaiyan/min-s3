<?php
namespace MinS3;

/**
 * 自动翻页。
 *
 * S3 单次 List 最多返回 1000 条，靠续传令牌取下一页。翻页参数
 * （令牌字段名、结果字段名）来自模型里的 paginators 配置，
 * 与 aws-sdk-php 用的是同一份数据。
 *
 *     foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => 'b']) as $page) {
 *         foreach ($page['Contents'] ?? [] as $object) { ... }
 *     }
 */
class Paginator implements \IteratorAggregate
{
    private S3Client $client;
    private string $operation;
    private array $args;
    private array $config;

    public function __construct(S3Client $client, string $operation, array $args = [])
    {
        $this->client = $client;
        $this->operation = $operation;
        $this->args = $args;
        $this->config = $client->getApi()->getPaginatorConfig($operation);
    }

    /**
     * 逐页产出 Result。
     *
     * @return \Generator<int, Result>
     */
    public function getIterator(): \Generator
    {
        $args = $this->args;
        $pageIndex = 0;

        // 用户可以用 @limit 限制总条数，用 @page_size 控制每页大小
        $limit = $args['@limit'] ?? null;
        $pageSize = $args['@page_size'] ?? null;
        unset($args['@limit'], $args['@page_size']);

        $remaining = $limit;

        // 记录上一轮的令牌：部分 S3 实现在没有更多数据时会把同一个
        // continuation token 原样回显，只看 IsTruncated 会无限翻页
        $lastToken = null;

        while (true) {
            if ($pageSize !== null && $this->config['limit_key'] !== null) {
                $args[$this->config['limit_key']] = $remaining !== null
                    ? min($pageSize, $remaining)
                    : $pageSize;
            } elseif ($remaining !== null && $this->config['limit_key'] !== null) {
                $args[$this->config['limit_key']] = $remaining;
            }

            $result = $this->client->execute($this->client->getCommand($this->operation, $args));

            yield $pageIndex++ => $result;

            if ($remaining !== null) {
                $remaining -= $this->countItems($result);
                if ($remaining <= 0) {
                    return;
                }
            }

            $nextToken = $this->getNextToken($result);
            if ($nextToken === null) {
                return;
            }

            // 令牌与上一轮相同说明服务端没有推进，再请求下去只会拿到同一页
            $tokenKey = is_array($nextToken)
                ? implode("\0", array_map(static fn($v): string => (string) $v, $nextToken))
                : (string) $nextToken;

            if ($tokenKey === $lastToken) {
                return;
            }
            $lastToken = $tokenKey;

            $args = $this->applyToken($args, $nextToken);
        }
    }

    /**
     * 跨页产出结果项，省去自己嵌套两层循环。
     *
     *     foreach ($paginator->search('Contents') as $object) { ... }
     *
     * @return \Generator<int, mixed>
     */
    public function search(string $expression): \Generator
    {
        foreach ($this as $result) {
            $value = $result->search($expression);

            if ($value === null) {
                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                yield from $value;
            } else {
                yield $value;
            }
        }
    }

    /**
     * 产出全部结果项（result_key 指定的那些列表合并）。
     *
     * @return \Generator<int, mixed>
     */
    public function items(): \Generator
    {
        $keys = (array) ($this->config['result_key'] ?? []);

        foreach ($this as $result) {
            foreach ($keys as $key) {
                $value = $result[$key];
                if (is_array($value)) {
                    yield from $value;
                }
            }
        }
    }

    /**
     * 下一页的续传令牌，没有则返回 null 表示结束。
     */
    private function getNextToken(Result $result): array|string|null
    {
        // more_results 明确说了没有更多时直接停，
        // 否则某些实现会把最后一页的令牌回显造成死循环
        $moreResults = $this->config['more_results'];
        if ($moreResults !== null && !$result[$moreResults]) {
            return null;
        }

        $outputToken = $this->config['output_token'];
        if ($outputToken === null) {
            return null;
        }

        if (is_array($outputToken)) {
            $tokens = [];
            $hasValue = false;
            foreach ($outputToken as $expression) {
                $value = $result->search($expression);
                $tokens[] = $value;
                if ($value !== null && $value !== '') {
                    $hasValue = true;
                }
            }

            return $hasValue ? $tokens : null;
        }

        $value = $result->search($outputToken);

        return ($value === null || $value === '') ? null : $value;
    }

    private function applyToken(array $args, array|string $token): array
    {
        $inputToken = $this->config['input_token'];

        if (is_array($inputToken)) {
            $tokenValues = (array) $token;
            foreach ($inputToken as $i => $name) {
                $value = $tokenValues[$i] ?? null;
                if ($value === null || $value === '') {
                    unset($args[$name]);
                } else {
                    $args[$name] = $value;
                }
            }

            return $args;
        }

        $args[$inputToken] = is_array($token) ? reset($token) : $token;

        return $args;
    }

    private function countItems(Result $result): int
    {
        $count = 0;
        foreach ((array) ($this->config['result_key'] ?? []) as $key) {
            $value = $result[$key];
            if (is_array($value)) {
                $count += count($value);
            }
        }

        return $count;
    }
}
