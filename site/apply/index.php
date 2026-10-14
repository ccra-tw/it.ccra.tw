<?php
// 資訊服務申請（v2.0 單一入口；規格 GitHub #7）。先選類型、再依類型顯示欄位；送出後走同一套流程
//（知會主管 → 主任核准或資訊部受理 → Planner 工單 → 完成通知）。這台不寄信，通知由資訊部本機維運腳本處理。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/svc_apply.php';
$id = access_identity();
$email = strtolower($id['email']);
$who = (json_decode((string)@file_get_contents(STATE_DIR . '/dept_map.json'), true) ?: [])[$email] ?? ['name' => '', 'dept' => ''];

$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
if ($secret === '') { $secret = bin2hex(random_bytes(32)); file_put_contents(STATE_DIR . '/form_secret', $secret); }
$csrf = hash_hmac('sha256', 'apply' . $email . date('Y-m-d'), $secret);
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$TYPES = svc_types();
$v = ['type' => isset($TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : (count($TYPES) === 1 ? array_key_first($TYPES) : ''),
      'name' => $who['name'], 'purpose' => '', 'wanted_by' => '', 'f' => []];
$err = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach (['type', 'name', 'purpose', 'wanted_by'] as $k) $v[$k] = (string)($_POST[$k] ?? '');
    $v['f'] = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) $err[] = '頁面已過期，請重新整理後再送出。';
    else {
        [$err, $rid] = svc_submit(['email' => $email, 'name' => $v['name'], 'dept' => $who['dept']], $v);
        if (!$err) { header('Location: ./?sent=' . (int)$rid, true, 303); exit; }
    }
}
$sent = null;
if (isset($_GET['sent'])) {
    $s = svc_db()->prepare('SELECT * FROM req WHERE id = ? AND applicant_email = ?');
    $s->execute([(int)$_GET['sent'], $email]);
    $sent = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>資訊服務申請｜CCRA 資訊服務</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32.png">
<link rel="apple-touch-icon" href="/img/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#109a4b">
<meta name="apple-mobile-web-app-title" content="資訊服務">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Noto+Sans+TC:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<link rel="stylesheet" href="/assets/form.css?v=dev">
<script src="/assets/whoami.js?v=dev" defer></script>
<script src="/assets/footer.js?v=dev" defer></script>
<style>.errs{margin:12px 0 0;padding-left:20px}</style>
</head>
<body>
<header class="bar"><div class="wrap">
  <a href="/" style="display:flex;align-items:center;gap:12px;text-decoration:none"><img class="mark" src="/img/logo.png" alt="CCRA 資訊服務首頁">
  <img class="word word-light" src="/img/textlogo_black.png" alt="中華基督教救助協會">
  <img class="word word-dark" src="/img/textlogo_white.png" alt="中華基督教救助協會"></a>
  <span class="sep" aria-hidden="true"></span>
  <div class="title">資訊服務申請<small>IT Service Request</small></div>
</div></header>
<main class="wrap">
  <div class="hero">
    <div class="pic">📝</div>
    <div><h1><?= h($who['name'] !== '' ? preg_replace('/^\d{3}-/', '', $who['name']) . '，' : '') ?>平安！需要什麼資訊服務？</h1>
      <p>帳號、權限、網域、防火牆等申請都在這裡。東西壞了請改用<a href="/repair/">資訊報修</a>。</p></div>
  </div>
<?php if ($sent): ?>
  <div class="box yay">
    <div class="big">✅</div>
    <h2>收到你的申請了！</h2>
    <p>申請編號 <b><?= h(svc_no($sent)) ?></b>（<?= h($TYPES[$sent['type']]['name'] ?? $sent['type']) ?>），目前狀態：<?= h(SVC_STATUS[$sent['status']] ?? $sent['status']) ?>。</p>
    <p class="hint"><?= $sent['status'] === 'review' ? '主任核准後資訊部會開始處理；' : '資訊部受理後會開始處理；' ?>確認信與完成通知會寄到你的協會信箱。</p>
    <p><a class="btn ghost" href="/">回首頁</a></p>
  </div>
<?php else: ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <div class="box">
      <p class="step"><b>1</b>申請內容</p>
      <?php if ($err): ?><ul class="err errs"><?php foreach ($err as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <label class="q" for="type">申請類型</label>
      <select id="type" name="type" required>
        <?php if (count($TYPES) > 1): ?><option value="">請選擇…</option><?php endif; ?>
        <?php foreach ($TYPES as $k => $t): ?><option value="<?= h($k) ?>"<?= $v['type'] === $k ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
      </select>
      <?php foreach ($TYPES as $k => $t): if (!$t['fields']) continue; ?>
      <div class="tf" data-type="<?= h($k) ?>"<?= $v['type'] === $k ? '' : ' hidden' ?>>
        <?php foreach ($t['fields'] as $f): $fid = 'f_' . $k . '_' . $f['key']; $fv = $v['type'] === $k ? (string)($v['f'][$f['key']] ?? '') : ''; ?>
        <label class="q" for="<?= h($fid) ?>"><?= h($f['label']) ?><?= empty($f['required']) ? '（選填）' : '' ?></label>
        <?php if ($f['type'] === 'date'): ?>
          <input type="date" id="<?= h($fid) ?>" name="f[<?= h($f['key']) ?>]" min="<?= date('Y-m-d') ?>" value="<?= h($fv) ?>"<?= !empty($f['required']) ? ' required' : '' ?><?= $v['type'] === $k ? '' : ' disabled' ?>>
        <?php else: ?>
          <input type="text" id="<?= h($fid) ?>" name="f[<?= h($f['key']) ?>]" maxlength="<?= (int)($f['max'] ?? 2000) ?>" value="<?= h($fv) ?>"<?= !empty($f['required']) ? ' required' : '' ?><?= $v['type'] === $k ? '' : ' disabled' ?>>
        <?php endif; ?>
        <?php if (!empty($f['hint'])): ?><p class="hint" style="margin:4px 0 0"><?= h($f['hint']) ?></p><?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <?php $dirTypes = array_keys(array_filter($TYPES, fn($t) => $t['director'])); ?>
      <p class="hint" id="dir-note" style="margin:8px 0 0"<?= in_array($v['type'], $dirTypes, true) ? '' : ' hidden' ?>>這類申請需要資訊部主任核准，核准後資訊部會開始處理。</p>
      <label class="q" for="name">姓名</label>
      <input type="text" id="name" name="name" maxlength="50" required value="<?= h($v['name']) ?>">
      <label class="q">登入帳號</label>
      <p style="margin:0;color:var(--ink-2)"><?= h($email) ?></p>
      <label class="q" for="purpose">用途說明</label>
      <textarea id="purpose" name="purpose" maxlength="2000" required placeholder="需要什麼、做什麼用"><?= h($v['purpose']) ?></textarea>
      <label class="q" for="wanted_by">希望完成日（選填）</label>
      <input type="date" id="wanted_by" name="wanted_by" min="<?= date('Y-m-d') ?>" value="<?= h($v['wanted_by']) ?>">
      <button class="go" type="submit">送出申請 →</button>
    </div>
  </form>
<?php endif; ?>
</main>
<script>
// 依類型顯示該類型的欄位；隱藏的欄位停用，不會被送出或擋住必填
(function () {
  var sel = document.getElementById('type'); if (!sel) return;
  var dir = <?= json_encode($dirTypes ?? []) ?>;
  function sync() {
    document.querySelectorAll('.tf').forEach(function (b) {
      var on = b.dataset.type === sel.value; b.hidden = !on;
      b.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !on; });
    });
    var n = document.getElementById('dir-note'); if (n) n.hidden = dir.indexOf(sel.value) < 0;
  }
  sel.addEventListener('change', sync); sync();
})();
</script>
</body>
</html>
