<?php
// 戰情室：軟體與系統使用調查的統計（熊哥 2026-10-16 選「只有資訊部看」）。
// 問卷本身在 /survey/（專屬連結免登入）；這一頁仍走 M365 登入＋資訊部名單。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/itstaff.php';
require '/var/www/it-lib/survey.php';
$email = strtolower(access_identity()['email']);
if (!is_it_staff($email)) { http_response_code(403); exit('戰情室只開放給資訊部帳號。'); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function short($n) { return preg_replace('/^\d{3}-/', '', (string)$n); }
$st = survey_stats();
$answered = count($st['done']);
$max = max(1, $answered);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>使用調查統計｜戰情室</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<style>
.bars{display:grid;gap:6px;margin:8px 0 18px}
.bar-row{display:grid;grid-template-columns:minmax(120px,190px) 1fr 48px;gap:10px;align-items:center}
.bar-row .track{height:14px;border-radius:7px;background:color-mix(in srgb,currentColor 10%,transparent);overflow:hidden}
.bar-row .fill{height:100%;background:var(--green,#109a4b)}
.bar-row b{text-align:right}
details{margin:0 0 4px} details summary{cursor:pointer}
.names{font-size:.9rem;opacity:.8;margin:2px 0 8px 0}
table{width:100%;border-collapse:collapse} td,th{padding:6px 4px;border-top:1px solid color-mix(in srgb,currentColor 15%,transparent);text-align:left;vertical-align:top}
.pre{white-space:pre-wrap}
@media (max-width:560px){.bar-row{grid-template-columns:1fr 48px}.bar-row .track{grid-column:1/-1;order:3}}
</style>
</head>
<body>
<main class="wrap">
  <p><a href="./">← 回戰情室</a></p>
  <h1>軟體與系統使用調查</h1>
  <p>已填 <b><?= $answered ?></b> 人／名單 <b><?= $st['total'] ?></b> 人。百分比以已填人數為分母。</p>

<?php foreach (SURVEY_GROUPS as $g => $items): ?>
  <h2><?= h($g) ?></h2>
  <div class="bars">
<?php foreach ($items as $i): $n = count($st['count'][$i]); ?>
    <details><summary class="bar-row"><span><?= h($i) ?></span><span class="track"><span class="fill" style="width:<?= round($n * 100 / $max) ?>%;display:block"></span></span><b><?= $n ?></b></summary>
      <div class="names"><?= $n ? h(implode('、', array_map('short', $st['count'][$i]))) : '沒有人勾' ?></div></details>
<?php endforeach; ?>
  </div>
<?php endforeach; ?>

  <h2>依職稱（新人到職預設用）</h2>
<?php $byTitle = []; foreach ($st['done'] as $p) $byTitle[$p['title'] !== '' ? $p['title'] : '（未填職稱）'][] = $p; ksort($byTitle); ?>
<?php if (!$byTitle): ?><p>還沒有人填。</p><?php else: ?>
  <table><tr><th>職稱</th><th>人數</th><th>勾選項目（括號內為勾選人數）</th></tr>
<?php foreach ($byTitle as $t => $ps): $c = []; foreach ($ps as $p) foreach ($p['items'] as $i) $c[$i] = ($c[$i] ?? 0) + 1; arsort($c); ?>
    <tr><td><?= h($t) ?></td><td><?= count($ps) ?></td><td><?= h(implode('、', array_map(fn($i, $n) => "$i（{$n}）", array_keys($c), $c))) ?: '—' ?></td></tr>
<?php endforeach; ?>
  </table>
<?php endif; ?>

  <h2>「其他」與備註（原文）</h2>
<?php if (!$st['texts']): ?><p>還沒有人寫。</p><?php else: ?>
  <table><tr><th>同工</th><th>其他軟體／系統</th><th>備註</th></tr>
<?php foreach ($st['texts'] as $t): ?>
    <tr><td><?= h(short($t['name'])) ?><br><small><?= h($t['dept']) ?></small></td><td class="pre"><?= h($t['other']) ?></td><td class="pre"><?= h($t['note']) ?></td></tr>
<?php endforeach; ?>
  </table>
<?php endif; ?>

  <h2>還沒填（<?= count($st['todo']) ?>）</h2>
  <p class="names"><?= $st['todo'] ? h(implode('、', array_map(fn($p) => short($p['name']) . ($p['sent_at'] ? '' : '（未寄信）'), $st['todo']))) : '全部填完了 🎉' ?></p>
</main>
</body>
</html>
