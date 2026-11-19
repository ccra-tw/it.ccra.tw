<?php
// 戰情室次頁：開發案狀態（熊哥 2026-11-20：「戰情室的開發狀態放到次頁去，現在戰情室資料太多」）。
// 內容原本在 /ops/ 首頁（熊哥 10-21：比照 CCNDA 戰情室，GitHub #18），資料來源不變。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/itstaff.php';
require '/var/www/it-lib/devprojects.php';
$email = strtolower(access_identity()['email']);
if (!is_it_staff($email)) { http_response_code(403); exit('戰情室只開放給資訊部帳號。'); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
[$dpData, $dpWarn] = devprojects_data();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>開發案狀態｜戰情室</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Noto+Sans+TC:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<link rel="stylesheet" href="/assets/form.css?v=dev">
<script src="/assets/whoami.js?v=dev" defer></script>
<script src="/assets/footer.js?v=dev" defer></script>
<style>
.sec{display:flex;align-items:baseline;gap:10px;margin:0 0 10px}
.sec h2{margin:0;font-size:18px}
.sec small{color:var(--muted)}
.tag{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:800;background:var(--cream);border:1px solid var(--edge)}
.tag.high{background:color-mix(in srgb,var(--red) 16%,var(--panel));color:var(--red);border-color:transparent}
.tag.mid{background:color-mix(in srgb,var(--yellow) 22%,var(--panel));border-color:transparent}
.tag.new{background:var(--mist);color:var(--teal-d);border-color:transparent}
.tag.doing{background:color-mix(in srgb,var(--yellow) 22%,var(--panel));border-color:transparent}
.tag.done{background:color-mix(in srgb,var(--green) 16%,var(--panel));color:var(--green);border-color:transparent}
.dp-proj{padding:10px 0;border-top:1px solid var(--edge)}.dp-proj:first-of-type{border-top:0}
.dp-head{display:flex;flex-wrap:wrap;gap:6px 10px;align-items:baseline}
.dp-items{list-style:none;margin:6px 0 0;padding:0}.dp-items li{padding:5px 0}
.dp-note{font-size:13px;color:var(--muted,#666);margin:2px 0 0 4px;overflow-wrap:anywhere}
.dp-warn{color:var(--red);font-weight:700}
.tag.dp-dev,.tag.dp-review{background:var(--mist);color:var(--teal-d);border-color:transparent}
.tag.dp-confirm,.tag.dp-waiting,.tag.dp-deploy{background:color-mix(in srgb,var(--yellow) 22%,var(--panel));border-color:transparent}
.tag.dp-blocked{background:color-mix(in srgb,var(--red) 16%,var(--panel));color:var(--red);border-color:transparent}
.tag.dp-done{background:color-mix(in srgb,var(--green) 16%,var(--panel));color:var(--green);border-color:transparent}
.meta{color:var(--muted);font-size:13px;margin-top:3px}
</style>
</head>
<body>
<main class="wrap">
  <p><a href="./">← 回戰情室</a></p>
  <div class="box" id="devprojects">
    <div class="sec"><h2>🧩 開發案狀態</h2><small><?= $dpData ? '更新於 ' . h(substr((string)($dpData['updated_at'] ?? ''), 11, 5)) . '・每小時更新' : '' ?>・項目＝未結的 GitHub issue 與對應 Planner 票</small></div>
    <?= devprojects_render($dpData, $dpWarn) ?>
  </div>
</main>
</body>
</html>
