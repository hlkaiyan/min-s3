<?php
namespace MinS3\Http;

use MinS3\Exception\ConnectException;
use MinS3\Promise\Promise;

/**
 * 基于 curl_multi 的 HTTP 传输层。
 *
 * 请求在 __invoke 时立即挂入 multi 队列，因此多个未 wait 的请求是
 * 真并发；随后对任意一个调用 wait() 都会驱动整个队列前进。分片
 * 并发上传依赖这个性质。
 *
 * 上传与下载都走流式回调，不会把对象内容整体读进内存。
 */
final class CurlHandler
{
    /** @var \CurlMultiHandle|null */
    private $multi = null;

    /** @var array<int, array{promise: Promise, ch: \CurlHandle, request: Request, sink: Stream, headers: string[], options: array}> */
    private array $active = [];

    private array $defaultOptions;

    /**
     * @param array $defaultOptions 每个请求的默认 curl 选项，见 __invoke
     */
    public function __construct(array $defaultOptions = [])
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('min-s3 需要 ext-curl 扩展');
        }

        $this->defaultOptions = $defaultOptions;
    }

    public function __destruct()
    {
        foreach ($this->active as $entry) {
            if ($this->multi !== null) {
                curl_multi_remove_handle($this->multi, $entry['ch']);
            }
            curl_close($entry['ch']);
        }
        $this->active = [];

        if ($this->multi !== null) {
            curl_multi_close($this->multi);
            $this->multi = null;
        }
    }

    /**
     * 发起请求，立即返回 promise。
     *
     * 支持的选项：
     *  - timeout          (float) 整体超时秒数，0 表示不限
     *  - connect_timeout  (float) 连接超时秒数
     *  - verify           (bool|string) 校验证书，或 CA 包路径
     *  - proxy            (string) 代理地址
     *  - sink             (Stream|string) 响应体去向，字符串视为文件路径
     *  - cert             (string|array) 客户端证书，数组形式为 [路径, 密码]
     *  - ssl_key          (string|array) 客户端私钥
     *  - force_ip_resolve ('v4'|'v6')
     *  - curl             (array) 直接透传的 curl 选项，优先级最高
     */
    public function __invoke(Request $request, array $options = []): Promise
    {
        $options += $this->defaultOptions;

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('curl_init 失败');
        }

        $sink = $this->resolveSink($options);
        $id = spl_object_id($ch);

        $headers = [];
        curl_setopt_array($ch, $this->buildOptions($request, $options, $sink, $headers));

        $promise = new Promise(function (Promise $self) use ($id): void {
            $this->tickUntilDone($id);
        });

        $this->active[$id] = [
            'promise' => $promise,
            'ch'      => $ch,
            'request' => $request,
            'sink'    => $sink,
            'headers' => &$headers,
            'options' => $options,
        ];

        curl_multi_add_handle($this->getMulti(), $ch);
        // 立即触发一次，让请求开始发送而不必等到 wait()
        $this->execMulti();

        return $promise;
    }

    /**
     * @param string[] $headerLines 由 HEADERFUNCTION 回填
     */
    private function buildOptions(
        Request $request,
        array $options,
        Stream $sink,
        array &$headerLines
    ): array {
        $method = $request->getMethod();
        $body = $request->getBody();
        $size = $body->getSize();
        $hasBody = $size === null || $size > 0;

        $curlOptions = [
            CURLOPT_URL            => (string) $request->getUri(),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION   => $request->getProtocolVersion() === '1.0'
                ? CURL_HTTP_VERSION_1_0
                : CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headerLines): int {
                $headerLines[] = $line;

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use ($sink): int {
                return $sink->write($data);
            },
        ];

        if ($method === 'HEAD') {
            $curlOptions[CURLOPT_NOBODY] = true;
            $hasBody = false;
        } else {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
        }

        if ($hasBody) {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            $curlOptions[CURLOPT_UPLOAD] = true;
            // CURLOPT_UPLOAD 会把方法改成 PUT，必须再次覆盖回真实方法
            $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
            $curlOptions[CURLOPT_READFUNCTION] = static function ($ch, $fd, int $length) use ($body): string {
                return $body->read($length);
            };

            if ($size !== null) {
                $curlOptions[CURLOPT_INFILESIZE] = $size;
            }
        }

        $curlOptions[CURLOPT_HTTPHEADER] = $this->flattenHeaders($request, $hasBody);

        $this->applyTransferOptions($curlOptions, $options);

        // 调用方直传的 curl 选项覆盖以上所有默认值
        if (!empty($options['curl']) && is_array($options['curl'])) {
            $curlOptions = $options['curl'] + $curlOptions;
        }

        return $curlOptions;
    }

    /**
     * @return string[]
     */
    private function flattenHeaders(Request $request, bool $hasBody): array
    {
        $lines = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                // curl 的空值头写法是 "Name;"，写成 "Name:" 会被当作删除该头
                $lines[] = $value === '' ? "{$name};" : "{$name}: {$value}";
            }
        }

        if (!$request->hasHeader('Expect')) {
            // 默认禁用 100-continue：多一次往返，且部分自建 S3 网关处理不当
            $lines[] = 'Expect:';
        }

        if (!$hasBody && !$request->hasHeader('Content-Length')
            && in_array($request->getMethod(), ['POST', 'PUT'], true)
        ) {
            $lines[] = 'Content-Length: 0';
        }

        // curl 默认会加 Accept，签名里没有它不影响，但保持报文干净
        if (!$request->hasHeader('Accept')) {
            $lines[] = 'Accept:';
        }

        return $lines;
    }

    private function applyTransferOptions(array &$curlOptions, array $options): void
    {
        $curlOptions[CURLOPT_TIMEOUT_MS] = (int) (($options['timeout'] ?? 0) * 1000);
        $curlOptions[CURLOPT_CONNECTTIMEOUT_MS] = (int) (($options['connect_timeout'] ?? 10) * 1000);

        $verify = $options['verify'] ?? true;
        if ($verify === false) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        } else {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 2;
            if (is_string($verify)) {
                if (is_dir($verify)) {
                    $curlOptions[CURLOPT_CAPATH] = $verify;
                } elseif (is_file($verify)) {
                    $curlOptions[CURLOPT_CAINFO] = $verify;
                } else {
                    throw new \InvalidArgumentException("CA 证书路径不存在: {$verify}");
                }
            }
        }

        if (!empty($options['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = $options['proxy'];
        }

        if (!empty($options['cert'])) {
            $cert = $options['cert'];
            if (is_array($cert)) {
                $curlOptions[CURLOPT_SSLCERTPASSWD] = $cert[1];
                $cert = $cert[0];
            }
            $curlOptions[CURLOPT_SSLCERT] = $cert;
        }

        if (!empty($options['ssl_key'])) {
            $key = $options['ssl_key'];
            if (is_array($key)) {
                $curlOptions[CURLOPT_SSLKEYPASSWD] = $key[1];
                $key = $key[0];
            }
            $curlOptions[CURLOPT_SSLKEY] = $key;
        }

        if (!empty($options['force_ip_resolve'])) {
            $curlOptions[CURLOPT_IPRESOLVE] = $options['force_ip_resolve'] === 'v6'
                ? CURL_IPRESOLVE_V6
                : CURL_IPRESOLVE_V4;
        }
    }

    private function resolveSink(array $options): Stream
    {
        $sink = $options['sink'] ?? null;

        if ($sink === null) {
            return Stream::create('');
        }

        if ($sink instanceof Stream) {
            return $sink;
        }

        if (is_string($sink)) {
            return Stream::open($sink, 'w+');
        }

        if (is_resource($sink)) {
            return new Stream($sink);
        }

        throw new \InvalidArgumentException('sink 必须是 Stream、文件路径或流资源');
    }

    /**
     * @return \CurlMultiHandle
     */
    private function getMulti()
    {
        if ($this->multi === null) {
            $this->multi = curl_multi_init();
        }

        return $this->multi;
    }

    private function tickUntilDone(int $id): void
    {
        while (isset($this->active[$id])) {
            $this->tick();
        }
    }

    private function tick(): void
    {
        $multi = $this->getMulti();

        $running = $this->execMulti();

        // 队列里还有在途请求时阻塞等待可读，避免空转烧 CPU
        if ($running > 0 && curl_multi_select($multi, 0.5) === -1) {
            // 部分平台在没有 fd 时立即返回 -1，退让一小段时间
            usleep(20000);
        }

        while (($info = curl_multi_info_read($multi)) !== false) {
            $this->finish($info);
        }

        if ($running === 0 && $this->active !== []) {
            // 句柄已跑完但没读到完成信息，再取一轮，防止死循环
            while (($info = curl_multi_info_read($multi)) !== false) {
                $this->finish($info);
            }

            if ($this->active !== []) {
                foreach (array_keys($this->active) as $pendingId) {
                    $entry = $this->active[$pendingId];
                    unset($this->active[$pendingId]);
                    curl_multi_remove_handle($multi, $entry['ch']);
                    curl_close($entry['ch']);
                    $entry['promise']->reject(new ConnectException(
                        'curl 传输意外终止',
                        $entry['request']
                    ));
                }
            }
        }
    }

    private function execMulti(): int
    {
        $multi = $this->getMulti();
        $running = 0;

        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($status !== CURLM_OK) {
            throw new \RuntimeException(
                'curl_multi_exec 失败: ' . curl_multi_strerror($status)
            );
        }

        return $running;
    }

    private function finish(array $info): void
    {
        $ch = $info['handle'];
        $id = spl_object_id($ch);

        if (!isset($this->active[$id])) {
            return;
        }

        $entry = $this->active[$id];
        unset($this->active[$id]);

        curl_multi_remove_handle($this->getMulti(), $ch);

        $errno = $info['result'];
        if ($errno !== CURLE_OK) {
            $message = curl_strerror($errno) ?: 'curl 错误';
            $detail = curl_error($ch);
            curl_close($ch);

            $entry['promise']->reject(new ConnectException(
                sprintf('%s (curl errno %d)%s', $message, $errno, $detail ? ": {$detail}" : ''),
                $entry['request']
            ));

            return;
        }

        curl_close($ch);

        try {
            $entry['promise']->resolve(
                $this->buildResponse($entry['headers'], $entry['sink'])
            );
        } catch (\Throwable $e) {
            $entry['promise']->reject($e);
        }
    }

    /**
     * @param string[] $headerLines
     */
    private function buildResponse(array $headerLines, Stream $sink): Response
    {
        $status = 200;
        $reason = null;
        $version = '1.1';
        $headers = [];

        foreach ($headerLines as $line) {
            $line = trim($line, "\r\n");
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'HTTP/')) {
                // 出现新的状态行说明前面是 100-continue 或重定向的头，丢弃重来
                $headers = [];
                $parts = explode(' ', $line, 3);
                $version = substr($parts[0], 5);
                $status = isset($parts[1]) ? (int) $parts[1] : 200;
                $reason = $parts[2] ?? null;
                continue;
            }

            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }

            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            $headers[$name][] = $value;
        }

        if ($sink->isSeekable()) {
            $sink->rewind();
        }

        return new Response($status, $headers, $sink, $version, $reason);
    }
}
