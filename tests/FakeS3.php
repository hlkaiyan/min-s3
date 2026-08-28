<?php
/**
 * 内存版 S3 服务端，用于端到端测试。
 *
 * 实现了 min-s3 会用到的那部分协议：对象增删查、列举与翻页、
 * 分片上传、批量删除、桶操作。行为按 S3 规范来（比如列举要排序、
 * IsTruncated 的语义、分片必须按序合并），这样测出来的问题
 * 才是真问题。
 */

use MinS3\Http\Request;
use MinS3\Http\Response;
use MinS3\Promise\Promise;

class FakeS3
{
    /** @var array<string, array<string, array{body: string, headers: array, mtime: int}>> */
    public array $buckets = [];

    /** @var array<string, array{bucket: string, key: string, parts: array}> */
    public array $uploads = [];

    /** @var array<int, array{method: string, path: string, query: string}> 收到的请求，供断言用 */
    public array $log = [];

    private int $uploadSeq = 0;

    /** @var callable|null 注入故障用：返回 Response 则直接短路 */
    public $interceptor = null;

    public function __construct(array $buckets = [])
    {
        foreach ($buckets as $name) {
            $this->buckets[$name] = [];
        }
    }

    public function handler(): callable
    {
        return function (Request $request, array $options): Promise {
            $response = $this->dispatch($request);

            // sink 语义要模拟到位，否则测不出 SaveAs / 目录下载
            if (isset($options['sink']) && $response->getStatusCode() < 300) {
                $body = (string) $response->getBody();
                if (is_string($options['sink'])) {
                    @mkdir(dirname($options['sink']), 0777, true);
                    file_put_contents($options['sink'], $body);
                } elseif ($options['sink'] instanceof MinS3\Http\Stream) {
                    $options['sink']->write($body);
                    $options['sink']->rewind();
                }
            }

            return Promise::resolved($response);
        };
    }

    private function dispatch(Request $request): Response
    {
        $uri = $request->getUri();
        $method = $request->getMethod();
        $path = rawurldecode($uri->getPath());
        parse_str($uri->getQuery(), $query);

        $this->log[] = ['method' => $method, 'path' => $path, 'query' => $uri->getQuery()];

        if ($this->interceptor !== null) {
            $injected = ($this->interceptor)($request, count($this->log));
            if ($injected instanceof Response) {
                return $injected;
            }
        }

        $segments = explode('/', ltrim($path, '/'), 2);
        $bucket = $segments[0] ?? '';
        $key = $segments[1] ?? '';

        // 列出所有桶
        if ($bucket === '') {
            return $this->listBuckets();
        }

        if (!isset($this->buckets[$bucket]) && !($method === 'PUT' && $key === '')) {
            return $this->error(404, 'NoSuchBucket', "桶不存在: {$bucket}");
        }

        // 桶级操作
        if ($key === '') {
            if (array_key_exists('delete', $query) && $method === 'POST') {
                return $this->deleteObjects($bucket, (string) $request->getBody());
            }
            if (array_key_exists('uploads', $query)) {
                return $this->xml('<ListMultipartUploadsResult><Bucket>' . $bucket
                    . '</Bucket><IsTruncated>false</IsTruncated></ListMultipartUploadsResult>');
            }

            return match ($method) {
                'PUT'    => $this->createBucket($bucket),
                'DELETE' => $this->deleteBucket($bucket),
                'HEAD'   => new Response(200),
                'GET'    => $this->listObjects($bucket, $query),
                default  => $this->error(405, 'MethodNotAllowed', "不支持 {$method}"),
            };
        }

        // 分片上传
        if (array_key_exists('uploads', $query) && $method === 'POST') {
            return $this->createMultipartUpload($bucket, $key);
        }
        if (isset($query['uploadId'])) {
            return match ($method) {
                'PUT'    => $this->uploadPart($query['uploadId'], (int) $query['partNumber'], $request),
                'POST'   => $this->completeMultipartUpload($query['uploadId'], (string) $request->getBody()),
                'DELETE' => $this->abortMultipartUpload($query['uploadId']),
                'GET'    => $this->listParts($query['uploadId']),
                default  => $this->error(405, 'MethodNotAllowed', "不支持 {$method}"),
            };
        }

        // 对象级操作
        return match ($method) {
            'PUT'    => isset($request->getHeaders()['x-amz-copy-source'])
                            || $request->hasHeader('x-amz-copy-source')
                        ? $this->copyObject($bucket, $key, $request)
                        : $this->putObject($bucket, $key, $request),
            'GET'    => $this->getObject($bucket, $key, $request),
            'HEAD'   => $this->headObject($bucket, $key),
            'DELETE' => $this->deleteObject($bucket, $key),
            default  => $this->error(405, 'MethodNotAllowed', "不支持 {$method}"),
        };
    }

