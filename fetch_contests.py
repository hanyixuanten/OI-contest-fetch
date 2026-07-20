import requests
import json
import time
import re
from datetime import datetime, timedelta, timezone
from bs4 import BeautifulSoup

# ---------- 工具函数 ----------
def now_ts():
    return int(time.time())

def parse_duration_to_end(start_ts, seconds):
    return start_ts + seconds

# ---------- Codeforces ----------
def fetch_codeforces():
    url = "https://codeforces.com/api/contest.list?gym=false"
    try:
        resp = requests.get(url, timeout=15)
        data = resp.json()
        if data["status"] != "OK":
            return []
        contests = []
        for c in data["result"]:
            if c["phase"] == "BEFORE":
                contests.append({
                    "platform": "Codeforces",
                    "title": c["name"],
                    "start_time": c["startTimeSeconds"],
                    "end_time": c["startTimeSeconds"] + c["durationSeconds"],
                    "url": f"https://codeforces.com/contests/{c['id']}"
                })
        return contests
    except Exception as e:
        print(f"Codeforces error: {e}")
        return []

# ---------- AtCoder (使用 kenkoooo 的非官方 API) ----------
def fetch_atcoder():
    url = "https://kenkoooo.com/atcoder/resources/contests.json"
    try:
        resp = requests.get(url, timeout=15)
        data = resp.json()
        contests = []
        now = now_ts()
        for c in data:
            start = c["start_epoch_second"]
            if start > now:  # 只展示未来赛事
                contests.append({
                    "platform": "AtCoder",
                    "title": c["title"],
                    "start_time": start,
                    "end_time": start + c["duration_second"],
                    "url": f"https://atcoder.jp/contests/{c['id']}"
                })
        return contests
    except Exception as e:
        print(f"AtCoder error: {e}")
        return []

# ---------- USACO (解析官网) ----------
def fetch_usaco():
    url = "http://www.usaco.org/index.php?page=contests"
    try:
        resp = requests.get(url, timeout=15)
        soup = BeautifulSoup(resp.text, "html.parser")
        # USACO 赛程通常在 <div class="panel"> 里的 <table> 中
        # 具体解析需观察当前页面结构（以下基于典型结构）
        panel = soup.find("div", class_="panel")
        if not panel:
            return []
        # 查找所有含有月份的行，例如 "Dec 16-19: USACO December Contest"
        text = panel.get_text(separator="\n")
        lines = [line.strip() for line in text.splitlines() if line.strip()]
        contests = []
        # 获取年份：页面标题或当前年附近推断
        # 简单策略：当前年，若当前月 >= 8 则 12月属于今年，否则属于去年/明年
        now = datetime.now(timezone.utc)
        year = now.year
        month = now.month
        for line in lines:
            # 匹配类似 "Dec 16-19: USACO December Contest"
            match = re.match(r"(\w{3})\s+(\d{1,2})-(\d{1,2}):(.+)", line)
            if match:
                mon_str, day_start, day_end, title = match.groups()
                # 推断年份
                month_map = {"jan":1,"feb":2,"mar":3,"apr":4,"may":5,"jun":6,
                             "jul":7,"aug":8,"sep":9,"oct":10,"nov":11,"dec":12}
                m = month_map.get(mon_str.lower())
                if not m:
                    continue
                # 如果当前月 >= 8 并且赛事月是 12-7，需要判断
                if month >= 8:
                    # 从 8 月到 12 月都算今年；来年的 1-7 月算是下一年
                    event_year = year if m >= 8 else year + 1
                else:
                    # 当前月 1-7，去年的 8-12 已经过去，今年的 1-7 为今年
                    event_year = year if m <= 7 else year - 1
                # 构造开始时间 (使用 UTC，忽略具体时区，作为近似)
                start_dt = datetime(event_year, m, int(day_start), 0, 0, 0, tzinfo=timezone.utc)
                end_dt = datetime(event_year, m, int(day_end), 23, 59, 59, tzinfo=timezone.utc)
                start_ts = int(start_dt.timestamp())
                end_ts = int(end_dt.timestamp())
                if start_ts > now.timestamp():
                    contests.append({
                        "platform": "USACO",
                        "title": title.strip(),
                        "start_time": start_ts,
                        "end_time": end_ts,
                        "url": "http://www.usaco.org/"
                    })
        return contests
    except Exception as e:
        print(f"USACO error: {e}")
        return []

# ---------- 洛谷 ----------
def fetch_luogu():
    url = "https://www.luogu.com.cn/contest/list?_contentOnly=1"
    headers = {"User-Agent": "Mozilla/5.0"}
    try:
        resp = requests.get(url, headers=headers, timeout=15)
        data = resp.json()
        if data.get("code") != 200:
            return []
        contests = []
        now = now_ts()
        for c in data["currentData"]["contests"]["result"]:
            # endTime 是秒级时间戳，只保留尚未结束的比赛
            if c["endTime"] > now:
                contests.append({
                    "platform": "洛谷",
                    "title": c["name"],
                    "start_time": c["startTime"],
                    "end_time": c["endTime"],
                    "url": f"https://www.luogu.com.cn/contest/{c['id']}"
                })
        return contests
    except Exception as e:
        print(f"Luogu error: {e}")
        return []

# ---------- 主逻辑 ----------
def main():
    all_contests = []
    all_contests.extend(fetch_codeforces())
    all_contests.extend(fetch_atcoder())
    all_contests.extend(fetch_usaco())
    all_contests.extend(fetch_luogu())

    # 按开始时间排序
    all_contests.sort(key=lambda x: x["start_time"])

    # 写 JSON
    with open("public/contests.json", "w", encoding="utf-8") as f:
        json.dump(all_contests, f, ensure_ascii=False, indent=2)

    print(f"Total upcoming contests: {len(all_contests)}")

if __name__ == "__main__":
    main()
