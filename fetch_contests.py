import requests
import json
import time
import re
from datetime import datetime, timezone
from bs4 import BeautifulSoup

# ---------- 工具函数 ----------
def now_ts():
    return int(time.time())

def parse_duration_to_end(start_ts, seconds):
    return start_ts + seconds

def get_status(start_ts, end_ts, current_ts=None):
    current_ts = current_ts or now_ts()
    if end_ts <= current_ts:
        return "finished"
    if start_ts > current_ts:
        return "upcoming"
    return "running"

def make_contest(platform, title, start_time, end_time, url):
    return {
        "platform": platform,
        "title": title,
        "start_time": start_time,
        "end_time": end_time,
        "status": get_status(start_time, end_time),
        "url": url
    }

def deduplicate_contests(contests):
    seen = set()
    unique = []
    for contest in contests:
        key = (contest["platform"], contest["url"])
        if key in seen:
            continue
        seen.add(key)
        unique.append(contest)
    return unique

def recent_finished_cutoff(days=30):
    return now_ts() - days * 24 * 60 * 60

def filter_recent_finished(contests, days=30):
    current_ts = now_ts()
    earliest_end_ts = recent_finished_cutoff(days)
    return [
        contest for contest in contests
        if contest["status"] == "finished" and contest["end_time"] >= earliest_end_ts
    ]

HEADERS = {
    "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
                  "(KHTML, like Gecko) Chrome/126.0 Safari/537.36"
}

MONTH_MAP = {"jan": 1, "feb": 2, "mar": 3, "apr": 4, "may": 5, "jun": 6,
             "jul": 7, "aug": 8, "sep": 9, "oct": 10, "nov": 11, "dec": 12}

def parse_atcoder_time(text):
    return int(datetime.strptime(text.strip(), "%Y-%m-%d %H:%M:%S%z").timestamp())

def infer_usaco_year(season, month_num):
    start_year, end_year = map(int, season.split("-"))
    return start_year if month_num >= 8 else end_year

def parse_usaco_day_range(date_text, season):
    match = re.match(r"(\w{3})\s+(\d{1,2})(?:-(?:(\w{3})\s+)?(\d{1,2}))?", date_text)
    if not match:
        return None
    start_mon, start_day, end_mon, end_day = match.groups()
    start_month = MONTH_MAP.get(start_mon.lower())
    end_month = MONTH_MAP.get((end_mon or start_mon).lower())
    if not start_month or not end_month:
        return None

    start_dt = datetime(infer_usaco_year(season, start_month), start_month, int(start_day),
                        0, 0, 0, tzinfo=timezone.utc)
    end_dt = datetime(infer_usaco_year(season, end_month), end_month, int(end_day or start_day),
                      23, 59, 59, tzinfo=timezone.utc)
    return int(start_dt.timestamp()), int(end_dt.timestamp())

# ---------- Codeforces ----------
def fetch_codeforces(include_all=False):
    url = "https://codeforces.com/api/contest.list?gym=false"
    try:
        resp = requests.get(url, headers=HEADERS, timeout=15)
        data = resp.json()
        if data["status"] != "OK":
            return []
        contests = []
        for c in data["result"]:
            if include_all or c["phase"] == "BEFORE":
                contests.append(make_contest(
                    "Codeforces",
                    c["name"],
                    c["startTimeSeconds"],
                    c["startTimeSeconds"] + c["durationSeconds"],
                    f"https://codeforces.com/contests/{c['id']}"
                ))
        return contests
    except Exception as e:
        print(f"Codeforces error: {e}")
        return []

# ---------- AtCoder (使用 kenkoooo 的非官方 API) ----------
def fetch_atcoder(include_all=False):
    url = "https://kenkoooo.com/atcoder/resources/contests.json"
    try:
        resp = requests.get(url, headers=HEADERS, timeout=15)
        resp.raise_for_status()
        data = resp.json()
        contests = []
        now = now_ts()
        for c in data:
            start = c["start_epoch_second"]
            end = start + c["duration_second"]
            if start > 0 and (include_all or start > now):
                contests.append(make_contest(
                    "AtCoder",
                    c["title"],
                    start,
                    end,
                    f"https://atcoder.jp/contests/{c['id']}"
                ))
        if contests and not include_all:
            return contests

        page = requests.get("https://atcoder.jp/contests/?lang=en", headers=HEADERS, timeout=15)
        page.raise_for_status()
        soup = BeautifulSoup(page.text, "html.parser")
        upcoming = soup.select_one("#contest-table-upcoming table tbody")
        if not upcoming:
            return []
        for row in upcoming.select("tr"):
            cols = row.find_all("td")
            if len(cols) < 3:
                continue
            start_time = parse_atcoder_time(cols[0].get_text(strip=True))
            duration_parts = [int(part) for part in cols[2].get_text(strip=True).split(":")]
            duration = duration_parts[0] * 3600 + duration_parts[1] * 60
            link = cols[1].find("a", href=True)
            if (include_all or start_time > now) and link:
                contests.append(make_contest(
                    "AtCoder",
                    link.get_text(strip=True),
                    start_time,
                    start_time + duration,
                    f"https://atcoder.jp{link['href']}"
                ))
        return deduplicate_contests(contests)
    except Exception as e:
        print(f"AtCoder error: {e}")
        return []

