<?php
namespace MinS3;

use MinS3\Exception\S3Exception;
use MinS3\Multipart\ObjectUploader;
use MinS3\Promise\Promise;

/**
 * S3Client 的便捷方法。
 *
 * 方法签名与 aws-sdk-php 的 S3ClientTrait 保持一致，从原 SDK
 * 迁移过来的代码不需要改调用处。
 */
trait S3ClientTrait
{
    /**
     * 上传对象，按大小自动选择整体上传或分片上传。
     *
     * @param string $bucket
     * @param string $key
     * @param mixed  $body   字符串、文件句柄或 Stream
     * @param string $acl
     * @param array  $options before_upload / concurrency / part_size / params / mup_threshold
     */
    public function upload(
        string $bucket,
        string $key,
        mixed $body,
        string $acl = 'private',
        array $options = []
    ): Result {
        return $this->uploadAsync($bucket, $key, $body, $acl, $options)->wait();
    }

    public function uploadAsync(
        string $bucket,
        string $key,
        mixed $body,
        string $acl = 'private',
        array $options = []
    ): Promise {
        return (new ObjectUploader($this, $bucket, $key, $body, $acl, $options))->promise();
    }

    /**
     * 服务端复制对象，大对象自动走分片复制。
     */
    public function copy(
        string $fromBucket,
        string $fromKey,
        string $destBucket,
        string $destKey,
        string $acl = 'private',
        array $options = []
    ): Result {
        return $this->copyAsync($fromBucket, $fromKey, $destBucket, $destKey, $acl, $options)->wait();
    }

    public function copyAsync(
        string $fromBucket,
        string $fromKey,
        string $destBucket,
        string $destKey,
        string $acl = 'private',
        array $options = []
    ): Promise {
        $source = ['Bucket' => $fromBucket, 'Key' => $fromKey];
        if (isset($options['version_id'])) {
            $source['VersionId'] = $options['version_id'];
        }

        return (new ObjectCopier(
            $this,
            $source,
            ['Bucket' => $destBucket, 'Key' => $destKey],
            $acl,
            $options
        ))->promise();
    }

    /**
     * 注册 s3:// 流包装器，之后可以用 file_get_contents('s3://bucket/key') 等。
     */
    public function registerStreamWrapper(string $protocol = 's3'): void
    {
        StreamWrapper::register($this, $protocol);
    }

    /**
     * 递归上传本地目录。
     */
    public function uploadDirectory(
        string $directory,
        string $bucket,
        ?string $keyPrefix = null,
        array $options = []
    ): void {
        $this->uploadDirectoryAsync($directory, $bucket, $keyPrefix, $options)->wait();
    }

    public function uploadDirectoryAsync(
        string $directory,
        string $bucket,
        ?string $keyPrefix = null,
        array $options = []
    ): Promise {
        $dest = "s3://{$bucket}" . ($keyPrefix ? '/' . ltrim($keyPrefix, '/') : '');

        return (new Transfer($this, $directory, $dest, $options))->promise();
    }

    /**
     * 把桶（或指定前缀）下载到本地目录。
     */
    public function downloadBucket(
        string $directory,
        string $bucket,
        string $keyPrefix = '',
        array $options = []
    ): void {
        $this->downloadBucketAsync($directory, $bucket, $keyPrefix, $options)->wait();
    }

    public function downloadBucketAsync(
        string $directory,
        string $bucket,
        string $keyPrefix = '',
        array $options = []
    ): Promise {
        $source = "s3://{$bucket}" . ($keyPrefix ? '/' . ltrim($keyPrefix, '/') : '');

        return (new Transfer($this, $source, $directory, $options))->promise();
    }

    /**
     * 批量删除前缀或正则匹配的对象。
     */
    public function deleteMatchingObjects(
        string $bucket,
        string $prefix = '',
        string $regex = '',
        array $options = []
    ): void {
        if ($prefix === '' && $regex === '') {
            throw new \RuntimeException('必须提供 prefix 或 regex，否则会删空整个桶');
        }

        $iterator = $this->getIterator('ListObjectsV2', ['Bucket' => $bucket, 'Prefix' => $prefix]);

        if ($regex !== '') {
            $iterator = (static function () use ($iterator, $regex): \Generator {
                foreach ($iterator as $object) {
                    if (preg_match($regex, $object['Key'])) {
                        yield $object;
                    }
                }
            })();
        }

        BatchDelete::fromIterator($this, $bucket, $iterator, $options)->delete();
    }

    /**
     * 桶是否存在。
     *
     * @param bool $accept403 为 true 时，403（无权限但桶存在）也算存在
     */
    public function doesBucketExist(string $bucket, bool $accept403 = false): bool
    {
        try {
            $this->execute($this->getCommand('HeadBucket', ['Bucket' => $bucket]));

            return true;
        } catch (S3Exception $e) {
            if ($accept403 && $e->getStatusCode() === 403) {
                return true;
            }

            if ($e->getStatusCode() === 404) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * 对象是否存在。
     *
     * @param bool $includeDeleteMarkers 为 true 时，带删除标记的对象也算存在
     */
    public function doesObjectExist(
        string $bucket,
        string $key,
        bool $includeDeleteMarkers = false,
        array $options = []
    ): bool {
        $command = $this->getCommand('HeadObject', [
            'Bucket' => $bucket,
            'Key'    => $key,
        ] + $options);

        try {
            $this->execute($command);

            return true;
        } catch (S3Exception $e) {
            if ($includeDeleteMarkers
                && $e->getResponse() !== null
                && $e->getResponse()->getHeaderLine('x-amz-delete-marker') !== ''
            ) {
                return true;
            }

            if ($e->getStatusCode() === 404) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * 自动翻页的分页器。
     */
    public function getPaginator(string $operation, array $args = []): Paginator
    {
        return new Paginator($this, $operation, $args);
    }

    /**
     * 跨页遍历结果项，直接拿到每个对象而不是每一页。
     *
     *     foreach ($s3->getIterator('ListObjectsV2', ['Bucket' => 'b']) as $object) {
     *         echo $object['Key'];
     *     }
     */
    public function getIterator(string $operation, array $args = []): \Generator
    {
        return $this->getPaginator($operation, $args)->items();
    }

    /**
     * 轮询等待资源就绪。
     */
    public function waitUntil(string $name, array $args = []): void
    {
        (new Waiter($this, $name, $args))->wait();
    }

    /**
     * 生成预签名的 GET URL，最常用的分享场景。
     *
     * @param int|string|\DateTimeInterface $expires
     */
    public function createPresignedUrl(
        string $bucket,
        string $key,
        int|string|\DateTimeInterface $expires = '+20 minutes',
        array $args = []
    ): string {
        $command = $this->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key] + $args);

        return (string) $this->createPresignedRequest($command, $expires)->getUri();
    }
}
