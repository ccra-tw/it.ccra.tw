<?php
// 捐款名單地址整理（網頁版）。繁英姊的 Python 工具原樣執行，網頁只負責上傳、呼叫、打包下載。
// 熊哥 10/19 同意做成網頁（Planner SDCCCf4C、GitHub #17）。
// 🔴 檔案含捐款人個資：每次上傳一個獨立暫存資料夾（不在網站根目錄），處理完不論成敗立刻刪除；
//    紀錄只寫誰、幾個檔、成功與否，不寫檔名以外的內容。
// 開放對象：資訊部名單＋捐款服務中心（依 dept_map.json，由 it_dept_map.py 每小時更新）。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/itstaff.php';
require '/var/www/it-lib/addr.php';
$email = strtolower(access_identity()['email']);
$who = (json_decode((string)@file_get_contents(STATE_DIR . '/dept_map.json'), true) ?: [])[$email] ?? ['name' => '', 'dept' => ''];
if (!is_it_staff($email) && ($who['dept'] ?? '') !== '捐款服務中心') {
    http_response_code(403);
    exit('這個工具只開放給捐款服務中心與資訊部。');
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
$csrf = hash_hmac('sha256', 'addr' . $email . date('Y-m-d'), $secret);
function addr_log($email, $n, $ok, $msg = '') {
    error_log(sprintf('it.ccra.tw addr: %s files=%d ok=%s %s', $email, $n, $ok ? 'yes' : 'no', $msg));
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $err = '頁面已過期，請重新整理後再上傳。';
    $f = $_FILES['files'] ?? null;
    $names = $f ? (array)$f['name'] : [];
    if (!$err && (!$names || $names[0] === '')) $err = '請選擇要整理的 Excel 檔。';
    if (!$err && count($names) > ADDR_MAX_FILES) $err = '一次最多 ' . ADDR_MAX_FILES . ' 個檔案。';
    if (!$err) foreach ($names as $i => $n) {
        if ((int)$f['error'][$i] !== UPLOAD_ERR_OK) { $err = '上傳失敗（檔案可能太大，單檔上限 20MB）。'; break; }
        if (!preg_match('/\.xlsx?$/i', $n)) { $err = '只接受 Excel 檔（.xlsx／.xls）：' . $n; break; }
    }
    if (!$err) {
        $job = addr_new_job();
        if (!$job) { addr_log($email, count($names), false, 'job'); $err = '系統暫時無法處理，請稍後再試；仍不行請找資訊部（分機 181）。'; }
    }
    if (!$err) {
        register_shutdown_function('addr_finish', $job);   // 正常結束、exit、逾時都會跑；被殺時由 addr-sweep 補清
        if (!addr_stage($job['dir'], (array)$f['tmp_name'], $names)) {
            addr_log($email, count($names), false, 'stage');
            $err = '有檔案上傳不完整，這一批沒有處理，請重新上傳。';
        } else {
            $r = addr_run($job['dir']);
            addr_log($email, count($names), $r['ok'], $r['ok'] ? '' : 'rc=' . $r['rc']);
            if (!$r['ok']) $err = $r['err'];
        }
        if (!$err) {
            header('Content-Type: application/zip');
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('地址整理結果_' . date('Ymd_Hi') . '.zip'));
            header('Content-Length: ' . filesize($r['zip']));
            header('Cache-Control: no-store');
            readfile($r['zip']);
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>捐款名單地址整理｜資訊服務</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<style>
.box{max-width:720px;margin:0 auto;padding:16px}
.note{font-size:.95em;line-height:1.7}
.err{background:#fde8e8;color:#9b1c1c;padding:10px 12px;border-radius:8px;margin:12px 0}
input[type=file]{display:block;margin:12px 0}
button{padding:10px 20px;font-size:1em}
</style>
</head>
<body>
<div class="box">
<h1>捐款名單地址整理</h1>
<p class="note">上傳含「收據號碼」或「收據編號」欄的 Excel（一次最多 <?= ADDR_MAX_FILES ?> 個、單檔 20MB），系統會補上郵遞區號、縣市區、整理後地址與收據判斷，下載回來是一個壓縮檔：每個檔一份「_地址整理.xlsx」，外加「00_處理摘要與待確認清單.xlsx」。</p>
<p class="note">🔒 上傳的檔案只在整理當下暫存，下載完成即刪除；萬一處理中斷，主機最多 30 分鐘內也會自動清除。</p>
<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='整理中，請稍候…'">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="file" name="files[]" accept=".xlsx,.xls" multiple required>
    <button type="submit">整理並下載</button>
</form>
<p class="note">整理規則沿用捐款服務組現行的工具（行政區補判、門牌中文數字、不需收據關鍵字等）；發現判錯請告訴資訊部。</p>
</div>
</body>
</html>
