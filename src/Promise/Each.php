<?php
namespace MinS3\Promise;

/**
 * 有并发上限的 promise 批处理。
 *
 * 并发是真并发：CurlHandler 在创建 promise 时就把句柄挂进了
 * curl_multi 队列，因此这里"逐个 wait"时，队列中所有请求都在同时推进。
 * 等第一个返回的过程本身就在驱动其余请求。
 */
final class Each
{
    private function __construct()
    {
    }

    /**
     * @param iterable $factories 每项是一个返回 Promise 的可调用对象
     * @param int      $concurrency 同时在途的最大数量
     * @param callable|null $onFulfilled function ($value, $key)
     * @param callable|null $onRejected  function ($reason, $key)
     */
    public static function ofLimit(
        iterable $factories,
        int $concurrency,
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): Promise {
        $concurrency = max(1, $concurrency);

        $iterator = self::toIterator($factories);
        $iterator->rewind();

        /** @var array<int|string, Promise> $pending */
        $pending = [];

        $fill = static function () use ($iterator, &$pending, $concurrency): void {
            while (count($pending) < $concurrency && $iterator->valid()) {
                $key = $iterator->key();
                $factory = $iterator->current();
                $iterator->next();

                $promise = $factory();
                if (!$promise instanceof Promise) {
                    $promise = Promise::resolved($promise);
                }
                $pending[$key] = $promise;
            }
        };

        return new Promise(static function (Promise $self) use (
            &$pending, $fill, $onFulfilled, $onRejected
        ): void {
            $fill();

            while ($pending !== []) {
                $key = array_key_first($pending);
                $promise = $pending[$key];
                unset($pending[$key]);

                try {
                    $value = $promise->wait();
                    if ($onFulfilled !== null) {
                        $onFulfilled($value, $key);
                    }
                } catch (\Throwable $e) {
                    if ($onRejected === null) {
                        throw $e;
                    }
                    $onRejected($e, $key);
                }

                // 腾出一个槽位后立即补充，保持在途数量
                $fill();
            }

            $self->resolve(null);
        });
    }

    /**
     * 等待全部完成，按原顺序返回结果；任一失败即整体失败。
     *
     * @param array<int|string, Promise> $promises
     */
    public static function all(array $promises): Promise
    {
        return new Promise(static function (Promise $self) use ($promises): void {
            $results = [];
            foreach ($promises as $key => $promise) {
                $results[$key] = $promise instanceof Promise
                    ? $promise->wait()
                    : $promise;
            }

            $self->resolve($results);
        });
    }

    private static function toIterator(iterable $iterable): \Iterator
    {
        if ($iterable instanceof \Iterator) {
            return $iterable;
        }

        if (is_array($iterable)) {
            return new \ArrayIterator($iterable);
        }

        return new \IteratorIterator($iterable);
    }
}
