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

    print(f"Total upcoming contests: {len(all_contests)}")