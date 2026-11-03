<?php
// 戰情室：主機操作紀錄（熊哥 2026-11-03：任何主機上的操作都要留紀錄，要能讓別人照著重做或還原，可搜尋、持續累積）。
// 只有資訊部看得到；新增時操作人取登入身分，不能自己填別人的名字。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/itstaff.php';
require '/var/www/it-lib/hostlog.php';
$email = strtolower(access_identity()['email']);
if (!is_it_staff($email)) { http_response_code(403); exit('戰情室只開放給資訊部帳號。'); }
$me = IT_STAFF[$email];
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// CSRF：與戰情室其他表單相同做法（form_secret＋登入信箱＋當天日期的 HMAC）
$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
$csrf = hash_hmac('sha256', 'hostlog' . $email . date('Y-m-d'), $secret);

$err = ''; $form = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $form = $_POST;
    if (strlen($secret) < 32 || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $err = '頁面已過期，請重新整理後再送出。';
    else {
        [$ts, $note] = hostlog_parse_time((string)($_POST['time'] ?? ''));
        $tsNote = trim((string)($_POST['ts_note'] ?? ''));
        if ($ts === null && $note !== null) $err = '時間格式不對；不確定確切時間請把時間欄清空，寫在「時間說明」。';
        else try {
            $id = hostlog_add(['ts' => $ts, 'ts_note' => $tsNote, 'operator' => $me, 'operator_email' => $email, 'created_by' => $email,
                'host' => $_POST['host'] ?? '', 'kind' => $_POST['kind'] ?? '', 'what' => $_POST['what'] ?? '', 'why' => $_POST['why'] ?? '',
                'change' => $_POST['change'] ?? '', 'verify' => $_POST['verify'] ?? '', 'undo' => $_POST['undo'] ?? '', 'ticket' => $_POST['ticket'] ?? '']);
            if (PHP_SAPI !== 'cli') { header('Location: hostlog.php?added=' . $id, true, 303); exit; }
            $_GET['added'] = $id; $form = [];   // 命令列測試不轉址，直接顯示結果
        } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    }
}

