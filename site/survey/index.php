<?php
// 應用軟體與專案系統使用調查（公開頁，不經 M365 登入）。
// 身分只認網址上的專屬識別碼 ?t=…（每位同工一封信、一條連結）；識別碼不對就什麼都不顯示。
// 這一頁在 Cloudflare Access 放行範圍內，所以不能引用 /assets/、/img/（那些仍要登入），樣式與標誌都放在本目錄。
require '/var/www/it-lib/survey.php';
const STATE = '/var/lib/it-ccra';
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');   // 識別碼在網址上，不要帶到別的網站

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$p = survey_person($token);
$secret = trim((string)@file_get_contents(STATE . '/form_secret'));
$csrf = $p ? hash_hmac('sha256', 'survey' . $p['token'], $secret) : '';
$err = '';
if ($p && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $err = '頁面已過期，請重新整理後再送出。';
    else {
        survey_save($p['email'], (array)($_POST['items'] ?? []), (string)($_POST['other'] ?? ''), (string)($_POST['note'] ?? ''));
        header('Location: ./?t=' . rawurlencode($p['token']) . '&sent=1', true, 303);
        exit;
    }
}
$a = $p ? survey_answer($p['email']) : null;
$checked = $a['items'] ?? [];
$name = $p ? preg_replace('/^\d{3}-/', '', (string)$p['name']) : '';
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>軟體與系統使用調查｜CCRA 資訊部</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;700;900&display=swap" rel="stylesheet">
<style>
:root{--bg:#f4f7f5;--card:#fff;--ink:#1d2a22;--sub:#5b6b61;--line:#dbe4de;--brand:#109a4b;--brand-ink:#fff;--soft:#e8f5ed;--warn:#b42318}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#0f1512;--card:#17201b;--ink:#e6eee9;--sub:#9fb0a6;--line:#2a3830;--brand:#2fbf6b;--brand-ink:#08130d;--soft:#1c2c23;--warn:#ff8a80}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.7 "Noto Sans TC",system-ui,sans-serif}
.wrap{max-width:720px;margin:0 auto;padding:0 16px}
header{background:var(--card);border-bottom:1px solid var(--line)}
header .wrap{display:flex;align-items:center;gap:12px;padding-top:12px;padding-bottom:12px}
header img{width:40px;height:40px}
header b{font-weight:900}
h1{font-size:1.5rem;margin:28px 0 4px;font-weight:900}
.lead{color:var(--sub);margin:0 0 20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px 18px 8px;margin:0 0 16px}
.card h2{font-size:1.05rem;margin:0 0 8px}
label.it{display:flex;gap:12px;align-items:flex-start;padding:10px 4px;border-top:1px solid var(--line);cursor:pointer}
label.it:first-of-type{border-top:0}
label.it input{width:22px;height:22px;margin-top:3px;accent-color:var(--brand);flex:none}
label.it small{display:block;color:var(--sub);font-size:.85rem}
textarea{width:100%;min-height:90px;border:1px solid var(--line);border-radius:10px;padding:10px;font:inherit;background:var(--bg);color:var(--ink)}
.q{font-weight:700;margin:0 0 6px}
.hint{color:var(--sub);font-size:.9rem;margin:0 0 10px}
button{width:100%;padding:14px;border:0;border-radius:12px;background:var(--brand);color:var(--brand-ink);font:700 1.05rem "Noto Sans TC",sans-serif;cursor:pointer;margin:4px 0 32px}
.ok{background:var(--soft);border-color:var(--brand)}
.err{color:var(--warn);font-weight:700}
footer{color:var(--sub);font-size:.85rem;text-align:center;padding:0 0 32px}
</style>
</head>
<body>
<header><div class="wrap"><img src="logo.png?v=2" alt=""><b>中華基督教救助協會｜資訊部</b></div></header>
<main class="wrap">
<?php if (!$p): ?>
  <h1>找不到這份問卷</h1>
  <p class="lead">請從資訊部寄給你的信裡，直接點那條連結開啟（每個人的連結都不一樣）。<br>有問題請找資訊部，分機 181。</p>
<?php else: ?>
  <h1><?= h($name) ?>，平安！</h1>
  <p class="lead">資訊部想知道大家工作上用到哪些軟體和系統，好幫你準備帳號和權限。勾選你<b>實際有在用</b>的就好，沒用的留空，大約兩分鐘。<?= $a ? '<br>你已經填過了，改完再按送出就會更新。' : '' ?></p>
<?php if (isset($_GET['sent'])): ?>
  <div class="card ok"><h2>✅ 收到了，謝謝你！</h2><p>之後想改，再點同一條連結進來修改就好。</p></div>
<?php endif; ?>
<?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
  <form method="post" action="./?t=<?= h(rawurlencode($p['token'])) ?>">
    <input type="hidden" name="t" value="<?= h($p['token']) ?>">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<?php foreach (SURVEY_GROUPS as $g => $items): ?>
    <div class="card"><h2><?= h($g) ?></h2>
<?php foreach ($items as $i): ?>
      <label class="it"><input type="checkbox" name="items[]" value="<?= h($i) ?>"<?= in_array($i, $checked, true) ? ' checked' : '' ?>>
        <span><?= h($i) ?><?php if (isset(SURVEY_HINTS[$i])): ?><small><?= h(SURVEY_HINTS[$i]) ?></small><?php endif; ?></span></label>
<?php endforeach; ?>
    </div>
<?php endforeach; ?>
    <div class="card">
      <p class="q">上面沒有列到，但你工作上會用的軟體或系統</p>
      <p class="hint">一行寫一個，例如：Excel 巨集、某個網站後台、某個 App</p>
      <textarea name="other" maxlength="1000"><?= h($a['other'] ?? '') ?></textarea>
      <p class="q" style="margin-top:14px">備註</p>
      <p class="hint">例如：需要的 NAS 資料夾、帳號權限等級、其他想讓資訊部知道的事</p>
      <textarea name="note" maxlength="1000"><?= h($a['note'] ?? '') ?></textarea>
      <p></p>
    </div>
    <button type="submit"><?= $a ? '更新我的回覆' : '送出' ?></button>
  </form>
<?php endif; ?>
</main>
<footer>CCRA 資訊部｜這份問卷只有資訊部看得到</footer>
</body>
</html>