    // ---- 桶 ----

    private function listBuckets(): Response
    {
        $xml = '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
             . '<Owner><ID>owner</ID><DisplayName>owner</DisplayName></Owner><Buckets>';
        foreach (array_keys($this->buckets) as $name) {
            $xml .= '<Bucket><Name>' . $name . '</Name>'
                  . '<CreationDate>2024-01-01T00:00:00.000Z</CreationDate></Bucket>';
        }

        return $this->xml($xml . '</Buckets></ListAllMyBucketsResult>');
    }

    private function createBucket(string $bucket): Response
    {
        if (isset($this->buckets[$bucket])) {
            return $this->error(409, 'BucketAlreadyOwnedByYou', '桶已存在');
        }
        $this->buckets[$bucket] = [];

        return new Response(200, ['Location' => '/' . $bucket]);
    }

    private function deleteBucket(string $bucket): Response
    {
        if ($this->buckets[$bucket] !== []) {
            return $this->error(409, 'BucketNotEmpty', '桶非空');
        }
        unset($this->buckets[$bucket]);

        return new Response(204);
    }

    private function listObjects(string $bucket, array $query): Response
    {
        $prefix = $query['prefix'] ?? '';
        $delimiter = $query['delimiter'] ?? '';
        $maxKeys = (int) ($query['max-keys'] ?? 1000);
        $isV2 = ($query['list-type'] ?? '') === '2';
        $token = $isV2 ? ($query['continuation-token'] ?? '') : ($query['marker'] ?? '');

        $keys = array_keys($this->buckets[$bucket]);
        sort($keys);   // S3 保证按 UTF-8 字典序返回

        $keys = array_values(array_filter(
            $keys,
            static fn(string $k): bool => $prefix === '' || str_starts_with($k, $prefix)
        ));

        if ($token !== '') {
            $keys = array_values(array_filter($keys, static fn(string $k): bool => $k > $token));
        }

        $contents = [];
        $commonPrefixes = [];

        foreach ($keys as $k) {
            if ($delimiter !== '') {
                $rest = substr($k, strlen($prefix));
                $pos = strpos($rest, $delimiter);
                if ($pos !== false) {
                    $commonPrefixes[$prefix . substr($rest, 0, $pos + strlen($delimiter))] = true;
                    continue;
                }
            }
            $contents[] = $k;
        }

        $total = count($contents) + count($commonPrefixes);
        $truncated = $total > $maxKeys;
        $contents = array_slice($contents, 0, $maxKeys);

        $root = $isV2 ? 'ListBucketResult' : 'ListBucketResult';
        $xml = '<' . $root . ' xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
             . '<Name>' . $bucket . '</Name>'
             . '<Prefix>' . htmlspecialchars($prefix) . '</Prefix>'
             . '<MaxKeys>' . $maxKeys . '</MaxKeys>'
             . '<IsTruncated>' . ($truncated ? 'true' : 'false') . '</IsTruncated>';

        if ($isV2) {
            $xml .= '<KeyCount>' . count($contents) . '</KeyCount>';
            if ($truncated && $contents) {
                $xml .= '<NextContinuationToken>' . htmlspecialchars(end($contents))
                      . '</NextContinuationToken>';
            }
        } elseif ($truncated && $contents) {
            $xml .= '<NextMarker>' . htmlspecialchars(end($contents)) . '</NextMarker>';
        }

        foreach ($contents as $k) {
            $object = $this->buckets[$bucket][$k];
            $xml .= '<Contents>'
                  . '<Key>' . htmlspecialchars($k) . '</Key>'
                  . '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', $object['mtime']) . '</LastModified>'
                  . '<ETag>&quot;' . md5($object['body']) . '&quot;</ETag>'
                  . '<Size>' . strlen($object['body']) . '</Size>'
                  . '<StorageClass>STANDARD</StorageClass>'
                  . '</Contents>';
        }

