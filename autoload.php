<?php
/**
 * 不使用 composer 时的加载器。
 *
 * 把整个 min-s3 目录丢进项目里，然后：
 *
 *     require __DIR__ . '/min-s3/autoload.php';
 *     $s3 = new MinS3\S3Client([...]);
 *
 * 本包没有第三方依赖，所以这里只需要注册一条 PSR-4 规则。
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'MinS3\\';
    $length = strlen($prefix);

    if (strncmp($class, $prefix, $length) !== 0) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, $length));
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});
