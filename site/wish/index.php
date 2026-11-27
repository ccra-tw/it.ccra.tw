<?php
// 資訊站許願池（熊哥 2026-11-27；Planner asQEZH9c）。邏輯在 lib/wish.php。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/itstaff.php';
require '/var/www/it-lib/wish.php';
$id = access_identity();
$email = strtolower($id['email']);
$who = (json_decode((string)@file_get_contents(STATE_DIR . '/dept_map.json'), true) ?: [])[$email] ?? ['name' => '', 'dept' => ''];
$staff = is_it_staff($email);
// 審查預覽（伺服器設 ITCCRA_DB_SUFFIX=_web、用測試資料）可加 ?role=staff 或 ?role=user 切換角色給檢查人測；正式站不理會這個參數。
$role = itdb_web_test() ? (string)($_GET['role'] ?? '') : '';
if ($role === 'staff') $staff = true; elseif ($role === 'user') $staff = false; else $role = '';
function wish_url(array $q = []): string { global $role; if ($role !== '') $q['role'] = $role; return './' . ($q ? '?' . http_build_query($q) : ''); }
$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
$csrf = hash_hmac('sha256', 'wish' . $email . date('Y-m-d'), $secret);
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$err = ''; $form = ['title' => '', 'body' => ''];
// 目前的檢視（狀態篩選、關鍵字、頁碼），投票後導回同一個檢視
$filter = isset($_GET['s'], WISH_STATUS[$_GET['s']]) ? (string)$_GET['s'] : '';
$q = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_GET['q'] ?? ''))), 0, WISH_Q_MAX);
$page = max(1, (int)($_GET['p'] ?? 1));
function view_q(array $extra = []): array { global $filter, $q, $page; return array_filter(['s' => $filter, 'q' => $q, 'p' => $page > 1 ? $page : 0] , fn($v) => $v !== '' && $v !== 0) + $extra; }
$rf = null;   // 資訊部回覆失敗時保留該筆的送出值，表單維持展開、錯誤放在表單旁（大沈 11/27 第二輪）
const WISH_TRY_AGAIN = '系統暫時出了點問題，剛才的動作沒有存進去，請稍後再試一次；一直不行請告訴資訊部。';
function wish_fail_log(Throwable $e): void { error_log('wish: ' . get_class($e) . ': ' . $e->getMessage()); }
try {
    $d = wish_db();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'reply') $rf = ['id' => (int)($_POST['id'] ?? 0), 'status' => (string)($_POST['status'] ?? ''),
                                   'reply' => (string)($_POST['reply'] ?? ''), 'planner' => (string)($_POST['planner'] ?? ''), 'err' => ''];
        if ($do === 'add') $form = ['title' => (string)($_POST['title'] ?? ''), 'body' => (string)($_POST['body'] ?? '')];
        $msg = '';
        if (strlen($secret) < 32 || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $msg = '頁面已過期，請重新整理後再送出。';
        else try {
            if ($do === 'add') {
                $new = wish_add($d, $email, $who['name'], $who['dept'], $form['title'], $form['body']);
                wish_redirect(wish_url(['ok' => 'add']) . '#w' . $new);
            } elseif ($do === 'vote') {
                $wid = (int)($_POST['id'] ?? 0); $on = wish_toggle_vote($d, $wid, $email);
                // 票數變動會改變排序：導到該筆現在所在的頁（不在目前篩選結果裡就留在原頁），並顯示成功訊息
                // 查頁碼失敗時留在原頁、仍顯示成功（票已存，不能讓人重按變成取消）
                $pg = wish_vote_page($d, $wid, $filter, $q, $page); $view = array_diff_key(view_q(), ['p' => 1]);
                if ($pg > 1) $view['p'] = $pg;
                wish_redirect(wish_url($view + ['ok' => $on ? 'vote' : 'unvote']) . '#w' . $wid);
            } elseif ($do === 'reply') {
                if (!$staff) throw new WishInput('只有資訊部可以回覆狀態');
                wish_reply($d, $rf['id'], $email, $rf['status'], $rf['reply'], $rf['planner']);
                wish_redirect(wish_url(['ok' => 'reply']) . '#w' . $rf['id']);
            }
        } catch (WishInput $e) { $msg = $e->getMessage(); }
        catch (Throwable $e) { wish_fail_log($e); $msg = WISH_TRY_AGAIN; }   // 交易已回滾，重送不會多一筆
        if ($msg !== '') { if ($rf && $staff) $rf['err'] = $msg; else $err = $msg; }
    }
    $total = wish_count($d, $filter, $q);
    $pages = max(1, (int)ceil($total / WISH_PER_PAGE)); if ($page > $pages) $page = $pages;
    $list = wish_list($d, $email, $filter, $q, WISH_PER_PAGE, ($page - 1) * WISH_PER_PAGE);
    $counts = wish_status_counts($d, $q);   // 有關鍵字時是「關鍵字下」各狀態筆數（大沈 11/27）
} catch (Throwable $e) {
    // 資料庫整體不可用（連不上、清單或計數失敗）：穩定的錯誤頁，細節只進伺服器紀錄
    wish_fail_log($e);
    http_response_code(503);
    header('Retry-After: 60');
    echo '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>許願池暫時無法使用｜CCRA 資訊服務</title>'
       . '<link rel="stylesheet" href="/assets/site.css?v=dev"></head><body><main class="wrap" style="padding-block:40px">'
       . '<h1>許願池暫時無法使用</h1><p>系統暫時出了點問題，' . (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? '剛才送出的內容可能沒有存進去，' : '')
       . '請稍後重新整理再試一次；一直不行請告訴資訊部。</p><p><a href="/">回資訊站首頁</a></p></main></body></html>';
    exit;
}
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
.wi{color:var(--ink);border:1px solid var(--edge);border-radius:12px;padding:14px 16px;background:var(--panel)}
.wi h3{margin:0 0 4px;font-size:1.05rem;overflow-wrap:anywhere}
.wi .meta{color:var(--muted);font-size:.85rem}
.wi .body{white-space:pre-wrap;margin:8px 0 0;overflow-wrap:anywhere}
.wi .rep{margin-top:10px;padding:10px 12px;border-radius:8px;background:rgba(16,154,75,.10);white-space:pre-wrap;overflow-wrap:anywhere}
.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin-top:10px}
.chip{display:inline-block;padding:2px 10px;border-radius:99px;font-size:.85rem;background:rgba(16,154,75,.12)}
.vote{border:1px solid var(--edge);background:transparent;color:var(--ink);border-radius:99px;padding:6px 14px;cursor:pointer;font:inherit}
.vote.on{background:#0b6b34;color:#fff;border-color:#0b6b34}
.search{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:18px}.search input{flex:1 1 220px;min-width:0;padding:8px 10px;border:1px solid var(--edge);border-radius:8px;font:inherit;background:var(--panel);color:var(--ink)}.search button{padding:8px 14px;border-radius:8px;border:1px solid #0b6b34;background:#0b6b34;color:#fff;font:inherit;cursor:pointer}
.pager{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;align-items:center;margin:18px 0}
.tabs{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
.tabs a{padding:4px 12px;border-radius:99px;border:1px solid var(--edge);text-decoration:none;color:inherit;font-size:.9rem}
.tabs a.on{background:#0b6b34;color:#fff;border-color:#0b6b34}
details.adm{margin-top:10px} details.adm summary{cursor:pointer;color:var(--muted);font-size:.9rem}
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
<?php if (itdb_web_test()): ?><p class="hint" style="border:2px dashed #c0392b;padding:8px 12px">審查預覽・測試資料（不是正式站）｜目前角色：<?= $staff ? '資訊部' : '一般同工' ?>｜切換：<a href="?role=staff">資訊部</a>・<a href="?role=user">一般同工</a></p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
<?php if (in_array($_GET['ok'] ?? '', ['vote', 'unvote'], true)): ?><p class="hint" role="status"><?= $_GET['ok'] === 'vote' ? '已加上你的「我也要」。' : '已收回你的「我也要」。' ?></p><?php endif; ?>
<?php if (($_GET['ok'] ?? '') === 'add'): ?><p class="hint">收到你的願望了！資訊部看過會在下面回覆。</p><?php endif; ?>
  <form method="post" action="<?= h(wish_url()) ?>">
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

  <form class="search" method="get" action="./" role="search">
<?php if ($role !== ''): ?>    <input type="hidden" name="role" value="<?= h($role) ?>">
<?php endif; ?><?php if ($filter !== ''): ?>    <input type="hidden" name="s" value="<?= h($filter) ?>">
<?php endif; ?>    <input type="search" name="q" value="<?= h($q) ?>" maxlength="<?= WISH_Q_MAX ?>" placeholder="搜尋願望（標題、說明、回覆）" aria-label="搜尋願望">
    <button type="submit">搜尋</button>
<?php if ($q !== ''): ?>    <a href="<?= h(wish_url($filter !== '' ? ['s' => $filter] : [])) ?>">清除</a>
<?php endif; ?>  </form>
  <nav class="tabs" aria-label="依狀態篩選">
    <a href="<?= h(wish_url($q !== '' ? ['q' => $q] : [])) ?>"<?= $filter === '' ? ' class="on"' : '' ?>>全部 <?= array_sum($counts) ?></a>
<?php foreach (WISH_STATUS as $k => [$label, $icon]): ?>
    <a href="<?= h(wish_url(['s' => $k] + ($q !== '' ? ['q' => $q] : []))) ?>"<?= $filter === $k ? ' class="on"' : '' ?>><?= $icon ?> <?= h($label) ?> <?= (int)($counts[$k] ?? 0) ?></a>
<?php endforeach; ?>
  </nav>

<?php if ($q !== ''): ?>
  <p class="hint" style="margin-top:12px">「<?= h($q) ?>」找到 <?= $total ?> 筆<?= $filter !== '' ? '（' . h(WISH_STATUS[$filter][0]) . '）' : '' ?>。<?php if (!$list): ?><a href="<?= h(wish_url()) ?>">看全部</a><?php endif; ?></p>
<?php elseif (!$list && $filter !== ''): ?>
  <p class="hint" style="margin-top:16px">這個狀態目前沒有願望。<a href="<?= h(wish_url()) ?>">看全部</a></p>
<?php elseif (!$list): ?>
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
        <form method="post" action="<?= h(wish_url(view_q())) ?>" style="margin:0">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="vote"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
          <button class="vote<?= $w['mine'] ? ' on' : '' ?>" type="submit" aria-pressed="<?= $w['mine'] ? 'true' : 'false' ?>">🙋 我也要 <?= (int)$w['votes'] ?></button>
        </form>
      </div>
<?php if ($staff): $mine = $rf && $rf['id'] === (int)$w['id'] && $rf['err'] !== '';
        $fs = $mine ? $rf['status'] : $w['status']; $fr = $mine ? $rf['reply'] : $w['reply']; $fp = $mine ? $rf['planner'] : $w['planner']; ?>
      <details class="adm"<?= $mine ? ' open' : '' ?>><summary>資訊部回覆狀態<?= $w['planner'] !== '' ? '（Planner ' . h($w['planner']) . '）' : '' ?></summary>
        <form method="post" action="<?= h(wish_url()) ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="reply"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
<?php if ($mine): ?>          <p class="err" role="alert">沒有存進去：<?= h($rf['err']) ?>（你填的內容還在，改好再按儲存）</p>
<?php endif; ?>
          <label class="q">狀態</label>
          <select name="status"><?php foreach (WISH_STATUS as $k => [$label]): ?><option value="<?= h($k) ?>"<?= $fs === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
          <label class="q">回覆（同工看得到；暫不處理要寫原因）</label>
          <textarea name="reply" maxlength="<?= WISH_REPLY_MAX ?>" rows="3"><?= h($fr) ?></textarea>
          <label class="q">對應的 Planner 工作（選填，只有資訊部看得到）</label>
          <p class="hint" style="margin:2px 0 0">決定要做時，在 Planner 開一張工作，把那張工作的網址貼在這裡，之後就知道是哪張在追。</p>
          <input type="text" name="planner" maxlength="400" value="<?= h($fp) ?>" placeholder="貼 Planner 工作網址，或代碼（例：asQEZH9c）">
          <button class="go" type="submit">儲存</button>
        </form>
      </details>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php if ($pages > 1): ?>
  <nav class="pager" aria-label="分頁">
<?php if ($page > 1): ?>    <a href="<?= h(wish_url(['p' => $page - 1] + array_diff_key(view_q(), ['p' => 1]))) ?>">← 上一頁</a>
<?php endif; ?>    <span>第 <?= $page ?>／<?= $pages ?> 頁，共 <?= $total ?> 筆</span>
<?php if ($page < $pages): ?>    <a href="<?= h(wish_url(['p' => $page + 1] + array_diff_key(view_q(), ['p' => 1]))) ?>">下一頁 →</a>
<?php endif; ?>  </nav>
<?php endif; ?>
</main>
</body>
</html>