        foreach (array_keys($commonPrefixes) as $cp) {
            $xml .= '<CommonPrefixes><Prefix>' . htmlspecialchars($cp) . '</Prefix></CommonPrefixes>';
        }

        return $this->xml($xml . '</' . $root . '>');
    }

    // ---- 对象 ----

    private function putObject(string $bucket, string $key, Request $request): Response
    {
        $body = (string) $request->getBody();

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            if (str_starts_with($lower, 'x-amz-meta-') || $lower === 'content-type') {
                $headers[$lower] = implode(', ', $values);
            }
        }

        $this->buckets[$bucket][$key] = [
            'body' => $body, 'headers' => $headers, 'mtime' => 1735689600,
        ];

        return new Response(200, ['ETag' => '"' . md5($body) . '"']);
    }

    private function getObject(string $bucket, string $key, Request $request): Response
    {
        if (!isset($this->buckets[$bucket][$key])) {
            return $this->error(404, 'NoSuchKey', "对象不存在: {$key}");
        }

        $object = $this->buckets[$bucket][$key];
        $body = $object['body'];
        $status = 200;
        $headers = $this->objectHeaders($object);

        // Range 请求：分片下载和断点续传依赖它
        $range = $request->getHeaderLine('Range');
        if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            $size = strlen($body);
            $start = $m[1] === '' ? 0 : (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            $body = substr($body, $start, $end - $start + 1);
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
            $headers['Content-Length'] = (string) strlen($body);
            $status = 206;
        }

        return new Response($status, $headers, $body);
    }

    private function headObject(string $bucket, string $key): Response
    {
        if (!isset($this->buckets[$bucket][$key])) {
            return $this->error(404, 'NotFound', '', true);
        }

        return new Response(200, $this->objectHeaders($this->buckets[$bucket][$key]));
    }

    private function deleteObject(string $bucket, string $key): Response
    {
        unset($this->buckets[$bucket][$key]);

        return new Response(204);
    }

    private function copyObject(string $bucket, string $key, Request $request): Response
    {
        $source = ltrim(rawurldecode($request->getHeaderLine('x-amz-copy-source')), '/');
        [$srcBucket, $srcKey] = explode('/', $source, 2);
        $srcKey = explode('?', $srcKey)[0];

        if (!isset($this->buckets[$srcBucket][$srcKey])) {
            return $this->error(404, 'NoSuchKey', "源对象不存在: {$srcKey}");
        }

        $this->buckets[$bucket][$key] = $this->buckets[$srcBucket][$srcKey];
        $body = $this->buckets[$bucket][$key]['body'];

        return $this->xml('<CopyObjectResult><ETag>&quot;' . md5($body) . '&quot;</ETag>'
            . '<LastModified>2025-01-01T00:00:00.000Z</LastModified></CopyObjectResult>');
    }

    private function deleteObjects(string $bucket, string $body): Response
    {
        $xml = new SimpleXMLElement($body);
        $deleted = [];

        foreach ($xml->Object as $object) {
            $key = (string) $object->Key;
            unset($this->buckets[$bucket][$key]);
            $deleted[] = $key;
        }

        $out = '<DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        foreach ($deleted as $key) {
            $out .= '<Deleted><Key>' . htmlspecialchars($key) . '</Key></Deleted>';
        }

        return $this->xml($out . '</DeleteResult>');
    }

    // ---- 分片上传 ----

    private function createMultipartUpload(string $bucket, string $key): Response
    {
        $uploadId = 'upload-' . (++$this->uploadSeq);
        $this->uploads[$uploadId] = ['bucket' => $bucket, 'key' => $key, 'parts' => []];

        return $this->xml('<InitiateMultipartUploadResult><Bucket>' . $bucket . '</Bucket>'
            . '<Key>' . htmlspecialchars($key) . '</Key>'
            . '<UploadId>' . $uploadId . '</UploadId></InitiateMultipartUploadResult>');
    }

    private function uploadPart(string $uploadId, int $partNumber, Request $request): Response
    {
        if (!isset($this->uploads[$uploadId])) {
            return $this->error(404, 'NoSuchUpload', '分片上传不存在');
        }

        $body = (string) $request->getBody();
        $this->uploads[$uploadId]['parts'][$partNumber] = $body;

        return new Response(200, ['ETag' => '"' . md5($body) . '"']);
    }

    private function completeMultipartUpload(string $uploadId, string $body): Response
    {
        if (!isset($this->uploads[$uploadId])) {
            return $this->error(404, 'NoSuchUpload', '分片上传不存在');
        }

        $upload = $this->uploads[$uploadId];
        $xml = new SimpleXMLElement($body);

        $assembled = '';
        $lastPartNumber = 0;
        foreach ($xml->Part as $part) {
            $number = (int) $part->PartNumber;

            // S3 要求分片号严格递增，顺序错了要报错
            if ($number <= $lastPartNumber) {
                return $this->error(400, 'InvalidPartOrder', '分片必须按分片号升序排列');
            }
            $lastPartNumber = $number;

            if (!isset($upload['parts'][$number])) {
                return $this->error(400, 'InvalidPart', "分片 {$number} 不存在");
            }

            $expected = '"' . md5($upload['parts'][$number]) . '"';
            $actual = (string) $part->ETag;
            if ($actual !== $expected) {
                return $this->error(400, 'InvalidPart', "分片 {$number} 的 ETag 不匹配");
            }

            $assembled .= $upload['parts'][$number];
        }

        $this->buckets[$upload['bucket']][$upload['key']] = [
            'body' => $assembled, 'headers' => [], 'mtime' => 1735689600,
        ];
        unset($this->uploads[$uploadId]);

        return $this->xml('<CompleteMultipartUploadResult>'
            . '<Location>http://127.0.0.1:9000/' . $upload['bucket'] . '/' . $upload['key'] . '</Location>'
            . '<Bucket>' . $upload['bucket'] . '</Bucket>'
            . '<Key>' . htmlspecialchars($upload['key']) . '</Key>'
            . '<ETag>&quot;' . md5($assembled) . '-' . count($upload['parts']) . '&quot;</ETag>'
            . '</CompleteMultipartUploadResult>');
    }

    private function abortMultipartUpload(string $uploadId): Response
    {
        unset($this->uploads[$uploadId]);

        return new Response(204);
    }

    private function listParts(string $uploadId): Response
    {
        if (!isset($this->uploads[$uploadId])) {
            return $this->error(404, 'NoSuchUpload', '分片上传不存在');
        }

        $xml = '<ListPartsResult><UploadId>' . $uploadId . '</UploadId><IsTruncated>false</IsTruncated>';
        foreach ($this->uploads[$uploadId]['parts'] as $number => $content) {
            $xml .= '<Part><PartNumber>' . $number . '</PartNumber>'
                  . '<ETag>&quot;' . md5($content) . '&quot;</ETag>'
                  . '<Size>' . strlen($content) . '</Size></Part>';
        }

        return $this->xml($xml . '</ListPartsResult>');
    }

    // ---- 辅助 ----

    private function objectHeaders(array $object): array
    {
        $headers = [
            'Content-Length' => (string) strlen($object['body']),
            'ETag'           => '"' . md5($object['body']) . '"',
            'Last-Modified'  => gmdate('D, d M Y H:i:s \G\M\T', $object['mtime']),
            'Accept-Ranges'  => 'bytes',
        ];

        foreach ($object['headers'] as $name => $value) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    private function xml(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/xml'], '<?xml version="1.0" encoding="UTF-8"?>' . $body);
    }

    public function error(int $status, string $code, string $message, bool $noBody = false): Response
    {
        if ($noBody) {
            // HEAD 请求没有响应体，客户端只能靠状态码判断
            return new Response($status, ['x-amz-request-id' => 'req-1']);
        }

        return new Response($status, ['Content-Type' => 'application/xml'],
            '<?xml version="1.0" encoding="UTF-8"?><Error>'
            . '<Code>' . $code . '</Code>'
            . '<Message>' . htmlspecialchars($message) . '</Message>'
            . '<RequestId>req-1</RequestId></Error>');
    }
}
