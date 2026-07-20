# OI Contest Fetch

en/[zh](README_zh.md)

Fetch programming contest schedules from several online judge platforms and export them as JSON files. The repository also includes a small PHP page that can read the generated JSON and display upcoming contests.

## Supported Platforms

- Codeforces
- AtCoder
- USACO
- UOJ

## Output Files

Running the fetch script writes JSON files to the repository root:

- `contests.json`: contests that have not ended yet, including upcoming and currently running contests.
- `contests_all.json`: contests that finished within the last 30 days.

Each contest item has this shape:

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

`start_time` and `end_time` are Unix timestamps in seconds. `status` can be one of:

- `finished`
- `running`
- `upcoming`

## Requirements

- Python 3
- PHP, only if you want to use the page under `server/`

Python packages:

```bash
pip install requests beautifulsoup4
```

## Usage

Run the fetch script from the repository root:

```bash
python fetch_contests.py
```

After a successful run, the script prints counts similar to:

```text
Total upcoming contests: 13
Total contests finished in last 30 days: 139
```

## Web Page

`server/index.php` reads the public raw GitHub URL for `contests.json`, caches it locally for 5 minutes, and renders upcoming contests with a client-side countdown.

If you deploy it yourself, update this constant in `server/index.php` if your repository path or branch changes:

```php
define('JSON_URL', 'https://raw.githubusercontent.com/hanyixuanten/OI-contest-fetch/master/contests.json');
```

The cache file is written next to `index.php` as `contests_cache.json`, so the web server process needs write permission for the `server/` directory.

## Automation

A typical automation flow is:

1. Run `python fetch_contests.py` on a schedule.
2. Commit the updated JSON files.
3. Push them to GitHub.
4. Let `server/index.php` read the latest `contests.json` from GitHub raw content.

For GitHub Actions, install the Python dependencies before running the script.

## Development Notes

AI coding agents and maintainers should follow [AGENTS.md](AGENTS.md) when changing providers, output contracts, workflow behavior, or documentation.

## Notes

- AtCoder uses the AtCoder Problems API and falls back to the official AtCoder contest page for upcoming contests when needed.
- UOJ is parsed from its public contests page, including the timeanddate duration parameters used by contest links.
- USACO schedule data depends on the current layout of the official USACO contests page. If USACO has no future schedule published, it may produce no upcoming USACO contests.
- Network or upstream API changes can temporarily reduce the number of fetched contests.
