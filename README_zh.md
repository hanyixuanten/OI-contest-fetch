# OI Contest Fetch

[en](README.md)/zh

从多个在线评测平台抓取编程竞赛日程，并导出为 JSON 文件。仓库中还包含一个简单的 PHP 页面，可以读取生成的 JSON 并展示即将到来的比赛。

## 支持平台

- Codeforces
- AtCoder
- UOJ

## 输出文件

运行抓取脚本后，会在仓库根目录写入以下 JSON 文件：

- `contests.json`：尚未结束的比赛，包括尚未开始和正在进行中的比赛。
- `contests_all.json`：最近 30 天内已经结束的比赛。

每条比赛记录的结构如下：

```json
{
  "platform": "AtCoder",
  "title": "AtCoder Beginner Contest 468",
  "start_time": 1784980800,
  "end_time": 1784986800,
  "status": "upcoming",
  "url": "https://atcoder.jp/contests/abc468"
}
```

`start_time` 和 `end_time` 是秒级 Unix 时间戳。`status` 可能是以下值之一：

- `finished`：已结束
- `running`：进行中
- `upcoming`：尚未开始

## 环境要求

- Python 3
- PHP 5.4 或更新版本，仅在需要使用 `server/` 下的页面时需要

Python 依赖：

```bash
pip install requests beautifulsoup4
```

## 使用方式

在仓库根目录运行抓取脚本：

```bash
python fetch_contests.py
```

成功运行后，脚本会输出类似下面的统计信息：

```text
Total upcoming contests: 13
Total contests finished in last 30 days: 139
```

## 网页展示

`server/index.php` 会读取公开的 GitHub Raw 地址中的 `contests.json` 和 `contests_all.json`，在本地缓存 5 分钟，并渲染带客户端倒计时的即将开始比赛，以及最近已结束的比赛。页面会识别浏览器/系统语言和时区，将其同步到 cookie，并在后续加载时用于本地化文案和比赛时间显示。不支持的语言会回退到英语。

如果你自行部署，并且仓库路径或分支发生变化，需要修改 `server/index.php` 中的这个常量：

```php
define('JSON_URL', 'https://raw.githubusercontent.com/hanyixuanten/OI-contest-fetch/master/contests.json');
```

缓存文件会以 `contests_cache.json` 和 `contests_all_cache.json` 的名字写在 `index.php` 同目录下，因此 Web 服务器进程需要拥有 `server/` 目录的写入权限。

## 自动化

一个常见的自动化流程是：

1. 定时运行 `python fetch_contests.py`。
2. 提交更新后的 JSON 文件。
3. 推送到 GitHub。
4. 让 `server/index.php` 从 GitHub Raw 读取最新的 `contests.json`。

如果使用 GitHub Actions，请在运行脚本前安装 Python 依赖。

## 开发说明

AI coding agents 和维护者在修改比赛提供商、输出契约、工作流行为或文档时，应遵循 [AGENTS.md](AGENTS.md)。

## 注意事项

- AtCoder 使用 AtCoder Problems API；在需要时会回退解析 AtCoder 官方比赛页面以获取即将开始的比赛。
- UOJ 从公开比赛列表页面解析，并读取比赛链接中的 timeanddate 持续时间参数。
- 网络问题或上游 API/页面结构变化，可能会暂时影响抓取到的比赛数量。
