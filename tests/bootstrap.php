<?php
/**
 * 测试引导：加载被测包与共用断言。
 *
 * 刻意不引入 PHPUnit —— 本包的卖点是零依赖，测试跟着零依赖
 * 才能顺带证明这一点：只要有一处漏用了第三方类，测试就会因为
 * 找不到类而失败。
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/FakeS3.php';

$GLOBALS['__test_pass'] = 0;
$GLOBALS['__test_fail'] = 0;
$GLOBALS['__test_failures'] = [];

/**
 * 跑一个用例。回调返回的字符串会作为补充信息显示出来，
 * 用于把"测了什么"变成可见的事实而不是一个笼统的 OK。
 */
function test(string $name, callable $fn): void
{
    try {
        $detail = $fn();
        $GLOBALS['__test_pass']++;
        echo "  [通过] {$name}" . ($detail ? " — {$detail}" : '') . "\n";
    } catch (\Throwable $e) {
        $GLOBALS['__test_fail']++;
        $GLOBALS['__test_failures'][] = $name;
        echo "  [失败] {$name}\n";
        echo '         ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        echo '         ' . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function assertSame(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        $format = static fn(mixed $v): string => is_string($v) && strlen($v) > 80
            ? substr($v, 0, 80) . '…(' . strlen($v) . ' 字节)'
            : var_export($v, true);

        throw new RuntimeException(sprintf(
            '%s期望 %s，实际 %s',
            $what !== '' ? "{$what}: " : '',
            $format($expected),
            $format($actual)
        ));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 输出汇总并返回退出码。
 */
function testSummary(): int
{
    $pass = $GLOBALS['__test_pass'];
    $fail = $GLOBALS['__test_fail'];

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "通过 {$pass} 项，失败 {$fail} 项\n";

    if ($fail > 0) {
        echo "失败清单:\n";
        foreach ($GLOBALS['__test_failures'] as $name) {
            echo "  - {$name}\n";
        }
    }

    return $fail > 0 ? 1 : 0;
}
