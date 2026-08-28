<?php
/**
 * 一次跑完全部测试。
 *
 * 每个测试文件作为独立进程运行，互不干扰全局状态；需要真实
 * HTTP 的那组会自动起停一个本机 PHP 内置服务器（仅监听
 * 127.0.0.1，不访问外网），跑完必定回收。
 *
 * 用法: php tests/run.php
 */

$suites = [
    '零依赖检查'   => 'dependencies.php',
    '端到端功能'   => 'functional.php',
    'README 示例'  => 'readme.php',
];

$failed = [];

foreach ($suites as $label => $file) {
    echo "\n", str_repeat('─', 60), "\n";
    echo "▶ {$label}\n";
    echo str_repeat('─', 60), "\n";

    if (runScript([__DIR__ . '/' . $file]) !== 0) {
        $failed[] = $label;
    }
}

// ---- 需要真实 HTTP 的那组 ----
echo "\n", str_repeat('─', 60), "\n";
echo "▶ 真实 HTTP 传输\n";
echo str_repeat('─', 60), "\n";

$dataDir = sys_get_temp_dir() . '/mins3-http-test';
removeDir($dataDir);

$port = findFreePort();
$server = startServer($port);

if ($server === null) {
    echo "  [跳过] 无法启动本地测试服务器\n";
    $failed[] = '真实 HTTP 传输（启动失败）';
} else {
    try {
        if (runScript([__DIR__ . '/transport.php', (string) $port]) !== 0) {
            $failed[] = '真实 HTTP 传输';
        }
    } finally {
        stopServer($server);
        removeDir($dataDir);
    }
}

// ---- 汇总 ----
echo "\n", str_repeat('═', 60), "\n";

if ($failed === []) {
    echo "全部测试通过\n";
    exit(0);
}

echo '有 ' . count($failed) . " 组测试未通过:\n";
foreach ($failed as $label) {
    echo "  - {$label}\n";
}
exit(1);

// ===============================================================

/**
 * 用当前的 PHP 解释器跑一个脚本，输出实时转发到本进程。
 *
 * 不用 passthru：它在管道环境下会缓冲，且没法可靠地拿到
 * 子进程的输出流。proc_open 显式接管管道更可控。
 *
 * @param string[] $args 脚本路径及其参数
 */
function runScript(array $args): int
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        buildCommand($args),
        $descriptors,
        $pipes,
        null,
        null,
        processOptions()
    );

    if (!is_resource($process)) {
        echo "  无法启动子进程\n";

        return 1;
    }

    fclose($pipes[0]);

    // 边读边输出，长测试也能看到进度
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $exitCode = -1;

    while (true) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);

        if ($out !== '' && $out !== false) {
            echo $out;
        }
        if ($err !== '' && $err !== false) {
            fwrite(STDERR, $err);
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            // 退出码必须在这里取：proc_get_status 一旦观察到进程结束就
            // 记下退出码，之后 proc_close 只会返回 -1
            $exitCode = $status['exitcode'];

            // 进程已退出，把管道里剩下的读干净
            foreach ([1 => STDOUT, 2 => STDERR] as $i => $target) {
                while (($rest = fread($pipes[$i], 8192)) !== false && $rest !== '') {
                    fwrite($target, $rest);
                }
            }
            break;
        }

        usleep(20000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $exitCode;
}

/**
 * 拼接命令。Windows 下用 bypass_shell 直接创建进程，
 * 因此这里给出的参数不经过 cmd 解析。
 *
 * @param string[] $args
 */
function buildCommand(array $args): string
{
    $parts = [escapeArgument(PHP_BINARY)];
    foreach ($args as $arg) {
        $parts[] = escapeArgument($arg);
    }

    return implode(' ', $parts);
}

function escapeArgument(string $value): string
{
    // Windows 走 bypass_shell，只需处理空格；escapeshellarg 在
    // 这条路径上反而会把引号一起传给程序
    if (PHP_OS_FAMILY === 'Windows') {
        return str_contains($value, ' ') ? '"' . $value . '"' : $value;
    }

    return escapeshellarg($value);
}

/**
 * Windows 上必须绕过 cmd：否则 proc_terminate 杀掉的是 cmd，
 * 真正的 php.exe 会留下来继续占着端口。
 */
function processOptions(): array
{
    return PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];
}

/**
 * 找一个空闲端口，避免固定端口在 CI 上撞车。
 */
function findFreePort(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        return 9911;
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * 启动内置服务器并等它就绪。
 *
 * @return resource|null
 */
function startServer(int $port)
{
    $command = implode(' ', [
        escapeArgument(PHP_BINARY),
        '-S',
        '127.0.0.1:' . $port,
        escapeArgument(__DIR__ . '/server.php'),
    ]);

    // 服务器的访问日志会淹没测试输出，直接丢弃
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $null, 'w'],
        2 => ['file', $null, 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes, null, null, processOptions());
    if (!is_resource($process)) {
        return null;
    }

    if (isset($pipes[0])) {
        fclose($pipes[0]);
    }

    // 轮询直到端口可连接，最多等 10 秒
    for ($i = 0; $i < 100; $i++) {
        usleep(100000);

        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
        if ($connection !== false) {
            fclose($connection);

            return $process;
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            return null;
        }
    }

    proc_terminate($process);
    proc_close($process);

    return null;
}

/**
 * @param resource $process
 */
function stopServer($process): void
{
    proc_terminate($process);

    // 给它一点时间自己退出，避免留下僵尸进程
    for ($i = 0; $i < 20; $i++) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(50000);
    }

    proc_close($process);
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($dir);
}
