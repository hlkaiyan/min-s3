# CI 与敏感信息扫描配置指南

这份文档记录 min-s3 实际在用的一套配置，可以整体搬到别的项目。
所有配置和坑都是实测过的，不是模板拼凑。

---

## 目录

- [三层防护模型](#三层防护模型)
- [第一层：GitHub Secret Scanning](#第一层github-secret-scanning)
- [第二层：CI 扫描](#第二层ci-扫描)
- [第三层：pre-commit 钩子](#第三层pre-commit-钩子)
- [测试矩阵 CI](#测试矩阵-ci)
- [实战踩坑记录](#实战踩坑记录)
- [搬到其他项目](#搬到其他项目)
- [落地检查清单](#落地检查清单)

---

## 三层防护模型

单一手段都有盲区，三层叠加才有意义：

| 层 | 拦截时机 | 能绕过吗 | 主要盲区 |
|---|---|---|---|
| pre-commit 钩子 | 本地 `git commit` 时 | 能，`--no-verify` | 没装钩子的人完全不设防 |
| CI 扫描 | 推送 / PR 之后 | 不能 | 密钥**已经进了远程仓库**，只能事后补救 |
| 平台 Push Protection | 推送时，服务端 | 不能 | 只认识已知格式的密钥 |

关键在于理解它们的**顺序关系**：

- 钩子最早，体验最好——问题在密钥离开你机器之前就被拦下，改起来零成本
- 但钩子靠自觉，新人 clone 下来不装就等于没有
- CI 兜底，但它报警时密钥已经推上去了。**这时候删提交是没用的**，
  必须当作已泄漏处理：吊销密钥、轮换，然后再清理历史
- Push Protection 是唯一在服务端强制、且能真正阻止密钥落库的一层

所以三层不是冗余，是各自补对方的洞。只做一层的话，优先做 Push Protection。

---

## 第一层：GitHub Secret Scanning

**成本最低，优先做。** 不用写任何配置文件。

### 开启

仓库 → **Settings** → **Code security** → 找到 **Secret scanning**：

1. 打开 **Secret scanning**
2. 勾上 **Push protection** ← 这个才是关键

### 效果

Push protection 打开后，推送含已知格式密钥（AWS key、GitHub token、
Stripe key 等 200 多种）的提交会被服务端直接拒绝：

```
remote: error: GH013: Repository rule violations found
remote: - Push cannot contain secrets
remote:   —— GITHUB PUSH PROTECTION ————————————————————
remote:    Push blocked by secret scanning
```

本地 `git commit --no-verify` 绕不过它，因为拦截发生在服务端。

### 适用范围

- **公开仓库**：免费
- **私有仓库**：需要 GitHub Advanced Security（付费）

私有仓库没买 GHAS 的话，就靠下面两层。

### 局限

只认识**已知格式**的密钥。自建系统的内部 token、数据库连接串、
私有 API 的密钥这些没有固定特征的，它认不出来。这类要靠自定义规则
（GHAS 支持自定义模式）或下面两层。

---

## 第二层：CI 扫描

### gitleaks

在工作流里加一个 job：

```yaml
  secrets:
    name: 敏感信息扫描
    runs-on: ubuntu-latest

    steps:
      - name: 检出代码
        uses: actions/checkout@v4
        with:
          # 必须为 0：gitleaks 要扫全部历史，
          # 默认的浅克隆只有最新一个提交，旧提交里的密钥会漏掉
          fetch-depth: 0

      - name: gitleaks 扫描
        uses: gitleaks/gitleaks-action@v2
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          GITLEAKS_CONFIG: .gitleaks.toml
```

`fetch-depth: 0` 容易漏，漏了这行等于只扫最新提交，历史里的密钥查不出来。

许可：个人公开仓库免费；**组织所有的仓库需要 `GITLEAKS_LICENSE`**
（免费申请，但要配）。个人仓库转到组织下之后会突然开始报许可错误，
这是最常见的困惑点。

### 配置文件

放仓库根，`.gitleaks.toml`：

```toml
title = "项目名"

[extend]
useDefault = true          # 继承 gitleaks 内置规则集

[allowlist]
description = "确认公开无害的示例值"

regexes = [
  # 每条都写明理由 —— 见下方说明
  '''AKIAIOSFODNN7EXAMPLE''',                   # AWS 文档示例密钥，全网可见
  '''wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY''', # 同上
  '''minioadmin''',                             # MinIO 官方默认账号
]

# 整个文件豁免（慎用）
paths = [
  '''vendor/''',
  '''package-lock\.json''',
]
```

### allowlist 的纪律

**这是整套方案里最容易失效的地方。**

allowlist 是"合法地关掉检查"。一旦养成"报错就加进 allowlist"的习惯，
扫描就变成了摆设——它永远绿，但什么也拦不住。

两条规则：

1. **每条都写明理由**，不写理由的条目等于没有审查
2. **优先用精确值，别用宽泛正则**。`'''AKIAIOSFODNN7EXAMPLE'''` 是
   放行一个具体的公开示例；`'''AKIA[A-Z0-9]+'''` 是把整条 AWS 规则废掉

`paths` 豁免比 `regexes` 更危险——它让整个文件脱离检查。只对确实无法
审查的第三方产物（`vendor/`、锁文件、编译产物）使用。

#### 一个真实例子

**这份文档本身就被钩子拦下过。** 下面「实战踩坑」那节教你造假密钥测试
拦截，示例里写了 `AKIAIOSFODNN7REALKEY`——提交文档时钩子照拦不误，
它不知道这是教学示例。

当时有三个选择：

| 做法 | 评价 |
|---|---|
| 豁免整个 `docs/` 路径 | ✗ 文档里同样可能误贴真密钥，等于给自己开后门 |
| 改示例，用不触发规则的占位值 | ✗ 读者照抄后测不出拦截，教学价值没了 |
| 精确放行这两个具体串 | ✓ 采用 |

最终在 allowlist 里加了这两个精确值并注明理由。这就是「优先用精确值、
别用宽泛正则、每条写理由」的实际含义——放行的范围应该恰好覆盖已知
无害的那一点，不多一分。

### 自带的兜底脚本

gitleaks 是外部二进制，本地要额外装。如果希望"任何人 clone 下来就能跑"，
可以再写一个零依赖的轻量扫描脚本，和钩子共用。

min-s3 里是 `tests/audit.php`（PHP，无第三方依赖），支持两种模式：

```bash
php tests/audit.php            # 全量扫描
php tests/audit.php --staged   # 只扫暂存内容，供钩子调用
```

两者定位不同，不是重复：

| | gitleaks | 自带脚本 |
|---|---|---|
| 规则数量 | 200+ | 十几条常见的 |
| 依赖 | 需要装二进制 | 无，跟着仓库走 |
| 用途 | CI 全面复查 | 本地即时拦截 |

---

## 第三层：pre-commit 钩子

### 用 core.hooksPath，别复制到 .git/hooks

传统做法是把脚本复制到 `.git/hooks/pre-commit`。问题是 `.git/` 不受版本
控制——钩子改了没法同步给别人，新人 clone 下来也没有。

正确做法是把钩子放在仓库内的目录，用 `core.hooksPath` 指过去：

```bash
git config core.hooksPath .githooks
```

这样钩子本身受版本控制、团队共享、改动可追溯。而且这是**仓库级配置**，
不影响你其他项目。

用 composer / npm script 包一下，让安装只有一条命令：

```json
{
    "scripts": {
        "hooks:install": "git config core.hooksPath .githooks"
    }
}
```

### 钩子脚本

`.githooks/pre-commit`：

```sh
#!/bin/sh
#
# 提交前扫描暂存内容里的敏感信息。
# 启用：git config core.hooksPath .githooks
# 单次跳过：git commit --no-verify

if ! command -v php >/dev/null 2>&1; then
    echo "pre-commit: 找不到 php，跳过扫描" >&2
    exit 0
fi

# 定位仓库根：hook 的 $0 在不同 git 版本与平台下形式不一，
# 靠它推路径不可靠；脚本内部还要执行 git 命令，工作目录也必须正确
repo_root=$(git rev-parse --show-toplevel 2>/dev/null)
if [ -z "$repo_root" ]; then
    exit 0
fi
cd "$repo_root" || exit 0

php tests/audit.php --staged || {
    echo ""
    echo "提交已拦下：暂存内容里有可疑的敏感信息。"
    echo "  · 确实是凭据   → 移出暂存区，改用环境变量"
    echo "  · 是示例值     → 加进 allowlist 并注明理由"
    echo "  · 要跳过检查   → git commit --no-verify"
    exit 1
}
```

记得给执行权限，并让 git 记录这个权限位：

```bash
chmod +x .githooks/pre-commit
git update-index --chmod=+x .githooks/pre-commit
```

Windows 上不设也能跑（Git Bash 会用 sh 执行），但 Linux/macOS 的同事
拉下来会因为没有执行位而静默跳过。

### 必须扫暂存区版本，不是工作区

```
git add file.php          # 此时 file.php 含密钥
vim file.php              # 改掉密钥，但没有重新 add
git commit                # 提交的仍是含密钥的那一版！
```

所以钩子要读 `git show :file.php`（暂存区版本），不能直接读工作区文件。
读错了会漏掉这种情况。

---

## 测试矩阵 CI

和扫描无关，但一起配了省事。

```yaml
name: 测试

on:
  push:
    branches: [main]
    tags: ['v*']
  pull_request:
  workflow_dispatch:      # 允许手动触发，调试 CI 时很有用

permissions:
  contents: read          # 最小权限，默认是可写的

jobs:
  test:
    name: PHP ${{ matrix.php }} / ${{ matrix.os }}
    runs-on: ${{ matrix.os }}

    strategy:
      # 一个版本失败不影响其他版本继续跑，一次看全兼容性；
      # 默认 true 会在第一个失败时取消其余 job
      fail-fast: false
      matrix:
        os: [ubuntu-latest]
        php: ['8.1', '8.2', '8.3', '8.4']
        include:
          - os: windows-latest
            php: '8.3'
          - os: macos-latest
            php: '8.3'

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: curl, simplexml, json
          coverage: none          # 不跑覆盖率时关掉，能省一半时间

      - name: 校验 composer.json
        run: composer validate --strict

      - name: 运行测试
        run: php tests/run.php
```

### 为什么值得跑多版本

不是形式主义。min-s3 声明支持 PHP >= 8.1，但代码里有一处写了
`: false` 作为返回类型——**那是 PHP 8.2 才有的语法**，8.1 下整个文件
语法错误。本地是 8.3，所有测试都绿，完全测不出来。

配 8.1 的 job 时才顺手查出来的。**声明支持的最低版本，就必须在 CI 里
真的跑一遍**，否则那个声明是空头支票。

### 跨平台也值得

如果测试涉及子进程、路径、换行符，Windows 和 Linux 行为差别很大。
min-s3 的测试要起停 HTTP 服务器，就踩了 Windows 的坑（见下）。

---

## 实战踩坑记录

这些都是这个项目里真实遇到并修掉的。

### 1. Windows 下 `2>/dev/null` 让检查静默失效

```php
// 有问题：/dev/null 在 Windows 上不是有效路径，整条命令失败
$content = shell_exec("git show :$file 2>/dev/null");
// 返回 null → 脚本以为暂存区是空的 → 什么也没扫就放行
```

**最危险的不是报错，是静默通过。** 当时钩子输出"暂存区没有需要扫描的
内容"然后放行，看起来一切正常，实际一个字节都没扫。

```php
// 修正：用 exec 加退出码判断，不做 shell 重定向
$output = [];
$code = 0;
exec('git show ' . escapeshellarg(':' . $file), $output, $code);
if ($code === 0 && $output !== []) {
    $content = implode("\n", $output);
}
```

### 2. 密钥正则漏掉带引号的键名

```php
// 有问题：匹配不到 'password' => '...'
'/(?:password|secret|api_?key)\s*(?:=>|=|:)\s*[\'"][^\'"]{8,}[\'"]/i'
```

`password\s*=>` 要求 `password` 后面直接跟空白和 `=>`，但 PHP 数组最
常见的写法是 `'password' => '...'`，键名两边有引号。

```php
// 修正：允许键名后紧跟一个引号
'/(?:password|secret|api_?key)[\'"]?\s*(?:=>|=|:)\s*[\'"][^\'"]{8,}[\'"]/i'
```

### 3. 一定要实测钩子，别写完就信

上面两个 bug 都是**实测时才发现的**。做法是造一个含假密钥的文件去提交：

```bash
cat > leak-test.php <<'PHP'
<?php
$config = [
    'access_key' => 'AKIAIOSFODNN7REALKEY',
    'password'   => 'SuperSecret123456',
];
PHP

git add leak-test.php
git commit -m "这个提交应该被拦下"
# 期望：退出码 1，HEAD 不变
```

第一次跑，**提交直接过了**。如果不做这一步，交付的是一个看起来有防护、
实际一个都拦不住的钩子——比没有防护更糟，因为它给人虚假的安全感。

测完记得清理：

```bash
git reset HEAD leak-test.php && rm leak-test.php
```

### 4. `composer audit` 是内置命令，会盖掉自定义脚本

```json
"scripts": {
    "audit": "php tests/audit.php"    // 无效！
}
```

Composer 2.4+ 有内置的 `composer audit`（检查依赖安全公告），自定义
同名脚本不会被执行。改个名字：

```json
"scripts": {
    "scan": "php tests/audit.php"
}
```

npm 也有同样的问题：`npm audit`、`npm test`、`npm start` 等都是内置的。

### 5. Windows 下 proc_open 要 bypass_shell

测试脚本需要起停 HTTP 服务器时：

```php
// 有问题：Windows 下 proc_open 默认经 cmd 启动，
// proc_terminate 杀掉的是 cmd，真正的 php.exe 会留下来占着端口
$process = proc_open($command, $descriptors, $pipes);

// 修正
$options = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];
$process = proc_open($command, $descriptors, $pipes, null, null, $options);
```

### 6. proc_get_status 会"吃掉"退出码

```php
$status = proc_get_status($process);
if (!$status['running']) {
    // 退出码必须在这里取
    $exitCode = $status['exitcode'];
}
// ...
proc_close($process);   // 此时只返回 -1，不是真实退出码
```

一旦 `proc_get_status` 观察到进程已结束，退出码就被它记下了，之后
`proc_close` 只返回 -1。踩这个坑的表现是：测试明明全过，CI 却报失败。

### 7. export-ignore：别让开发文件进分发包

`.gitattributes`：

```
/.gitattributes export-ignore
/.gitignore     export-ignore
/.github        export-ignore
/.githooks      export-ignore
/.gitleaks.toml export-ignore
/tests          export-ignore
/docs           export-ignore
```

验证实际会分发什么：

```bash
git archive --format=tar HEAD | tar -t
```

或者装一遍看 `vendor/包名/` 下有什么。

### 8. 顺手统一行尾

```
*.php  text eol=lf
*.md   text eol=lf
*.json text eol=lf
*.yml  text eol=lf
```

Windows 上开发、Linux 上跑 CI 时，行尾不一致会让 diff 变成整文件重写，
也可能影响校验和比对。

---

## 搬到其他项目

三层模型和绝大多数坑是通用的，换语言只是换执行器。

### 需要改的部分

| 项 | PHP | 换成别的语言 |
|---|---|---|
| 钩子里的解释器 | `php tests/audit.php` | `node scripts/audit.js` / `python -m audit` |
| script 定义 | `composer.json` 的 `scripts` | `package.json` / `Makefile` / `justfile` |
| CI 环境准备 | `shivammathur/setup-php` | `actions/setup-node` / `setup-python` / `setup-go` |
| 版本矩阵 | `php: ['8.1', ...]` | `node: [18, 20, 22]` 等 |

### 不用改的部分

- `.gitleaks.toml` —— 与语言无关
- gitleaks 的 CI job —— 与语言无关
- Secret Scanning 的开启 —— 与语言无关
- `core.hooksPath` 的用法 —— 与语言无关
- `.gitattributes` 的 export-ignore —— 与语言无关（对 npm 是 `.npmignore` / `files` 字段）

### 现成的钩子框架

不想自己写钩子脚本的话：

- **[pre-commit](https://pre-commit.com/)**（Python 写的，但支持任何语言）
  配置 `.pre-commit-config.yaml`，官方有 gitleaks 的 hook，一行接入
- **[husky](https://typicode.github.io/husky/)** + **lint-staged**（Node 生态）

它们的好处是生态成熟、能串联多个检查（格式化、lint、扫描）。代价是引入
额外依赖。min-s3 因为主打零依赖才手写了钩子；一般项目直接用 pre-commit
框架更省事。

---

## 落地检查清单

新项目照着过一遍：

**平台层**
- [ ] Settings → Code security → 开启 Secret scanning
- [ ] 勾上 **Push protection**（关键，别漏）
- [ ] 私有仓库确认是否有 GHAS，没有就更依赖下面两层

**CI 层**
- [ ] 加 gitleaks job，`fetch-depth: 0` 别漏
- [ ] 建 `.gitleaks.toml`，allowlist 每条写理由
- [ ] 组织仓库确认是否需要 `GITLEAKS_LICENSE`
- [ ] 测试矩阵覆盖到**声明支持的最低版本**
- [ ] `fail-fast: false`
- [ ] `permissions: contents: read`

**本地层**
- [ ] 钩子放仓库内目录 + `core.hooksPath`，不要复制到 `.git/hooks`
- [ ] 提供一条命令的安装方式，写进 README
- [ ] 钩子读**暂存区版本**，不是工作区
- [ ] `chmod +x` 并 `git update-index --chmod=+x`
- [ ] **造假密钥实测一次拦截**，别写完就信

**分发**
- [ ] `.gitattributes` 配 export-ignore
- [ ] `git archive --format=tar HEAD | tar -t` 验证实际分发内容
- [ ] 统一行尾

**万一 CI 报出了密钥**
- [ ] 先**吊销并轮换**密钥——推上去就当已泄漏，删提交不解决问题
- [ ] 再清理历史（`git filter-repo` 或 BFG）
- [ ] 强推后旧提交对象仍可能通过 SHA 访问，彻底清除需联系平台支持
