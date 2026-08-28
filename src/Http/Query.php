<?php
namespace MinS3\Http;

/**
 * query 字符串的解析与构建。
 *
 * 行为必须与 guzzlehttp/psr7 的 Query 保持逐字节一致：SigV4 的
 * 规范请求要对 query 重新排序并编码，任何编码差异都会导致
 * SignatureDoesNotMatch。特别是：
 *  - 无值的键（如 ?uploads）值为 null，构建时只输出键名，不带 =
 *  - 使用 rawurlencode（RFC3986），不是 urlencode（+ 号语义不同）
 */
final class Query
{
    private function __construct()
    {
    }

    /**
     * 解析为关联数组。重复键合并为数组，无值的键取 null。
     * 不解析 PHP 风格的嵌套数组：foo[a]=1 的键就是字符串 "foo[a]"。
     */
    public static function parse(string $str): array
    {
        $result = [];

        if ($str === '') {
            return $result;
        }

        foreach (explode('&', $str) as $kvp) {
            $parts = explode('=', $kvp, 2);
            $key = self::decode($parts[0]);
            $value = isset($parts[1]) ? self::decode($parts[1]) : null;

            if (!array_key_exists($key, $result)) {
                $result[$key] = $value;
            } else {
                if (!is_array($result[$key])) {
                    $result[$key] = [$result[$key]];
                }
                $result[$key][] = $value;
            }
        }

        return $result;
    }

    /**
     * 由键值对构建 query 字符串，可直接消费 parse() 的返回值。
     */
    public static function build(array $params): string
    {
        if (!$params) {
            return '';
        }

        $qs = '';
        foreach ($params as $k => $v) {
            $k = rawurlencode((string) $k);

            if (!is_array($v)) {
                $qs .= $k;
                $v = self::normalizeValue($v);
                if ($v !== null) {
                    $qs .= '=' . rawurlencode($v);
                }
                $qs .= '&';
            } else {
                foreach ($v as $vv) {
                    $qs .= $k;
                    $vv = self::normalizeValue($vv);
                    if ($vv !== null) {
                        $qs .= '=' . rawurlencode($vv);
                    }
                    $qs .= '&';
                }
            }
        }

        return $qs !== '' ? substr($qs, 0, -1) : '';
    }

    private static function decode(string $value): string
    {
        return rawurldecode(str_replace('+', ' ', $value));
    }

    private static function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return (string) (int) $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('query 值必须是标量、null 或可转字符串的对象');
    }
}
