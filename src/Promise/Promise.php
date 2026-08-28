<?php
namespace MinS3\Promise;

/**
 * 最小 Promise 实现。
 *
 * 覆盖 S3 客户端需要的场景：`*Async` 方法、分片并发上传、批量删除。
 * 与 guzzlehttp/promises 的差异：
 *  - 回调同步执行，没有任务队列。S3 的 promise 链很短（3-5 层），
 *    不存在深度递归风险，省掉队列换来实现体量减半。
 *  - wait() 通过 waitFn 驱动底层 curl_multi；then() 产生的子 promise
 *    会继承父链的 waitFn，所以在链尾调用 wait() 同样能驱动 IO。
 */
class Promise
{
    public const PENDING = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED = 'rejected';

    private string $state = self::PENDING;
    private mixed $value = null;

    /** @var array<array{0: ?callable, 1: ?callable, 2: Promise}> */
    private array $handlers = [];

    /**
     * 驱动底层 IO 直到本 promise 完成。
     * 签名为 function (Promise $self): void，需在内部调用
     * $self->resolve()/reject()，否则 wait() 会判定为未完成。
     *
     * @var callable|null
     */
    private $waitFn;

    /** @var Promise[] 上游 promise，wait() 时需要一并驱动 */
    private array $waitList = [];

    private bool $waiting = false;

    public function __construct(?callable $waitFn = null)
    {
        $this->waitFn = $waitFn;
    }

    public static function resolved(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $p = new self();
        $p->resolve($value);

        return $p;
    }

    public static function rejected(mixed $reason): self
    {
        $p = new self();
        $p->reject($reason);

        return $p;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function resolve(mixed $value): void
    {
        $this->settle(self::FULFILLED, $value);
    }

    public function reject(mixed $reason): void
    {
        $this->settle(self::REJECTED, $reason);
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        $child = new self();
        // 子 promise 需要能驱动父链的 IO，否则在链尾 wait() 会直接判定未完成
        $child->waitList = [$this];

        if ($this->state === self::PENDING) {
            $this->handlers[] = [$onFulfilled, $onRejected, $child];
        } else {
            $this->invoke($onFulfilled, $onRejected, $child);
        }

        return $child;
    }

    public function otherwise(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    /**
     * 阻塞直到完成。
     *
     * @param bool $unwrap true 时失败会抛出原因，false 时返回原因
     */
    public function wait(bool $unwrap = true): mixed
    {
        $this->waitInternal();

        if ($this->state === self::PENDING) {
            throw new \LogicException('promise 未能完成：没有可驱动的 IO 任务');
        }

        if ($this->state === self::REJECTED && $unwrap) {
            if ($this->value instanceof \Throwable) {
                throw $this->value;
            }

            throw new \RuntimeException(
                is_scalar($this->value)
                    ? (string) $this->value
                    : 'promise 被拒绝：' . print_r($this->value, true)
            );
        }

        return $this->value;
    }

    private function waitInternal(): void
    {
        if ($this->state !== self::PENDING || $this->waiting) {
            return;
        }

        $this->waiting = true;
        try {
            // 先驱动上游：本 promise 的结果来自上游回调
            foreach ($this->waitList as $parent) {
                $parent->waitInternal();
            }

            if ($this->state === self::PENDING && $this->waitFn !== null) {
                $fn = $this->waitFn;
                // 只驱动一次：waitFn 内部若再次触发 wait 会走 $this->waiting 短路
                $this->waitFn = null;
                try {
                    $fn($this);
                } catch (\Throwable $e) {
                    $this->settle(self::REJECTED, $e);
                }
            }
        } finally {
            $this->waiting = false;
        }
    }

    private function settle(string $state, mixed $value): void
    {
        if ($this->state !== self::PENDING) {
            // 已完成的 promise 忽略重复 settle，与 A+ 语义一致
            return;
        }

        // 结果本身是 promise 时，等它完成后再继承其状态
        if ($value instanceof self) {
            if ($value === $this) {
                throw new \LogicException('promise 不能以自身作为结果');
            }

            $this->waitList[] = $value;
            $value->then(
                fn($v) => $this->settle(self::FULFILLED, $v),
                fn($r) => $this->settle(self::REJECTED, $r)
            );

            return;
        }

        $this->state = $state;
        $this->value = $value;

        $handlers = $this->handlers;
        $this->handlers = [];
        foreach ($handlers as [$onFulfilled, $onRejected, $child]) {
            $this->invoke($onFulfilled, $onRejected, $child);
        }
    }

    private function invoke(?callable $onFulfilled, ?callable $onRejected, self $child): void
    {
        $callback = $this->state === self::FULFILLED ? $onFulfilled : $onRejected;

        if ($callback === null) {
            // 没有对应回调则把状态透传给下游
            $child->settle($this->state, $this->value);

            return;
        }

        try {
            $child->settle(self::FULFILLED, $callback($this->value));
        } catch (\Throwable $e) {
            $child->settle(self::REJECTED, $e);
        }
    }
}
