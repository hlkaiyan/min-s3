# 发布流程

记录 min-s3 怎么发一个新版本，以及 Packagist、GitHub Release、
本地仓库这三处内容各自代表什么、什么时候会不一致。

---

## 目录

- [三处内容的关系](#三处内容的关系)
- [版本号怎么定](#版本号怎么定)
- [发布步骤](#发布步骤)
- [Packagist 同步](#packagist-同步)
- [Release 附件：对拍归档](#release-附件对拍归档)
  - [准备对拍环境](#准备对拍环境)
- [验证发布结果](#验证发布结果)
- [常见困惑](#常见困惑)
- [发布检查清单](#发布检查清单)

---

## 三处内容的关系

发一个版本会牵动三个地方，它们的内容**本来就不相同**，搞混会白排查半天：

| 位置 | 内容 | 更新时机 |
|---|---|---|
| GitHub 仓库 `main` | 全部文件，含 `tests/`、`docs/` | 每次 push |
| Packagist / `vendor/` | 只有 `src/`、`README.md`、`LICENSE`、`NOTICE`、`composer.json`、`autoload.php` | 只在**打 tag** 之后 |
| GitHub Release 附件 | 对拍用的第三方 zip 归档 | 手动上传 |

两条关键规则：

1. **composer 只认 tag，不认 commit。** 往 `main` 推多少提交都不影响
   `composer require` 装到的内容——不打 tag 就等于没发布。
2. **`.gitattributes` 的 `export-ignore` 会裁掉目录。** `tests/`、`docs/`、
   `.github/`、`.githooks/` 及各种点文件都不进分发包。所以 `vendor/hlkaiyan/min-s3/`
   永远比仓库少一批目录，这是设计如此，不是安装出错。

---

## 版本号怎么定

遵循 [语义化版本](https://semver.org/lang/zh-CN/)，`MAJOR.MINOR.PATCH`。
判断依据是**对使用方 public API 的影响**，不是改动量大小：

| 情况 | 版本位 | 例子 |
|---|---|---|
| 删改公开方法签名、改变已有方法的正常语义 | MAJOR | 改 `putObject()` 的参数顺序 |
| 新增公开方法 / 类 / 常量，向后兼容 | MINOR | 给 `Stream` 加 `truncate()` |
| 只修 bug，public API 一个字没动 | PATCH | 修分页死循环 |

容易判错的两种：

- **修 bug 时顺手加了个 public 方法** → 算 MINOR，不是 PATCH。
  v1.1.0 就是这种：4 个修复本身是 PATCH 级，但 `Stream::truncate()`
  是新的公开方法，一旦有人用了，降回旧版就会 fatal error。
- **修复改变了原有行为** → 仍然算 PATCH/MINOR，不必升 MAJOR。
  比如「消息体不可定位时不再重试」，旧行为是静默数据损坏，
  属于 bug 而非契约。

判断当前该发什么版本，先看新增了哪些公开成员：

```bash
git diff v1.0.0..main -- src/ | grep -E '^\+' | grep -E 'public (function|const)'
```

有输出就至少是 MINOR。

---

## 发布步骤

### 1. 确认工作区干净、与远程同步

```bash
git status --short          # 应为空
git log --oneline origin/main..HEAD    # 应为空，有输出说明还没 push
```

### 2. 跑完整测试

```bash
composer test               # 等价于 php tests/run.php
```

改过签名、序列化、解析、寻址或 URL 编码的话，**必须**补跑对拍。
它需要 aws-sdk-php 作参照，没装会自动跳过——注意 `run.php` 末尾会写
「跳过 1 组」，**跳过不等于通过**，别当成跑完了。

参照物从 v1.0.0 的 Release 附件取，见
[准备对拍环境](#准备对拍环境)。跑完应输出「一致 129 项，不符 0 项」。

### 3. 确认待发布的改动

```bash
git log --oneline v1.0.0..main          # 上个 tag 之后的提交
git diff --stat v1.0.0..main -- src/    # 只看真正影响使用方的部分
```

### 4. 打附注标签

本仓库用附注标签（`-a`），带中文说明，与 v1.0.0 保持一致。
**别用轻量标签**——附注标签才记录打标签的人和时间。

```bash
git tag -a v1.1.0 -m "修复重试副作用、分页死循环与头部注入

1. 消息体不可定位时不再重试，避免发出空内容造成静默数据损坏
2. sink 重试前 rewind + truncate，不再拼接残留内容
3. 分页器识别服务端回显相同 continuation token 的情况，不再死循环
4. 头部值校验 CRLF，拒绝请求头注入

新增 Stream::truncate()。"
```

### 5. 推送标签

标签**不会**跟着 `git push` 自动上去，要单独推：

```bash
git push origin v1.1.0
```

---

## Packagist 同步

推完 tag 后，Packagist 需要知道有新版本了。两种机制：

### 自动（推荐，配一次就行）

Packagist 账号设置里连接 GitHub 账号（GitHub App 集成），或在仓库
**Settings → Webhooks** 里加 Packagist 的 hook。配好之后 push tag
即自动同步，通常几十秒内生效。

### 手动

打开 <https://packagist.org/packages/hlkaiyan/min-s3>，登录后点
**Update** 按钮，立刻抓取。

判断自己配没配自动同步：推 tag 后等两分钟，用下面的命令查版本，
没出现就是没配，先手动更新，再去补配置。

---

## Release 附件：对拍归档

`tests/compat.php` 需要 aws-sdk-php 作参照。相关 zip **不进 git 仓库**，
挂在 GitHub Release 上，`.gitignore` 第 12–15 行负责挡住它们。

这么做的原因：二进制进了 git 历史就删不掉，普通 commit 删除只是加一层
删除记录，体积永远背着；而 Release 附件独立存储，clone 不下载，随时可换。

当前 v1.0.0 挂着三个：

| 文件 | 大小 | 用途 |
|---|---|---|
| `aws-s3.zip` | 691 KB | 裁剪版 SDK，只保留 S3 相关，501 个文件 |
| `aws-sdk-php-master.zip` | 6.5 MB | 完整 SDK 快照 |
| `composer.zip` | 7.4 MB | composer 快照 |

上传方式（网页最省事）：仓库 → **Releases** → 选中版本 **Edit release**
→ 拖进 **Attach binaries** → **Update release**。装了
[gh CLI](https://cli.github.com/) 的话是 `gh release upload <tag> <文件>`。

> 新版本若没改动对拍基准，附件不必重传——旧 Release 上的照样能下，
> 对拍固定取 v1.0.0 那份即可。

### 准备对拍环境

**直接用 `composer.zip`，不要用 `aws-s3.zip`。** 前者是完整的 composer
`vendor/` 目录，解压即用；后者是裁剪版，它的 autoloader 明确要求外部另行
提供 guzzle / psr7 / promises / jmespath / psr 接口，单独解压跑不起来。

```bash
# 1. 下载（asset id 见下方说明）
mkdir -p vendor/_dl
curl -L -H "Accept: application/octet-stream" \
  -o vendor/_dl/composer.zip \
  https://api.github.com/repos/hlkaiyan/min-s3/releases/assets/534980152

# 2. 核对哈希，确认与 Release 上是同一份
sha256sum vendor/_dl/composer.zip
# 期望 aa1b6945a2955ed370a825a215dc7f3bbbd9a7c85273bb93190eb8227854463b

# 3. 解压并把 vendor 内容摊平到 min-s3/vendor/
unzip -q vendor/_dl/composer.zip -d vendor/_ext
mv vendor/_ext/aws/vendor/* vendor/

# 4. 验证类可加载，再跑对拍
php -r "require 'vendor/autoload.php'; var_dump(class_exists('Aws\S3\S3Client'));"
php tests/compat.php
```

摊平到 `min-s3/vendor/` 是因为 `tests/compat.php` 只认两个候选路径
（`../vendor/autoload.php`、`../../vendor/autoload.php`），且要求
`Aws\S3\S3Client` 存在。`vendor/` 被 `.gitignore` 第 1 行忽略，不会污染仓库；
用完 `rm -rf vendor/` 即可。

**下载走 `api.github.com` 的 asset 端点，别用 `github.com/.../releases/download/` 直链。**
实测直链会 `curl: (56) Recv failure: Connection was reset`，而 API 端点正常。
asset id 用下面这条查（public 仓库匿名可读，不需要 token）：

```bash
curl -s https://api.github.com/repos/hlkaiyan/min-s3/releases/378923750/assets \
  | grep -E '"(id|name|digest)"'
```

当前三个附件的 id：`aws-s3.zip` = 534979759，
`aws-sdk-php-master.zip` = 534979818，`composer.zip` = 534980152。

---

## 验证发布结果

### Packagist 收到了吗

```bash
curl -s https://repo.packagist.org/p2/hlkaiyan/min-s3.json | head -c 400
```

看 `version` 和 `source.reference`，后者应等于新 tag 指向的 commit：

```bash
git rev-parse v1.1.0^{}     # ^{} 解引用附注标签，取到真正的 commit
```

两者对上才算发布成功。P2 的 JSON 有 CDN 缓存，刚更新可能要等一会儿。

### 实际装一遍

在仓库外面找个空目录：

```bash
mkdir /tmp/verify && cd /tmp/verify
composer require hlkaiyan/min-s3
composer show hlkaiyan/min-s3 | head
ls vendor/hlkaiyan/min-s3/     # 应只有 src README.md LICENSE NOTICE 等
```

`ls` 看不到 `tests/`、`docs/` 是**正常的**，见上面 export-ignore 的说明。

---

## 常见困惑

### composer 装下来的代码和仓库里的不一样

先分清是哪种「不一样」：

| 现象 | 原因 | 处理 |
|---|---|---|
| 少了 `tests/`、`docs/`、`.github/` | `.gitattributes` 的 `export-ignore` | 正常，不用处理 |
| `src/` 里的代码是旧的、缺某个修复 | 改动没打 tag，或 tag 没推、Packagist 没同步 | 走上面的发布步骤 |

排查第二种，一条命令看清楚：

```bash
git log --oneline $(git describe --tags --abbrev=0)..main
```

有输出，就说明最新 tag 之后还有提交没发布——**这些改动使用方拿不到**。

### 使用方装到的还是旧版

Packagist 已经更新的话，是对方本地的问题：

```bash
composer clear-cache
composer update hlkaiyan/min-s3
```

对方 `composer.json` 里若写着 `^1.0`，`composer update` 会升到 1.x 最新，
能拿到 1.1.0；写死 `1.0.0` 则不会动，需要对方自己改约束。

### tag 打错了想改

**已经推送并被 Packagist 抓取的 tag，不要删了重打。** 别人可能已经装了，
同名不同内容的 tag 会让缓存和 lock 文件对不上。正确做法是直接发下一个
补丁版本。

只在本地、还没 push 的情况下可以随便改：

```bash
git tag -d v1.1.0
```

### Release 附件传错了

附件可以随时删改，不影响 tag 和代码：

```bash
gh release delete-asset v1.1.0 aws-s3.zip
```

或在 Release 编辑页点附件旁的删除。

---

## 发布检查清单

- [ ] `git status` 干净，本地与 `origin/main` 同步
- [ ] `composer test` 全绿
- [ ] 动过签名/序列化/解析/寻址的话，`php tests/compat.php` 输出
      「一致 129 项，不符 0 项」——看到「跳过对拍」说明没跑成
- [ ] 按新增的 public 成员定好版本号
- [ ] `git tag -a` 打附注标签，说明写清改了什么
- [ ] `git push origin <tag>` 单独推标签
- [ ] Packagist 出现新版本，`source.reference` 与 tag 的 commit 一致
- [ ] 空目录里 `composer require` 实测装到新版
- [ ] 对拍基准有变的话，Release 附件同步更新并核对 SHA256
