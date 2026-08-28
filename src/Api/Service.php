<?php
namespace MinS3\Api;

/**
 * S3 服务模型。
 *
 * 直接消费 aws-sdk-php 的 api-2 模型文件，因此 116 个 S3 操作的参数名、
 * 类型、位置（header/query/uri/body）与官方 SDK 完全一致，无需逐个手写映射。
 */
class Service
{
    private array $definition;
    private ShapeMap $shapeMap;

    /** @var array<string, Operation> 已实例化的操作，按需构建 */
    private array $operations = [];

    private array $paginators;
    private array $waiters;

    private static ?self $default = null;

    public function __construct(array $definition, array $paginators = [], array $waiters = [])
    {
        $definition['metadata'] ??= [];
        $definition['operations'] ??= [];
        $definition['shapes'] ??= [];

        $this->definition = $definition;
        $this->shapeMap = new ShapeMap($definition['shapes']);
        $this->paginators = $paginators;
        $this->waiters = $waiters;
    }

    /**
     * 加载随包分发的 S3 模型，进程内只解析一次。
     */
    public static function s3(): self
    {
        if (self::$default === null) {
            $dir = __DIR__ . '/../data';
            self::$default = new self(
                require $dir . '/api-2.json.php',
                (require $dir . '/paginators-1.json.php')['pagination'] ?? [],
                (require $dir . '/waiters-2.json.php')['waiters'] ?? []
            );
        }

        return self::$default;
    }

    public function getMetadata(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->definition['metadata'];
        }

        return $this->definition['metadata'][$key] ?? null;
    }

    public function getServiceName(): string
    {
        return $this->getMetadata('endpointPrefix') ?? 's3';
    }

    /**
     * 签名时使用的服务名，S3 固定为 s3。
     */
    public function getSigningName(): string
    {
        return $this->getMetadata('signingName')
            ?? $this->getMetadata('endpointPrefix')
            ?? 's3';
    }

    public function getApiVersion(): ?string
    {
        return $this->getMetadata('apiVersion');
    }

    public function getProtocol(): ?string
    {
        return $this->getMetadata('protocol');
    }

    public function hasOperation(string $name): bool
    {
        return isset($this->definition['operations'][$name]);
    }

    public function getOperation(string $name): Operation
    {
        if (!isset($this->operations[$name])) {
            if (!isset($this->definition['operations'][$name])) {
                throw new \InvalidArgumentException("S3 没有名为 {$name} 的操作");
            }

            $this->operations[$name] = new Operation(
                $this->definition['operations'][$name],
                $this->shapeMap
            );
        }

        return $this->operations[$name];
    }

    /**
     * @return array<string, Operation>
     */
    public function getOperations(): array
    {
        foreach (array_keys($this->definition['operations']) as $name) {
            $this->getOperation($name);
        }

        return $this->operations;
    }

    /**
     * @return string[] 操作名列表，不会触发 Operation 实例化
     */
    public function getOperationNames(): array
    {
        return array_keys($this->definition['operations']);
    }

    public function getShapeMap(): ShapeMap
    {
        return $this->shapeMap;
    }

    public function getPaginatorConfig(string $name): array
    {
        static $defaults = [
            'input_token'  => null,
            'output_token' => null,
            'limit_key'    => null,
            'result_key'   => null,
            'more_results' => null,
        ];

        if (!isset($this->paginators[$name])) {
            throw new \UnexpectedValueException("{$name} 没有分页配置");
        }

        return $this->paginators[$name] + $defaults;
    }

    public function hasPaginator(string $name): bool
    {
        return isset($this->paginators[$name]);
    }

    public function getWaiterConfig(string $name): array
    {
        if (!isset($this->waiters[$name])) {
            throw new \UnexpectedValueException("{$name} 没有等待器配置");
        }

        return $this->waiters[$name];
    }

    public function hasWaiter(string $name): bool
    {
        return isset($this->waiters[$name]);
    }

    /**
     * @return string[]
     */
    public function getWaiterNames(): array
    {
        return array_keys($this->waiters);
    }

    public function toArray(): array
    {
        return $this->definition;
    }
}
