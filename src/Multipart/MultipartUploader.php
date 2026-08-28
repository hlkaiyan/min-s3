<?php
namespace MinS3\Multipart;

use MinS3\Command;
use MinS3\Exception\MultipartUploadException;
use MinS3\Http\LimitStream;
use MinS3\Http\Stream;
use MinS3\Promise\Each;
use MinS3\Promise\Promise;
use MinS3\Result;
use MinS3\S3Client;

/**
 * 分片上传。
 *
 * 流程与 aws-sdk-php 一致：CreateMultipartUpload → 并发 UploadPart →
 * CompleteMultipartUpload。失败时抛出的异常里带着 UploadState，
 * 可用于断点续传。
 *
 *     $uploader = new MultipartUploader($s3, '/path/big.bin', [
 *         'bucket' => 'my-bucket',
 *         'key'    => 'big.bin',
 *     ]);
 *     $result = $uploader->upload();
 */
class MultipartUploader
{
    public const PART_MIN_SIZE = 5242880;      // 5 MB，S3 规定的最小分片
    public const PART_MAX_SIZE = 5368709120;   // 5 GB
    public const PART_MAX_NUM = 10000;

    private S3Client $client;
    private Stream $source;
    private array $config;
    private UploadState $state;

    /** 源是真实文件时的路径：每个分片可以独立开句柄，实现零拷贝并发 */
    private ?string $sourceFile = null;

    private int $partSize;
    private int $nextPartNumber = 1;

    /**
     * 配置项：
     *  - bucket / key   (必填)
     *  - part_size      分片字节数，默认 5 MB，会按源大小自动上调
     *  - concurrency    并发分片数，默认 5
     *  - state          UploadState，用于续传
     *  - params         附加到每个子请求的参数
     *  - acl            对象 ACL
     *  - before_initiate / before_upload / before_complete 回调，
     *                   签名为 function (Command $command)
     *
     * @param mixed $source 文件路径、流资源或 Stream
     */
    public function __construct(S3Client $client, mixed $source, array $config = [])
    {
        $this->client = $client;
        $this->config = array_change_key_case($config) + [
            'part_size'   => self::PART_MIN_SIZE,
            'concurrency' => 5,
            'params'      => [],
        ];

        $this->source = $this->resolveSource($source);
        $this->state = $this->config['state'] ?? new UploadState([
            'Bucket' => $this->config['bucket'] ?? null,
            'Key'    => $this->config['key'] ?? null,
        ]);

        if (!isset($this->config['state'])) {
            if (empty($this->config['bucket']) || empty($this->config['key'])) {
                throw new \InvalidArgumentException('分片上传必须指定 bucket 与 key');
            }
        }

        $this->partSize = $this->determinePartSize();
        $this->state->setPartSize($this->partSize);
    }

    public function upload(): Result
    {
        return $this->promise()->wait();
    }

    public function promise(): Promise
    {
        return new Promise(function (Promise $self): void {
            $self->resolve($this->run());
        });
    }

    public function getState(): UploadState
    {
        return $this->state;
    }

    private function run(): Result
    {
        if (!$this->state->isInitiated()) {
            $this->initiate();
        }

        $this->uploadParts();

        return $this->complete();
    }

    private function initiate(): void
    {
        $args = $this->config['params'];
        $args['Bucket'] = $this->state->getId()['Bucket'];
        $args['Key'] = $this->state->getId()['Key'];

        if (!empty($this->config['acl'])) {
            $args['ACL'] = $this->config['acl'];
        }

        // 按源文件名推断 Content-Type，与整体上传行为一致
        if (!isset($args['ContentType']) && $this->sourceFile !== null) {
            $type = \MinS3\MimeType::fromFilename($this->sourceFile);
            if ($type !== null) {
                $args['ContentType'] = $type;
            }
        }

        $command = $this->client->getCommand('CreateMultipartUpload', $args);
        $this->invokeCallback('before_initiate', $command);

        try {
            $result = $this->client->execute($command);
        } catch (\Throwable $e) {
            throw new MultipartUploadException($this->state, [], $e);
        }

        $this->state->setUploadId($result['UploadId']);
        $this->state->setStatus(UploadState::INITIATED);
    }

    private function uploadParts(): void
    {
        $failures = [];

        Each::ofLimit(
            $this->createPartGenerator(),
            (int) $this->config['concurrency'],
            function (Result $result, int $partNumber): void {
                $this->state->markPartUploaded($partNumber, [
                    'PartNumber' => $partNumber,
                    'ETag'       => $result['ETag'],
                ] + $this->extractChecksums($result));
            },
            static function (\Throwable $reason, int $partNumber) use (&$failures): void {
                $failures[$partNumber] = $reason;
            }
        )->wait();

        if ($failures !== []) {
            throw new MultipartUploadException($this->state, $failures);
        }
    }

    /**
     * 惰性产出每个分片的上传任务。
     *
     * 生成器保证分片是按需创建的：对不可重复打开的源（内存流、
     * 网络流），Each 每腾出一个槽位才读下一段，因此读取始终串行，
     * 内存占用上限是 并发数 × 分片大小。
     *
     * @return \Generator<int, callable>
     */
    private function createPartGenerator(): \Generator
    {
        while (true) {
            $partNumber = $this->nextPartNumber++;

            if ($partNumber > self::PART_MAX_NUM) {
                throw new \RuntimeException(
                    '分片数超过上限 ' . self::PART_MAX_NUM . '，请调大 part_size'
                );
            }

            // 续传：跳过已完成的分片，但源流仍需前移
            if ($this->state->hasPart($partNumber)) {
                if ($this->sourceFile === null) {
                    $this->skipBytes($this->partSize);
                }
                if ($this->isSourceExhausted($partNumber)) {
                    return;
                }
                continue;
            }

            $body = $this->createPartBody($partNumber);
            if ($body === null) {
                return;
            }

            $size = $body->getSize();
            yield $partNumber => function () use ($partNumber, $body, $size): Promise {
                $args = $this->config['params'];
                $args['Bucket'] = $this->state->getId()['Bucket'];
                $args['Key'] = $this->state->getId()['Key'];
                $args['UploadId'] = $this->state->getUploadId();
                $args['PartNumber'] = $partNumber;
                $args['Body'] = $body;
                if ($size !== null) {
                    $args['ContentLength'] = $size;
                }

                $command = $this->client->getCommand('UploadPart', $args);
                $this->invokeCallback('before_upload', $command);

                return $this->client->executeAsync($command);
            };
        }
    }

