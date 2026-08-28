<?php
namespace MinS3;

use MinS3\Multipart\ObjectUploader;
use MinS3\Promise\Each;
use MinS3\Promise\Promise;

/**
 * 目录与存储桶之间的批量传输。
 *
 * 源和目标一个是本地路径，另一个是 s3://bucket/prefix：
 *
 *     new Transfer($s3, '/var/www/assets', 's3://my-bucket/assets');  // 上传
 *     new Transfer($s3, 's3://my-bucket/assets', '/var/www/assets');  // 下载
 */
class Transfer
{
    private S3Client $client;
    private string $source;
    private string $destination;
    private array $options;

    private bool $isUpload;

    /**
     * 配置项：
     *  - concurrency  并发数，默认 5
     *  - base_dir     计算相对路径的基准目录，默认取源目录
     *  - before       每个子命令执行前的回调 function (Command $command)
     *  - debug        true 或可写流，输出传输进度
     *  - mup_threshold 超过该大小走分片上传
     *  - params       附加到每个请求的参数
     */
    public function __construct(
        S3Client $client,
        string $source,
        string $destination,
        array $options = []
    ) {
        $this->client = $client;
        $this->source = $source;
        $this->destination = $destination;
        $this->options = $options + ['concurrency' => 5];

        $sourceIsS3 = $this->isS3Uri($source);
        $destIsS3 = $this->isS3Uri($destination);

        if ($sourceIsS3 === $destIsS3) {
            throw new \InvalidArgumentException(
                '源和目标必须一个是本地路径、一个是 s3:// 地址'
            );
        }

        $this->isUpload = $destIsS3;

        if ($this->isUpload && !is_dir($source)) {
            throw new \InvalidArgumentException("本地目录不存在: {$source}");
        }
    }

    public function transfer(): void
    {
        $this->promise()->wait();
    }

    public function promise(): Promise
    {
        return new Promise(function (Promise $self): void {
            $tasks = $this->isUpload ? $this->uploadTasks() : $this->downloadTasks();

            Each::ofLimit($tasks, (int) $this->options['concurrency'])->wait();

            $self->resolve(null);
        });
    }

    /**
     * @return \Generator<int, callable>
     */
    private function uploadTasks(): \Generator
    {
        ['bucket' => $bucket, 'key' => $prefix] = $this->parseS3Uri($this->destination);
        $baseDir = rtrim($this->options['base_dir'] ?? $this->source, '/\\');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $index = 0;
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relative = ltrim(substr($path, strlen($baseDir)), '/\\');
            // S3 的 key 一律用正斜杠，Windows 下的反斜杠要转换
            $key = ($prefix !== '' ? rtrim($prefix, '/') . '/' : '') . str_replace('\\', '/', $relative);

            yield $index++ => function () use ($path, $bucket, $key): Promise {
                return new Promise(function (Promise $self) use ($path, $bucket, $key): void {
                    $this->debug("上传 {$path} -> s3://{$bucket}/{$key}");

                    $uploader = new ObjectUploader(
                        $this->client,
                        $bucket,
                        $key,
                        $path,
                        '',
                        $this->uploadOptions()
                    );

                    $self->resolve($uploader->upload());
                });
            };
        }
    }

    /**
     * @return \Generator<int, callable>
     */
    private function downloadTasks(): \Generator
    {
        ['bucket' => $bucket, 'key' => $prefix] = $this->parseS3Uri($this->source);
        $targetDir = rtrim($this->destination, '/\\');

        $listArgs = ['Bucket' => $bucket];
        if ($prefix !== '') {
            $listArgs['Prefix'] = $prefix;
        }

        $index = 0;
        foreach ($this->client->getIterator('ListObjectsV2', $listArgs) as $object) {
            $key = $object['Key'];

            // 以 / 结尾的是目录占位对象，建目录即可
            if (str_ends_with($key, '/')) {
                continue;
            }

            $relative = $prefix !== '' ? ltrim(substr($key, strlen(rtrim($prefix, '/'))), '/') : $key;
            $target = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            yield $index++ => function () use ($bucket, $key, $target): Promise {
                return new Promise(function (Promise $self) use ($bucket, $key, $target): void {
                    $dir = dirname($target);
                    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                        throw new \RuntimeException("无法创建目录: {$dir}");
                    }

                    $this->debug("下载 s3://{$bucket}/{$key} -> {$target}");

                    $args = ($this->options['params'] ?? []) + [
                        'Bucket' => $bucket,
                        'Key'    => $key,
                        // 直接落盘，不经过内存
                        '@http'  => ['sink' => $target],
                    ];

                    $command = $this->client->getCommand('GetObject', $args);
                    $this->invokeBefore($command);

                    $self->resolve($this->client->execute($command));
                });
            };
        }
    }

    private function uploadOptions(): array
    {
        $options = [];

        if (isset($this->options['mup_threshold'])) {
            $options['mup_threshold'] = $this->options['mup_threshold'];
        }
        if (isset($this->options['params'])) {
            $options['params'] = $this->options['params'];
        }
        if (isset($this->options['before'])) {
            $options['before_upload'] = $this->options['before'];
        }
        if (isset($this->options['concurrency'])) {
            $options['concurrency'] = $this->options['concurrency'];
        }

        return $options;
    }

    private function invokeBefore(Command $command): void
    {
        if (isset($this->options['before']) && is_callable($this->options['before'])) {
            ($this->options['before'])($command);
        }
    }

    private function debug(string $message): void
    {
        $debug = $this->options['debug'] ?? false;
        if ($debug === false) {
            return;
        }

        $line = $message . PHP_EOL;

        if (is_resource($debug)) {
            fwrite($debug, $line);

            return;
        }

        echo $line;
    }

    private function isS3Uri(string $path): bool
    {
        return str_starts_with($path, 's3://');
    }

    /**
     * @return array{bucket: string, key: string}
     */
    private function parseS3Uri(string $uri): array
    {
        $path = substr($uri, 5);
        $slash = strpos($path, '/');

        if ($slash === false) {
            return ['bucket' => $path, 'key' => ''];
        }

        return [
            'bucket' => substr($path, 0, $slash),
            'key'    => substr($path, $slash + 1),
        ];
    }
}
