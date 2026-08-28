<?php
namespace MinS3;

use MinS3\Exception\S3Exception;
use MinS3\Promise\Promise;

/**
 * 批量删除对象。
 *
 * S3 的 DeleteObjects 单次最多 1000 个 key，这里负责攒批与分批发送。
 *
 *     BatchDelete::fromListObjects($s3, 'my-bucket', ['Prefix' => 'logs/'])->delete();
 */
class BatchDelete
{
    /** S3 规定的单批上限 */
    public const BATCH_SIZE = 1000;

    private S3Client $client;
    private string $bucket;
    private \Iterator $iterator;
    private int $batchSize;
    private array $options;

    /** @var callable|null */
    private $before;

    private function __construct(
        S3Client $client,
        string $bucket,
        \Iterator $iterator,
        array $options = []
    ) {
        $this->client = $client;
        $this->bucket = $bucket;
        $this->iterator = $iterator;
        $this->batchSize = min((int) ($options['batch_size'] ?? self::BATCH_SIZE), self::BATCH_SIZE);
        $this->before = $options['before'] ?? null;
        $this->options = $options;
    }

    /**
     * 删除 ListObjectsV2 列出的对象。
     *
     * @param array $listArgs 传给 ListObjectsV2 的参数，通常是 Prefix
     */
    public static function fromListObjects(
        S3Client $client,
        string $bucket,
        array $listArgs = [],
        array $options = []
    ): self {
        $listArgs['Bucket'] = $bucket;
        $iterator = $client->getIterator('ListObjectsV2', $listArgs);

        return new self($client, $bucket, $iterator, $options);
    }

    /**
     * 删除迭代器产出的对象，每项需含 Key（可选 VersionId）。
     */
    public static function fromIterator(
        S3Client $client,
        string $bucket,
        iterable $iterator,
        array $options = []
    ): self {
        if (is_array($iterator)) {
            $iterator = new \ArrayIterator($iterator);
        } elseif (!$iterator instanceof \Iterator) {
            $iterator = new \IteratorIterator($iterator);
        }

        return new self($client, $bucket, $iterator, $options);
    }

    /**
     * 删除指定的 key 列表。
     *
     * @param string[] $keys
     */
    public static function fromKeys(
        S3Client $client,
        string $bucket,
        array $keys,
        array $options = []
    ): self {
        $objects = array_map(static fn(string $key): array => ['Key' => $key], $keys);

        return new self($client, $bucket, new \ArrayIterator($objects), $options);
    }

    /**
     * 执行删除。
     *
     * @return Result[] 每批一个结果
     */
    public function delete(): array
    {
        return $this->promise()->wait();
    }

    public function promise(): Promise
    {
        return new Promise(function (Promise $self): void {
            $results = [];
            $errors = [];

            foreach ($this->batches() as $batch) {
                $result = $this->deleteBatch($batch);
                $results[] = $result;

                foreach ($result['Errors'] ?? [] as $error) {
                    $errors[] = $error;
                }
            }

            if ($errors !== []) {
                throw new \RuntimeException(sprintf(
                    "有 %d 个对象删除失败，例如: %s (%s)",
                    count($errors),
                    $errors[0]['Key'] ?? '?',
                    $errors[0]['Message'] ?? $errors[0]['Code'] ?? '未知原因'
                ));
            }

            $self->resolve($results);
        });
    }

    /**
     * @return \Generator<int, array>
     */
    private function batches(): \Generator
    {
        $batch = [];

        foreach ($this->iterator as $object) {
            if (!isset($object['Key'])) {
                continue;
            }

            $entry = ['Key' => $object['Key']];
            if (isset($object['VersionId'])) {
                $entry['VersionId'] = $object['VersionId'];
            }

            $batch[] = $entry;

            if (count($batch) >= $this->batchSize) {
                yield $batch;
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    private function deleteBatch(array $objects): Result
    {
        $command = $this->client->getCommand('DeleteObjects', [
            'Bucket' => $this->bucket,
            'Delete' => [
                'Objects' => $objects,
                'Quiet'   => (bool) ($this->options['quiet'] ?? false),
            ],
        ]);

        if ($this->before !== null) {
            ($this->before)($command);
        }

        return $this->client->execute($command);
    }
}