# ---------- USACO (解析官网) ----------
def fetch_usaco(include_all=False):
    url = "https://usaco.org/index.php?page=contests"
    try:
        resp = requests.get(url, headers=HEADERS, timeout=15)
        resp.raise_for_status()
        soup = BeautifulSoup(resp.text, "html.parser")
        panel = None
        season = None
        for candidate in soup.find_all("div", class_="panel"):
            heading = candidate.find("h2")
            if not heading:
                continue
            match = re.search(r"(\d{4}-\d{4})\s+Schedule", heading.get_text(" ", strip=True))
            if match:
                panel = candidate
                season = match.group(1)
                break
        if not panel:
            return []
        text = panel.get_text(separator="\n")
        lines = [line.strip() for line in text.splitlines() if line.strip()]
        contests = []
        now = datetime.now(timezone.utc)
        for line in lines:
            match = re.match(r"((?:\w{3}\s+)?\d{1,2}(?:-(?:\w{3}\s+)?\d{1,2})?):\s*(.+)", line)
            if match:
                date_text, title = match.groups()
                parsed_range = parse_usaco_day_range(date_text, season)
                if not parsed_range:
                    continue
                start_ts, end_ts = parsed_range
                if include_all or end_ts > now.timestamp():
                    contests.append(make_contest(
                        "USACO",
                        title.strip(),
                        start_ts,
                        end_ts,
                        "https://usaco.org/"
                    ))
        return contests
    except Exception as e:
        print(f"USACO error: {e}")
        return []

# ---------- 洛谷 ----------
def fetch_luogu(include_all=False, min_end_time=None):
    url = "https://www.luogu.com.cn/contest/list"
    headers = {
        **HEADERS,
        "Accept": "application/json, text/plain, */*",
        "Referer": "https://www.luogu.com.cn/contest/list",
        "X-Requested-With": "XMLHttpRequest",
        "x-lentille-request": "content-only"
    }
    try:
        session = requests.Session()
        session.headers.update(headers)
        session.get("https://www.luogu.com.cn/contest/list", timeout=15)
        contests = []
        now = now_ts()
        page = 1
        total = None
        while True:
            resp = session.get(url, params={"page": page, "_contentOnly": 1}, timeout=15)
            resp.raise_for_status()
            data = resp.json()
            if data.get("status") != 200 and data.get("code") != 200:
                return contests
            contest_data = data.get("data") or data.get("currentData") or {}
            page_data = contest_data.get("contests", {})
            result = page_data.get("result", [])
            if total is None:
                total = page_data.get("count", len(result))
            if not result:
                break
            for c in result:
                # endTime 是秒级时间戳；默认只保留尚未结束的比赛，全量模式保留全部
                if (include_all and (min_end_time is None or c["endTime"] >= min_end_time)) or c["endTime"] > now:
                    contests.append(make_contest(
                        "洛谷",
                        c["name"],
                        c["startTime"],
                        c["endTime"],
                        f"https://www.luogu.com.cn/contest/{c['id']}"
                    ))
            if include_all and min_end_time is not None and max(c["endTime"] for c in result) < min_end_time:
                break
            if not include_all or page * page_data.get("perPage", len(result)) >= total:
                break
            page += 1
        return contests
    except Exception as e:
        print(f"Luogu error: {e}")
        return []

# ---------- 主逻辑 ----------
# ... 前面四个平台的抓取函数保持不变 ...

def main():
    all_contests = []
    all_contests.extend(fetch_codeforces())
    all_contests.extend(fetch_atcoder())
    all_contests.extend(fetch_usaco())
    all_contests.extend(fetch_luogu())

    all_contests.sort(key=lambda x: x["start_time"])

    # 直接写入仓库根目录
    with open("contests.json", "w", encoding="utf-8") as f:
        json.dump(all_contests, f, ensure_ascii=False, indent=2)

    recent_finished_contests = []
    recent_finished_min_end_time = recent_finished_cutoff()
    recent_finished_contests.extend(fetch_codeforces(include_all=True))
    recent_finished_contests.extend(fetch_atcoder(include_all=True))
    recent_finished_contests.extend(fetch_usaco(include_all=True))
    recent_finished_contests.extend(fetch_luogu(include_all=True, min_end_time=recent_finished_min_end_time))

    recent_finished_contests = filter_recent_finished(deduplicate_contests(recent_finished_contests))
    recent_finished_contests.sort(key=lambda x: x["end_time"])

    with open("contests_all.json", "w", encoding="utf-8") as f:
        json.dump(recent_finished_contests, f, ensure_ascii=False, indent=2)

    print(f"Total upcoming contests: {len(all_contests)}")
    print(f"Total contests finished in last 30 days: {len(recent_finished_contests)}")
if __name__ == "__main__":
    main()
