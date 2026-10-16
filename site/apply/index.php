<?php
// 資訊服務申請（v2.0 單一入口；規格 GitHub #7）。先選類型、再依類型顯示欄位；送出後走同一套流程
//（知會主管 → 主任核准或資訊部受理 → Planner 工單 → 完成通知）。這台不寄信，通知由資訊部本機維運腳本處理。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/svc_apply.php';
require_once '/var/www/it-lib/itstaff.php';
$id = access_identity();
$email = strtolower($id['email']);
$who = (json_decode((string)@file_get_contents(STATE_DIR . '/dept_map.json'), true) ?: [])[$email] ?? ['name' => '', 'dept' => ''];

$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
if ($secret === '') { $secret = bin2hex(random_bytes(32)); file_put_contents(STATE_DIR . '/form_secret', $secret); }
$csrf = hash_hmac('sha256', 'apply' . $email . date('Y-m-d'), $secret);
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$TYPES = svc_types();
$v = ['type' => isset($TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : (count($TYPES) === 1 ? array_key_first($TYPES) : ''),
      'name' => $who['name'], 'purpose' => '', 'wanted_by' => '', 'f' => [],
      'for' => 'self', 'target_name' => '', 'target_dept' => '', 'target_start' => ''];
$DEPTS = require '/var/www/it-lib/depts.php';
$err = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach (['type', 'name', 'purpose', 'wanted_by', 'for', 'target_name', 'target_dept', 'target_start'] as $k) $v[$k] = (string)($_POST[$k] ?? '');
    $v['f'] = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    // 測試單（#15，熊哥 10-14 選 A）：只有資訊部能送；通知只寄送單人、不知會主管與主任、不開 Planner、不動真實名單
    $v['is_test'] = is_it_staff($email) && !empty($_POST['is_test']);
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
      <p>要帳號、要開權限、要用新工具，都在這裡申請。電腦或系統<b>壞掉</b>了，請改用<a href="/repair/">資訊報修</a>。</p></div>
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
      <?php
      // 熊哥 2026-10-15：「申請內容太雜一般人看不懂，需要分類讓使用者好申請」——改成分類卡片＋一句白話說明；送出的 type 值不變
      $CATS = [
        ['👤', '我需要一個帳號', ['email' => '需要一個其他用途的信箱，例如活動、專案或部門共用（新同工的信箱由人事通知資訊部開，不用申請）', 'google' => '要用 Google 的東西，例如看網站有多少人來、管 YouTube 頻道']],
        ['🤖', '我想用 AI 幫忙', ['ai' => '想用 Claude 幫我寫東西、整理資料']],
        ['📁', '我打不開某個檔案或系統', ['folder' => '有一個資料夾我打不開，或打得開卻不能改', 'sysperm' => '工作用的系統（例如 1919 系統）我進不去，或少了某個功能', 'vpn' => '在家或出差時，想用協會裡的電腦或檔案']],
        ['🌐', '網站和網址', ['shortlink' => '想要一個很短、好記的網址，例如 ccra.tw/活動名稱', 'dns' => '廠商說要「設定網址」或「改 DNS」——把他們給你的內容貼過來就好']],
        ['🛠️', '工程師才會用到（看不懂就跳過）', ['github' => '工程師要看協會的程式', 'firewall' => '工程師要開一條網路連線']],
        ['💬', '找不到？選這個', ['other' => '用自己的話寫下需要什麼，資訊部會幫你看']],
      ];
      // 保險：新增了類型卻忘了放進分類時，自動列在「其他」，不會從頁面上消失
      $listed = array_merge(...array_map(fn($c) => array_keys($c[2]), $CATS));
      foreach (array_diff(array_keys($TYPES), $listed) as $k) $CATS[count($CATS) - 1][2] = [$k => $TYPES[$k]['name']] + $CATS[count($CATS) - 1][2];
      $SHOW = ['email' => '其他用途信箱', 'google' => 'Google 服務', 'ai' => '用 AI（Claude）', 'folder' => '資料夾打不開', 'sysperm' => '系統進不去', 'vpn' => '在外面連回協會（VPN）',
               'shortlink' => '短網址', 'dns' => '設定網址（DNS）', 'github' => 'GitHub', 'firewall' => '防火牆', 'other' => '其他'];
      ?>
      <p class="q">你想做什麼？點一下最像的那一個。不確定的話，選最下面的「找不到？」就好。</p>
      <?php foreach ($CATS as [$icon, $title, $items]): $items = array_intersect_key($items, $TYPES); if (!$items) continue; ?>
      <fieldset class="cat"><legend><?= $icon ?> <?= h($title) ?></legend>
        <?php foreach ($items as $k => $desc): ?>
        <label class="tcard"><input type="radio" name="type" value="<?= h($k) ?>"<?= $v['type'] === $k ? ' checked' : '' ?> required>
          <span><b><?= h($SHOW[$k] ?? $TYPES[$k]['name']) ?></b><small><?= h($desc) ?></small></span></label>
        <?php endforeach; ?>
      </fieldset>
      <?php endforeach; ?>
      <style>.cat{border:0;margin:14px 0 0;padding:0}.cat legend{font-weight:800;margin-bottom:6px}
      .cat{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}
      .tcard{position:relative;display:block;cursor:pointer}.tcard input{position:absolute;opacity:0;inset:0}
      .tcard span{display:flex;flex-direction:column;gap:2px;height:100%;padding:12px 14px;border-radius:14px;border:2px solid var(--edge);background:var(--cream);transition:transform .15s}
      .tcard span:hover{transform:translateY(-2px)}.tcard small{color:var(--ink-2);font-size:14px;line-height:1.5}
      .tcard input:checked+span{border-color:var(--teal);background:var(--mist)}.tcard input:focus-visible+span{outline:3px solid var(--focus);outline-offset:2px}</style>
      <?php foreach ($TYPES as $k => $t): if (!$t['fields']) continue; ?>
      <div class="tf" data-type="<?= h($k) ?>"<?= $v['type'] === $k ? '' : ' hidden' ?>>
        <?php foreach ($t['fields'] as $f): $fid = 'f_' . $k . '_' . $f['key']; $fv = $v['type'] === $k ? (string)($v['f'][$f['key']] ?? '') : ''; ?>
        <label class="q" for="<?= h($fid) ?>"><?= h($f['label']) ?><?= empty($f['required']) ? '（選填）' : '' ?></label>
        <?php if ($f['type'] === 'date'): ?>
          <input type="date" id="<?= h($fid) ?>" name="f[<?= h($f['key']) ?>]"<?= empty($f['past_ok']) ? ' min="' . date('Y-m-d') . '"' : '' ?> value="<?= h($fv) ?>"<?= !empty($f['required']) ? ' required' : '' ?><?= $v['type'] === $k ? '' : ' disabled' ?>>
        <?php elseif ($f['type'] === 'textarea'): ?>
          <textarea id="<?= h($fid) ?>" name="f[<?= h($f['key']) ?>]" maxlength="<?= (int)($f['max'] ?? 2000) ?>"<?= !empty($f['required']) ? ' required' : '' ?><?= $v['type'] === $k ? '' : ' disabled' ?>><?= h($fv) ?></textarea>
        <?php elseif ($f['type'] === 'select'): ?>
          <select id="<?= h($fid) ?>" name="f[<?= h($f['key']) ?>]"<?= !empty($f['required']) ? ' required' : '' ?><?= $v['type'] === $k ? '' : ' disabled' ?>>
            <option value="">請選擇…</option>
            <?php foreach ($f['options'] as $op): ?><option<?= $fv === $op ? ' selected' : '' ?>><?= h($op) ?></option><?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" id="<?= h($fid) ?>" name="f[<?= h($f['key']) ?>]" maxlength="<?= (int)($f['max'] ?? 2000) ?>" value="<?= h($fv) ?>"<?= !empty($f['required']) ? ' required' : '' ?><?= $v['type'] === $k ? '' : ' disabled' ?>>
        <?php endif; ?>
        <?php if (!empty($f['hint'])): ?><p class="hint" style="margin:4px 0 0"><?= h($f['hint']) ?></p><?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <?php $dirTypes = array_keys(array_filter($TYPES, fn($t) => $t['director'])); ?>
      <p class="hint" id="dir-note" style="margin:8px 0 0"<?= in_array($v['type'], $dirTypes, true) ? '' : ' hidden' ?>>這一項要主任同意，主任同意後資訊部就會開始做。</p>
      <label class="q" for="name">姓名</label>
      <input type="text" id="name" name="name" maxlength="50" required value="<?= h($v['name']) ?>">
      <label class="q">登入帳號</label>
      <p style="margin:0;color:var(--ink-2)"><?= h($email) ?></p>
      <label class="q">是幫誰申請？</label>
      <div class="pills">
        <label class="pill"><input type="radio" name="for" value="self"<?= $v['for'] !== 'other' ? ' checked' : '' ?>><span>我自己</span></label>
        <label class="pill"><input type="radio" name="for" value="other"<?= $v['for'] === 'other' ? ' checked' : '' ?>><span>幫別人申請</span></label>
      </div>
      <div id="target"<?= $v['for'] === 'other' ? '' : ' hidden' ?>>
        <label class="q" for="target_name">申請對象姓名</label>
        <input type="text" id="target_name" name="target_name" maxlength="50" value="<?= h($v['target_name']) ?>">
        <label class="q" for="target_dept">申請對象部門</label>
        <select id="target_dept" name="target_dept"><option value="">請選擇…</option>
          <?php foreach ($DEPTS as $dp): ?><option<?= $v['target_dept'] === $dp ? ' selected' : '' ?>><?= h($dp) ?></option><?php endforeach; ?></select>
        <p class="hint" style="margin:4px 0 0">會同時知會申請對象所屬部門的主管。</p>
      </div>
      <label class="q" for="purpose">你需要什麼？要拿來做什麼？</label>
      <textarea id="purpose" name="purpose" maxlength="2000" required placeholder="用自己的話寫就好，例如：年底路跑活動需要一個信箱收報名信"><?= h($v['purpose']) ?></textarea>
      <label class="q" for="wanted_by">希望什麼時候好？（可以不填）</label>
      <input type="date" id="wanted_by" name="wanted_by" min="<?= date('Y-m-d') ?>" value="<?= h($v['wanted_by']) ?>">
      <?php if (is_it_staff($email)): ?><label class="hint" style="display:block;margin:10px 0 0"><input type="checkbox" name="is_test" value="1"<?= !empty($v['is_test']) ? ' checked' : '' ?>> 測試單（資訊部專用：通知只寄給自己，不知會主管與主任、不開 Planner 工單）</label><?php endif; ?>
      <button class="go" type="submit">送出申請 →</button>
    </div>
  </form>
<?php endif; ?>
</main>
<script>
// 依類型顯示該類型的欄位；隱藏的欄位停用，不會被送出或擋住必填
(function () {
  var radios = document.querySelectorAll('input[name=type]'); if (!radios.length) return;
  var dir = <?= json_encode($dirTypes ?? []) ?>;
  function cur() { var c = document.querySelector('input[name=type]:checked'); return c ? c.value : ''; }
  function sync() {
    var t = cur();
    document.querySelectorAll('.tf').forEach(function (b) {
      var on = b.dataset.type === t; b.hidden = !on;
      b.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !on; });
    });
    var n = document.getElementById('dir-note'); if (n) n.hidden = dir.indexOf(t) < 0;
  }
  radios.forEach(function (r) { r.addEventListener('change', function () {
    sync();
    var box = document.querySelector('.tf:not([hidden])') || document.getElementById('name');   // 選好後帶到要填的地方（手機上分類很長）
    if (box && box.scrollIntoView) box.scrollIntoView({behavior: 'smooth', block: 'center'});
  }); });
  sync();
  var tg = document.getElementById('target');
  function syncFor() {
    var other = document.querySelector('input[name=for]:checked').value === 'other';
    tg.hidden = !other;
    ['target_name', 'target_dept'].forEach(function (id) { document.getElementById(id).required = other; });
  }
  document.querySelectorAll('input[name=for]').forEach(function (r) { r.addEventListener('change', syncFor); }); syncFor();
})();
</script>
</body>
</html>
