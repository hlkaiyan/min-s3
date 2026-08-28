<?php
namespace MinS3\Multipart;

use MinS3\Http\Stream;
use MinS3\Promise\Promise;
use MinS3\Result;
use MinS3\S3Client;

/**
 * 按大小自动选择上传方式：小对象一次 PutObject，大对象走分片上传。
 *
 * 对于长度未知的流（管道、网络流），先试读阈值那么多字节：
 * 读满了说明是大对象，走分片；没读满就直接整体上传。
 */
class ObjectUploader
{
    /** 超过这个大小改用分片上传 */
    public const DEFAULT_MULTIPART_THRESHOLD = 16777216; // 16 MB

    private S3Client $client;
    private string $bucket;
    private string $key;
    private mixed $body;
    private string $acl;
    private array $options;

    public function __construct(
        S3Client $client,
        string $bucket,
        string $key,
        mixed $body,
        string $acl = 'private',
        array $options = []
    ) {
        $this->client = $client;
        $this->bucket = $bucket;
        $this->key = $key;
        $this->body = $body;
        $this->acl = $acl;
        $this->options = $options;
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

    private function run(): Result
    {
        $threshold = (int) ($this->options['mup_threshold'] ?? self::DEFAULT_MULTIPART_THRESHOLD);

        if ($threshold < MultipartUploader::PART_MIN_SIZE) {
            throw new \InvalidArgumentException(
                'mup_threshold 不能小于 ' . MultipartUploader::PART_MIN_SIZE . ' 字节'
            );
        }

        // 文件路径：直接看文件大小，不必打开
        if (is_string($this->body) && $this->looksLikeFilePath($this->body)) {
            return filesize($this->body) >= $threshold
                ? $this->multipart($this->body)
                : $this->putObject(Stream::open($this->body, 'r'));
        }

        $stream = Stream::create($this->body);
        $size = $stream->getSize();

        if ($size !== null) {
            return $size >= $threshold
                ? $this->multipart($stream)
                : $this->putObject($stream);
        }

        // 长度未知：预读阈值大小，据此决定走哪条路
        [$buffered, $exhausted] = $this->peek($stream, $threshold);

        if ($exhausted) {
            return $this->putObject($buffered);
        }

        // 没读完，说明超过阈值。把已读部分和剩余部分接起来交给分片上传
        return $this->multipart(new ConcatStream([$buffered, $stream]));
    }

    /**
     * 读取至多 $limit 字节。
     *
     * @return array{0: Stream, 1: bool} 缓冲流，以及源是否已读完
     */
    private function peek(Stream $stream, int $limit): array
    {
        $buffer = Stream::create('');
        Stream::copyTo($stream, $buffer, $limit);
        $buffer->rewind();

        return [$buffer, $stream->eof()];
    }

    private function putObject(Stream $body): Result
    {
        $args = ($this->options['params'] ?? []) + [
            'Bucket' => $this->bucket,
            'Key'    => $this->key,
            'Body'   => $body,
        ];

        if ($this->acl !== '') {
            $args['ACL'] ??= $this->acl;
        }

        if (isset($this->options['before_upload'])) {
            $command = $this->client->getCommand('PutObject', $args);
            ($this->options['before_upload'])($command);

            return $this->client->execute($command);
        }

        return $this->client->execute($this->client->getCommand('PutObject', $args));
    }

    private function multipart(mixed $source): Result
    {
        $config = $this->options + [
            'bucket' => $this->bucket,
            'key'    => $this->key,
        ];

        if ($this->acl !== '') {
            $config['acl'] ??= $this->acl;
        }

        unset($config['mup_threshold']);

        return (new MultipartUploader($this->client, $source, $config))->upload();
    }

    /**
     * 判断字符串该当作文件路径还是对象内容。
     *
     * 传字符串既可能是"上传这个文件"也可能是"上传这段内容"，
     * 只有确实存在且可读的短路径才按文件处理，其余一律当内容，
     * 否则一段恰好等于某个路径的文本会被当成文件上传。
     */
    public static function looksLikeFilePath(string $value): bool
    {
        // 内容里有换行或 NUL 肯定不是路径；过长的字符串也不必去碰文件系统
        if ($value === '' || strlen($value) > 4096 || strpbrk($value, "\n\r\0") !== false) {
            return false;
        }

        return @is_file($value) && @is_readable($value);
    }
}
