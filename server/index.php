<?php
// 这里可以留空，或者添加一些 PHP 逻辑，但不需要。
// 下面的 HTML 是直接输出的静态页面。
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>编程竞赛日程</title>
<style>
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f5f7fa; margin: 0; padding: 20px; }
  .container { max-width: 900px; margin: 0 auto; }
  h1 { text-align: center; color: #2c3e50; }
  .card {
    background: white; border-radius: 12px; padding: 20px; margin: 15px 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08); display: flex; justify-content: space-between;
    align-items: center; flex-wrap: wrap;
  }
  .platform {
    font-weight: 700; color: white; padding: 4px 12px; border-radius: 20px;
    font-size: 14px; background: #3498db;
  }
  .platform.cf { background: #1f8acb; }
  .platform.atc { background: #5b8c5a; }
  .platform.usaco { background: #e67e22; }
  .platform.luogu { background: #e74c3c; }
  .info { flex: 1; margin-left: 15px; }
  .title { font-size: 18px; font-weight: 600; color: #2c3e50; }
  .time { font-size: 14px; color: #7f8c8d; margin-top: 5px; }
  .countdown { font-weight: 700; color: #e74c3c; margin-left: auto; min-width: 120px; text-align: right; }
  a { text-decoration: none; color: inherit; }
</style>
</head>
<body>
<div class="container">
  <h1>📅 即将到来的编程竞赛</h1>
  <div id="contest-list">加载中...</div>
</div>

<script>
// 请将下面的 URL 替换为你的仓库 Raw 文件地址
const DATA_URL = "https://raw.githubusercontent.com/hanyixuanten/OI-contest-fetch/master/contests.json";

const PLATFORM_CLASS = {
  "Codeforces": "cf",
  "AtCoder": "atc",
  "USACO": "usaco",
  "洛谷": "luogu"
};

function formatTime(ts) {
  return new Date(ts * 1000).toLocaleString("zh-CN", { timeZone: "Asia/Shanghai" });
}

function calcCountdown(startTs) {
  const diff = startTs * 1000 - Date.now();
  if (diff <= 0) return "进行中";
  const d = Math.floor(diff / 86400000);
  const h = Math.floor((diff % 86400000) / 3600000);
  const m = Math.floor((diff % 3600000) / 60000);
  const s = Math.floor((diff % 60000) / 1000);
  return `${d}天 ${h}时 ${m}分 ${s}秒`;
}

async function load() {
  const list = document.getElementById("contest-list");
  try {
    const res = await fetch(DATA_URL);
    if (!res.ok) throw new Error("数据加载失败");
    const contests = await res.json();
    if (!contests.length) {
      list.innerHTML = "<p style='text-align:center'>暂无即将开始的赛事</p>";
      return;
    }
    list.innerHTML = contests.map(c => {
      const cls = PLATFORM_CLASS[c.platform] || "";
      return `
        <a href="${c.url}" target="_blank" class="card">
          <span class="platform ${cls}">${c.platform}</span>
          <div class="info">
            <div class="title">${c.title}</div>
            <div class="time">${formatTime(c.start_time)} ~ ${formatTime(c.end_time)}</div>
          </div>
          <div class="countdown" data-start="${c.start_time}">${calcCountdown(c.start_time)}</div>
        </a>
      `;
    }).join("");
  } catch (e) {
    list.innerHTML = "<p style='text-align:center; color:red;'>赛事数据暂时无法加载，请稍后再试</p>";
    console.error(e);
  }
}

// 每秒更新倒计时
setInterval(() => {
  document.querySelectorAll(".countdown").forEach(el => {
    const start = parseInt(el.dataset.start);
    el.textContent = calcCountdown(start);
  });
}, 1000);

load();
</script>
</body>
</html>