<?php
// 物資捐贈收據系統（GitHub #21；業務細節見 Planner 票 pnZtsan4）。
// 使用者：開收據者（edit）可建批次、匯入、配號、列印、改資料；客服與助理會計（view）只能查詢與看收據。
// 🔴 捐物人姓名、電話、地址只存資料庫；紀錄檔只寫動作與筆數，不寫任何個資。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/receipt.php';
require '/var/www/it-lib/addr.php';
$email = strtolower(access_identity()['email']);
$d = rcpt_db();
$role = rcpt_role($d, $email);
if (!$role) { http_response_code(403); exit('物資捐贈收據系統只開放給開收據的同仁、客服與助理會計。需要使用請找資訊部（分機 181）。'); }
$canEdit = $role === 'edit';
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
if (strlen($secret) < 16) { http_response_code(503); exit('系統設定異常，請通知資訊部（分機 181）。'); }
$csrf = hash_hmac('sha256', 'receipt' . $email . date('Y-m-d'), $secret);
$a = $_GET['a'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$msg = ''; $err = '';

function batch_get(PDO $d, int $id): ?array {
    $s = $d->prepare('SELECT * FROM batch WHERE id = ?'); $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function need_edit($canEdit) { if (!$canEdit) { http_response_code(403); exit('您的權限只能查詢。'); } }

// ── POST 動作 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { $err = '頁面已過期，請重新整理後再操作。'; }
    else {
        need_edit($canEdit);
        $do = $_POST['do'] ?? '';
        try {
            if ($do === 'save_batch') {
                $src = $_POST['source'] ?? '';
                if (!isset(RCPT_SOURCES[$src])) throw new RuntimeException('請選來源');
                $date = rcpt_roc_to_date($_POST['in_date'] ?? '');
                if (!$date) throw new RuntimeException('收貨日期格式不對（例：115/09/14）');
                $po = trim($_POST['po_no'] ?? '') ?: null;
                $f = [$src, RCPT_SOURCES[$src], $po, $date, trim($_POST['supplier_code'] ?? ''), trim($_POST['supplier_name'] ?? ''),
                      ($_POST['set_price'] ?? '') === '' ? null : (int)$_POST['set_price'], trim($_POST['handler'] ?? ''),
                      trim($_POST['contents'] ?? ''), trim($_POST['letter'] ?? '')];
                $d->beginTransaction();
                if ($id) {
                    $s0 = $d->prepare('SELECT * FROM batch WHERE id = ? FOR UPDATE'); $s0->execute([$id]);   // 與配號共用列鎖，持鎖後依當下狀態決定可改哪些欄位
                    $b = $s0->fetch(PDO::FETCH_ASSOC);
                    if (!$b) throw new RuntimeException('找不到批次');
                    if ($b['status'] !== 'draft') {   // 已配號：只能補進貨單號與經手人、信件等不影響編號的欄位
                        $d->prepare('UPDATE batch SET po_no = ?, supplier_code = ?, supplier_name = ?, handler = ?, contents = ?, letter = ?, updated_at = now() WHERE id = ?')
                          ->execute([$po, $f[4], $f[5], $f[7], $f[8], $f[9], $id]);
                    } else {
                        $d->prepare('UPDATE batch SET source=?, track=?, po_no=?, in_date=?, supplier_code=?, supplier_name=?, set_price=?, handler=?, contents=?, letter=?, updated_at=now() WHERE id=?')
                          ->execute(array_merge($f, [$id]));
                    }
                    rcpt_audit($d, $email, 'edit_batch', $id);
                } else {
                    $d->prepare('INSERT INTO batch (source, track, po_no, in_date, supplier_code, supplier_name, set_price, handler, contents, letter, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                      ->execute(array_merge($f, [$email]));
                    $id = (int)$d->lastInsertId();
                    rcpt_audit($d, $email, 'new_batch', $id);
                }
                // 產品明細：每行「代號,品名,數量,單位,單價」（只在同一交易、持鎖下且仍是草稿時改）
                $b = $id && isset($b) ? $b : ['status' => 'draft'];
                if ($b['status'] === 'draft') {
                    $d->prepare('DELETE FROM batch_item WHERE batch_id = ?')->execute([$id]);
                    $ins = $d->prepare('INSERT INTO batch_item (batch_id, code, name, qty, unit, price) VALUES (?,?,?,?,?,?)');
                    foreach (preg_split('/\R/u', (string)($_POST['items'] ?? '')) as $line) {
                        $p = array_map('trim', preg_split('/[,，\t]/u', $line));
                        if (count($p) < 3 || $p[1] === '') continue;
                        $ins->execute([$id, $p[0], $p[1], (float)str_replace(',', '', $p[2]), $p[3] ?? '', isset($p[4]) && $p[4] !== '' ? (float)$p[4] : null]);
                    }
                }
                $d->commit();
                header("Location: ./?a=batch&id=$id&ok=saved"); exit;
            }
            if ($do === 'import') {
                $b = batch_get($d, $id);
                if (!$b || $b['status'] !== 'draft') throw new RuntimeException('只有尚未配號的批次可以匯入');
                $f = $_FILES['file'] ?? null;
                if (!$f || (int)$f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('上傳失敗（單檔上限 20MB）');
                if (!preg_match('/\.xlsx$/i', $f['name'])) throw new RuntimeException('請上傳 .xlsx（.xls 請先用 Excel 另存成 .xlsx）');
                $job = addr_new_job();
                if (!$job) throw new RuntimeException('系統暫時無法處理，請稍後再試');
                register_shutdown_function('addr_finish', $job);
                $src = $job['dir'] . '/data/01_list.xlsx';
                $mv = (PHP_SAPI === 'cli' && getenv('ITCCRA_TEST_DB') === '1') ? 'copy' : 'move_uploaded_file';   // 測試接縫：命令列測試沒有真的上傳
                if (!$mv($f['tmp_name'], $src)) throw new RuntimeException('上傳不完整，請重新上傳');
                $read = function (string $p) {
                    $out = shell_exec('python3 ' . escapeshellarg('/var/www/it-lib/receipt_read.py') . ' ' . escapeshellarg($p) . ' 2>/dev/null');
                    $j = json_decode((string)$out, true);
                    if (!is_array($j)) throw new RuntimeException('讀不到這個 Excel（檔案可能損壞或有密碼）');
                    return $j;
                };
                $j = $read($src);
                $tidy = isset($j['mapping']['zip'], $j['mapping']['address']);
                if (!$tidy) {           // 還沒整理過地址：跑捐款服務組的地址整理程式
                    $r = addr_run($job['dir']);
                    if (!$r['ok']) throw new RuntimeException('地址整理失敗：' . $r['err']);
                    $rep = glob($job['dir'] . '/report/*' . ADDR_REPORT_SUFFIX);
                    if (!$rep) throw new RuntimeException('地址整理沒有產出結果');
                    $j = $read($rep[0]);
                }
                if ($j['missing']) throw new RuntimeException('對不到必要欄位：' . implode('、', $j['missing']) . '（實際欄名：' . implode('、', array_filter($j['columns'])) . '）');
                if (!$j['rows']) throw new RuntimeException('檔案裡沒有資料列');
                // 測試接縫（大沈：匯入整理完暫停→另一請求配號→恢復匯入）：只在命令列＋測試 schema 生效
                if (PHP_SAPI === 'cli' && getenv('ITCCRA_TEST_DB') === '1' && ($ps = (int)getenv('ITCCRA_TEST_IMPORT_PAUSE')) > 0) sleep(min($ps, 30));
                $d->beginTransaction();
                rcpt_lock_draft($d, $id);   // 整理檔案期間別人可能已配號：持鎖後再核對仍是草稿才刪除重建
                $d->prepare('DELETE FROM donation WHERE batch_id = ?')->execute([$id]);
                $ins = $d->prepare('INSERT INTO donation (batch_id, row_no, order_no, name, mobile, phone, raw_addr, zip, city, address, product, qty, send, check_note, dup_note, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                foreach ($j['rows'] as $i => $r) {
                    $ins->execute([$id, $i + 1, $r['order_no'], $r['name'], $r['mobile'], $r['phone'], $r['raw_addr'], $r['zip'], $r['city'], $r['address'],
                                   $r['product'], (int)($r['qty'] ?? 0), ($r['send'] ?? true) ? 'true' : 'false', $r['check_note'], $r['dup_note'], $email]);
                }
                rcpt_audit($d, $email, 'import', $id, count($j['rows']) . ' 筆' . ($tidy ? '（已整理檔）' : '（經地址整理）'));
                $d->commit();
                header("Location: ./?a=batch&id=$id&ok=imported"); exit;
            }
            if ($do === 'row') {           // 改單筆：草稿時檢核用；配號後也可改（業務單位 11/12：原本不要收據、後來來電索取），收據編號不變、留改前改後
                // 大沈 R-itsu-1112-01 複驗 P1：讀舊值、更新、稽核在同一交易；鎖順序同配號（先批次、再明細），稽核失敗整筆回滾
                $d->beginTransaction();
                $sb = $d->prepare('SELECT status FROM batch WHERE id = ? FOR UPDATE'); $sb->execute([$id]);
                $b = $sb->fetch(PDO::FETCH_ASSOC);
                if (!$b) throw new RuntimeException('找不到批次');
                $rid = (int)$_POST['rid'];
                $s0 = $d->prepare('SELECT name, zip, city, address, send, mailed_on FROM donation WHERE id = ? AND batch_id = ? FOR UPDATE'); $s0->execute([$rid, $id]);
                $old = $s0->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new RuntimeException('找不到這一筆');
                $mail = trim($_POST['mailed_on'] ?? '');
                $mailDate = $mail === '' ? null : rcpt_roc_to_date($mail);
                if ($mail !== '' && !$mailDate) throw new RuntimeException('寄出日期格式不對（例：115/11/12）');
                $newv = ['name' => trim($_POST['name'] ?? $old['name']), 'zip' => trim($_POST['zip'] ?? ''), 'city' => trim($_POST['city'] ?? ''),
                         'address' => trim($_POST['address'] ?? ''), 'send' => isset($_POST['send']), 'mailed_on' => $mailDate];
                if ($newv['name'] === '') throw new RuntimeException('收件人不能空白');
                $d->prepare('UPDATE donation SET name = ?, send = ?, zip = ?, city = ?, address = ?, mailed_on = ?, updated_by = ?, updated_at = now() WHERE id = ? AND batch_id = ?')
                  ->execute([$newv['name'], $newv['send'] ? 'true' : 'false', $newv['zip'], $newv['city'], $newv['address'], $newv['mailed_on'], $email, $rid, $id]);
                $chg = [];
                foreach ($newv as $k => $v) {
                    $o = $old[$k];
                    $os = $k === 'send' ? ($o ? 'Y' : 'N') : (string)$o; $vs = $k === 'send' ? ($v ? 'Y' : 'N') : (string)$v;
                    if ($os !== $vs) $chg[] = "$k: $os → $vs";
                }
                rcpt_audit($d, $email, $b['status'] === 'draft' ? 'edit_row' : 'edit_after_number', $id, "row $rid " . implode('；', $chg));
                $d->commit();
                header("Location: ./?a=batch&id=$id&ok=row#r$rid"); exit;
            }
            if ($do === 'mailed') {        // 整份標寄出日期（列印頁送來這份的 id）
                $date = rcpt_roc_to_date($_POST['mailed_on'] ?? '');
                if (!$date) throw new RuntimeException('寄出日期格式不對（例：115/11/12）');
                $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))));
                if (!$ids) throw new RuntimeException('沒有要標記的收據');
                $in = implode(',', array_fill(0, count($ids), '?'));
                $d->beginTransaction();   // 更新與稽核同一交易（大沈複驗：整份標寄出日期也要一起）
                $d->prepare('SELECT id FROM batch WHERE id = ? FOR UPDATE')->execute([$id]);
                $u = $d->prepare("UPDATE donation SET mailed_on = ?, updated_by = ?, updated_at = now() WHERE batch_id = ? AND id IN ($in)");
                $u->execute(array_merge([$date, $email, $id], $ids));
                rcpt_audit($d, $email, 'mailed', $id, $u->rowCount() . " 張 $date");
                $d->commit();
                header("Location: ./?a=batch&id=$id&ok=mailed"); exit;
            }
            if ($do === 'assign') {
                $r = rcpt_assign($d, $id, $email);
                header("Location: ./?a=batch&id=$id&ok=assigned"); exit;
            }
        } catch (Throwable $e) {
            if ($d->inTransaction()) $d->rollBack();
            if ($e instanceof PDOException) {
                $ref = rcpt_log_db_error($e, $do);
                $err = str_contains($e->getMessage(), 'batch_po_no_key') ? '這個進貨單號已經建過批次了。' : "資料庫錯誤，請通知資訊部（代碼 {$ref}）。";
            } else $err = $e->getMessage();
        }
    }
}
$okMsg = ['saved' => '已儲存。', 'imported' => '已匯入，請檢核下面的清單，確認無誤後再配號。', 'row' => '已更新這一筆。', 'assigned' => '已配號。', 'mailed' => '已標記寄出日期。'][$_GET['ok'] ?? ''] ?? '';
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>物資捐贈收據｜資訊服務</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<style>
.box{max-width:1100px;margin:0 auto;padding:16px}
.err{background:#fde8e8;color:#9b1c1c;padding:10px 12px;border-radius:8px;margin:12px 0}
.ok{background:#e6f4ea;color:#14532d;padding:10px 12px;border-radius:8px;margin:12px 0}
table{border-collapse:collapse;width:100%;font-size:.92em}
th,td{border:1px solid #ddd;padding:6px 8px;text-align:left;vertical-align:top}
th{background:#f3f4f6}
.n{text-align:right}
.bad{background:#fff1f2}
.dup{background:#fde68a}
nav a{margin-right:14px}
label{display:block;margin:8px 0 2px;font-weight:700}
input[type=text],select,textarea{width:100%;padding:6px;font-size:1em;box-sizing:border-box}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 16px}
button{padding:8px 16px;font-size:1em}
.tag{display:inline-block;padding:1px 8px;border-radius:10px;background:#e5e7eb;font-size:.85em}
</style>
</head>
<body>
<div class="box">
<h1>物資捐贈收據</h1>
<nav><a href="./">批次清單</a><?php if ($canEdit): ?><a href="./?a=new">＋新增批次</a><?php endif; ?><a href="./?a=search">查詢捐物人</a>
<span class="tag"><?= $canEdit ? '開收據' : '唯讀' ?></span></nav>
<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
<?php if ($okMsg): ?><div class="ok"><?= h($okMsg) ?></div><?php endif; ?>

<?php if ($a === 'list'):
    $rows = $d->query("SELECT b.*, (SELECT COUNT(*) FROM donation WHERE batch_id=b.id) n, (SELECT COALESCE(SUM(qty),0) FROM donation WHERE batch_id=b.id) q FROM batch b ORDER BY in_date DESC, id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); ?>
<table><tr><th>收貨日期</th><th>來源</th><th>進貨單號</th><th class="n">筆數</th><th class="n">數量</th><th>收據起訖</th><th>狀態</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= h(rcpt_date_to_roc($r['in_date'])) ?></td><td><?= h($r['source']) ?>（<?= h($r['track']) ?>）</td>
<td><a href="./?a=batch&id=<?= (int)$r['id'] ?>"><?= h($r['po_no'] ?: '（未填）') ?></a></td><td class="n"><?= (int)$r['n'] ?></td><td class="n"><?= (int)$r['q'] ?></td>
<td><?= $r['first_no'] ? h(rcpt_range_text($r['first_no'], $r['last_no'])) : '' ?></td><td><?= $r['status'] === 'draft' ? '草稿' : '已配號' ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="7">還沒有批次。</td></tr><?php endif; ?>
</table>

<?php elseif ($a === 'new' || $a === 'edit'):
    need_edit($canEdit);
    $b = $a === 'edit' ? batch_get($d, $id) : null;
    $items = $b ? $d->query('SELECT * FROM batch_item WHERE batch_id = ' . (int)$b['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) : [];
    $locked = $b && $b['status'] !== 'draft';
    // 新增批次：信件與每套內含帶入最近一批同字軌（業務單位 11/12：信件每兩個月 1、3、5、7、9、11 月統一更新）
    $prevLetter = $prevContents = '';
    if (!$b) {
        // 信件：物、食兩個字軌用同一封（業務單位 11/12）→ 取最近一批（不分字軌）有填信的；每套內含只有物用，取最近一批物
        $prevLetter = (string)$d->query("SELECT letter FROM batch WHERE letter <> '' ORDER BY in_date DESC, id DESC LIMIT 1")->fetchColumn();
        $prevContents = (string)$d->query("SELECT contents FROM batch WHERE track = '物' AND contents <> '' ORDER BY in_date DESC, id DESC LIMIT 1")->fetchColumn();
    } ?>
<h2><?= $b ? '編輯批次' : '新增批次' ?></h2>
<?php if ($locked): ?><p>已配號：只能補進貨單號、供應商、經手人、每套內含與信件內容。</p><?php endif; ?>
<form method="post" action="./?a=<?= $b ? 'edit&id=' . (int)$b['id'] : 'new' ?>">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="save_batch">
<div class="grid">
<div><label>來源</label><select name="source" <?= $locked ? 'disabled' : '' ?>><?php foreach (RCPT_SOURCES as $s => $t): ?><option value="<?= h($s) ?>" <?= ($b['source'] ?? '') === $s ? 'selected' : '' ?>><?= h($s) ?>（<?= h($t) ?>）</option><?php endforeach; ?></select>
<?php if ($locked): ?><input type="hidden" name="source" value="<?= h($b['source']) ?>"><?php endif; ?></div>
<div><label>文中進貨單號（可之後補）</label><input type="text" name="po_no" value="<?= h($b['po_no'] ?? '') ?>" placeholder="11509140004"></div>
<div><label>收貨日期（民國）</label><input type="text" name="in_date" value="<?= h($b ? rcpt_date_to_roc($b['in_date']) : '') ?>" placeholder="115/09/14" <?= $locked ? 'readonly' : '' ?>></div>
<div><label>供應商編號</label><input type="text" name="supplier_code" value="<?= h($b['supplier_code'] ?? '') ?>" placeholder="0000036"></div>
<div><label>供應商名稱</label><input type="text" name="supplier_name" value="<?= h($b['supplier_name'] ?? '') ?>" placeholder="統一超商"></div>
<div><label>套餐每套金額（物）</label><select name="set_price" <?= $locked ? 'disabled' : '' ?>><option value="">（無）</option><?php foreach ([360, 300] as $p): ?><option <?= (int)($b['set_price'] ?? 0) === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></div>
<div><label>經手人</label><input type="text" name="handler" value="<?= h($b['handler'] ?? '') ?>"></div>
</div>
<label>每套內含（印在物收據上）</label><textarea name="contents" rows="2"><?= h($b['contents'] ?? $prevContents) ?></textarea>
<label>給捐贈者的信（印在收據下半部）</label><textarea name="letter" rows="8"><?= h($b['letter'] ?? $prevLetter) ?></textarea><?php if (!$b && $prevLetter !== ''): ?><small>已帶入最近一批的信；1、3、5、7、9、11 月換新信時在這裡改。</small><?php endif; ?>
<label>產品明細（每行：代號,品名,數量,單位,單價）</label>
<textarea name="items" rows="5" <?= $locked ? 'readonly' : '' ?>><?php foreach ($items as $it) echo h("{$it['code']},{$it['name']}," . (0 + $it['qty']) . ",{$it['unit']}," . ($it['price'] === null ? '' : 0 + $it['price'])) . "\n"; ?></textarea>
<p><button type="submit">儲存</button></p>
</form>

<?php elseif ($a === 'batch'):
    $b = batch_get($d, $id);
    if (!$b) { echo '<p>找不到批次。</p>'; } else {
    $items = $d->query('SELECT * FROM batch_item WHERE batch_id = ' . $id . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $rows = $d->query('SELECT * FROM donation WHERE batch_id = ' . $id . ' ORDER BY row_no')->fetchAll(PDO::FETCH_ASSOC);
    $dup = $rows ? rcpt_dup_orders($d, array_column($rows, 'order_no'), $id) : [];
    $inb = rcpt_dup_in_batch($d, $id);
    $qty = array_sum(array_column($rows, 'qty'));
    $nY = count(array_filter($rows, fn($r) => $r['send'])); $itemQty = $items ? max(array_map(fn($i) => (float)$i['qty'], $items)) : 0; ?>
<h2><?= h($b['source']) ?>（<?= h($b['track']) ?>）｜收貨 <?= h(rcpt_date_to_roc($b['in_date'])) ?>｜進貨單 <?= h($b['po_no'] ?: '未填') ?>
<?php if ($canEdit): ?><a href="./?a=edit&id=<?= $id ?>" style="font-size:.6em">編輯</a><?php endif; ?></h2>
<p>筆數 <b><?= count($rows) ?></b>　數量合計 <b><?= $qty ?></b><?php if ($itemQty): ?>（進貨單 <?= 0 + $itemQty ?><?= abs($qty - $itemQty) > 0.001 ? ' ⚠️ 不符' : ' ✅' ?>）<?php endif; ?>
　寄發 <b><?= $nY ?></b>　不寄 <b><?= count($rows) - $nY ?></b><?php if ($dup): ?>　<span class="dup">⚠️ 訂單編號已開過收據 <?= count($dup) ?> 筆</span><?php endif; ?><?php if ($inb): ?>　<span class="dup">⚠️ 名單內訂單編號重複 <?= count($inb) ?> 個</span><?php endif; ?></p>
<?php if ($b['status'] !== 'draft'): ?>
<p class="ok">收據起訖（請會計填入文中進貨單「報關單號」）：<b><?= h(rcpt_range_text($b['first_no'], $b['last_no'])) ?></b>
　<a href="./print.php?id=<?= $id ?>">列印收據</a></p>
<?php elseif ($canEdit): ?>
<form method="post" action="./?a=batch&id=<?= $id ?>" enctype="multipart/form-data" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='匯入中，請稍候…'">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="import">
<label>匯入名單（.xlsx；平台原始檔或「地址整理」後的檔都可以，重新匯入會取代這一批目前的清單）</label>
<input type="file" name="file" accept=".xlsx" required> <button type="submit">匯入</button>
</form>
<?php if ($rows): ?>
<form method="post" action="./?a=batch&id=<?= $id ?>" onsubmit="return confirm('配號後清單就鎖定、號碼不能再變。確定配號？')">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="assign">
<p><button type="submit">檢核完成，配號（<?= count($rows) ?> 筆）</button></p></form>
<?php endif; endif; ?>
<table><tr><th>#</th><th>收據編號</th><th>訂單編號</th><th>收件人</th><th>郵遞區號／縣市區／地址</th><th class="n">數量</th><th>寄發</th><th>檢核說明</th><th>寄出日期</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr>
<?php foreach ($rows as $r): $isDup = isset($dup[$r['order_no']]) || isset($inb[$r['order_no']]); $fid = 'f' . (int)$r['id']; ?>
<tr id="r<?= (int)$r['id'] ?>" class="<?= $isDup ? 'dup' : (!$r['send'] ? 'bad' : '') ?>">
<td><?= (int)$r['row_no'] ?></td><td><?= h($r['receipt_no']) ?></td><td><?= h($r['order_no']) ?><?= isset($dup[$r['order_no']]) ? '<br>已開：' . h($dup[$r['order_no']][1]) : '' ?><?= isset($inb[$r['order_no']]) ? '<br>名單內重複' : '' ?></td>
<?php if ($canEdit): ?>
<td><input form="<?= $fid ?>" type="text" name="name" value="<?= h($r['name']) ?>" style="width:7em"></td>
<td><input form="<?= $fid ?>" type="text" name="zip" value="<?= h($r['zip']) ?>" style="width:5em"> <input form="<?= $fid ?>" type="text" name="city" value="<?= h($r['city']) ?>" style="width:9em"> <input form="<?= $fid ?>" type="text" name="address" value="<?= h($r['address']) ?>" style="width:16em"><br><small><?= h($r['raw_addr']) ?></small></td>
<td class="n"><?= (int)$r['qty'] ?></td><td><input form="<?= $fid ?>" type="checkbox" name="send" <?= $r['send'] ? 'checked' : '' ?>></td><td><?= h($r['check_note']) ?></td>
<td><input form="<?= $fid ?>" type="text" name="mailed_on" value="<?= $r['mailed_on'] ? h(rcpt_date_to_roc($r['mailed_on'])) : '' ?>" placeholder="115/11/12" style="width:7em"></td>
<td><form id="<?= $fid ?>" method="post" action="./?a=batch&id=<?= $id ?>"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="row"><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>"><button>存</button></form>
<?php if ($r['receipt_no']): ?><a href="./print.php?id=<?= $id ?>&one=<?= (int)$r['id'] ?>">補印</a><?php endif; ?></td>
<?php else: ?>
<td><?= h($r['name']) ?></td><td><?= h($r['zip'] . ' ' . $r['city'] . $r['address']) ?></td><td class="n"><?= (int)$r['qty'] ?></td><td><?= $r['send'] ? 'Y' : 'N' ?></td><td><?= h($r['check_note']) ?></td>
<td><?= $r['mailed_on'] ? h(rcpt_date_to_roc($r['mailed_on'])) : '' ?></td>
<?php endif; ?></tr>
<?php endforeach; ?>
</table>
<?php } ?>

<?php elseif ($a === 'search'):
    $q = trim($_GET['q'] ?? ''); $res = [];
    if ($q !== '') {
        $s = $d->prepare("SELECT dn.*, b.in_date, b.source, b.po_no FROM donation dn JOIN batch b ON b.id = dn.batch_id
            WHERE dn.name = ? OR dn.mobile = ? OR dn.phone = ? OR dn.receipt_no = ? OR dn.order_no = ? ORDER BY b.in_date DESC LIMIT 200");
        $s->execute([$q, $q, $q, $q, $q]); $res = $s->fetchAll(PDO::FETCH_ASSOC);
        rcpt_audit($d, $email, 'search', null, count($res) . ' 筆');
    } ?>
<form method="get"><input type="hidden" name="a" value="search"><label>姓名、電話、收據編號或訂單編號（完整比對）</label>
<input type="text" name="q" value="<?= h($q) ?>"> <button>查詢</button></form>
<?php if ($q !== ''): ?><table><tr><th>收貨日期</th><th>來源</th><th>收據編號</th><th>訂單編號</th><th>姓名</th><th>電話</th><th>地址</th><th class="n">數量</th><th>寄發</th><th>寄出日期</th><th></th></tr>
<?php foreach ($res as $r): ?><tr><td><?= h(rcpt_date_to_roc($r['in_date'])) ?></td><td><?= h($r['source']) ?></td><td><?= h($r['receipt_no']) ?></td><td><?= h($r['order_no']) ?></td><td><?= h($r['name']) ?></td>
<td><?= h(trim($r['mobile'] . ' ' . $r['phone'])) ?></td><td><?= h($r['zip'] . ' ' . $r['city'] . $r['address']) ?></td><td class="n"><?= (int)$r['qty'] ?></td><td><?= $r['send'] ? 'Y' : 'N' ?></td><td><?= $r['mailed_on'] ? h(rcpt_date_to_roc($r['mailed_on'])) : '' ?></td>
<td><?php if ($r['receipt_no']): ?><a href="./print.php?id=<?= (int)$r['batch_id'] ?>&one=<?= (int)$r['id'] ?>">看收據／補印</a><?php endif; ?></td></tr>
<?php endforeach; if (!$res): ?><tr><td colspan="10">查無資料。</td></tr><?php endif; ?></table><?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
