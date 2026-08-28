<?php
namespace MinS3;

use MinS3\Exception\S3Exception;

/**
 * 轮询等待资源达到目标状态。
 *
 * S3 的 4 个等待器（BucketExists / BucketNotExists / ObjectExists /
 * ObjectNotExists）判定条件都是 HTTP 状态码，配置直接取自模型文件。
 *
 *     $s3->waitUntil('ObjectExists', ['Bucket' => 'b', 'Key' => 'k']);
 */
class Waiter
{
    private S3Client $client;
    private string $name;
    private array $args;
    private array $config;

    public function __construct(S3Client $client, string $name, array $args = [])
    {
        $this->client = $client;
        $this->name = $name;
        $this->args = $args;
        $this->config = $client->getApi()->getWaiterConfig($name);
    }

    /**
     * 阻塞直到成功，或超出尝试次数抛异常。
     *
     * 可用 @waiter 参数覆盖默认节奏：
     *   ['@waiter' => ['delay' => 1, 'maxAttempts' => 5]]
     */
    public function wait(): void
    {
        $args = $this->args;
        $overrides = $args['@waiter'] ?? [];
        unset($args['@waiter']);

        $delay = (int) ($overrides['delay'] ?? $this->config['delay'] ?? 5);
        $maxAttempts = (int) ($overrides['maxAttempts'] ?? $this->config['maxAttempts'] ?? 20);
        $operation = $this->config['operation'];
        $acceptors = $this->config['acceptors'] ?? [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $state = $this->attempt($operation, $args, $acceptors);

            if ($state === 'success') {
                return;
            }

            if ($state === 'failure') {
                throw new \RuntimeException(
                    "等待 {$this->name} 失败：资源进入了终态"
                );
            }

            if ($attempt < $maxAttempts && $delay > 0) {
                sleep($delay);
            }
        }

        throw new \RuntimeException(
            "等待 {$this->name} 超时：已尝试 {$maxAttempts} 次，间隔 {$delay} 秒"
        );
    }

    /**
     * @return string 'success' | 'failure' | 'retry'
     */
    private function attempt(string $operation, array $args, array $acceptors): string
    {
        $status = null;

        try {
            $result = $this->client->execute($this->client->getCommand($operation, $args));
            $status = $result->getStatusCode();
        } catch (S3Exception $e) {
            $status = $e->getStatusCode();
            if ($status === null) {
                throw $e;
            }
        }

        foreach ($acceptors as $acceptor) {
            if (($acceptor['matcher'] ?? '') !== 'status') {
                // S3 的等待器只用状态码匹配，其余类型留待需要时再实现
                continue;
            }

            if ((int) $acceptor['expected'] === (int) $status) {
                return $acceptor['state'];
            }
        }

        return 'retry';
    }
}
