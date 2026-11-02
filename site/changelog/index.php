<?php
// 版本升級紀錄（熊哥 2026-10-06：「網站要開始加上版本，目前算是1版，版本編號由你控管，小修大修生，升級服務，
// 資訊站最下方要有一個版本升級記錄可以點選去看」）。
// 唯一來源是同目錄的 versions.json（最新的放最前面；項目可以是文字，或 {text, links:[[標籤,網址],…]}）；首頁頁尾的版本號也是讀它，不要另外寫死一份。
// 版本號 X.Y.Z：X 升級服務（新增服務或服務方式改變）／Y 大修（既有服務的功能改版）／Z 小修（錯誤修正、文字、版面）。
require '/var/www/it-lib/access.php';
access_identity();
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$vers = json_decode((string)@file_get_contents(__DIR__ . '/versions.json'), true) ?: [];
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>版本紀錄｜CCRA 資訊服務</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32.png">
<link rel="apple-touch-icon" href="/img/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#109a4b">
<meta name="apple-mobile-web-app-title" content="資訊服務">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Noto+Sans+TC:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<style>
.rule{background:var(--panel);border:1px solid var(--edge);border-radius:16px;padding:14px 18px;margin:22px 0 18px;color:var(--ink-2);font-size:14.5px;line-height:1.8}
.rule b{color:var(--ink)}
.rel{background:var(--panel);border:1px solid var(--edge);border-radius:16px;padding:16px 20px;margin:0 0 14px;box-shadow:var(--glow)}
.rel h2{margin:0;font-size:20px;display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 12px}
.rel h2 code{font:500 20px "IBM Plex Mono",ui-monospace,monospace}
.rel .meta{color:var(--muted);font-size:13.5px;font-weight:400}
.tag{font-size:12.5px;font-weight:700;padding:2px 10px;border-radius:999px;background:color-mix(in srgb,var(--green) 15%,transparent);color:var(--green)}
.tag.test{background:color-mix(in srgb,var(--yellow) 22%,transparent);color:var(--ink-2)}
.rel ul{margin:10px 0 0;padding-left:1.3em;line-height:1.8}
</style>
<script src="/assets/whoami.js?v=dev" defer></script>
<script src="/assets/footer.js?v=dev" defer></script>
</head>
<body>
<header class="bar"><div class="wrap">
  <a href="/" style="display:flex;align-items:center;gap:12px;text-decoration:none"><img class="mark" src="/img/logo.png" alt="CCRA 資訊服務首頁">
  <img class="word word-light" src="/img/textlogo_black.png" alt="中華基督教救助協會">
  <img class="word word-dark" src="/img/textlogo_white.png" alt="中華基督教救助協會"></a>
  <span class="sep" aria-hidden="true"></span>
  <div class="title">版本紀錄<small>Release notes</small></div>
</div></header>
<main class="wrap">
  <p class="rule">版本號依序是 <b>升級服務．大修．小修</b>。
  <b>升級服務</b>：新增一項服務，或服務方式改變；<b>大修</b>：既有服務的功能改版；<b>小修</b>：錯誤修正、文字與版面調整。</p>
<?php foreach ($vers as $v): ?>
  <section class="rel">
    <h2><code>v<?= h($v['version']) ?></code>
      <?php if (!empty($v['kind'])): ?><span class="tag"><?= h($v['kind']) ?></span><?php endif; ?>
      <?php if (!empty($v['note'])): ?><span class="tag test"><?= h($v['note']) ?></span><?php endif; ?>
      <span class="meta"><?= h($v['date']) ?></span></h2>
    <ul><?php foreach ($v['items'] ?? [] as $it): ?><li><?php if (is_array($it)): ?><?= h($it['text'] ?? '') ?>：<?php foreach ($it['links'] ?? [] as $i => [$lab, $url]): ?><?= $i ? '、' : '' ?><a href="<?= h($url) ?>" target="_blank" rel="noopener"><?= h($lab) ?></a><?php endforeach; ?><?php else: ?><?= h($it) ?><?php endif; ?></li><?php endforeach; ?></ul>
  </section>
<?php endforeach; ?>
  <p><a class="btn ghost" href="/">回首頁</a></p>
</main>
</body>
</html>
