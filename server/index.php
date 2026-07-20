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

// ========== 本地化逻辑 ==========
$translations = [
  'zh' => [
    'html_lang' => 'zh-CN',
    'page_title' => '信息竞赛日程',
    'heading' => '信息竞赛日程',
    'upcoming_title' => '即将到来的信息竞赛',
    'finished_title' => '已结束的信息竞赛',
    'load_error' => '暂时无法加载赛事数据，请稍后再试。',
    'finished_load_error' => '暂时无法加载已结束赛事数据，请稍后再试。',
    'empty_upcoming' => '暂无即将开始的赛事。',
    'empty_finished' => '暂无最近结束的赛事。',
    'ended' => '已结束',
    'running' => '进行中',
    'timezone_label' => '时区',
    'last_updated_label' => '最后更新时间',
    'day' => '天',
    'hour' => '时',
    'minute' => '分',
    'second' => '秒'
  ],
  'en' => [
    'html_lang' => 'en',
    'page_title' => 'OI Contest Schedule',
    'heading' => 'OI Contest Schedule',
    'upcoming_title' => 'Upcoming Contests',
    'finished_title' => 'Finished Contests',
    'load_error' => 'Contest data is temporarily unavailable. Please try again later.',
    'finished_load_error' => 'Finished contest data is temporarily unavailable. Please try again later.',
    'empty_upcoming' => 'No upcoming contests.',
    'empty_finished' => 'No recently finished contests.',
    'ended' => 'Ended',
    'running' => 'Running',
    'timezone_label' => 'Time zone',
    'last_updated_label' => 'Last updated',
    'day' => 'd',
    'hour' => 'h',
    'minute' => 'm',
    'second' => 's'
  ]
];

function normalize_language($lang) {
  $lang = strtolower(trim($lang));
  if (strpos($lang, 'zh') === 0) {
    return 'zh';
  }
  if (strpos($lang, 'en') === 0) {
    return 'en';
  }
  return 'en';
}

function detect_language($translations) {
  $lang = '';
  if (isset($_GET['lang'])) {
    $lang = $_GET['lang'];
  } elseif (isset($_COOKIE['oi_lang'])) {
    $lang = $_COOKIE['oi_lang'];
  } elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $accepted_languages = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
    $lang = $accepted_languages[0];
  }

  return normalize_language($lang);
}

function detect_timezone() {
  if (isset($_COOKIE['oi_timezone']) && in_array($_COOKIE['oi_timezone'], timezone_identifiers_list(), true)) {
    return $_COOKIE['oi_timezone'];
  }
  return date_default_timezone_get();
}

$current_lang = detect_language($translations);
$t = $translations[$current_lang];
$current_timezone = detect_timezone();
$timezone = new DateTimeZone($current_timezone);

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

function parse_contest_payload($json) {
  $data = json_decode($json, true);
  if (!is_array($data)) {
    return false;
  }

  if (isset($data['contests']) && is_array($data['contests'])) {
    return [
      'generated_at' => isset($data['generated_at']) ? (int)$data['generated_at'] : 0,
      'contests' => $data['contests']
    ];
  }

  return [
    'generated_at' => 0,
    'contests' => $data
  ];
}

// 获取赛事 JSON
$contests_json = fetch_and_cache(JSON_URL, CACHE_FILE, CACHE_TTL);
if ($contests_json === false) {
    $contests = [];
    $contests_generated_at = 0;
    $error_msg = $t['load_error'];
} else {
    $contests_payload = parse_contest_payload($contests_json);
    if ($contests_payload === false) {
        $contests = [];
        $contests_generated_at = 0;
        $error_msg = $t['load_error'];
    } else {
        $contests = $contests_payload['contests'];
        $contests_generated_at = $contests_payload['generated_at'];
        $error_msg = '';
    }
}

$finished_contests_json = fetch_and_cache(FINISHED_JSON_URL, FINISHED_CACHE_FILE, CACHE_TTL);
if ($finished_contests_json === false) {
  $finished_contests = [];
  $finished_contests_generated_at = 0;
  $finished_error_msg = $t['finished_load_error'];
} else {
  $finished_contests_payload = parse_contest_payload($finished_contests_json);
  if ($finished_contests_payload === false) {
    $finished_contests = [];
    $finished_contests_generated_at = 0;
    $finished_error_msg = $t['finished_load_error'];
  } else {
    $finished_contests = $finished_contests_payload['contests'];
    $finished_contests_generated_at = $finished_contests_payload['generated_at'];
    $finished_error_msg = '';
  }
}

$last_generated_at = max($contests_generated_at, $finished_contests_generated_at);

