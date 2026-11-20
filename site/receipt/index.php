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

// 轉址（測試接縫：命令列＋測試 schema 時把目的地印出來，讓測試驗得到 Location；大沈 d836044 審查要求）
function rc_go(string $u): void {
    if (PHP_SAPI === 'cli' && getenv('ITCCRA_TEST_DB') === '1') echo "
X-TEST-LOCATION: $u
";
    header("Location: $u"); exit;
}
// ── POST 動作 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { $err = '頁面已過期，請重新整理後再操作。'; }
    else {
        need_edit($canEdit);
        $do = $_POST['do'] ?? '';
        try {
            if ($do === 'save_batch') {
                // 產品明細改表格輸入（繁英姊 11/19：「原先是表格能夠依每個項目填寫的」）：每列轉回一行「代號	品名	數量	單位	單價」，交給下面同一套解析；
                // 用 tab 分隔，品名裡有逗號也不會被切開。沒有表格欄位時（舊表單、測試）照舊讀 items 文字。
                if (isset($_POST['it_name']) && is_array($_POST['it_name'])) {
                    $lines = [];
                    foreach (array_keys($_POST['it_name']) as $k) {
                        $f = fn($a) => trim(str_replace(["\t", "\r", "\n"], ' ', (string)($_POST[$a][$k] ?? '')));
                        if ($f('it_name') === '') continue;
                        $lines[] = implode("\t", [$f('it_code'), $f('it_name'), $f('it_qty') === '' ? '0' : $f('it_qty'), $f('it_unit'), $f('it_price')]);
                    }
                    $_POST['items'] = implode("\n", $lines);
                }
                // 來源、供應商、套餐金額改由「設定」頁維護（繁英姊 11/18 第 8 項）：只收啟用中的選項；編輯舊批次時，沿用它原本的值也可以（停用不影響舊批次）
                $src = $_POST['source'] ?? '';
                $cur = $id ? batch_get($d, $id) : null;
                $srcOpts = array_column(rcpt_options($d, 'source'), 'extra', 'code');
                if (isset($srcOpts[$src])) $track = $srcOpts[$src];
                elseif ($cur && $cur['source'] === $src) $track = $cur['track'];
                else throw new RuntimeException('請選來源');
                $supCode = trim((string)($_POST['supplier_code'] ?? ''));
                $supOpts = array_column(rcpt_options($d, 'supplier'), 'name', 'code');
                if ($supCode === '') $supName = '';
                elseif (isset($supOpts[$supCode])) $supName = $supOpts[$supCode];
                elseif ($cur && $cur['supplier_code'] === $supCode) $supName = $cur['supplier_name'];
                else throw new RuntimeException('請從清單選供應商（沒有的話先到「設定」新增）');
                $sp = trim((string)($_POST['set_price'] ?? ''));
                $spOpts = array_column(rcpt_options($d, 'set_price'), 'code');
                if ($sp !== '' && !in_array($sp, $spOpts, true) && !($cur && (string)$cur['set_price'] === $sp)) throw new RuntimeException('請從清單選套餐每套金額（沒有的話先到「設定」新增）');
                $rawDate = trim((string)($_POST['in_date'] ?? ''));
                if ($rawDate === '') throw new RuntimeException('請填收貨日期');
                $date = rcpt_roc_to_date($rawDate);
                if (!$date) throw new RuntimeException('收貨日期格式不對（例：115/09/14，或用日曆點選）');
                $po = trim($_POST['po_no'] ?? '') ?: null;
                $f = [$src, $track, $po, $date, $supCode, $supName,
                      $sp === '' ? null : (int)$sp, trim($_POST['handler'] ?? ''),
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
                        $p = array_map('trim', str_contains($line, "\t") ? explode("\t", $line) : preg_split('/[,，]/u', $line));
                        if (count($p) < 3 || $p[1] === '') continue;
                        $ins->execute([$id, $p[0], $p[1], (float)str_replace(',', '', $p[2]), $p[3] ?? '', isset($p[4]) && $p[4] !== '' ? (float)$p[4] : null]);
                    }
                }
                $d->commit();
                header("Location: ./?a=batch&id=$id&ok=saved" . (($_POST['single'] ?? '') === '1' ? '#single' : '')); exit;
            }
            if ($do === 'add_row') {       // 單筆輸入（繁英姊 11/18 驗收第 7 項：臨時來捐、電話捐贈，一次開一張）：只加在尚未配號的批次，配號檢查與匯入的一樣
                $name = trim($_POST['name'] ?? '');
                $qRaw = mb_convert_kana(trim((string)($_POST['qty'] ?? '')), 'n', 'UTF-8');   // 全形數字轉半形
                if ($name === '') throw new RuntimeException('請填捐物人（收件人）姓名');
                if (!preg_match('/^[1-9][0-9]{0,4}$/', $qRaw)) throw new RuntimeException('數量請填正整數（1～99999），例如 1、2；不能有小數，也不能以 0 開頭');   // 大沈 d836044：1.5 曾被直接轉成 1
                $qty = (int)$qRaw;
                $send = isset($_POST['send']);
                $zip = trim($_POST['zip'] ?? ''); $city = trim($_POST['city'] ?? ''); $addr = trim($_POST['address'] ?? '');
                if ($send && $addr === '') throw new RuntimeException('要寄收據的話請填地址；不寄請取消勾選「寄收據」');
                $addrBad = $send && rcpt_addr_incomplete($city, $addr);
                if ($addrBad) $send = false;   // 繁英姊 11/20 選 B：地址不完整自動不寄
                $d->beginTransaction();
                rcpt_lock_draft($d, $id);
                $s2 = $d->prepare('SELECT COALESCE(MAX(row_no), 0) FROM donation WHERE batch_id = ?'); $s2->execute([$id]);
                $n = (int)$s2->fetchColumn() + 1;
                $insOne = $d->prepare('INSERT INTO donation (batch_id, row_no, order_no, name, mobile, phone, raw_addr, zip, city, address, product, qty, send, check_note, dup_note, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
                $insOne->execute([$id, $n, trim($_POST['order_no'] ?? ''), $name, trim($_POST['mobile'] ?? ''), trim($_POST['phone'] ?? ''), trim("$zip $city$addr"),
                             $zip, $city, $addr, trim($_POST['product'] ?? ''), $qty, $send ? 'true' : 'false', $addrBad ? '單筆輸入；' . RCPT_ADDR_NOTE : '單筆輸入', '', $email]);
                $newId = (int)$insOne->fetchColumn();   // 清單列的錨點是 donation.id，不是 row_no（大沈 d836044：新增首筆導到 #r1，列卻是 #r5135）
                rcpt_audit($d, $email, 'add_row', $id, "row $n 單筆輸入");
                $d->commit();
                rc_go("./?a=batch&id=$id&ok=added#r$newId");
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
                // 到達暫停點先寫 ready 檔，等測試寫 release 檔才繼續（最多 30 秒），時序不靠 sleep 猜
                if (PHP_SAPI === 'cli' && getenv('ITCCRA_TEST_DB') === '1' && ($sync = (string)getenv('ITCCRA_TEST_IMPORT_SYNC')) !== '' && is_dir($sync)) {
                    touch("$sync/ready");
                    for ($w = 0; $w < 300 && !is_file("$sync/release"); $w++) usleep(100000);
                }
                $d->beginTransaction();
                rcpt_lock_draft($d, $id);   // 整理檔案期間別人可能已配號：持鎖後再核對仍是草稿才刪除重建
                $d->prepare('DELETE FROM donation WHERE batch_id = ?')->execute([$id]);
                $ins = $d->prepare('INSERT INTO donation (batch_id, row_no, order_no, name, mobile, phone, raw_addr, zip, city, address, product, qty, send, check_note, dup_note, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                foreach ($j['rows'] as $i => $r) {
                    $sendR = (bool)($r['send'] ?? true); $noteR = (string)$r['check_note'];
                    if ($sendR && rcpt_addr_nomail((string)$r['address'], (string)$r['city'])) $sendR = false;   // 繁英姊 11/20：空白／愛心捐贈／不用／少於 4 字本來就不寄（不標紅）
                    if ($sendR && rcpt_addr_incomplete((string)$r['city'], (string)$r['address'])) {   // 繁英姊 11/20 選 B
                        $sendR = false; $noteR = trim(RCPT_ADDR_NOTE . ($noteR !== '' ? '；' . $noteR : ''));
                    }
                    $ins->execute([$id, $i + 1, $r['order_no'], $r['name'], $r['mobile'], $r['phone'], $r['raw_addr'], $r['zip'], $r['city'], $r['address'],
                                   $r['product'], (int)($r['qty'] ?? 0), $sendR ? 'true' : 'false', $noteR, $r['dup_note'], $email]);
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
                if (!rcpt_addr_incomplete($newv['city'], $newv['address'])) {   // 繁英姊 11/20：地址改完整後，拿掉「自動不寄」的檢核說明（寄發由她勾選）
                    $d->prepare("UPDATE donation SET check_note = trim(both '；' from replace(replace(replace(check_note, ?, ''), ?, ''), '；；', '；')) WHERE id = ? AND batch_id = ?")
                      ->execute([RCPT_ADDR_NOTE, RCPT_ADDR_NOTE_OLD, $rid, $id]);
                }
                $chg = [];
                foreach ($newv as $k => $v) {
                    $o = $old[$k];
                    $os = $k === 'send' ? ($o ? 'Y' : 'N') : (string)$o; $vs = $k === 'send' ? ($v ? 'Y' : 'N') : (string)$v;
                    if ($os !== $vs) $chg[] = "$k: $os → $vs";
                }
                rcpt_audit($d, $email, $b['status'] === 'draft' ? 'edit_row' : 'edit_after_number', $id, "row $rid " . implode('；', $chg));
                $d->commit();
                // 繁英姊 11/20：在「地址不完整或有誤」篩選頁按存，要回到同一個篩選頁繼續校對
                rc_go("./?a=batch&id=$id" . ((int)($_GET['only'] ?? 0) ? '&only=' . $rid : '') . ((($_GET['addr'] ?? '') === 'bad') ? '&addr=bad' : '') . "&ok=row#r$rid");
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
            if ($do === 'opt_save') {      // 設定頁：新增或修改一筆選項（改名不影響舊批次，舊批次存的是當時的值）
                $kind = (string)($_POST['kind'] ?? '');
                [$code, $name, $extra] = rcpt_option_clean($kind, (string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['extra'] ?? ''));
                $oid = (int)($_POST['oid'] ?? 0);
                $d->beginTransaction();
                $dupq = $d->prepare('SELECT id FROM option_item WHERE kind = ? AND code = ? AND id <> ?'); $dupq->execute([$kind, $code, $oid]);
                if ($dupq->fetchColumn()) throw new RuntimeException(RCPT_OPT_KINDS[$kind] . '「' . $code . '」已經有了（停用的也算），請改用修改或重新啟用');
                if ($oid) {
                    $o0 = $d->prepare('SELECT * FROM option_item WHERE id = ? AND kind = ? FOR UPDATE'); $o0->execute([$oid, $kind]);
                    $old = $o0->fetch(PDO::FETCH_ASSOC);
                    if (!$old) throw new RuntimeException('找不到這筆設定');
                    $d->prepare('UPDATE option_item SET code = ?, name = ?, extra = ?, updated_by = ?, updated_at = now() WHERE id = ?')->execute([$code, $name, $extra, $email, $oid]);
                    rcpt_audit($d, $email, 'opt_edit', null, "$kind {$old['code']}/{$old['name']}/{$old['extra']} → $code/$name/$extra");
                } else {
                    $mx = $d->prepare('SELECT COALESCE(MAX(sort),0) + 1 FROM option_item WHERE kind = ?'); $mx->execute([$kind]);
                    $d->prepare('INSERT INTO option_item (kind, code, name, extra, sort, updated_by) VALUES (?,?,?,?,?,?)')->execute([$kind, $code, $name, $extra, (int)$mx->fetchColumn(), $email]);
                    rcpt_audit($d, $email, 'opt_add', null, "$kind $code/$name/$extra");
                }
                $d->commit();
                header('Location: ./?a=settings&ok=opt#' . $kind); exit;
            }
            if ($do === 'opt_toggle') {    // 停用／重新啟用
                $oid = (int)($_POST['oid'] ?? 0);
                $d->beginTransaction();
                $o0 = $d->prepare('SELECT * FROM option_item WHERE id = ? FOR UPDATE'); $o0->execute([$oid]);
                $old = $o0->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new RuntimeException('找不到這筆設定');
                $d->prepare('UPDATE option_item SET active = NOT active, updated_by = ?, updated_at = now() WHERE id = ?')->execute([$email, $oid]);
                rcpt_audit($d, $email, $old['active'] ? 'opt_disable' : 'opt_enable', null, "{$old['kind']} {$old['code']}");
                $d->commit();
                header('Location: ./?a=settings&ok=' . ($old['active'] ? 'optoff' : 'opton') . '#' . $old['kind']); exit;
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
// 新增／編輯批次出錯時回填使用者剛填的內容（繁英姊 11/18：出錯後欄位全被清空）
$refill = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'save_batch' && $err !== '') ? $_POST : null;
$refillOne = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'add_row' && $err !== '') ? $_POST : null;
$okMsg = ['saved' => '已儲存。', 'imported' => '已匯入，請檢核下面的清單，確認無誤後再配號。', 'row' => '已更新這一筆。', 'added' => '已新增一筆（單筆輸入）。還要加就繼續填，全部好了再按「配號」。', 'assigned' => '已配號。', 'mailed' => '已標記寄出日期。', 'opt' => '設定已儲存。', 'optoff' => '已停用：之後不會出現在選單，舊批次不受影響。', 'opton' => '已重新啟用。'][$_GET['ok'] ?? ''] ?? '';
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>物資捐贈收據｜CCRA 資訊服務</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32.png">
<meta name="theme-color" content="#109a4b">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Noto+Sans+TC:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<link rel="stylesheet" href="/assets/receipt.css?v=dev">
<script src="/assets/whoami.js?v=dev" defer></script>
<script src="/assets/footer.js?v=dev" defer></script>
</head>
<body class="rcpage">
<header class="bar"><div class="wrap">
  <a href="/" style="display:flex;align-items:center;gap:12px;text-decoration:none"><img class="mark" src="/img/logo.png" alt="CCRA 資訊服務首頁">
  <img class="word word-light" src="/img/textlogo_black.png" alt="中華基督教救助協會">
  <img class="word word-dark" src="/img/textlogo_white.png" alt="中華基督教救助協會"></a>
  <span class="sep" aria-hidden="true"></span>
  <div class="title">物資捐贈收據<small>Donation Receipts</small></div>
</div></header>
<main class="rc">
<?php
$tab = in_array($a, ['new', 'search', 'settings'], true) ? $a : 'list';
$head = ['list' => ['批次清單', '每次收貨建一個批次：匯入名單、檢核、配號、列印收據。'],
         'new' => [$a === 'edit' ? '編輯批次' : (isset($_GET['single']) ? '單筆輸入' : '新增批次'), isset($_GET['single']) ? '第一步：填收貨資料並儲存。第二步：在批次頁「單筆輸入」填捐物人，可連續加好幾位。' : '第一步：填收貨資料並儲存。第二步：在批次頁匯入名單（.xlsx）。'],
         'search' => ['查詢捐物人', '用姓名、電話、收據編號或訂單編號找，可看收據或補印。'],
         'settings' => ['設定', '來源、供應商、套餐每套金額：可新增、修改、停用。停用後不出現在選單，舊批次保留原本的值。']][$tab];
if ($a === 'batch') $head = ['批次明細', '檢核名單、配號、列印收據。'];
if ($a === 'edit') $head = ['編輯批次', '已配號的批次只能補不影響編號的欄位。'];
?>
<div class="rc-head">
  <div><h1><?= h($head[0]) ?></h1><p><?= h($head[1]) ?></p></div>
  <div class="chips"><?php if (itdb_web_test()): ?><span class="chip test">測試環境・假資料</span><?php endif; ?>
  <span class="chip <?= $canEdit ? 'ok' : '' ?>"><?= $canEdit ? '開收據權限' : '唯讀（只能查看）' ?></span></div>
</div>
<nav class="tabs" aria-label="收據系統功能">
  <a href="./"<?= $tab === 'list' && $a !== 'batch' ? ' aria-current="page"' : '' ?>>批次清單</a>
  <?php if ($canEdit): ?><a href="./?a=new"<?= $a === 'new' && !isset($_GET['single']) ? ' aria-current="page"' : '' ?>>＋ 新增批次（匯入名單）</a><a href="./?a=new&amp;single=1"<?= $a === 'new' && isset($_GET['single']) ? ' aria-current="page"' : '' ?>>＋ 單筆輸入</a><?php endif; ?>
  <a href="./?a=search"<?= $tab === 'search' ? ' aria-current="page"' : '' ?>>查詢捐物人</a>
  <?php if ($canEdit): ?><a href="./?a=settings"<?= $tab === 'settings' ? ' aria-current="page"' : '' ?>>設定</a><?php endif; ?>
</nav>
<?php if ($err): ?><div class="alert err" role="alert"><?= h($err) ?></div><?php endif; ?>
<?php if ($okMsg): ?><div class="alert ok" role="status"><?= h($okMsg) ?></div><?php endif; ?>

<?php if ($a === 'list'):
    $rows = $d->query("SELECT b.*, (SELECT COUNT(*) FROM donation WHERE batch_id=b.id) n, (SELECT COALESCE(SUM(qty),0) FROM donation WHERE batch_id=b.id) q FROM batch b ORDER BY in_date DESC, id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); ?>
<div class="panel flush"><div class="tbl">
<table class="rows"><tr class="hd"><th>收貨日期</th><th>來源</th><th>進貨單號</th><th class="n">筆數</th><th class="n">數量</th><th>收據起訖</th><th>狀態</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td data-l="收貨日期" class="mono"><?= h(rcpt_date_to_roc($r['in_date'])) ?></td><td data-l="來源"><?= h($r['source']) ?> <span class="chip"><?= h($r['track']) ?></span></td>
<td data-l="進貨單號"><a href="./?a=batch&id=<?= (int)$r['id'] ?>"><?= h($r['po_no'] ?: '（未填單號）') ?></a></td><td class="n" data-l="筆數"><?= (int)$r['n'] ?></td><td class="n" data-l="數量"><?= (int)$r['q'] ?></td>
<td data-l="收據起訖" class="mono"><?= $r['first_no'] ? h(rcpt_range_text($r['first_no'], $r['last_no'])) : '—' ?></td><td data-l="狀態"><?= $r['status'] === 'draft' ? '<span class="chip draft">草稿</span>' : '<span class="chip ok">已配號</span>' ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">還沒有批次。<?php if ($canEdit): ?>按上方「＋ 新增批次（匯入名單）」開始：先填收貨資料，儲存後在批次頁匯入名單。<?php endif; ?></td></tr><?php endif; ?>
</table></div></div>

<?php elseif ($a === 'new' || $a === 'edit'):
    need_edit($canEdit);
    $b = $a === 'edit' ? batch_get($d, $id) : null;
    $items = $b ? $d->query('SELECT * FROM batch_item WHERE batch_id = ' . (int)$b['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) : [];
    $locked = $b && $b['status'] !== 'draft';
    $fv = function (string $k, $dflt = '') use ($refill, $b) { return $refill !== null && array_key_exists($k, $refill) ? $refill[$k] : ($b[$k] ?? $dflt); };
    // 新增批次：信件與每套內含帶入最近一批同字軌（業務單位 11/12：信件每兩個月 1、3、5、7、9、11 月統一更新）
    $prevLetter = $prevContents = '';
    if (!$b) {
        // 信件：物、食兩個字軌用同一封（業務單位 11/12）→ 取最近一批（不分字軌）有填信的；每套內含只有物用，取最近一批物
        $prevLetter = (string)$d->query("SELECT letter FROM batch WHERE letter <> '' ORDER BY in_date DESC, id DESC LIMIT 1")->fetchColumn();
        $prevContents = (string)$d->query("SELECT contents FROM batch WHERE track = '物' AND contents <> '' ORDER BY in_date DESC, id DESC LIMIT 1")->fetchColumn();
    } ?>
<?php if ($locked): ?><div class="alert warn">已配號：只能補進貨單號、供應商、經手人、每套內含與信件內容。</div><?php endif; ?>
<form method="post" action="./?a=<?= $b ? 'edit&id=' . (int)$b['id'] : 'new' ?>">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="save_batch"><?php if (isset($_GET['single']) || ($refill['single'] ?? '') === '1'): ?><input type="hidden" name="single" value="1"><?php endif; ?>
<section class="panel"><h2>收貨資料</h2>
<div class="grid">
<?php
    // 選單：啟用中的選項；這一批原本的值若已停用或被改名，也列出來（標「已停用」），不讓舊批次被迫改值
    $optList = function (string $kind, $curCode, $curLabel) use ($d) {
        $o = []; foreach (rcpt_options($d, $kind) as $r) $o[$r['code']] = $r;
        if ($curCode !== null && $curCode !== '' && !isset($o[(string)$curCode])) $o[(string)$curCode] = ['code' => (string)$curCode, 'name' => $curLabel, 'extra' => '', 'gone' => true];
        return $o;
    }; ?>
<div><label for="f_source">來源</label><select id="f_source" name="source" <?= $locked ? 'disabled' : '' ?>><?php foreach ($optList('source', $b['source'] ?? null, '') as $o): ?><option value="<?= h($o['code']) ?>" <?= (string)$fv('source') === $o['code'] ? 'selected' : '' ?>><?= h($o['code']) ?><?= $o['extra'] !== '' ? '（' . h($o['extra']) . '）' : '' ?><?= !empty($o['gone']) ? '（已停用）' : '' ?></option><?php endforeach; ?></select>
<?php if ($locked): ?><input type="hidden" name="source" value="<?= h($b['source']) ?>"><?php endif; ?></div>
<div><label for="f_po_no">文中進貨單號（可之後補）</label><input type="text" id="f_po_no" name="po_no" value="<?= h($fv('po_no')) ?>" placeholder="例：11509140004" maxlength="30" inputmode="numeric"></div>
<div><label for="in_date">收貨日期</label><?php
    $dv = $refill !== null ? (rcpt_roc_to_date((string)($refill['in_date'] ?? '')) ?? '') : ($b ? substr($b['in_date'], 0, 10) : date('Y-m-d')); ?>
<input type="date" id="in_date" name="in_date" value="<?= h($dv) ?>" required <?= $locked ? 'readonly' : '' ?>>
<p class="hint" id="in_date_roc"><?= $dv !== '' ? '民國 ' . h(rcpt_date_to_roc($dv)) : '點右邊日曆選日期' ?><?= !$b ? '（預設今天）' : '' ?></p></div>
<div><label for="f_supplier">供應商</label><select id="f_supplier" name="supplier_code"><option value="">（未選）</option><?php foreach ($optList('supplier', $b['supplier_code'] ?? null, $b['supplier_name'] ?? '') as $o): ?><option value="<?= h($o['code']) ?>" <?= (string)$fv('supplier_code') === $o['code'] ? 'selected' : '' ?>><?= h($o['code'] . '　' . $o['name']) ?><?= !empty($o['gone']) ? '（已停用）' : '' ?></option><?php endforeach; ?></select>
<p class="hint">清單裡沒有？到「設定」新增。</p></div>
<div><label for="f_set_price">套餐每套金額（物）</label><select id="f_set_price" name="set_price" <?= $locked ? 'disabled' : '' ?>><option value="">（無）</option><?php foreach ($optList('set_price', isset($b['set_price']) ? (string)$b['set_price'] : null, '') as $o): ?><option value="<?= h($o['code']) ?>" <?= (string)$fv('set_price') === $o['code'] ? 'selected' : '' ?>><?= h($o['code']) ?><?= !empty($o['gone']) ? '（已停用）' : '' ?></option><?php endforeach; ?></select></div>
<div><label for="f_handler">經手人</label><input type="text" id="f_handler" name="handler" value="<?= h($fv('handler')) ?>"></div>
</div></section>
<section class="panel"><h2>收據內容</h2>
<label for="f_contents">每套內含（印在物收據上）</label><textarea id="f_contents" name="contents" rows="2"><?= h($fv('contents', $prevContents)) ?></textarea>
<label for="f_letter">給捐贈者的信（印在收據下半部）</label><textarea id="f_letter" name="letter" rows="8"><?= h($fv('letter', $prevLetter)) ?></textarea><?php if (!$b && $prevLetter !== ''): ?><p class="hint">已帶入最近一批的信；1、3、5、7、9、11 月換新信時在這裡改。</p><?php endif; ?>
<?php
  $itRows = [];
  if ($refill !== null) {
      foreach (preg_split('/\R/u', (string)($refill['items'] ?? '')) as $ln) { $p = array_map('trim', str_contains($ln, "\t") ? explode("\t", $ln) : preg_split('/[,，]/u', $ln)); if (count($p) >= 2 && $p[1] !== '') $itRows[] = [$p[0], $p[1], $p[2] ?? '', $p[3] ?? '', $p[4] ?? '']; }
  } else foreach ($items as $it) $itRows[] = [$it['code'], $it['name'], 0 + $it['qty'], $it['unit'], $it['price'] === null ? '' : 0 + $it['price']];
  if (!$locked) { $want = max(3, count($itRows) + 1); while (count($itRows) < $want) $itRows[] = ['', '', '', '', '']; }   // 至少 3 列、比現有多 1 列空白（目標先算好，不能在條件裡重算）
  $ro = $locked ? ' readonly' : '';
?>
<label id="items_lbl">產品明細 <small class="hint" style="font-weight:500">一列一個品項；品名空白的列不會存。</small></label>
<div class="tbl"><table class="rows items" aria-labelledby="items_lbl"><thead><tr><th>代號</th><th>品名</th><th>數量</th><th>單位</th><th>單價</th></tr></thead><tbody>
<?php foreach ($itRows as $r): ?><tr><td><input type="text" name="it_code[]" value="<?= h((string)$r[0]) ?>" aria-label="品項代號" style="width:8em"<?= $ro ?>></td><td><input type="text" name="it_name[]" value="<?= h((string)$r[1]) ?>" aria-label="品名" style="min-width:12em"<?= $ro ?>></td><td><input type="text" name="it_qty[]" value="<?= h((string)$r[2]) ?>" aria-label="數量" inputmode="decimal" style="width:5em"<?= $ro ?>></td><td><input type="text" name="it_unit[]" value="<?= h((string)$r[3]) ?>" aria-label="單位" style="width:4em"<?= $ro ?>></td><td><input type="text" name="it_price[]" value="<?= h((string)$r[4]) ?>" aria-label="單價" inputmode="decimal" style="width:6em"<?= $ro ?>></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if (!$locked): ?><div class="actions" style="margin-top:6px"><button type="button" class="btn ghost sm" onclick="var b=document.querySelector('table.items tbody'),r=b.rows[b.rows.length-1].cloneNode(true);r.querySelectorAll('input').forEach(function(i){i.value=''});b.appendChild(r);r.querySelector('input').focus()">＋ 加一列</button></div><?php endif; ?>
<script>(function(){var i=document.getElementById('in_date'),o=document.getElementById('in_date_roc');if(!i||!o)return;i.addEventListener('change',function(){var m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(i.value);o.textContent=m?'民國 '+(m[1]-1911)+'/'+m[2]+'/'+m[3]:'點右邊日曆選日期';});})();</script>
<div class="actions"><button type="submit">儲存</button><a class="btn ghost" href="<?= $b ? './?a=batch&id=' . (int)$b['id'] : './' ?>">取消</a></div>
</section>
</form>

<?php elseif ($a === 'batch'):
    $b = batch_get($d, $id);
    if (!$b) { echo '<div class="alert err">找不到批次。</div>'; } else {
    $items = $d->query('SELECT * FROM batch_item WHERE batch_id = ' . $id . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $rows = $d->query('SELECT * FROM donation WHERE batch_id = ' . $id . ' ORDER BY row_no')->fetchAll(PDO::FETCH_ASSOC);
    $dup = $rows ? rcpt_dup_orders($d, array_column($rows, 'order_no'), $id) : [];
    $inb = rcpt_dup_in_batch($d, $id);
    $fmiss = $b['track'] === '食' ? array_column(rcpt_food_missing($d, $id), 1, 0) : [];   // 食字軌缺品名／單位的列（列號 => 說明）
    $qty = array_sum(array_column($rows, 'qty'));
    $nY = count(array_filter($rows, fn($r) => $r['send'])); $itemQty = $items ? max(array_map(fn($i) => (float)$i['qty'], $items)) : 0; ?>
<section class="panel">
<h2><?= h($b['source']) ?> <span class="chip"><?= h($b['track']) ?></span> <?= $b['status'] === 'draft' ? '<span class="chip draft">草稿</span>' : '<span class="chip ok">已配號</span>' ?>
<small>收貨 <?= h(rcpt_date_to_roc($b['in_date'])) ?>｜進貨單 <?= h($b['po_no'] ?: '未填') ?></small></h2>
<div class="stats">
  <div class="stat"><small>筆數</small><b><?= count($rows) ?></b></div>
  <div class="stat"><small>數量合計</small><b><?= $qty ?></b><?php if ($itemQty): ?><em><?= abs($qty - $itemQty) > 0.001 ? '<span class="chip warn">進貨單 ' . (0 + $itemQty) . '，不符</span>' : '<span class="chip ok">與進貨單相符</span>' ?></em><?php endif; ?></div>
  <div class="stat"><small>寄發</small><b><?= $nY ?></b></div>
  <div class="stat"><small>不寄</small><b><?= count($rows) - $nY ?></b></div>
</div>
<?php if ($dup): ?><div class="alert warn">訂單編號已開過收據 <?= count($dup) ?> 筆（下方黃底）。</div><?php endif; ?>
<?php if ($fmiss): ?><div class="alert warn">食字軌品名／單位不完整 <?= count($fmiss) ?> 列，補齊前不能配號。</div><?php endif; ?>
<?php // 繁英姊 11/20：只列「原本要寄、地址卻不完整」的——寄發仍勾著，或是系統因地址自動改成不寄（檢核說明有那句）；匯入時就是不寄的不用再校對
$addrBadIds = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => rcpt_addr_incomplete((string)$r['city'], (string)$r['address'])
    && ($r['send'] || str_contains((string)$r['check_note'], RCPT_ADDR_NOTE) || str_contains((string)$r['check_note'], RCPT_ADDR_NOTE_OLD))));
if ($addrBadIds): ?><div class="alert warn">地址不完整或有誤 <?= count($addrBadIds) ?> 筆（缺路／街／大道、門牌號，或有重複字，會被退件；下方紅底，匯入時已自動設為不寄）。<?= ($_GET['addr'] ?? '') === 'bad' ? '<a href="./?a=batch&amp;id=' . $id . '">顯示整批</a>' : '<a href="./?a=batch&amp;id=' . $id . '&amp;addr=bad">只看這些</a>' ?></div><?php endif; ?>
<?php if ($inb): ?><div class="alert warn">名單內訂單編號重複 <?= count($inb) ?> 個（下方黃底）。</div><?php endif; ?>
<?php if ($b['status'] !== 'draft'): ?>
<div class="alert ok">收據起訖（請會計填入文中進貨單「報關單號」）：<b class="mono"><?= h(rcpt_range_text($b['first_no'], $b['last_no'])) ?></b></div>
<div class="actions"><a class="btn" href="./print.php?id=<?= $id ?>">列印收據</a><?php if ($canEdit): ?><a class="btn ghost" href="./?a=edit&id=<?= $id ?>">編輯批次資料</a><?php endif; ?></div>
<?php elseif ($canEdit): ?>
<div class="actions"><a class="btn ghost" href="./?a=edit&id=<?= $id ?>">編輯批次資料</a></div>
<?php endif; ?>
</section>
<?php if ($b['status'] === 'draft' && $canEdit): ?>
<?php $o1 = $refillOne ?? []; $ov = fn($k, $dflt = '') => h((string)($o1[$k] ?? $dflt)); ?>
<section class="panel" id="single"><h2>單筆輸入 <small>臨時來捐、電話捐贈：一次填一位，可連續加好幾位</small></h2>
<form method="post" action="./?a=batch&amp;id=<?= $id ?>#single">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="add_row">
<div class="grid">
<div><label for="s_name">捐物人（收件人）*</label><input type="text" id="s_name" name="name" value="<?= $ov('name') ?>" required></div>
<div><label for="s_mobile">手機</label><input type="text" id="s_mobile" name="mobile" value="<?= $ov('mobile') ?>" inputmode="tel"></div>
<div><label for="s_phone">市話</label><input type="text" id="s_phone" name="phone" value="<?= $ov('phone') ?>" inputmode="tel"></div>
<div><label for="s_zip">郵遞區號</label><input type="text" id="s_zip" name="zip" value="<?= $ov('zip') ?>" inputmode="numeric" maxlength="6"></div>
<div><label for="s_city">縣市區</label><input type="text" id="s_city" name="city" value="<?= $ov('city') ?>" placeholder="例：新北市永和區"></div>
<div><label for="s_address">地址</label><input type="text" id="s_address" name="address" value="<?= $ov('address') ?>" placeholder="例：保生路1號20樓"></div>
<div><label for="s_product">品名<?= $b['track'] === '食' ? '（要和上面產品明細的品名一樣）' : '' ?></label><input type="text" id="s_product" name="product" value="<?= $ov('product') ?>" list="s_products"><datalist id="s_products"><?php foreach ($items as $it): ?><option value="<?= h($it['name']) ?>"><?php endforeach; ?></datalist></div>
<div><label for="s_qty">數量（份）*</label><input type="text" id="s_qty" name="qty" value="<?= $ov('qty', '1') ?>" inputmode="numeric" pattern="[1-9１-９][0-9０-９]{0,4}" title="請填 1～99999 的正整數，不能以 0 開頭，例如 1、2" required></div>
<div><label for="s_order">訂單編號（沒有可空白）</label><input type="text" id="s_order" name="order_no" value="<?= $ov('order_no') ?>"></div>
</div>
<p><label style="display:inline-flex;align-items:center;gap:8px;margin:12px 0 0"><input type="checkbox" name="send" value="1"<?= $o1 && !isset($o1['send']) ? '' : ' checked' ?>> 寄收據</label></p>
<div class="actions"><button type="submit">加入這一筆</button><span class="hint">加完會出現在下方清單，可再修改；全部好了再按「配號」。</span></div>
</form>
</section>
<section class="panel"><h2>匯入名單 <small>.xlsx；平台原始檔或「地址整理」後的檔都可以。重新匯入會取代這一批目前的清單（包含上面單筆輸入的）。</small></h2>
<form method="post" action="./?a=batch&id=<?= $id ?>" enctype="multipart/form-data" onsubmit="return rcBusy(this, '匯入中，請稍候…（名單多時要一兩分鐘）')">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="import">
<div class="actions" style="margin-top:0"><input type="file" name="file" accept=".xlsx" required aria-label="選擇名單檔"> <button type="submit">匯入</button></div>
</form>
<?php if ($rows): ?>
<form method="post" action="./?a=batch&id=<?= $id ?>" onsubmit="if (!confirm('配號後清單就鎖定、號碼不能再變。確定配號？')) return false; return rcBusy(this, '配號中，請稍候…')">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="assign">
<div class="actions"><button type="submit">檢核完成，配號（<?= count($rows) ?> 筆）</button><span class="hint">配號後清單鎖定，號碼不再變動。</span></div></form>
<?php endif; ?>
</section>
<?php endif; ?>
<?php if ((int)($_GET['only'] ?? 0)): ?><div class="alert ok" role="status">只顯示查詢的這一筆。<a href="./?a=batch&amp;id=<?= $id ?>">顯示整批 <?= count($rows) ?> 筆</a></div><?php endif; ?>
<div class="panel flush"><div class="tbl">
<table class="rows"><tr class="hd"><th>#</th><th>收據編號</th><th>訂單編號</th><th>收件人</th><th>郵遞區號／縣市區／地址</th><?php if ($b['track'] === '食'): ?><th>品名</th><?php endif; ?><th class="n">數量</th><th>寄發</th><th>檢核說明</th><th>寄出日期</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr>
<?php // 查詢結果按「修改」只顯示那一筆（繁英姊 11/19：「按修改結果出現所有的資料，而不是這個人的資料」）；統計仍以整批計
$only = (int)($_GET['only'] ?? 0);
$shown = $only ? array_values(array_filter($rows, fn($r) => (int)$r['id'] === $only)) : $rows;
if (($_GET['addr'] ?? '') === 'bad') $shown = array_values(array_filter($shown, fn($r) => in_array((int)$r['id'], $addrBadIds, true)));   // 繁英姊 11/20：篩選標紅的
foreach ($shown as $r): $isDup = isset($dup[$r['order_no']]) || isset($inb[$r['order_no']]); $fid = 'f' . (int)$r['id']; ?>
<tr id="r<?= (int)$r['id'] ?>" class="<?= $isDup ? 'dup' : (in_array((int)$r['id'], $addrBadIds, true) ? 'addrbad' : (!$r['send'] ? 'bad' : '')) ?>">
<td data-l="#"><?= (int)$r['row_no'] ?></td><td data-l="收據編號" class="mono"><?= h($r['receipt_no']) ?></td><td data-l="訂單編號" class="mono"><?= h($r['order_no']) ?><?= isset($dup[$r['order_no']]) ? '<br><small>已開：' . h($dup[$r['order_no']][1]) . '</small>' : '' ?><?= isset($inb[$r['order_no']]) ? '<br><small>名單內重複</small>' : '' ?></td>
<?php if ($canEdit): ?>
<td data-l="收件人"><input form="<?= $fid ?>" type="text" name="name" value="<?= h($r['name']) ?>" style="width:7em" aria-label="收件人"></td>
<td data-l="地址" class="addr"><input form="<?= $fid ?>" type="text" name="zip" value="<?= h($r['zip']) ?>" style="width:5em" aria-label="郵遞區號"> <input form="<?= $fid ?>" type="text" name="city" value="<?= h($r['city']) ?>" style="width:9em" aria-label="縣市區"> <input form="<?= $fid ?>" type="text" name="address" value="<?= h($r['address']) ?>" style="width:16em" aria-label="地址"><br><small><?= h($r['raw_addr']) ?></small></td>
<?php if ($b['track'] === '食'): ?><td data-l="品名" class="<?= isset($fmiss[(int)$r['row_no']]) ? 'bad' : '' ?>"><?= h($r['product']) ?><?= isset($fmiss[(int)$r['row_no']]) ? '<br><small>' . h($fmiss[(int)$r['row_no']]) . '</small>' : '' ?></td><?php endif; ?><td data-l="數量" class="n"><?= (int)$r['qty'] ?></td><td data-l="寄發"><input form="<?= $fid ?>" type="checkbox" name="send" <?= $r['send'] ? 'checked' : '' ?> aria-label="寄發"></td><td data-l="檢核說明"><?= h($r['check_note']) ?></td>
<td data-l="寄出日期"><input form="<?= $fid ?>" type="text" name="mailed_on" value="<?= $r['mailed_on'] ? h(rcpt_date_to_roc($r['mailed_on'])) : '' ?>" placeholder="115/11/12" style="width:7em" aria-label="寄出日期"></td>
<td class="act"><form id="<?= $fid ?>" style="display:inline" method="post" action="./?a=batch&id=<?= $id ?><?= $only ? '&amp;only=' . $only : '' ?><?= ($_GET['addr'] ?? '') === 'bad' ? '&amp;addr=bad' : '' ?>"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="row"><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>"><button class="sm">存</button></form>
<?php if ($r['receipt_no']): ?><a href="./print.php?id=<?= $id ?>&one=<?= (int)$r['id'] ?>">補印</a><?php endif; ?></td>
<?php else: ?>
<td data-l="收件人"><?= h($r['name']) ?></td><td data-l="地址"><?= h($r['zip'] . ' ' . $r['city'] . $r['address']) ?></td><?php if ($b['track'] === '食'): ?><td data-l="品名" class="<?= isset($fmiss[(int)$r['row_no']]) ? 'bad' : '' ?>"><?= h($r['product']) ?><?= isset($fmiss[(int)$r['row_no']]) ? '<br><small>' . h($fmiss[(int)$r['row_no']]) . '</small>' : '' ?></td><?php endif; ?><td data-l="數量" class="n"><?= (int)$r['qty'] ?></td><td data-l="寄發"><?= $r['send'] ? '<span class="chip ok">寄</span>' : '<span class="chip">不寄</span>' ?></td><td data-l="檢核說明"><?= h($r['check_note']) ?></td>
<td data-l="寄出日期"><?= $r['mailed_on'] ? h(rcpt_date_to_roc($r['mailed_on'])) : '' ?></td>
<?php endif; ?></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="11" class="empty">這一批還沒有名單<?= $canEdit && $b['status'] === 'draft' ? '，請先在上方匯入。' : '。' ?></td></tr><?php endif; ?>
</table></div></div>
<?php } ?>

<?php elseif ($a === 'settings'):
    need_edit($canEdit);
    $eid = (int)($_GET['e'] ?? 0);
    foreach (RCPT_OPT_KINDS as $kind => $kname):
        $opts = rcpt_options($d, $kind, false);
        $col1 = $kind === 'supplier' ? '編號' : ($kind === 'source' ? '名稱' : '金額'); ?>
<section class="panel" id="<?= h($kind) ?>"><h2><?= h($kname) ?> <small><?= count(array_filter($opts, fn($o) => $o['active'])) ?> 項啟用中</small></h2>
<div class="tbl"><table class="rows"><tr class="hd"><th><?= $col1 ?></th><?php if ($kind === 'supplier'): ?><th>名稱</th><?php endif; ?><?php if ($kind === 'source'): ?><th>字軌</th><?php endif; ?><th>狀態</th><th></th></tr>
<?php foreach ($opts as $o): $isE = $eid === (int)$o['id']; $fid = 'oe' . (int)$o['id']; ?>
<tr id="o<?= (int)$o['id'] ?>" class="<?= $o['active'] ? '' : 'bad' ?>">
<?php if ($isE): ?>
<td data-l="<?= $col1 ?>"><input form="<?= $fid ?>" type="text" name="code" value="<?= h($o['code']) ?>" required aria-label="<?= $col1 ?>"></td>
<?php if ($kind === 'supplier'): ?><td data-l="名稱"><input form="<?= $fid ?>" type="text" name="name" value="<?= h($o['name']) ?>" required aria-label="名稱"></td><?php endif; ?>
<?php if ($kind === 'source'): ?><td data-l="字軌"><select form="<?= $fid ?>" name="extra" aria-label="字軌"><?php foreach (['物', '食'] as $t): ?><option <?= $o['extra'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></td><?php endif; ?>
<td data-l="狀態"><?= $o['active'] ? '<span class="chip ok">啟用</span>' : '<span class="chip">已停用</span>' ?></td>
<td class="act"><form id="<?= $fid ?>" method="post" action="./?a=settings" style="display:inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="opt_save"><input type="hidden" name="kind" value="<?= h($kind) ?>"><input type="hidden" name="oid" value="<?= (int)$o['id'] ?>"><button class="sm">存</button></form><a href="./?a=settings#<?= h($kind) ?>">取消</a></td>
<?php else: ?>
<td data-l="<?= $col1 ?>" class="<?= $kind !== 'source' ? 'mono' : '' ?>"><?= h($o['code']) ?></td>
<?php if ($kind === 'supplier'): ?><td data-l="名稱"><?= h($o['name']) ?></td><?php endif; ?>
<?php if ($kind === 'source'): ?><td data-l="字軌"><span class="chip"><?= h($o['extra']) ?></span></td><?php endif; ?>
<td data-l="狀態"><?= $o['active'] ? '<span class="chip ok">啟用</span>' : '<span class="chip">已停用</span>' ?></td>
<td class="act"><a href="./?a=settings&e=<?= (int)$o['id'] ?>#o<?= (int)$o['id'] ?>">修改</a>
<form method="post" action="./?a=settings" style="display:inline"<?= $o['active'] ? ' onsubmit="return confirm(&quot;停用後不會出現在選單（舊批次不受影響）。確定停用？&quot;)"' : '' ?>><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="opt_toggle"><input type="hidden" name="oid" value="<?= (int)$o['id'] ?>"><button class="sm btn ghost"><?= $o['active'] ? '停用' : '重新啟用' ?></button></form></td>
<?php endif; ?></tr>
<?php endforeach; if (!$opts): ?><tr><td colspan="5" class="empty">還沒有<?= h($kname) ?>，請在下面新增。</td></tr><?php endif; ?>
</table></div>
<form method="post" action="./?a=settings" class="actions">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="opt_save"><input type="hidden" name="kind" value="<?= h($kind) ?>">
<input type="text" name="code" placeholder="<?= $kind === 'supplier' ? '新供應商編號（例：0000036）' : ($kind === 'source' ? '新來源名稱' : '新金額（例：360）') ?>" required style="max-width:240px" aria-label="新增<?= h($kname) ?>">
<?php if ($kind === 'supplier'): ?><input type="text" name="name" placeholder="供應商名稱（例：統一超商）" required style="max-width:260px" aria-label="供應商名稱"><?php endif; ?>
<?php if ($kind === 'source'): ?><select name="extra" style="max-width:150px" aria-label="字軌"><option value="物">物（預印紙）</option><option value="食">食（白紙）</option></select><?php endif; ?>
<button>＋ 新增</button></form>
</section>
<?php endforeach; ?>


<?php elseif ($a === 'search'):
    $q = trim($_GET['q'] ?? ''); $res = [];
    if ($q !== '') {
        $s = $d->prepare("SELECT dn.*, b.in_date, b.source, b.po_no FROM donation dn JOIN batch b ON b.id = dn.batch_id
            WHERE dn.name = ? OR dn.mobile = ? OR dn.phone = ? OR dn.receipt_no = ? OR dn.order_no = ? ORDER BY b.in_date DESC LIMIT 200");
        $s->execute([$q, $q, $q, $q, $q]); $res = $s->fetchAll(PDO::FETCH_ASSOC);
        rcpt_audit($d, $email, 'search', null, count($res) . ' 筆');
    } ?>
<section class="panel"><form method="get"><input type="hidden" name="a" value="search"><label for="q">姓名、電話、收據編號或訂單編號（完整比對）</label>
<div class="searchbar"><input type="text" id="q" name="q" value="<?= h($q) ?>" autofocus> <button>查詢</button></div></form></section>
<?php if ($q !== ''): ?><div class="panel flush"><div class="tbl"><table class="rows"><tr class="hd"><th>收貨日期</th><th>來源</th><th>收據編號</th><th>訂單編號</th><th>姓名</th><th>電話</th><th>地址</th><th class="n">數量</th><th>寄發</th><th>寄出日期</th><th></th></tr>
<?php foreach ($res as $r): ?><tr><td data-l="收貨日期" class="mono"><?= h(rcpt_date_to_roc($r['in_date'])) ?></td><td data-l="來源"><?= h($r['source']) ?></td><td data-l="收據編號" class="mono"><?= h($r['receipt_no']) ?></td><td data-l="訂單編號" class="mono"><?= h($r['order_no']) ?></td><td data-l="姓名"><?= h($r['name']) ?></td>
<td data-l="電話"><?= h(trim($r['mobile'] . ' ' . $r['phone'])) ?></td><td data-l="地址"><?= h($r['zip'] . ' ' . $r['city'] . $r['address']) ?></td><td data-l="數量" class="n"><?= (int)$r['qty'] ?></td><td data-l="寄發"><?= $r['send'] ? '<span class="chip ok">寄</span>' : '<span class="chip">不寄</span>' ?></td><td data-l="寄出日期"><?= $r['mailed_on'] ? h(rcpt_date_to_roc($r['mailed_on'])) : '' ?></td>
<td class="act"><?php if ($canEdit): ?><a href="./?a=batch&id=<?= (int)$r['batch_id'] ?>&only=<?= (int)$r['id'] ?>#r<?= (int)$r['id'] ?>">修改</a><?php endif; ?><?php if ($r['receipt_no']): ?><a href="./print.php?id=<?= (int)$r['batch_id'] ?>&one=<?= (int)$r['id'] ?>">看收據／補印</a><?php endif; ?></td></tr>
<?php endforeach; if (!$res): ?><tr><td colspan="11" class="empty">查無資料。</td></tr><?php endif; ?></table></div></div><?php endif; ?>
<?php endif; ?>
</main>
<?php /* 匯入／配號進行中與完成的小視窗（繁英姊 11/19：「匯入或配號時可以 popup 一個小視窗『匯入中』或『配號中』，完成時出現『完成』」） */ ?>
<dialog id="rcBusy" class="rc-dlg" aria-live="polite"><p class="rc-dlg-t"><span class="spin" aria-hidden="true"></span> <span id="rcBusyMsg">處理中…</span></p><p class="hint">請不要關閉或重新整理這個頁面，完成後會自動顯示結果。</p></dialog>
<?php $doneMsg = ['imported' => '匯入完成', 'assigned' => '配號完成'][$_GET['ok'] ?? ''] ?? ''; if ($doneMsg): ?>
<dialog id="rcDone" class="rc-dlg"><p class="rc-dlg-t">✓ <?= h($doneMsg) ?></p><p><?= h($okMsg) ?></p><form method="dialog"><button autofocus>知道了</button></form></dialog>
<script>document.getElementById('rcDone').showModal()</script>
<?php endif; ?>
<script>
function rcBusy(f, msg) {
  var d = document.getElementById('rcBusy');
  document.getElementById('rcBusyMsg').textContent = msg;
  d.addEventListener('cancel', function (e) { e.preventDefault(); });   // Esc 不能關掉，避免以為取消了
  if (d.showModal) d.showModal();
  var b = f.querySelector('button[type=submit]'); if (b) b.disabled = true;   // 防重按
  return true;
}
</script>
</body>
</html>
