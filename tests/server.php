<?php
/**
 * 供 PHP 内置服务器使用的 S3 端点，用于验证真实 curl 传输。
 *
 * 只监听 127.0.0.1，不访问外网。对象存在临时目录里，
 * 每次启动前由调用方清空。
 *
 * 启动: php -S 127.0.0.1:<port> http_server.php
 */

$dataDir = sys_get_temp_dir() . '/mins3-http-test';
@mkdir($dataDir, 0777, true);

$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '', $query);

$segments = explode('/', ltrim($path, '/'), 2);
$bucket = $segments[0] ?? '';
$key = $segments[1] ?? '';

/** 把 key 映射成扁平的文件名，避免创建深层目录 */
function objectPath(string $dataDir, string $bucket, string $key): string
{
    return $dataDir . '/' . $bucket . '__' . sha1($key);
}

function metaPath(string $dataDir, string $bucket, string $key): string
{
    return objectPath($dataDir, $bucket, $key) . '.meta';
}

function sendXml(string $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . $body;
}

function sendError(int $status, string $code, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/xml');
    header('x-amz-request-id: req-test');
    echo '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code
       . '</Code><Message>' . htmlspecialchars($message)
       . '</Message><RequestId>req-test</RequestId></Error>';
}

// 服务端必须收到完整的消息体：这同时验证了客户端的
// Content-Length 与流式上传是否正确
$input = file_get_contents('php://input');

// 分片上传状态
$uploadsFile = $dataDir . '/uploads.json';
$uploads = is_file($uploadsFile) ? json_decode(file_get_contents($uploadsFile), true) : [];

$saveUploads = static function () use ($uploadsFile, &$uploads): void {
    file_put_contents($uploadsFile, json_encode($uploads));
};

// ---- 列桶 ----
if ($bucket === '') {
    sendXml('<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Buckets><Bucket><Name>test</Name>'
        . '<CreationDate>2025-01-01T00:00:00.000Z</CreationDate></Bucket></Buckets>'
        . '</ListAllMyBucketsResult>');
    return;
}

// ---- 桶级 ----
if ($key === '') {
    if ($method === 'HEAD' || $method === 'PUT') {
        http_response_code(200);
        return;
    }

    if ($method === 'POST' && array_key_exists('delete', $query)) {
        $xml = new SimpleXMLElement($input);
        $out = '<DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        foreach ($xml->Object as $object) {
            $k = (string) $object->Key;
            @unlink(objectPath($dataDir, $bucket, $k));
            @unlink(metaPath($dataDir, $bucket, $k));
            $out .= '<Deleted><Key>' . htmlspecialchars($k) . '</Key></Deleted>';
        }
        sendXml($out . '</DeleteResult>');
        return;
    }

    if ($method === 'GET') {
        $prefix = $query['prefix'] ?? '';
        $keys = [];
        foreach (glob($dataDir . '/' . $bucket . '__*.meta') ?: [] as $metaFile) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if ($prefix === '' || str_starts_with($meta['key'], $prefix)) {
                $keys[$meta['key']] = $meta;
            }
        }
        ksort($keys);

        $xml = '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
             . '<Name>' . $bucket . '</Name><Prefix>' . htmlspecialchars($prefix) . '</Prefix>'
             . '<IsTruncated>false</IsTruncated><KeyCount>' . count($keys) . '</KeyCount>';
        foreach ($keys as $k => $meta) {
            $xml .= '<Contents><Key>' . htmlspecialchars($k) . '</Key>'
                  . '<LastModified>2025-01-01T00:00:00.000Z</LastModified>'
                  . '<ETag>&quot;' . $meta['etag'] . '&quot;</ETag>'
                  . '<Size>' . $meta['size'] . '</Size></Contents>';
        }
        sendXml($xml . '</ListBucketResult>');
        return;
    }

    sendError(405, 'MethodNotAllowed', "不支持 {$method}");
    return;
}

// ---- 分片上传 ----
if ($method === 'POST' && array_key_exists('uploads', $query)) {
    $uploadId = 'u' . bin2hex(random_bytes(6));
    $uploads[$uploadId] = ['bucket' => $bucket, 'key' => $key, 'parts' => []];
    $saveUploads();

    sendXml('<InitiateMultipartUploadResult><Bucket>' . $bucket . '</Bucket>'
        . '<Key>' . htmlspecialchars($key) . '</Key>'
        . '<UploadId>' . $uploadId . '</UploadId></InitiateMultipartUploadResult>');
    return;
}

