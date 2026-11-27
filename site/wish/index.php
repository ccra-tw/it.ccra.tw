<?php
// 資訊站許願池（熊哥 2026-11-27；Planner asQEZH9c）。邏輯在 lib/wish.php。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/itstaff.php';
require '/var/www/it-lib/wish.php';
$id = access_identity();
$email = strtolower($id['email']);
$who = (json_decode((string)@file_get_contents(STATE_DIR . '/dept_map.json'), true) ?: [])[$email] ?? ['name' => '', 'dept' => ''];
$staff = is_it_staff($email);
$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
$csrf = hash_hmac('sha256', 'wish' . $email . date('Y-m-d'), $secret);
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$d = wish_db();
$err = ''; $form = ['title' => '', 'body' => ''];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    if (strlen($secret) < 32 || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $err = '頁面已過期，請重新整理後再送出。';
    else try {
        if ($do === 'add') {
            $form = ['title' => (string)($_POST['title'] ?? ''), 'body' => (string)($_POST['body'] ?? '')];
            $new = wish_add($d, $email, $who['name'], $who['dept'], $form['title'], $form['body']);
            header('Location: ./?ok=add#w' . $new, true, 303); exit;
        } elseif ($do === 'vote') {
            $wid = (int)($_POST['id'] ?? 0); wish_toggle_vote($d, $wid, $email);
            header('Location: ./' . (isset($_GET['s']) ? '?s=' . urlencode((string)$_GET['s']) : '') . '#w' . $wid, true, 303); exit;
        } elseif ($do === 'reply') {
            if (!$staff) throw new RuntimeException('只有資訊部可以回覆狀態');
            $wid = (int)($_POST['id'] ?? 0);
            wish_reply($d, $wid, $email, (string)($_POST['status'] ?? ''), (string)($_POST['reply'] ?? ''), (string)($_POST['planner'] ?? ''));
            header('Location: ./?ok=reply#w' . $wid, true, 303); exit;
        }
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}
$filter = isset($_GET['s'], WISH_STATUS[$_GET['s']]) ? (string)$_GET['s'] : '';
$list = wish_list($d, $email, $filter);
$counts = $d->query('SELECT status, count(*) FROM wish GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>許願池｜CCRA 資訊服務</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32.png">
<link rel="apple-touch-icon" href="/img/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#109a4b">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Noto+Sans+TC:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<link rel="stylesheet" href="/assets/form.css?v=dev">
<script src="/assets/whoami.js?v=dev" defer></script>
<script src="/assets/footer.js?v=dev" defer></script>
<style>
.wl{list-style:none;padding:0;margin:16px 0 0;display:grid;gap:12px}
.wi{border:1px solid var(--line,#ddd);border-radius:12px;padding:14px 16px;background:var(--card,#fff)}
.wi h3{margin:0 0 4px;font-size:1.05rem;overflow-wrap:anywhere}
.wi .meta{color:var(--ink-2,#666);font-size:.85rem}
.wi .body{white-space:pre-wrap;margin:8px 0 0;overflow-wrap:anywhere}
.wi .rep{margin-top:10px;padding:10px 12px;border-radius:8px;background:var(--soft,#f2f7f3);white-space:pre-wrap;overflow-wrap:anywhere}
.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin-top:10px}
.chip{display:inline-block;padding:2px 10px;border-radius:99px;font-size:.85rem;background:var(--soft,#eef3ef)}
.vote{border:1px solid var(--line,#ccc);background:transparent;border-radius:99px;padding:6px 14px;cursor:pointer;font:inherit}
.vote.on{background:#109a4b;color:#fff;border-color:#109a4b}
.tabs{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
.tabs a{padding:4px 12px;border-radius:99px;border:1px solid var(--line,#ccc);text-decoration:none;color:inherit;font-size:.9rem}
.tabs a.on{background:#109a4b;color:#fff;border-color:#109a4b}
details.adm{margin-top:10px} details.adm summary{cursor:pointer;color:var(--ink-2,#666);font-size:.9rem}
details.adm select,details.adm input,details.adm textarea{width:100%;margin-top:6px}
</style>
</head>
<body>
<header class="bar"><div class="wrap">
  <a href="/" style="display:flex;align-items:center;gap:12px;text-decoration:none"><img class="mark" src="/img/logo.png" alt="CCRA 資訊服務首頁">
  <img class="word word-light" src="/img/textlogo_black.png" alt="中華基督教救助協會">
  <img class="word word-dark" src="/img/textlogo_white.png" alt="中華基督教救助協會"></a>
  <span class="sep" aria-hidden="true"></span>
  <div class="title">許願池<small>Wish Pool</small></div>
</div></header>
<main class="wrap">
  <div class="hero">
    <div class="pic">🌠</div>
    <div><h1>希望資訊部幫你做什麼？</h1><p>想要的工具、覺得麻煩的流程、希望電腦或系統可以更好用的地方，都可以寫下來。別人也想要就按「我也要」，資訊部會回覆處理狀態。</p></div>
  </div>
<?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
<?php if (($_GET['ok'] ?? '') === 'add'): ?><p class="hint">收到你的願望了！資訊部看過會在下面回覆。</p><?php endif; ?>
  <form method="post" action="./">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="add">
    <div class="box">
      <p class="step"><b>✨</b>許一個願</p>
      <label class="q" for="title">想要什麼（一句話）</label>
      <input type="text" id="title" name="title" maxlength="<?= WISH_TITLE_MAX ?>" required value="<?= h($form['title']) ?>" placeholder="例：希望報修單送出後能收到處理進度通知">
      <label class="q" for="body">說明（選填：現在怎麼做、哪裡不方便、希望變成怎樣）</label>
      <textarea id="body" name="body" maxlength="<?= WISH_BODY_MAX ?>" rows="4"><?= h($form['body']) ?></textarea>
      <p class="hint">許願內容協會同工都看得到，請不要寫捐款人、個案的姓名或個資。</p>
      <button class="go" type="submit">許願 →</button>
    </div>
  </form>

  <nav class="tabs" aria-label="依狀態篩選">
    <a href="./"<?= $filter === '' ? ' class="on"' : '' ?>>全部 <?= array_sum($counts) ?></a>
<?php foreach (WISH_STATUS as $k => [$label, $icon]): ?>
    <a href="./?s=<?= h($k) ?>"<?= $filter === $k ? ' class="on"' : '' ?>><?= $icon ?> <?= h($label) ?> <?= (int)($counts[$k] ?? 0) ?></a>
<?php endforeach; ?>
  </nav>

<?php if (!$list): ?>
  <p class="hint" style="margin-top:16px">還沒有願望，來許第一個吧！</p>
<?php endif; ?>
  <ul class="wl">
<?php foreach ($list as $w): [$sl, $si] = WISH_STATUS[$w['status']] ?? [$w['status'], '']; ?>
    <li class="wi" id="w<?= (int)$w['id'] ?>">
      <h3><?= h($w['title']) ?></h3>
      <div class="meta"><?= h(preg_replace('/^\d{3}-/', '', $w['name']) ?: '同工') ?><?= $w['dept'] !== '' ? '・' . h($w['dept']) : '' ?>・<?= h(substr($w['created_at'], 0, 10)) ?></div>
<?php if ($w['body'] !== ''): ?>      <div class="body"><?= h($w['body']) ?></div>
<?php endif; ?>
<?php if ($w['reply'] !== '' || $w['planner'] !== ''): ?>      <div class="rep"><b>資訊部回覆：</b><?= h($w['reply']) ?></div>
<?php endif; ?>
      <div class="row">
        <span class="chip"><?= $si ?> <?= h($sl) ?></span>
        <form method="post" action="./<?= $filter !== '' ? '?s=' . h($filter) : '' ?>" style="margin:0">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="vote"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
          <button class="vote<?= $w['mine'] ? ' on' : '' ?>" type="submit" aria-pressed="<?= $w['mine'] ? 'true' : 'false' ?>">🙋 我也要 <?= (int)$w['votes'] ?></button>
        </form>
      </div>
<?php if ($staff): ?>
      <details class="adm"><summary>資訊部回覆狀態<?= $w['planner'] !== '' ? '（Planner ' . h($w['planner']) . '）' : '' ?></summary>
        <form method="post" action="./">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="reply"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
          <label class="q">狀態</label>
          <select name="status"><?php foreach (WISH_STATUS as $k => [$label]): ?><option value="<?= h($k) ?>"<?= $w['status'] === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
          <label class="q">回覆（同工看得到；暫不處理要寫原因）</label>
          <textarea name="reply" maxlength="<?= WISH_REPLY_MAX ?>" rows="3"><?= h($w['reply']) ?></textarea>
          <label class="q">Planner 票號（選填，只有資訊部看得到）</label>
          <input type="text" name="planner" maxlength="40" value="<?= h($w['planner']) ?>">
          <button class="go" type="submit">儲存</button>
        </form>
      </details>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
</main>
</body>
</html>
