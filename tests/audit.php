<?php
/**
 * 敏感信息审计。
 *
 * 两种用法：
 *   php tests/audit.php              扫描整个仓库
 *   php tests/audit.php --staged     只扫本次暂存的改动（供 pre-commit 使用）
 *
 * 规则宁可多报也不漏报，命中项由人工判定。已知的公开示例值
 * （MinIO 默认账号、AWS 文档示例密钥等）在 allowlist 里放行。
 *
 * 这是零依赖的兜底检查，不能替代 gitleaks —— 后者规则库大得多。
 * 两者定位不同：这个保证任何人 clone 下来都能立刻跑，
 * gitleaks 在 CI 里做更全面的扫描。
 */

$root = dirname(__DIR__);
$stagedOnly = in_array('--staged', $argv, true);

/** 检测规则 */
$rules = [
    'AWS 访问密钥 ID'    => '/\b(?:AKIA|ASIA|AGPA|AIDA|AROA|AIPA|ANPA|ANVA|ABIA|ACCA)[A-Z0-9]{16}\b/',
    '私钥文件块'         => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/',
    'URL 内嵌凭据'       => '#[a-z][a-z0-9+.-]*://[^/\s:@]+:[^/\s:@]+@#i',
    'GitHub token'       => '/\b(?:ghp|gho|ghu|ghs|ghr|github_pat)_[A-Za-z0-9_]{20,}\b/',
    'GitLab token'       => '/\bglpat-[A-Za-z0-9_-]{20,}\b/',
    'Slack token'        => '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
    'Google API key'     => '/\bAIza[0-9A-Za-z_-]{35}\b/',
    'Stripe key'         => '/\b[sr]k_(?:live|test)_[A-Za-z0-9]{20,}\b/',
    'npm token'          => '/\bnpm_[A-Za-z0-9]{36}\b/',
    'JWT'                => '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\./',
    // 键名可能被引号包裹（'password' => '...'），所以 [\'"]? 不能少
    '密码/密钥赋值'      => '/(?:password|passwd|pwd|secret|api_?key|access_?token|auth_?token|private_?key)[\'"]?\s*(?:=>|=|:)\s*[\'"][^\'"]{8,}[\'"]/i',
    '邮箱地址'           => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
    '公网 IP'            => '/\b(?!127\.|0\.|10\.|192\.168\.|169\.254\.|172\.(?:1[6-9]|2\d|3[01])\.|255\.|224\.)(?:\d{1,3}\.){3}\d{1,3}\b/',
    'Windows 用户路径'   => '/[A-Za-z]:\\\\Users\\\\[^\\\\\s"\']+/',
    'Unix 家目录路径'    => '#/(?:home|Users)/[a-z][a-z0-9_-]*/#i',
];

/**
 * 允许列表：本项目里确实存在、且确认公开无害的值。
 * 新增条目时请在这里写明理由，别无声无息地放行。
 */
$allowlist = [
    'minioadmin',                                // MinIO 官方文档的默认账号
    'AKIAIOSFODNN7EXAMPLE',                      // AWS 官方文档示例密钥 ID
    'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',  // AWS 官方文档示例私有密钥
    'testkey',                                   // 测试桩
    'testsecret',                                // 测试桩
    'noreply@anthropic.com',                     // 提交信息里的协作者标识
    'users.noreply.github.com',                  // GitHub 的隐私邮箱域名
    'you@example.com',                           // README 示例
    'example.com',                               // 文档占位域名
    's3.internal',                               // README 里 verify 选项的示例域名
    's3.amazonaws.com',                          // XML 命名空间标识符，非请求地址
    'www.w3.org',                                // XML 规范命名空间

    // docs/ci-setup.md 里演示"如何造假密钥测试拦截"的示例值。
    // 只放行这两个具体串，不豁免整个 docs/ 目录 —— 文档里同样可能
    // 误贴真密钥，整目录豁免等于给自己开后门。
    'AKIAIOSFODNN7REALKEY',
    'SuperSecret123456',
];

/**
 * 路径级豁免：整个文件跳过某些规则。
 * api-2 是 AWS 官方接口模型，里面大量 40 字符的类型名会撞上密钥的长度特征。
 */
$pathExemptions = [
    'src/data/api-2.json.php' => ['密码/密钥赋值'],
];

// ---- 收集待扫描内容 ----

/** @var array<string, string> 相对路径 => 内容 */
$targets = [];

if ($stagedOnly) {
    exec('git diff --cached --name-only --diff-filter=ACMR', $files, $code);
    if ($code !== 0) {
        fwrite(STDERR, "无法读取暂存区，是否在 git 仓库内？\n");
        exit(2);
    }

    foreach ($files as $file) {
        $file = trim($file);
        if ($file === '') {
            continue;
        }

        // 读暂存区版本而非工作区：add 之后又改动过工作区时，
        // 要扫的是真正会被提交的那份内容。
        // 用 exec 而不是 shell_exec + 重定向：2>/dev/null 在 Windows 下
        // 不是有效路径，会让整条命令失败。
        $output = [];
        $showCode = 0;
        exec('git show ' . escapeshellarg(':' . $file), $output, $showCode);

        if ($showCode === 0 && $output !== []) {
            $targets[$file] = implode("\n", $output);
        }
    }

    if ($targets === []) {
        echo "暂存区没有需要扫描的内容\n";
        exit(0);
    }
} else {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        // 跳过 .git 内部与依赖目录
        if (str_starts_with($relative, '.git/') || str_starts_with($relative, 'vendor/')) {
            continue;
        }

        $content = @file_get_contents($file->getPathname());
        if ($content !== false) {
            $targets[$relative] = $content;
        }
    }
}

// ---- 扫描 ----

$hits = [];
$bytes = 0;

foreach ($targets as $relative => $content) {
    $bytes += strlen($content);
    $exempt = $pathExemptions[$relative] ?? [];

    foreach ($rules as $name => $pattern) {
        if (in_array($name, $exempt, true)) {
            continue;
        }

        if (!preg_match_all($pattern, $content, $matches)) {
            continue;
        }

        foreach (array_unique($matches[0]) as $match) {
            foreach ($allowlist as $allowed) {
                if (stripos($match, $allowed) !== false) {
                    continue 2;
                }
            }

            $offset = strpos($content, $match);
            $line = $offset === false ? 0 : substr_count($content, "\n", 0, $offset) + 1;

            $hits[$name][] = [
                'file'  => $relative,
                'line'  => $line,
                'match' => strlen($match) > 90 ? substr($match, 0, 90) . '…' : $match,
            ];
        }
    }
}

printf(
    "敏感信息扫描：%d 个文件，%s%s\n\n",
    count($targets),
    $bytes > 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB',
    $stagedOnly ? '（仅暂存区）' : ''
);

if ($hits === []) {
    echo "未发现可疑内容。\n";
    exit(0);
}

$total = 0;
foreach ($hits as $name => $items) {
    printf("【%s】%d 处\n", $name, count($items));
    foreach (array_slice($items, 0, 8) as $item) {
        printf("    %s:%d\n      %s\n", $item['file'], $item['line'], $item['match']);
    }
    if (count($items) > 8) {
        printf("    …另有 %d 处\n", count($items) - 8);
    }
    echo "\n";
    $total += count($items);
}

printf("发现 %d 处可疑内容。\n", $total);
echo "确认无害的话，把对应值加进 tests/audit.php 的 \$allowlist 并注明理由。\n";

exit(1);
