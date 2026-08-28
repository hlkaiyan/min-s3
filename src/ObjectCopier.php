<?php
namespace MinS3;

use MinS3\Exception\MultipartUploadException;
use MinS3\Multipart\UploadState;
use MinS3\Promise\Each;
use MinS3\Promise\Promise;

/**
 * 服务端复制对象，数据不经过本机。
 *
 * 超过阈值的对象改用分片复制（UploadPartCopy）：S3 的单次
 * CopyObject 上限是 5 GB，超过必须分片。
 */
class ObjectCopier
{
    /** 超过这个大小改用分片复制 */
    public const DEFAULT_MULTIPART_THRESHOLD = 52428800; // 50 MB

    /** S3 对单次 CopyObject 的硬上限 */
    private const COPY_MAX_SIZE = 5368709120; // 5 GB

    private S3Client $client;
    private array $source;
    private array $destination;
    private string $acl;
    private array $options;

    /**
     * @param array $source      Bucket / Key（可选 VersionId）
     * @param array $destination Bucket / Key
     * @param array $options     params / part_size / concurrency / before_lookup / mup_threshold
     */
    public function __construct(
        S3Client $client,
        array $source,
        array $destination,
        string $acl = 'private',
        array $options = []
    ) {
        foreach (['Bucket', 'Key'] as $required) {
            if (empty($source[$required]) || empty($destination[$required])) {
                throw new \InvalidArgumentException("source 与 destination 都必须包含 {$required}");
            }
        }

        $this->client = $client;
        $this->source = $source;
        $this->destination = $destination;
        $this->acl = $acl;
        $this->options = $options;
    }

    public function copy(): Result
    {
        return $this->promise()->wait();
    }

    public function promise(): Promise
    {
        return new Promise(function (Promise $self): void {
            $self->resolve($this->run());
        });
    }

    private function run(): Result
    {
        $size = $this->getSourceSize();
        $threshold = (int) ($this->options['mup_threshold'] ?? self::DEFAULT_MULTIPART_THRESHOLD);

        if ($size < $threshold && $size < self::COPY_MAX_SIZE) {
            return $this->simpleCopy();
        }

        return $this->multipartCopy($size);
    }

    private function getSourceSize(): int
    {
        $args = ['Bucket' => $this->source['Bucket'], 'Key' => $this->source['Key']];
        if (isset($this->source['VersionId'])) {
            $args['VersionId'] = $this->source['VersionId'];
        }

        $command = $this->client->getCommand('HeadObject', $args);

        if (isset($this->options['before_lookup'])) {
            ($this->options['before_lookup'])($command);
        }

        return (int) $this->client->execute($command)['ContentLength'];
    }

    private function simpleCopy(): Result
    {
        $args = ($this->options['params'] ?? []) + [
            'Bucket'     => $this->destination['Bucket'],
            'Key'        => $this->destination['Key'],
            'CopySource' => $this->buildCopySource(),
        ];

        if ($this->acl !== '') {
            $args['ACL'] ??= $this->acl;
        }

        return $this->client->execute($this->client->getCommand('CopyObject', $args));
    }

    private function multipartCopy(int $sourceSize): Result
    {
        $partSize = $this->determinePartSize($sourceSize);

        $state = new UploadState([
            'Bucket' => $this->destination['Bucket'],
            'Key'    => $this->destination['Key'],
        ]);

        $initArgs = ($this->options['params'] ?? []) + [
            'Bucket' => $this->destination['Bucket'],
            'Key'    => $this->destination['Key'],
        ];
        if ($this->acl !== '') {
            $initArgs['ACL'] ??= $this->acl;
        }

        $result = $this->client->execute(
            $this->client->getCommand('CreateMultipartUpload', $initArgs)
        );
        $state->setUploadId($result['UploadId']);
        $state->setStatus(UploadState::INITIATED);

        $copySource = $this->buildCopySource();
        $failures = [];

        Each::ofLimit(
            $this->createPartGenerator($sourceSize, $partSize, $state, $copySource),
            (int) ($this->options['concurrency'] ?? 5),
            static function (Result $partResult, int $partNumber) use ($state): void {
                $state->markPartUploaded($partNumber, [
                    'PartNumber' => $partNumber,
                    'ETag'       => $partResult['CopyPartResult']['ETag'] ?? null,
                ]);
            },
            static function (\Throwable $reason, int $partNumber) use (&$failures): void {
                $failures[$partNumber] = $reason;
            }
        )->wait();

        if ($failures !== []) {
            // 复制失败要主动清理，否则残留分片会一直计费
            $this->abortQuietly($state);

            throw new MultipartUploadException($state, $failures);
        }

        return $this->client->execute($this->client->getCommand('CompleteMultipartUpload', [
            'Bucket'          => $this->destination['Bucket'],
            'Key'             => $this->destination['Key'],
            'UploadId'        => $state->getUploadId(),
            'MultipartUpload' => ['Parts' => array_values($state->getUploadedParts())],
        ]));
    }

    /**
     * @return \Generator<int, callable>
     */
    private function createPartGenerator(
        int $sourceSize,
        int $partSize,
        UploadState $state,
        string $copySource
    ): \Generator {
        $partNumber = 1;

        for ($offset = 0; $offset < $sourceSize; $offset += $partSize) {
            $end = min($offset + $partSize, $sourceSize) - 1;
            $range = "bytes={$offset}-{$end}";
            $current = $partNumber++;

            yield $current => function () use ($current, $range, $state, $copySource): Promise {
                return $this->client->executeAsync($this->client->getCommand('UploadPartCopy', [
                    'Bucket'          => $this->destination['Bucket'],
                    'Key'             => $this->destination['Key'],
                    'UploadId'        => $state->getUploadId(),
                    'PartNumber'      => $current,
                    'CopySource'      => $copySource,
                    'CopySourceRange' => $range,
                ]));
            };
        }
    }

    /**
     * CopySource 的格式是 /bucket/key，key 需要编码但要保留斜杠。
     */
    private function buildCopySource(): string
    {
        $source = '/' . $this->source['Bucket'] . '/'
            . str_replace('%2F', '/', rawurlencode($this->source['Key']));

        if (isset($this->source['VersionId'])) {
            $source .= '?versionId=' . $this->source['VersionId'];
        }

        return $source;
    }

    private function determinePartSize(int $sourceSize): int
    {
        $partSize = (int) ($this->options['part_size'] ?? Multipart\MultipartUploader::PART_MIN_SIZE);
        $partSize = max($partSize, Multipart\MultipartUploader::PART_MIN_SIZE);

        $needed = (int) ceil($sourceSize / Multipart\MultipartUploader::PART_MAX_NUM);

        return max($partSize, $needed);
    }

    private function abortQuietly(UploadState $state): void
    {
        try {
            $this->client->execute($this->client->getCommand('AbortMultipartUpload', [
                'Bucket'   => $this->destination['Bucket'],
                'Key'      => $this->destination['Key'],
                'UploadId' => $state->getUploadId(),
            ]));
        } catch (\Throwable $e) {
            // 清理失败不应掩盖原始错误
        }
    }
}