    /**
     * 切出一个分片的内容。
     *
     * @return Stream|null null 表示源已读完
     */
    private function createPartBody(int $partNumber): ?Stream
    {
        if ($this->sourceFile !== null) {
            // 真实文件：每个分片独立开句柄，并发读互不干扰，且零拷贝
            $offset = ($partNumber - 1) * $this->partSize;
            $total = $this->source->getSize();
            if ($total !== null && $offset >= $total) {
                return null;
            }

            $handle = Stream::open($this->sourceFile, 'r');
            $part = new LimitStream($handle, $this->partSize, $offset);

            return $part->getSize() === 0 ? null : $part;
        }

        // 其他来源：顺序读一段到临时流
        $buffer = Stream::create('');
        Stream::copyTo($this->source, $buffer, $this->partSize);

        if ($buffer->getSize() === 0) {
            return null;
        }

        $buffer->rewind();

        return $buffer;
    }

    private function skipBytes(int $length): void
    {
        $sink = Stream::create('');
        Stream::copyTo($this->source, $sink, $length);
    }

    private function isSourceExhausted(int $partNumber): bool
    {
        $total = $this->source->getSize();
        if ($total === null) {
            return $this->source->eof();
        }

        return $partNumber * $this->partSize >= $total;
    }

    private function complete(): Result
    {
        $parts = [];
        foreach ($this->state->getUploadedParts() as $part) {
            // @ 开头的是内部记账字段，不能发给服务端
            $parts[] = array_filter(
                $part,
                static fn(string $k): bool => !str_starts_with($k, '@'),
                ARRAY_FILTER_USE_KEY
            );
        }

        if ($parts === []) {
            throw new MultipartUploadException(
                $this->state,
                [],
                new \RuntimeException('没有任何分片被上传，源内容为空')
            );
        }

        $args = $this->config['params'];
        $args['Bucket'] = $this->state->getId()['Bucket'];
        $args['Key'] = $this->state->getId()['Key'];
        $args['UploadId'] = $this->state->getUploadId();
        $args['MultipartUpload'] = ['Parts' => $parts];

        $command = $this->client->getCommand('CompleteMultipartUpload', $args);
        $this->invokeCallback('before_complete', $command);

        try {
            $result = $this->client->execute($command);
        } catch (\Throwable $e) {
            throw new MultipartUploadException($this->state, [], $e);
        }

        $this->state->setStatus(UploadState::COMPLETED);

        return $result;
    }

    /**
     * 放弃这次分片上传，清理服务端已存的分片，避免产生计费但不可见的垃圾数据。
     */
    public function abort(): ?Result
    {
        $uploadId = $this->state->getUploadId();
        if ($uploadId === null) {
            return null;
        }

        return $this->client->execute($this->client->getCommand('AbortMultipartUpload', [
            'Bucket'   => $this->state->getId()['Bucket'],
            'Key'      => $this->state->getId()['Key'],
            'UploadId' => $uploadId,
        ]));
    }

    private function extractChecksums(Result $result): array
    {
        $checksums = [];
        foreach (['ChecksumCRC32', 'ChecksumCRC32C', 'ChecksumSHA1', 'ChecksumSHA256'] as $key) {
            if ($result[$key] !== null) {
                $checksums[$key] = $result[$key];
            }
        }

        return $checksums;
    }

    private function invokeCallback(string $name, Command $command): void
    {
        $callback = $this->config[$name] ?? null;
        if (is_callable($callback)) {
            $callback($command);
        }
    }

    private function resolveSource(mixed $source): Stream
    {
        if (is_string($source)) {
            // 与 ObjectUploader 用同一套判断：确实是文件才按路径处理，
            // 否则当成待上传的内容本身
            if (ObjectUploader::looksLikeFilePath($source)) {
                $this->sourceFile = $source;

                return Stream::open($source, 'r');
            }

            return Stream::create($source);
        }

        $stream = Stream::create($source);

        // 真实文件流也能享受独立句柄的并发优化
        $uri = $stream->getMetadata('uri');
        if (is_string($uri) && $uri !== '' && is_file($uri) && is_readable($uri)) {
            $this->sourceFile = $uri;
        }

        return $stream;
    }

    /**
     * 分片大小：不小于 5 MB，且保证分片数不超过 10000。
     */
    private function determinePartSize(): int
    {
        // 续传时沿用原来的分片大小，否则分片边界对不上
        $partSize = (int) (($this->config['state'] ?? null)?->getPartSize()
            ?? $this->config['part_size']);
        $partSize = max($partSize, self::PART_MIN_SIZE);

        $sourceSize = $this->source->getSize();
        if ($sourceSize !== null && $sourceSize > 0) {
            $needed = (int) ceil($sourceSize / self::PART_MAX_NUM);
            $partSize = max($partSize, $needed);
        }

        if ($partSize > self::PART_MAX_SIZE) {
            throw new \InvalidArgumentException(
                'part_size 超过 5 GB 上限，源文件过大无法上传'
            );
        }

        return $partSize;
    }
}