// ========== 平台样式映射 ==========
$platform_class = [
    'Codeforces' => 'cf',
    'AtCoder'    => 'atc',
    'UOJ'        => 'uoj',
    'Nowcoder'   => 'nowcoder'
];

function h($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// 辅助函数：Unix 时间戳按当前时区格式化
function format_time($ts, $timezone, $include_seconds=false) {
  $date = new DateTime('@' . $ts);
  $date->setTimezone($timezone);
  return $date->format($include_seconds ? 'Y-m-d H:i:s' : 'Y-m-d H:i');
}

function platform_class_name($platform, $platform_class) {
  return isset($platform_class[$platform]) ? $platform_class[$platform] : '';
}

function contest_value($contest, $key, $default) {
  return is_array($contest) && isset($contest[$key]) ? $contest[$key] : $default;
}
?>
<!DOCTYPE html>
<html lang="<?php echo h($t['html_lang']); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($t['page_title']); ?></title>
<style>
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f5f7fa; margin: 0; padding: 20px; }
  .container { max-width: 900px; margin: 0 auto; }
  h1 { text-align: center; color: #2c3e50; }
  .meta { text-align: center; color: #7f8c8d; font-size: 13px; margin-top: -8px; }
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
  .platform.uoj { background: #c0392b; }
  .platform.nowcoder { background: #00a6a6; }
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
  <h1>📅 <?php echo h($t['heading']); ?></h1>
  <div class="meta"><?php echo h($t['timezone_label']); ?>: <span id="timezone-label"><?php echo h($current_timezone); ?></span></div>
  <?php if ($last_generated_at > 0): ?>
    <div class="meta"><?php echo h($t['last_updated_label']); ?>: <span id="last-updated" data-generated-at="<?php echo $last_generated_at; ?>"><?php echo h(format_time($last_generated_at, $timezone, true)); ?></span></div>
  <?php endif; ?>
  <h2 class="section-title"><?php echo h($t['upcoming_title']); ?></h2>
  <?php if ($error_msg): ?>
    <div class="error"><?php echo h($error_msg); ?></div>
  <?php elseif (empty($contests)): ?>
    <p style="text-align:center"><?php echo h($t['empty_upcoming']); ?></p>
  <?php else: ?>
    <?php foreach ($contests as $c): ?>
      <?php
        $platform = contest_value($c, 'platform', '');
        $title = contest_value($c, 'title', '');
        $url = contest_value($c, 'url', '#');
        $start_ts = (int)contest_value($c, 'start_time', 0);
        $end_ts = (int)contest_value($c, 'end_time', 0);
        $cls = platform_class_name($platform, $platform_class);
        $start_time = format_time($start_ts, $timezone);
        $end_time = format_time($end_ts, $timezone);
      ?>
      <a href="<?php echo h($url); ?>" target="_blank" class="card">
        <span class="platform <?php echo h($cls); ?>"><?php echo h($platform); ?></span>
        <div class="info">
          <div class="title"><?php echo h($title); ?></div>
          <div class="time" data-start="<?php echo $start_ts; ?>" data-end="<?php echo $end_ts; ?>"><?php echo h($start_time); ?> ~ <?php echo h($end_time); ?></div>
        </div>
        <div class="countdown" data-start="<?php echo $start_ts; ?>"></div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <h2 class="section-title"><?php echo h($t['finished_title']); ?></h2>
  <?php if ($finished_error_msg): ?>
    <div class="error"><?php echo h($finished_error_msg); ?></div>
  <?php elseif (empty($finished_contests)): ?>
    <p style="text-align:center"><?php echo h($t['empty_finished']); ?></p>
  <?php else: ?>
    <?php foreach (array_reverse($finished_contests) as $c): ?>
      <?php
        $platform = contest_value($c, 'platform', '');
        $title = contest_value($c, 'title', '');
        $url = contest_value($c, 'url', '#');
        $start_ts = (int)contest_value($c, 'start_time', 0);
        $end_ts = (int)contest_value($c, 'end_time', 0);
        $cls = platform_class_name($platform, $platform_class);
        $start_time = format_time($start_ts, $timezone);
        $end_time = format_time($end_ts, $timezone);
      ?>
      <a href="<?php echo h($url); ?>" target="_blank" class="card">
        <span class="platform <?php echo h($cls); ?>"><?php echo h($platform); ?></span>
        <div class="info">
          <div class="title"><?php echo h($title); ?></div>
          <div class="time" data-start="<?php echo $start_ts; ?>" data-end="<?php echo $end_ts; ?>"><?php echo h($start_time); ?> ~ <?php echo h($end_time); ?></div>
        </div>
        <div class="status-ended"><?php echo h($t['ended']); ?></div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
var translations = <?php echo json_encode($t, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
var serverLanguage = <?php echo json_encode($current_lang); ?>;
var serverTimezone = <?php echo json_encode($current_timezone); ?>;
var generatedAt = <?php echo json_encode($last_generated_at); ?>;

function cookieValue(name) {
  var parts = document.cookie ? document.cookie.split('; ') : [];
  for (var i = 0; i < parts.length; i++) {
    var pair = parts[i].split('=');
    if (decodeURIComponent(pair[0]) === name) {
      return decodeURIComponent(pair.slice(1).join('='));
    }
  }
  return '';
}

function setCookie(name, value) {
  document.cookie = encodeURIComponent(name) + '=' + encodeURIComponent(value) + '; max-age=31536000; path=/; samesite=lax';
}

function normalizeLanguage(language) {
  language = (language || '').toLowerCase();
  if (language.indexOf('zh') === 0) return 'zh';
  if (language.indexOf('en') === 0) return 'en';
  return 'en';
}

function syncSystemLocale() {
  if (window.sessionStorage && sessionStorage.getItem('oi_locale_sync_attempted') === '1') {
    return;
  }

  var systemLanguage = normalizeLanguage(navigator.language || (navigator.languages && navigator.languages[0]) || serverLanguage);
  var systemTimezone = serverTimezone;
  if (window.Intl && Intl.DateTimeFormat) {
    systemTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || serverTimezone;
  }

  var changed = false;
  if (cookieValue('oi_lang') !== systemLanguage) {
    setCookie('oi_lang', systemLanguage);
    changed = true;
  }
  if (systemTimezone && cookieValue('oi_timezone') !== systemTimezone) {
    setCookie('oi_timezone', systemTimezone);
    changed = true;
  }

  if ((systemLanguage !== serverLanguage || systemTimezone !== serverTimezone) && changed) {
    if (window.sessionStorage) {
      sessionStorage.setItem('oi_locale_sync_attempted', '1');
    }
    window.location.reload();
  }
}

function formatDateTime(ts) {
  if (window.Intl && Intl.DateTimeFormat) {
    return new Intl.DateTimeFormat(serverLanguage === 'en' ? 'en-US' : 'zh-CN', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
      timeZone: serverTimezone
    }).format(new Date(ts * 1000));
  }
  return '';
}

function formatDateTimeWithSeconds(ts) {
  if (window.Intl && Intl.DateTimeFormat) {
    return new Intl.DateTimeFormat(serverLanguage === 'en' ? 'en-US' : 'zh-CN', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
      timeZone: serverTimezone
    }).format(new Date(ts * 1000));
  }
  return '';
}

function updateTimes() {
  var timezoneLabel = document.getElementById('timezone-label');
  if (timezoneLabel) timezoneLabel.textContent = serverTimezone;

  var lastUpdated = document.getElementById('last-updated');
  if (lastUpdated) {
    var generatedAtTs = parseInt(lastUpdated.getAttribute('data-generated-at') || generatedAt, 10);
    var generatedAtText = formatDateTimeWithSeconds(generatedAtTs);
    if (generatedAtText) {
      lastUpdated.textContent = generatedAtText;
    }
  }

  var els = document.querySelectorAll('.time');
  els.forEach(function(el) {
    var start = parseInt(el.getAttribute('data-start'), 10);
    var end = parseInt(el.getAttribute('data-end'), 10);
    var startText = formatDateTime(start);
    var endText = formatDateTime(end);
    if (startText && endText) {
      el.textContent = startText + ' ~ ' + endText;
    }
  });
}

function calcCountdown(startTs) {
  var diff = startTs * 1000 - Date.now();
  if (diff <= 0) return translations.running;
  var d = Math.floor(diff / 86400000);
  var h = Math.floor((diff % 86400000) / 3600000);
  var m = Math.floor((diff % 3600000) / 60000);
  var s = Math.floor((diff % 60000) / 1000);
  if (serverLanguage === 'en') {
    return d + translations.day + ' ' + h + translations.hour + ' ' + m + translations.minute + ' ' + s + translations.second;
  }
  return d + translations.day + ' ' + h + translations.hour + ' ' + m + translations.minute + ' ' + s + translations.second;
}

function updateCountdowns() {
  var els = document.querySelectorAll('.countdown');
  els.forEach(function(el) {
    var start = parseInt(el.getAttribute('data-start'));
    el.textContent = calcCountdown(start);
  });
}
syncSystemLocale();
updateTimes();
setInterval(updateCountdowns, 1000);
updateCountdowns();
</script>
</body>
</html>