if (isset($query['uploadId'])) {
    $uploadId = $query['uploadId'];
    if (!isset($uploads[$uploadId])) {
        sendError(404, 'NoSuchUpload', '分片上传不存在');
        return;
    }

    if ($method === 'PUT') {
        $partNumber = (int) $query['partNumber'];
        $partFile = $dataDir . '/' . $uploadId . '.part' . $partNumber;
        file_put_contents($partFile, $input);

        $uploads[$uploadId]['parts'][$partNumber] = strlen($input);
        $saveUploads();

        header('ETag: "' . md5($input) . '"');
        http_response_code(200);
        return;
    }

    if ($method === 'POST') {
        $xml = new SimpleXMLElement($input);
        $assembled = '';
        $count = 0;
        foreach ($xml->Part as $part) {
            $n = (int) $part->PartNumber;
            $partFile = $dataDir . '/' . $uploadId . '.part' . $n;
            if (!is_file($partFile)) {
                sendError(400, 'InvalidPart', "分片 {$n} 不存在");
                return;
            }
            $assembled .= file_get_contents($partFile);
            @unlink($partFile);
            $count++;
        }

        $upload = $uploads[$uploadId];
        file_put_contents(objectPath($dataDir, $upload['bucket'], $upload['key']), $assembled);
        file_put_contents(metaPath($dataDir, $upload['bucket'], $upload['key']), json_encode([
            'key' => $upload['key'], 'etag' => md5($assembled),
            'size' => strlen($assembled), 'type' => 'application/octet-stream',
        ]));

        unset($uploads[$uploadId]);
        $saveUploads();

        sendXml('<CompleteMultipartUploadResult>'
            . '<Location>http://127.0.0.1/' . $upload['bucket'] . '/' . $upload['key'] . '</Location>'
            . '<Bucket>' . $upload['bucket'] . '</Bucket>'
            . '<Key>' . htmlspecialchars($upload['key']) . '</Key>'
            . '<ETag>&quot;' . md5($assembled) . '-' . $count . '&quot;</ETag>'
            . '</CompleteMultipartUploadResult>');
        return;
    }

    if ($method === 'DELETE') {
        foreach (glob($dataDir . '/' . $uploadId . '.part*') ?: [] as $f) {
            @unlink($f);
        }
        unset($uploads[$uploadId]);
        $saveUploads();
        http_response_code(204);
        return;
    }
}

// ---- 对象级 ----
$file = objectPath($dataDir, $bucket, $key);
$metaFile = metaPath($dataDir, $bucket, $key);

switch ($method) {
    case 'PUT':
        $contentType = $_SERVER['HTTP_CONTENT_TYPE'] ?? 'binary/octet-stream';
        file_put_contents($file, $input);
        file_put_contents($metaFile, json_encode([
            'key' => $key, 'etag' => md5($input),
            'size' => strlen($input), 'type' => $contentType,
        ]));

        header('ETag: "' . md5($input) . '"');
        http_response_code(200);
        return;

    case 'GET':
    case 'HEAD':
        if (!is_file($file)) {
            if ($method === 'HEAD') {
                http_response_code(404);
                header('x-amz-request-id: req-test');
                return;
            }
            sendError(404, 'NoSuchKey', "对象不存在: {$key}");
            return;
        }

        $meta = json_decode(file_get_contents($metaFile), true);
        $body = file_get_contents($file);
        $status = 200;

        // Range 支持：验证客户端能正确处理 206
        if (isset($_SERVER['HTTP_RANGE'])
            && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)
        ) {
            $size = strlen($body);
            $start = $m[1] === '' ? 0 : (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            $body = substr($body, $start, $end - $start + 1);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
            $status = 206;
        }

        http_response_code($status);
        header('Content-Type: ' . $meta['type']);
        header('Content-Length: ' . strlen($body));
        header('ETag: "' . $meta['etag'] . '"');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', 1735689600));

        if ($method === 'GET') {
            echo $body;
        }
        return;

    case 'DELETE':
        @unlink($file);
        @unlink($metaFile);
        http_response_code(204);
        return;
}

sendError(405, 'MethodNotAllowed', "不支持 {$method}");