$f = [];
foreach (['host', 'from', 'to', 'q', 'operator', 'ticket'] as $k) $f[$k] = trim((string)($_GET[$k] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
[$rows, $total] = hostlog_search($f, $page);
$pages = max(1, (int)ceil($total / HOSTLOG_PER_PAGE));
$hosts = hostlog_hosts();
$qs = fn($p) => h('?' . http_build_query(array_filter($f, fn($v) => $v !== '') + ['p' => $p]));
$fv = fn($k, $d = '') => h($form[$k] ?? $d);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>主機操作紀錄｜戰情室</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<style>
.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:8px 0 14px}
.filters label{display:flex;flex-direction:column;font-size:.85rem;gap:2px}
.filters input,.filters select{padding:5px 6px;max-width:100%}
details.hl{border-top:1px solid color-mix(in srgb,currentColor 15%,transparent);padding:6px 0}
details.hl summary{cursor:pointer;display:flex;flex-wrap:wrap;gap:4px 10px}
details.hl summary .w{flex-basis:100%;opacity:.85;overflow-wrap:anywhere}
.tag{font-size:.8rem;padding:0 6px;border-radius:4px;background:color-mix(in srgb,currentColor 10%,transparent)}
dl.hf{display:grid;grid-template-columns:7em 1fr;gap:4px 10px;margin:6px 0 4px}
dl.hf dt{opacity:.7} dl.hf dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere;font-family:ui-monospace,Consolas,monospace;font-size:.9rem}
form.add{display:grid;gap:8px;max-width:760px}
form.add label{display:flex;flex-direction:column;gap:2px;font-size:.9rem}
form.add textarea{min-height:4.5em;font-family:ui-monospace,Consolas,monospace}
.err{color:#c0392b} .ok{color:#109a4b}
.pager{display:flex;gap:12px;margin:12px 0}
@media (max-width:560px){dl.hf{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="wrap">
  <p><a href="./">← 回戰情室</a></p>
  <h1>主機操作紀錄</h1>
  <p>每一次在主機上的操作都記一筆：做了什麼、為什麼、改了哪些、怎麼驗證、怎麼還原。寫到別人看了能照做、也能照著還原。<b>不寫密碼與金鑰</b>，只寫放在哪裡。</p>
  <?php if (isset($_GET['added'])): ?><p class="ok">已新增一筆（編號 <?= (int)$_GET['added'] ?>）。</p><?php endif; ?>

  <form method="get" class="filters">
    <label>主機<select name="host"><option value="">全部</option><?php foreach ($hosts as $x): ?><option value="<?= h($x) ?>"<?= strcasecmp($x, $f['host']) === 0 ? ' selected' : '' ?>><?= h($x) ?></option><?php endforeach; ?></select></label>
    <label>從<input type="date" name="from" value="<?= h($f['from']) ?>"></label>
    <label>到<input type="date" name="to" value="<?= h($f['to']) ?>"></label>
    <label>關鍵字<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="指令、檔名、設定…"></label>
    <label>操作人<input type="text" name="operator" value="<?= h($f['operator']) ?>" size="8"></label>
    <label>工單<input type="text" name="ticket" value="<?= h($f['ticket']) ?>" size="10"></label>
    <button type="submit">查詢</button> <a href="hostlog.php">清除</a>
  </form>

  <p>符合 <b><?= $total ?></b> 筆<?= $pages > 1 ? '，第 ' . $page . '／' . $pages . ' 頁' : '' ?>（新的在前，點一筆展開全部欄位）</p>
  <?php if (!$rows): ?><p>沒有符合的紀錄。</p><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <details class="hl" id="e<?= (int)$r['id'] ?>">
    <summary><b><?= h($r['ts_local'] ?? '') ?: '時間不明' ?></b><?php if ($r['ts_note'] !== null && $r['ts_note'] !== ''): ?><span><?= h($r['ts_note']) ?></span><?php endif; ?>
      <span class="tag"><?= h($r['host']) ?></span><span class="tag"><?= h($r['kind']) ?></span><span><?= h($r['operator']) ?></span>
      <?php if ($r['ticket'] !== null && $r['ticket'] !== ''): ?><span class="tag">票 <?= h($r['ticket']) ?></span><?php endif; ?>
      <span class="w"><?= h(mb_strimwidth(preg_replace('/\s+/u', ' ', $r['what']), 0, 120, '…', 'UTF-8')) ?></span></summary>
    <dl class="hf">
      <dt>做了什麼</dt><dd><?= h($r['what']) ?></dd>
      <dt>原因</dt><dd><?= h($r['why']) ?></dd>
      <dt>變更（前→後）</dt><dd><?= h($r['change']) ?></dd>
      <dt>驗證</dt><dd><?= h($r['verify']) ?></dd>
      <dt>還原方式</dt><dd><?= h($r['undo']) ?></dd>
      <dt>工單</dt><dd><?= h($r['ticket']) ?></dd>
      <dt>操作人</dt><dd><?= h($r['operator']) ?> <?= h($r['operator_email']) ?></dd>
      <dt>記錄</dt><dd>#<?= (int)$r['id'] ?>　<?= h($r['created_local']) ?>　<?= h($r['created_by']) ?></dd>
    </dl>
  </details>
  <?php endforeach; ?>
  <?php if ($pages > 1): ?><p class="pager">
    <?php if ($page > 1): ?><a href="<?= $qs($page - 1) ?>">← 較新</a><?php endif; ?>
    <?php if ($page < $pages): ?><a href="<?= $qs($page + 1) ?>">較舊 →</a><?php endif; ?>
  </p><?php endif; ?>

  <h2 id="add">新增一筆</h2>
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
  <form method="post" class="add" action="hostlog.php#add">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <p>操作人：<b><?= h($me) ?></b>（依登入帳號）</p>
    <label>主機（例如 ITSrv01、CCEAW2012）<input name="host" required maxlength="64" pattern="[A-Za-z0-9._\-]+" list="hl-hosts" value="<?= $fv('host') ?>"></label>
    <datalist id="hl-hosts"><?php foreach ($hosts as $x): ?><option value="<?= h($x) ?>"><?php endforeach; ?></datalist>
    <label>類型<select name="kind" required><?php foreach (HOSTLOG_KINDS as $k): ?><option<?= ($form['kind'] ?? '') === $k ? ' selected' : '' ?>><?= h($k) ?></option><?php endforeach; ?></select></label>
    <label>操作時間（台北時間；不確定就清空，改寫下一欄）<input type="datetime-local" name="time" value="<?= $fv('time', date('Y-m-d\TH:i')) ?>"></label>
    <label>時間說明（不確定確切時間時填，例如「11/02 晚上」）<input name="ts_note" maxlength="200" value="<?= $fv('ts_note') ?>"></label>
    <label>做了什麼（實際指令、檔案、設定項目）<textarea name="what" required><?= $fv('what') ?></textarea></label>
    <label>原因<textarea name="why"><?= $fv('why') ?></textarea></label>
    <label>變更（改之前 → 改之後）<textarea name="change"><?= $fv('change') ?></textarea></label>
    <label>怎麼驗證<textarea name="verify"><?= $fv('verify') ?></textarea></label>
    <label>怎麼還原<textarea name="undo"><?= $fv('undo') ?></textarea></label>
    <label>工單（Planner／GitHub 編號）<input name="ticket" maxlength="200" value="<?= $fv('ticket') ?>"></label>
    <button type="submit">新增</button>
  </form>
</main>
</body>
</html>
