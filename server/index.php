<?php
// ========== 配置 ==========
// 你的 GitHub 仓库 Raw 文件地址（必须公开仓库）
define('JSON_URL', 'https://raw.githubusercontent.com/hanyixuanten/OI-contest-fetch/master/contests.json');
define('FINISHED_JSON_URL', 'https://raw.githubusercontent.com/hanyixuanten/OI-contest-fetch/master/contests_all.json');
// 缓存文件路径（与 index.php 同目录，需可写）
define('CACHE_FILE', __DIR__ . '/contests_cache.json');
define('FINISHED_CACHE_FILE', __DIR__ . '/contests_all_cache.json');
// 缓存有效期（秒），这里设 5 分钟
define('CACHE_TTL', 300);

// ========== 缓存逻辑 ==========
function fetch_and_cache($url, $cache_file, $ttl) {
    // 如果缓存存在且未过期，直接返回缓存内容
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $ttl)) {
        return file_get_contents($cache_file);
    }

    // 远程获取 JSON
    $json = false;
    if (ini_get('allow_url_fopen')) {
        $json = @file_get_contents($url);
    } elseif (function_exists('curl_init')) {
        // 备选：使用 cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $json = curl_exec($ch);
        curl_close($ch);
    }

    if ($json === false) {
        // 远程获取失败，若有旧缓存则使用旧缓存（即使过期）
        if (file_exists($cache_file)) {
            return file_get_contents($cache_file);
        }
        return false;
    }

    // 验证是否为有效 JSON
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // JSON 解析失败，使用旧缓存
        if (file_exists($cache_file)) {
            return file_get_contents($cache_file);
        }
        return false;
    }

    // 写入缓存文件
    @file_put_contents($cache_file, $json, LOCK_EX);

    return $json;
}

// 获取赛事 JSON
$contests_json = fetch_and_cache(JSON_URL, CACHE_FILE, CACHE_TTL);
if ($contests_json === false) {
    $contests = [];
    $error_msg = '暂时无法加载赛事数据，请稍后再试。';
} else {
    $contests = json_decode($contests_json, true);
    $error_msg = '';
}

$finished_contests_json = fetch_and_cache(FINISHED_JSON_URL, FINISHED_CACHE_FILE, CACHE_TTL);
if ($finished_contests_json === false) {
  $finished_contests = [];
  $finished_error_msg = '暂时无法加载已结束赛事数据，请稍后再试。';
} else {
  $finished_contests = json_decode($finished_contests_json, true);
  $finished_error_msg = '';
}

// ========== 平台样式映射 ==========
$platform_class = [
    'Codeforces' => 'cf',
    'AtCoder'    => 'atc',
    'USACO'      => 'usaco',
    'UOJ'        => 'uoj'
];

// 辅助函数：Unix 时间戳转中文日期
function format_time($ts) {
    return date('Y-m-d H:i', $ts); // 可根据需要调整时区
}

function platform_class_name($platform, $platform_class) {
  return isset($platform_class[$platform]) ? $platform_class[$platform] : '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>信息竞赛日程</title>
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
  .platform.uoj { background: #c0392b; }
  .info { flex: 1; margin-left: 15px; }
  .title { font-size: 18px; font-weight: 600; color: #2c3e50; }
  .time { font-size: 14px; color: #7f8c8d; margin-top: 5px; }
  .countdown { font-weight: 700; color: #e74c3c; margin-left: auto; min-width: 120px; text-align: right; }
  .status-ended { font-weight: 700; color: #95a5a6; margin-left: auto; min-width: 120px; text-align: right; }
  .section-title { color: #2c3e50; margin: 35px 0 10px; border-bottom: 1px solid #dfe6e9; padding-bottom: 8px; }
  a { text-decoration: none; color: inherit; }
  .error { text-align: center; color: #e74c3c; padding: 20px; }
</style>
</head>
<body>
<div class="container">
  <h1>📅 信息竞赛日程</h1>
  <h2 class="section-title">即将到来的信息竞赛</h2>
  <?php if ($error_msg): ?>
    <div class="error"><?php echo htmlspecialchars($error_msg); ?></div>
  <?php elseif (empty($contests)): ?>
    <p style="text-align:center">暂无即将开始的赛事。</p>
  <?php else: ?>
    <?php foreach ($contests as $c): ?>
      <?php
        $cls = platform_class_name($c['platform'], $platform_class);
        $start_time = format_time($c['start_time']);
        $end_time = format_time($c['end_time']);
        $start_ts = $c['start_time'];
      ?>
      <a href="<?php echo htmlspecialchars($c['url']); ?>" target="_blank" class="card">
        <span class="platform <?php echo $cls; ?>"><?php echo htmlspecialchars($c['platform']); ?></span>
        <div class="info">
          <div class="title"><?php echo htmlspecialchars($c['title']); ?></div>
          <div class="time"><?php echo $start_time; ?> ~ <?php echo $end_time; ?></div>
        </div>
        <div class="countdown" data-start="<?php echo $start_ts; ?>"></div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <h2 class="section-title">已结束的信息竞赛</h2>
  <?php if ($finished_error_msg): ?>
    <div class="error"><?php echo htmlspecialchars($finished_error_msg); ?></div>
  <?php elseif (empty($finished_contests)): ?>
    <p style="text-align:center">暂无最近结束的赛事。</p>
  <?php else: ?>
    <?php foreach (array_reverse($finished_contests) as $c): ?>
      <?php
        $cls = platform_class_name($c['platform'], $platform_class);
        $start_time = format_time($c['start_time']);
        $end_time = format_time($c['end_time']);
      ?>
      <a href="<?php echo htmlspecialchars($c['url']); ?>" target="_blank" class="card">
        <span class="platform <?php echo $cls; ?>"><?php echo htmlspecialchars($c['platform']); ?></span>
        <div class="info">
          <div class="title"><?php echo htmlspecialchars($c['title']); ?></div>
          <div class="time"><?php echo $start_time; ?> ~ <?php echo $end_time; ?></div>
        </div>
        <div class="status-ended">已结束</div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
// 客户端倒计时更新
function calcCountdown(startTs) {
  var diff = startTs * 1000 - Date.now();
  if (diff <= 0) return "进行中";
  var d = Math.floor(diff / 86400000);
  var h = Math.floor((diff % 86400000) / 3600000);
  var m = Math.floor((diff % 3600000) / 60000);
  var s = Math.floor((diff % 60000) / 1000);
  return d + "天 " + h + "时 " + m + "分 " + s + "秒";
}

function updateCountdowns() {
  var els = document.querySelectorAll('.countdown');
  els.forEach(function(el) {
    var start = parseInt(el.getAttribute('data-start'));
    el.textContent = calcCountdown(start);
  });
}
setInterval(updateCountdowns, 1000);
updateCountdowns();
</script>
</body>
</html>