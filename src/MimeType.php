<?php
namespace MinS3;

/**
 * 按扩展名推断 Content-Type。
 *
 * 只收录对象存储里常见的类型。查不到时返回 null，由调用方决定
 * 是否退回 application/octet-stream —— 不猜错比猜得全更重要，
 * 错误的 Content-Type 会让浏览器以错误方式渲染对象。
 */
final class MimeType
{
    private const TYPES = [
        // 文本与网页
        'txt'   => 'text/plain',
        'text'  => 'text/plain',
        'log'   => 'text/plain',
        'md'    => 'text/markdown',
        'csv'   => 'text/csv',
        'html'  => 'text/html',
        'htm'   => 'text/html',
        'css'   => 'text/css',
        'js'    => 'text/javascript',
        'mjs'   => 'text/javascript',
        'json'  => 'application/json',
        'xml'   => 'application/xml',
        'yaml'  => 'application/yaml',
        'yml'   => 'application/yaml',
        'ics'   => 'text/calendar',
        'vtt'   => 'text/vtt',

        // 图片
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'jpe'   => 'image/jpeg',
        'png'   => 'image/png',
        'gif'   => 'image/gif',
        'bmp'   => 'image/bmp',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'svg'   => 'image/svg+xml',
        'svgz'  => 'image/svg+xml',
        'ico'   => 'image/vnd.microsoft.icon',
        'tif'   => 'image/tiff',
        'tiff'  => 'image/tiff',
        'heic'  => 'image/heic',
        'heif'  => 'image/heif',
        'psd'   => 'image/vnd.adobe.photoshop',

        // 音视频
        'mp3'   => 'audio/mpeg',
        'wav'   => 'audio/wav',
        'ogg'   => 'audio/ogg',
        'oga'   => 'audio/ogg',
        'opus'  => 'audio/opus',
        'flac'  => 'audio/flac',
        'aac'   => 'audio/aac',
        'm4a'   => 'audio/mp4',
        'weba'  => 'audio/webm',
        'mid'   => 'audio/midi',
        'midi'  => 'audio/midi',
        'mp4'   => 'video/mp4',
        'm4v'   => 'video/mp4',
        'mov'   => 'video/quicktime',
        'avi'   => 'video/x-msvideo',
        'wmv'   => 'video/x-ms-wmv',
        'flv'   => 'video/x-flv',
        'mkv'   => 'video/x-matroska',
        'webm'  => 'video/webm',
        'mpeg'  => 'video/mpeg',
        'mpg'   => 'video/mpeg',
        'ts'    => 'video/mp2t',
        '3gp'   => 'video/3gpp',

        // 文档
        'pdf'   => 'application/pdf',
        'rtf'   => 'application/rtf',
        'doc'   => 'application/msword',
        'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'   => 'application/vnd.ms-excel',
        'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'   => 'application/vnd.ms-powerpoint',
        'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'   => 'application/vnd.oasis.opendocument.text',
        'ods'   => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'   => 'application/vnd.oasis.opendocument.presentation',
        'epub'  => 'application/epub+zip',

        // 压缩与打包
        'zip'   => 'application/zip',
        'gz'    => 'application/gzip',
        'tgz'   => 'application/gzip',
        'bz2'   => 'application/x-bzip2',
        'xz'    => 'application/x-xz',
        'zst'   => 'application/zstd',
        'tar'   => 'application/x-tar',
        '7z'    => 'application/x-7z-compressed',
        'rar'   => 'application/vnd.rar',

        // 字体
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',

        // 其他
        'wasm'  => 'application/wasm',
        'apk'   => 'application/vnd.android.package-archive',
        'exe'   => 'application/vnd.microsoft.portable-executable',
        'dmg'   => 'application/x-apple-diskimage',
        'iso'   => 'application/x-iso9660-image',
        'deb'   => 'application/vnd.debian.binary-package',
        'rpm'   => 'application/x-rpm',
        'sql'   => 'application/sql',
        'parquet' => 'application/vnd.apache.parquet',
    ];

    private function __construct()
    {
    }

    /**
     * @return string|null 查不到返回 null
     */
    public static function fromFilename(string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $extension === '' ? null : (self::TYPES[$extension] ?? null);
    }

    public static function fromExtension(string $extension): ?string
    {
        return self::TYPES[strtolower($extension)] ?? null;
    }
}
