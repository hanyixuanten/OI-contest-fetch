import requests
import json
import time
import re
from datetime import datetime, timezone
from urllib.parse import urljoin, urlparse, parse_qs
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

def parse_atcoder_time(text):
    return int(datetime.strptime(text.strip(), "%Y-%m-%d %H:%M:%S%z").timestamp())

def parse_uoj_time(text):
    return int(datetime.strptime(text.strip(), "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc).timestamp()) - 8 * 3600

def parse_uoj_duration(time_url, duration_text):
    params = parse_qs(urlparse(time_url).query)
    seconds = 0
    if params.get("ah"):
        seconds += int(float(params["ah"][0]) * 3600)
    if params.get("am"):
        seconds += int(float(params["am"][0]) * 60)
    if seconds:
        return seconds

    match = re.search(r"([\d.]+)\s*小时", duration_text)
    if match:
        return int(float(match.group(1)) * 3600)
    return 0

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

# ---------- UOJ ----------
def fetch_uoj(include_all=False, min_end_time=None):
    url = "https://uoj.ac/contests"
    try:
        resp = requests.get(url, headers=HEADERS, timeout=15)
        resp.raise_for_status()
        soup = BeautifulSoup(resp.text, "html.parser")
        contests = []
        now = now_ts()
        for row in soup.select("table tr"):
            cols = row.find_all("td")
            if len(cols) < 3:
                continue
            contest_link = cols[0].find("a", href=re.compile(r"^/contest/\d+$"))
            time_link = cols[1].find("a", href=True)
            if not contest_link or not time_link:
                continue
            start = parse_uoj_time(time_link.get_text(strip=True))
            duration = parse_uoj_duration(time_link["href"], cols[2].get_text(" ", strip=True))
            if duration <= 0:
                continue
            end = start + duration
            if (include_all and (min_end_time is None or end >= min_end_time)) or end > now:
                contests.append(make_contest(
                    "UOJ",
                    contest_link.get_text(strip=True),
                    start,
                    end,
                    urljoin("https://uoj.ac", contest_link["href"])
                ))
        return contests
    except Exception as e:
        print(f"UOJ error: {e}")
        return []

def main():
    all_contests = []
    all_contests.extend(fetch_codeforces())
    all_contests.extend(fetch_atcoder())
    all_contests.extend(fetch_uoj())

    all_contests.sort(key=lambda x: x["start_time"])

    # 直接写入仓库根目录
    with open("contests.json", "w", encoding="utf-8") as f:
        json.dump(all_contests, f, ensure_ascii=False, indent=2)

    recent_finished_contests = []
    recent_finished_min_end_time = recent_finished_cutoff()
    recent_finished_contests.extend(fetch_codeforces(include_all=True))
    recent_finished_contests.extend(fetch_atcoder(include_all=True))
    recent_finished_contests.extend(fetch_uoj(include_all=True, min_end_time=recent_finished_min_end_time))

    recent_finished_contests = filter_recent_finished(deduplicate_contests(recent_finished_contests))
    recent_finished_contests.sort(key=lambda x: x["end_time"])

    with open("contests_all.json", "w", encoding="utf-8") as f:
        json.dump(recent_finished_contests, f, ensure_ascii=False, indent=2)

    print(f"Total upcoming contests: {len(all_contests)}")
    print(f"Total contests finished in last 30 days: {len(recent_finished_contests)}")
if __name__ == "__main__":
    main()
