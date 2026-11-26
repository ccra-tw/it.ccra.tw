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
// 大沈審 1504565：過期（超過 1 小時）的進貨單預填暫存要真的刪掉，不只是不讀——擱置的草稿裡有抬頭、收件人與地址
// 大沈審 0488773：放在角色檢查之後，沒有收據權限的請求不會動到資料
$d->exec("DELETE FROM pdf_prefill WHERE created_at < now() - interval '1 hour'");
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
// 11/24 明細分頁：存檔、轉址時帶回目前頁碼
function rc_pq(): string { $p = (int)($_GET['p'] ?? 1); return $p > 1 ? '&p=' . $p : ''; }
function need_edit($canEdit) { if (!$canEdit) { http_response_code(403); exit('您的權限只能查詢。'); } }

// 轉址（測試接縫：命令列＋測試 schema 時把目的地印出來，讓測試驗得到 Location；大沈 d836044 審查要求）
function rc_go(string $u): void {
    if (PHP_SAPI === 'cli' && getenv('ITCCRA_TEST_DB') === '1') echo "
X-TEST-LOCATION: $u
";
    header("Location: $u"); exit;
}
// 繁英姊 11/25：列上的刪除／作廢／恢復（先在頁面上確認再送出，與「地址不完整全部改不寄」相同做法）
function rc_row_act_form(int $bid, int $rid, string $do, string $csrf, string $label, string $ask): string {
    $act = './?a=batch&id=' . $bid . (($_GET['addr'] ?? '') === 'bad' ? '&addr=bad' : '') . rc_pq();
    return '<form style="display:inline" method="post" action="' . h($act) . '" onsubmit="return confirm(' . h(json_encode($ask, JSON_UNESCAPED_UNICODE)) . ')">'
         . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="do" value="' . h($do) . '"><input type="hidden" name="rid" value="' . $rid . '">'
         . '<button class="sm ghost">' . h($label) . '</button></form>';
}
// ── POST 動作 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { $err = '頁面已過期，請重新整理後再操作。'; }
    else {
        need_edit($canEdit);
        $do = $_POST['do'] ?? '';
        try {
            if ($do === 'pdf_prefill') {   // 繁英姊 11/24：文中進貨單 PDF 帶入收貨資料（只帶入表單，按「儲存」才建批次）
                $f = $_FILES['file'] ?? null;
                if (!$f || (int)$f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('上傳失敗，請重新選擇進貨單 PDF');
                if (!preg_match('/\.pdf$/i', (string)$f['name']) || (int)$f['size'] > 5 * 1024 * 1024) throw new RuntimeException('請上傳文中列印的進貨單 PDF（5MB 以內）');
                $pdf = rcpt_parse_wenzhong_pdf((string)file_get_contents($f['tmp_name']));
                $srcOk = array_values(array_filter(rcpt_options($d, 'source'), fn($o) => $o['extra'] === $pdf['track']));
                $supOk = array_column(rcpt_options($d, 'supplier'), 'name', 'code');
                $pdfRefill = ['po_no' => $pdf['po_no'], 'in_date' => $pdf['in_date'],
                    'source' => count($srcOk) === 1 ? $srcOk[0]['code'] : '',
                    'supplier_code' => isset($supOk[$pdf['supplier_code']]) ? $pdf['supplier_code'] : '',
                    'items' => implode("\n", array_map(fn($it) => implode("\t", array_map(fn($v) => str_replace(["\t", "\n"], ' ', (string)$v), $it)), $pdf['items'])),
                    'pf_name' => $pdf['title'], 'pf_attn' => $pdf['attn'], 'pf_addr' => $pdf['addr']];
                $pdfInfo = ['track' => $pdf['track'], 'receipt_no' => $pdf['receipt_no'], 'srcs' => array_column($srcOk, 'code'),
                    'sup_missing' => isset($supOk[$pdf['supplier_code']]) ? '' : trim($pdf['supplier_code'] . ' ' . $pdf['supplier_name']),
                    'n' => count($pdf['items']), 'file' => (string)$f['name']];
                rcpt_audit($d, $email, 'pdf_prefill', null, "{$pdf['po_no']} {$pdfInfo['file']} 明細 {$pdfInfo['n']} 項");
            }
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
                      trim($_POST['contents'] ?? '')];   // 信件改由「設定」統一維護（繁英姊 11/25），批次不再存
                $d->beginTransaction();
                if ($id) {
                    $s0 = $d->prepare('SELECT * FROM batch WHERE id = ? FOR UPDATE'); $s0->execute([$id]);   // 與配號共用列鎖，持鎖後依當下狀態決定可改哪些欄位
                    $b = $s0->fetch(PDO::FETCH_ASSOC);
                    if (!$b) throw new RuntimeException('找不到批次');
                    if ($b['status'] !== 'draft') {   // 已配號：只能補進貨單號與經手人、信件等不影響編號的欄位
                        $d->prepare('UPDATE batch SET po_no = ?, supplier_code = ?, supplier_name = ?, handler = ?, contents = ?, updated_at = now() WHERE id = ?')
                          ->execute([$po, $f[4], $f[5], $f[7], $f[8], $id]);
                    } else {
                        $d->prepare('UPDATE batch SET source=?, track=?, po_no=?, in_date=?, supplier_code=?, supplier_name=?, set_price=?, handler=?, contents=?, updated_at=now() WHERE id=?')
                          ->execute(array_merge($f, [$id]));
                    }
                    rcpt_audit($d, $email, 'edit_batch', $id);
                } else {
                    $d->prepare('INSERT INTO batch (source, track, po_no, in_date, supplier_code, supplier_name, set_price, handler, contents, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
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
                // 進貨單 PDF 帶入的捐贈者（收據抬頭／收件人／寄件地址）：存在伺服器端、綁批次＋登入者（大沈審 5b711e9：個資不進網址、瀏覽紀錄與代理日誌）
                $pf = trim((string)($_POST['pf_name'] ?? ''));
                if ($pf !== '' && !$cur) {
                    $d->prepare('INSERT INTO pdf_prefill (batch_id, email, data) VALUES (?,?,?) ON CONFLICT (batch_id, email) DO UPDATE SET data = EXCLUDED.data, created_at = now()')
                      ->execute([$id, $email, json_encode(['name' => $pf, 'attn' => trim((string)($_POST['pf_attn'] ?? '')), 'addr' => trim((string)($_POST['pf_addr'] ?? ''))], JSON_UNESCAPED_UNICODE)]);
                }
                $d->commit();
                if ($pf !== '' && !$cur) rc_go('./?a=batch&id=' . $id . '&ok=saved&pf=1#single');
                header("Location: ./?a=batch&id=$id&ok=saved" . (($_POST['single'] ?? '') === '1' ? '#single' : '')); exit;
            }
            if ($do === 'add_row') {       // 單筆輸入（繁英姊 11/18 驗收第 7 項：臨時來捐、電話捐贈，一次開一張）：只加在尚未配號的批次，配號檢查與匯入的一樣
                $name = trim($_POST['name'] ?? '');
                $qRaw = mb_convert_kana(trim((string)($_POST['qty'] ?? '')), 'n', 'UTF-8');   // 全形數字轉半形
                if ($name === '') throw new RuntimeException('請填捐物人（收件人）姓名');
                if (!preg_match('/^[1-9][0-9]{0,4}$/', $qRaw)) throw new RuntimeException('數量請填正整數（1～99999），例如 1、2；不能有小數，也不能以 0 開頭');   // 大沈 d836044：1.5 曾被直接轉成 1
                $qty = (int)$qRaw;
                $send = isset($_POST['send']);
                $taxId = rcpt_tax_id((string)($_POST['tax_id'] ?? ''));
                $zip = trim($_POST['zip'] ?? ''); $city = trim($_POST['city'] ?? ''); $addr = trim($_POST['address'] ?? '');
                if ($send && $addr === '') throw new RuntimeException('要寄收據的話請填地址；不寄請取消勾選「寄收據」');
                $addrBad = $send && rcpt_addr_incomplete($city, $addr);
                if ($addrBad) $send = false;   // 繁英姊 11/20 選 B：地址不完整自動不寄
                $d->beginTransaction();
                rcpt_lock_draft($d, $id);
                $s2 = $d->prepare('SELECT COALESCE(MAX(row_no), 0) FROM donation WHERE batch_id = ?'); $s2->execute([$id]);
                $n = (int)$s2->fetchColumn() + 1;
                $insOne = $d->prepare('INSERT INTO donation (batch_id, row_no, order_no, name, mobile, phone, raw_addr, zip, city, address, product, qty, send, check_note, dup_note, tax_id, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
                $insOne->execute([$id, $n, trim($_POST['order_no'] ?? ''), $name, trim($_POST['mobile'] ?? ''), trim($_POST['phone'] ?? ''), trim("$zip $city$addr"),
                             $zip, $city, $addr, trim($_POST['product'] ?? ''), $qty, $send ? 'true' : 'false', $addrBad ? '單筆輸入；' . RCPT_ADDR_NOTE : '單筆輸入', '', $taxId, $email]);
                $newId = (int)$insOne->fetchColumn();   // 清單列的錨點是 donation.id，不是 row_no（大沈 d836044：新增首筆導到 #r1，列卻是 #r5135）
                rcpt_audit($d, $email, 'add_row', $id, "row $n 單筆輸入");
                $d->prepare('DELETE FROM pdf_prefill WHERE batch_id = ? AND email = ?')->execute([$id, $email]);   // 進貨單帶入的捐贈者用過就刪
                $d->commit();
                $nAll = (int)$d->query('SELECT COUNT(*) FROM donation WHERE batch_id = ' . $id)->fetchColumn(); $lastP = max(1, (int)ceil($nAll / RCPT_PAGE));
                rc_go("./?a=batch&id=$id" . ($lastP > 1 ? "&p=$lastP" : '') . "&ok=added#r$newId");
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
                // 11/23 繁英姊（熊哥同意）：ibon 一張進貨單常分兩個月份的檔 → 可選「追加」，接在現有清單後面、不刪除
                $append = ($_POST['mode'] ?? 'replace') === 'append';
                $base = 0; $have = [];
                if ($append) {
                    $base = (int)$d->query('SELECT COALESCE(max(row_no), 0) FROM donation WHERE batch_id = ' . (int)$id)->fetchColumn();
                    $hs = $d->prepare("SELECT order_no FROM donation WHERE batch_id = ? AND order_no <> ''");
                    $hs->execute([$id]);
                    $have = array_fill_keys($hs->fetchAll(PDO::FETCH_COLUMN), true);
                } else {
                    $d->prepare('DELETE FROM donation WHERE batch_id = ?')->execute([$id]);
                }
                $ins = $d->prepare('INSERT INTO donation (batch_id, row_no, order_no, name, mobile, phone, raw_addr, zip, city, address, product, qty, send, check_note, dup_note, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $dupPrev = 0;
                foreach ($j['rows'] as $i => $r) {
                    $dupR = (string)$r['dup_note'];
                    $prevDup = $append && (string)$r['order_no'] !== '' && isset($have[(string)$r['order_no']]);   // 同一批前次匯入已有同訂單編號（例：同一個檔匯了兩次）
                    if ($prevDup) $dupPrev++;
                    $sendR = (bool)($r['send'] ?? true); $noteR = (string)$r['check_note'];
                    if ($sendR && rcpt_addr_nomail((string)$r['address'], (string)$r['city'])) $sendR = false;   // 繁英姊 11/20：空白／愛心捐贈／不用／少於 4 字本來就不寄（不標紅）
                    if ($sendR && rcpt_addr_incomplete((string)$r['city'], (string)$r['address'])) {   // 繁英姊 11/20 選 B
                        $sendR = false; $noteR = trim(RCPT_ADDR_NOTE . ($noteR !== '' ? '；' . $noteR : ''));
                    }
                    if ($prevDup) $noteR = trim(RCPT_DUP_PREV_NOTE . ($noteR !== '' ? '；' . $noteR : ''));   // 寫進檢核說明（清單上看得到），不自動改寄發，由同工判斷
                    $ins->execute([$id, $base + $i + 1, $r['order_no'], $r['name'], $r['mobile'], $r['phone'], $r['raw_addr'], $r['zip'], $r['city'], $r['address'],
                                   $r['product'], (int)($r['qty'] ?? 0), $sendR ? 'true' : 'false', $noteR, $dupR, $email]);
                }
                rcpt_audit($d, $email, $append ? 'import_append' : 'import', $id, count($j['rows']) . ' 筆' . ($tidy ? '（已整理檔）' : '（經地址整理）') . ($append ? "（追加，接在第 $base 筆後；與前次重複訂單編號 $dupPrev 筆）" : ''));
                $d->commit();
                header("Location: ./?a=batch&id=$id&ok=" . ($append ? 'appended' : 'imported') . ($append && $dupPrev ? "&dupprev=$dupPrev" : '')); exit;
            }
            if ($do === 'addr_bad_nosend') {   // 繁英姊 11/20：舊批次裡地址不完整、仍勾「寄發」的，一次改成不寄，確認後再由她逐筆勾回
                $d->beginTransaction();
                $sb = $d->prepare('SELECT status FROM batch WHERE id = ? FOR UPDATE'); $sb->execute([$id]);
                if (!$sb->fetch()) throw new RuntimeException('找不到批次');
                $s1 = $d->prepare('SELECT id, city, address, check_note FROM donation WHERE batch_id = ? AND send = true FOR UPDATE'); $s1->execute([$id]);
                $up = $d->prepare("UPDATE donation SET send = false, check_note = trim(both '；' from ? || CASE WHEN check_note <> '' THEN '；' || check_note ELSE '' END), updated_by = ?, updated_at = now() WHERE id = ? AND batch_id = ?");
                $n = 0;
                foreach ($s1->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (!rcpt_addr_incomplete((string)$r['city'], (string)$r['address'])) continue;
                    $up->execute([RCPT_ADDR_NOTE, $email, (int)$r['id'], $id]); $n++;
                }
                rcpt_audit($d, $email, 'addr_bad_nosend', $id, "地址不完整一次改不寄 $n 筆");
                $d->commit();
                rc_go("./?a=batch&id=$id&addr=bad&ok=nosend$n");
            }
            if ($do === 'rows_bulk') {   // 繁英姊 11/20：改好多筆地址、勾好寄發後一次儲存（只送有改過的列）
                $items = json_decode((string)($_POST['rows'] ?? ''), true);
                if (!is_array($items) || !$items) throw new RuntimeException('沒有要儲存的修改');
                if (count($items) > 2000) throw new RuntimeException('一次最多 2000 筆');
                $d->beginTransaction();
                $sb = $d->prepare('SELECT status, track FROM batch WHERE id = ? FOR UPDATE'); $sb->execute([$id]);
                $b = $sb->fetch(PDO::FETCH_ASSOC);
                if (!$b) throw new RuntimeException('找不到批次');
                $s0 = $d->prepare('SELECT name, zip, city, address, send, mailed_on, order_no, product, qty, tax_id, receipt_no, row_no, void, EXISTS (SELECT 1 FROM donation_item di WHERE di.donation_id = donation.id) AS has_items FROM donation WHERE id = ? AND batch_id = ? FOR UPDATE');
                $up = $d->prepare('UPDATE donation SET name = ?, send = ?, zip = ?, city = ?, address = ?, mailed_on = ?, order_no = ?, product = ?, qty = ?, tax_id = ?, updated_by = ?, updated_at = now() WHERE id = ? AND batch_id = ?');
                $clr = $d->prepare("UPDATE donation SET check_note = trim(both '；' from replace(replace(replace(check_note, ?, ''), ?, ''), '；；', '；')) WHERE id = ? AND batch_id = ?");
                $n = 0;
                foreach ($items as $it) {
                    $rid = (int)($it['rid'] ?? 0);
                    $s0->execute([$rid, $id]); $old = $s0->fetch(PDO::FETCH_ASSOC);
                    if (!$old) throw new RuntimeException("找不到第 $rid 筆");
                    if ($old['void']) throw new RuntimeException("第 {$old['row_no']} 列已作廢，要修改請先按「恢復」。這次全部儲存沒有存任何一列。");
                    $mail = trim((string)($it['mailed_on'] ?? ''));
                    $mailDate = $mail === '' ? null : rcpt_roc_to_date($mail);
                    if ($mail !== '' && !$mailDate) throw new RuntimeException("寄出日期格式不對（例：115/11/12）：$mail");
                    $newv = ['name' => trim((string)($it['name'] ?? $old['name'])), 'zip' => trim((string)($it['zip'] ?? '')), 'city' => trim((string)($it['city'] ?? '')),
                             'address' => trim((string)($it['address'] ?? '')), 'send' => !empty($it['send']), 'mailed_on' => $mailDate,
                             'order_no' => trim((string)($it['order_no'] ?? $old['order_no'])), 'product' => $old['has_items'] ? $old['product'] : trim((string)($it['product'] ?? $old['product'])),
                             'qty' => !$old['has_items'] && isset($it['qty']) ? rcpt_qty((string)$it['qty']) : (int)$old['qty'],   // 繁英姊 11/25：數量可改；多品項的列在「品項」頁改
                             'tax_id' => rcpt_tax_id((string)($it['tax_id'] ?? $old['tax_id']))];
                    if ($newv['name'] === '') throw new RuntimeException('收件人不能空白');
                    if ($old['receipt_no'] !== null && $newv['order_no'] !== $old['order_no'] && ($t = rcpt_order_taken($d, $newv['order_no'], $rid)) !== null) throw new RuntimeException("訂單編號 {$newv['order_no']} 已經開過收據 {$t}，同一張訂單只能有一張有效收據");
                    if ($b['track'] === '食' && $newv['product'] !== $old['product'] && !rcpt_food_product_ok($d, $id, $newv['product'])) throw new RuntimeException("第 {$old['row_no']} 列品名「{$newv['product']}」不在這一批的進貨明細（或沒有單位），收據印不出來；請從清單選進貨明細裡的品名。這次全部儲存沒有存任何一列。");
                    $up->execute([$newv['name'], $newv['send'] ? 'true' : 'false', $newv['zip'], $newv['city'], $newv['address'], $newv['mailed_on'], $newv['order_no'], $newv['product'], $newv['qty'], $newv['tax_id'], $email, $rid, $id]);
                    if (!rcpt_addr_incomplete($newv['city'], $newv['address'])) $clr->execute([RCPT_ADDR_NOTE, RCPT_ADDR_NOTE_OLD, $rid, $id]);
                    $chg = [];
                    foreach ($newv as $k => $v) {
                        $o = $old[$k];
                        $os = $k === 'send' ? ($o ? 'Y' : 'N') : (string)$o; $vs = $k === 'send' ? ($v ? 'Y' : 'N') : (string)$v;
                        if ($os !== $vs) $chg[] = "$k: $os → $vs";
                    }
                    if ($chg) { rcpt_audit($d, $email, $b['status'] === 'draft' ? 'edit_row' : 'edit_after_number', $id, "row {$rid}（批次儲存）" . implode('；', $chg)); $n++; }
                }
                $d->commit();
                rc_go("./?a=batch&id=$id" . ((($_GET['addr'] ?? '') === 'bad') ? '&addr=bad' : '') . rc_pq() . "&ok=bulk$n");
            }
            if ($do === 'row') {           // 改單筆：草稿時檢核用；配號後也可改（業務單位 11/12：原本不要收據、後來來電索取），收據編號不變、留改前改後
                // 大沈 R-itsu-1112-01 複驗 P1：讀舊值、更新、稽核在同一交易；鎖順序同配號（先批次、再明細），稽核失敗整筆回滾
                $d->beginTransaction();
                $sb = $d->prepare('SELECT status, track FROM batch WHERE id = ? FOR UPDATE'); $sb->execute([$id]);
                $b = $sb->fetch(PDO::FETCH_ASSOC);
                if (!$b) throw new RuntimeException('找不到批次');
                $rid = (int)$_POST['rid'];
                $s0 = $d->prepare('SELECT name, zip, city, address, send, mailed_on, order_no, product, qty, tax_id, receipt_no, row_no, void, EXISTS (SELECT 1 FROM donation_item di WHERE di.donation_id = donation.id) AS has_items FROM donation WHERE id = ? AND batch_id = ? FOR UPDATE'); $s0->execute([$rid, $id]);
                $old = $s0->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new RuntimeException('找不到這一筆');
                if ($old['void']) throw new RuntimeException('這張收據已作廢，要修改請先按「恢復」');
                $mail = trim($_POST['mailed_on'] ?? '');
                $mailDate = $mail === '' ? null : rcpt_roc_to_date($mail);
                if ($mail !== '' && !$mailDate) throw new RuntimeException('寄出日期格式不對（例：115/11/12）');
                $newv = ['name' => trim($_POST['name'] ?? $old['name']), 'zip' => trim($_POST['zip'] ?? ''), 'city' => trim($_POST['city'] ?? ''),
                         'address' => trim($_POST['address'] ?? ''), 'send' => isset($_POST['send']), 'mailed_on' => $mailDate,
                         'order_no' => trim((string)($_POST['order_no'] ?? $old['order_no'])), 'product' => $old['has_items'] ? $old['product'] : trim((string)($_POST['product'] ?? $old['product'])),
                         'qty' => !$old['has_items'] && isset($_POST['qty']) ? rcpt_qty((string)$_POST['qty']) : (int)$old['qty'],   // 繁英姊 11/25：數量可改；多品項的列在「品項」頁改
                         'tax_id' => rcpt_tax_id((string)($_POST['tax_id'] ?? $old['tax_id']))];
                if ($newv['name'] === '') throw new RuntimeException('收件人不能空白');
                if ($old['receipt_no'] !== null && $newv['order_no'] !== $old['order_no'] && ($t = rcpt_order_taken($d, $newv['order_no'], $rid)) !== null) throw new RuntimeException("訂單編號 {$newv['order_no']} 已經開過收據 {$t}，同一張訂單只能有一張有效收據");
                if ($b['track'] === '食' && $newv['product'] !== $old['product'] && !rcpt_food_product_ok($d, $id, $newv['product'])) throw new RuntimeException("第 {$old['row_no']} 列品名「{$newv['product']}」不在這一批的進貨明細（或沒有單位），收據印不出來；請從清單選進貨明細裡的品名。");
                $d->prepare('UPDATE donation SET name = ?, send = ?, zip = ?, city = ?, address = ?, mailed_on = ?, order_no = ?, product = ?, qty = ?, tax_id = ?, updated_by = ?, updated_at = now() WHERE id = ? AND batch_id = ?')
                  ->execute([$newv['name'], $newv['send'] ? 'true' : 'false', $newv['zip'], $newv['city'], $newv['address'], $newv['mailed_on'], $newv['order_no'], $newv['product'], $newv['qty'], $newv['tax_id'], $email, $rid, $id]);
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
                rc_go("./?a=batch&id=$id" . ((int)($_GET['only'] ?? 0) ? '&only=' . $rid : '') . ((($_GET['addr'] ?? '') === 'bad') ? '&addr=bad' : '') . rc_pq() . "&ok=row#r$rid");
            }
            // 繁英姊 11/25：刪除／作廢。未配號的列直接刪；已配號的改「作廢」，收據編號保留、不重排，不再列印
            if (in_array($do, ['row_del', 'row_void', 'row_unvoid'], true)) {
                $d->beginTransaction();
                $sb = $d->prepare('SELECT status FROM batch WHERE id = ? FOR UPDATE'); $sb->execute([$id]);
                $bst = $sb->fetchColumn();
                if ($bst === false) throw new RuntimeException('找不到批次');
                $rid = (int)($_POST['rid'] ?? 0);
                $s0 = $d->prepare('SELECT row_no, receipt_no, order_no, name, void FROM donation WHERE id = ? AND batch_id = ? FOR UPDATE'); $s0->execute([$rid, $id]);
                $old = $s0->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new RuntimeException('找不到這一筆');
                if ($do === 'row_del') {
                    if ($old['receipt_no'] !== null) throw new RuntimeException('這一筆已經有收據編號，不能刪除；請改用「作廢」');
                    $d->prepare('DELETE FROM donation WHERE id = ? AND batch_id = ?')->execute([$rid, $id]);
                    rcpt_audit($d, $email, 'del_row', $id, "row $rid 第 {$old['row_no']} 列刪除");
                    $ok = 'deleted';
                } elseif ($do === 'row_void') {
                    if ($old['receipt_no'] === null) throw new RuntimeException('這一筆還沒有收據編號，直接刪除即可');
                    if ($old['void']) throw new RuntimeException('這張收據已經作廢了');
                    $d->prepare('UPDATE donation SET void = true, updated_by = ?, updated_at = now() WHERE id = ? AND batch_id = ?')->execute([$email, $rid, $id]);
                    rcpt_audit($d, $email, 'void', $id, "row $rid {$old['receipt_no']} 作廢");
                    $ok = 'voided';
                } else {
                    if (!$old['void']) throw new RuntimeException('這張收據沒有作廢');
                    if (($t = rcpt_order_taken($d, (string)$old['order_no'], $rid)) !== null) throw new RuntimeException("訂單編號 {$old['order_no']} 已經另外開了收據 {$t}，同一張訂單只能有一張有效收據，所以不能恢復");
                    $d->prepare('UPDATE donation SET void = false, updated_by = ?, updated_at = now() WHERE id = ? AND batch_id = ?')->execute([$email, $rid, $id]);
                    rcpt_audit($d, $email, 'unvoid', $id, "row $rid {$old['receipt_no']} 恢復");
                    $ok = 'unvoided';
                }
                $d->commit();
                rc_go("./?a=batch&id=$id" . ((($_GET['addr'] ?? '') === 'bad') ? '&addr=bad' : '') . rc_pq() . "&ok=$ok" . ($do === 'row_del' ? '' : "#r$rid"));
            }
            if ($do === 'items_save') {    // 繁英姊 11/25：食字軌一張收據列多個品項（草稿、已配號都可改，收據編號不變）
                $prods = (array)($_POST['product'] ?? []); $qtys = (array)($_POST['qty'] ?? []);
                $list = [];
                foreach ($prods as $i => $p) {
                    $p = trim((string)$p); $qRaw = trim((string)($qtys[$i] ?? ''));
                    if ($p === '' && $qRaw === '') continue;   // 空白列不存
                    if ($p === '') throw new RuntimeException('第 ' . ($i + 1) . ' 項有數量但沒有品名');
                    $list[] = ['product' => $p, 'qty' => rcpt_qty($qRaw)];
                }
                if (!$list) throw new RuntimeException('至少要有一個品項');
                if (count($list) > 50) throw new RuntimeException('一張收據最多 50 個品項');
                $d->beginTransaction();   // 鎖順序同配號、改單筆：先批次、再明細
                $sb = $d->prepare('SELECT status, track FROM batch WHERE id = ? FOR UPDATE'); $sb->execute([$id]);
                $b = $sb->fetch(PDO::FETCH_ASSOC);
                if (!$b) throw new RuntimeException('找不到批次');
                if ($b['track'] !== '食') throw new RuntimeException('只有食字軌收據可以列多個品項');
                $rid = (int)($_POST['rid'] ?? 0);
                $s0 = $d->prepare('SELECT * FROM donation WHERE id = ? AND batch_id = ? FOR UPDATE'); $s0->execute([$rid, $id]);
                $old = $s0->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new RuntimeException('找不到這一筆');
                if ($old['void']) throw new RuntimeException('這張收據已作廢，要修改請先按「恢復」');
                foreach ($list as $it) if (!rcpt_food_product_ok($d, $id, $it['product'])) throw new RuntimeException("品名「{$it['product']}」不在這一批的進貨明細（或沒有單位），收據印不出來；請從清單選進貨明細裡的品名。");
                $before = rcpt_items_text(rcpt_row_items($old, rcpt_items($d, [$rid])));
                $d->prepare('DELETE FROM donation_item WHERE donation_id = ?')->execute([$rid]);
                if (count($list) > 1) {
                    $ins = $d->prepare('INSERT INTO donation_item (donation_id, product, qty, sort) VALUES (?,?,?,?)');
                    foreach ($list as $k => $it) $ins->execute([$rid, $it['product'], $it['qty'], $k]);
                }   // 只剩一項就回到單一品項（存在 donation 本身），和匯入的資料一樣
                $d->prepare('UPDATE donation SET product = ?, qty = ?, updated_by = ?, updated_at = now() WHERE id = ? AND batch_id = ?')
                  ->execute([$list[0]['product'], $list[0]['qty'], $email, $rid, $id]);
                $after = rcpt_items_text($list);
                if ($before !== $after) rcpt_audit($d, $email, $b['status'] === 'draft' ? 'edit_items' : 'edit_items_after_number', $id, "row $rid 品項：$before → $after");
                $d->commit();
                rc_go("./?a=batch&id=$id&ok=items#r$rid");
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
            if ($do === 'letter_save') {   // 設定頁：給捐贈者的信（列印一律套用；繁英姊 11/25）
                $v = trim(str_replace("\r\n", "\n", (string)($_POST['letter'] ?? '')));
                $d->beginTransaction();
                $old = rcpt_letter($d);
                $d->prepare("INSERT INTO settings (k, v) VALUES ('letter', ?) ON CONFLICT (k) DO UPDATE SET v = EXCLUDED.v")->execute([$v]);
                rcpt_audit($d, $email, 'letter_save', null, mb_strlen($old) . ' 字 → ' . mb_strlen($v) . ' 字');
                $d->commit();
                header('Location: ./?a=settings&ok=letter#letter'); exit;
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
if ($refill === null && isset($pdfRefill) && $err === '') { $refill = $pdfRefill; $a = 'new'; }   // 進貨單 PDF 帶入
$refillOne = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'add_row' && $err !== '') ? $_POST : null;
// 11/23 大沈審查：匯入出錯回頁時保留使用者選的匯入方式（原本跳回「取代」，換對的檔直接重送會把原清單蓋掉）；初次進頁仍預設取代
$importMode = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'import' && $err !== '' && ($_POST['mode'] ?? '') === 'append') ? 'append' : 'replace';
$okMsg = ['saved' => '已儲存。', 'imported' => '已匯入，請檢核下面的清單，確認無誤後再配號。', 'appended' => '已追加匯入，接在原清單後面。請檢核下面的清單，確認無誤後再配號。' . ((int)($_GET['dupprev'] ?? 0) ? '有 ' . (int)$_GET['dupprev'] . ' 筆訂單編號和先前匯入的重複，已在「檢核說明」標出，請確認是否同一個檔匯了兩次。' : ''), 'row' => '已更新這一筆。', 'added' => '已新增一筆（單筆輸入）。還要加就繼續填，全部好了再按「配號」。', 'assigned' => '已配號。', 'mailed' => '已標記寄出日期。', 'opt' => '設定已儲存。', 'letter' => '給捐贈者的信已儲存，之後列印的收據都套用這個版本。', 'optoff' => '已停用：之後不會出現在選單，舊批次不受影響。', 'opton' => '已重新啟用。', 'deleted' => '已刪除這一筆。', 'voided' => '已作廢這張收據：編號保留、不再列印。', 'unvoided' => '已恢復這張收據。', 'items' => '已更新品項。'][$_GET['ok'] ?? ''] ?? '';
if (preg_match('/^bulk(\d+)$/', (string)($_GET['ok'] ?? ''), $mm)) $okMsg = "已一次儲存 {$mm[1]} 筆修改。";
if (preg_match('/^nosend(\d+)$/', (string)($_GET['ok'] ?? ''), $mm)) $okMsg = "已把 {$mm[1]} 筆地址不完整的改為不寄；確認地址沒問題的，可在該列勾「寄發」再按存。";
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
$head = ['list' => ['收據清單', '每次收貨建一個批次：匯入名單、檢核、配號、列印收據。'],
         'new' => [$a === 'edit' ? '編輯批次' : (isset($_GET['single']) ? '單筆輸入' : '新增批次'), isset($_GET['single']) ? '第一步：填收貨資料並儲存。第二步：在批次頁「單筆輸入」填捐物人，可連續加好幾位。' : '第一步：填收貨資料並儲存。第二步：在批次頁匯入名單（.xlsx）。'],
         'search' => ['查詢捐贈人', '用姓名、電話、收據編號或訂單編號找，可看收據或補印。'],
         'settings' => ['設定', '來源、供應商、套餐每套金額：可新增、修改、停用。停用後不出現在選單，舊批次保留原本的值。']][$tab];
if ($a === 'batch') $head = ['批次明細', '檢核名單、配號、列印收據。'];
if ($a === 'edit') $head = ['編輯批次', '已配號的批次只能補不影響編號的欄位。'];
if ($a === 'items') $head = ['收據品項', '一張食字軌收據可以列好幾個品項；收據編號不變。'];
?>
<div class="rc-head">
  <div><h1><?= h($head[0]) ?></h1><p><?= h($head[1]) ?></p></div>
  <div class="chips"><?php if (itdb_web_test()): ?><span class="chip test">測試環境・假資料</span><?php endif; ?>
  <span class="chip <?= $canEdit ? 'ok' : '' ?>"><?= $canEdit ? '開收據權限' : '唯讀（只能查看）' ?></span></div>
</div>
<nav class="tabs" aria-label="收據系統功能">
  <!-- 11/24 繁英姊：功能表改名「📋 收據清單｜➕ 匯入名單｜➕ 新增收據｜🔍 查詢捐贈人｜⚙️ 設定」，較容易明白 -->
  <a href="./"<?= $tab === 'list' && $a !== 'batch' ? ' aria-current="page"' : '' ?>>📋 收據清單</a>
  <?php if ($canEdit): ?><a href="./?a=new"<?= $a === 'new' && !isset($_GET['single']) ? ' aria-current="page"' : '' ?>>➕ 匯入名單</a><a href="./?a=new&amp;single=1"<?= $a === 'new' && isset($_GET['single']) ? ' aria-current="page"' : '' ?>>➕ 新增收據</a><?php endif; ?>
  <a href="./?a=search"<?= $tab === 'search' ? ' aria-current="page"' : '' ?>>🔍 查詢捐贈人</a>
  <?php if ($canEdit): ?><a href="./?a=settings"<?= $tab === 'settings' ? ' aria-current="page"' : '' ?>>⚙️ 設定</a><?php endif; ?>
</nav>
<?php if ($err): ?><div class="alert err" role="alert"><?= h($err) ?></div><?php endif; ?>
<?php if ($okMsg): ?><div class="alert ok" role="status"><?= h($okMsg) ?></div><?php endif; ?>

<?php if ($a === 'list'):
    // 大沈審 8bdd23b：清單與批次明細同一口徑——只算未作廢的列；有品項就加總各品項，舊的單品項列用 donation.qty
    $rows = $d->query("SELECT b.*, (SELECT COUNT(*) FROM donation WHERE batch_id=b.id AND NOT void) n,
        (SELECT COALESCE(SUM(COALESCE((SELECT SUM(di.qty) FROM donation_item di WHERE di.donation_id = dn.id), dn.qty)), 0) FROM donation dn WHERE dn.batch_id=b.id AND NOT dn.void) q
        FROM batch b ORDER BY in_date DESC, id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); ?>
<div class="panel flush"><div class="tbl">
<table class="rows"><tr class="hd"><th>收貨日期</th><th>來源</th><th>進貨單號</th><th class="n">筆數</th><th class="n">數量</th><th>收據起訖</th><th>狀態</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td data-l="收貨日期" class="mono"><?= h(rcpt_date_to_roc($r['in_date'])) ?></td><td data-l="來源"><?= h($r['source']) ?> <span class="chip"><?= h($r['track']) ?></span></td>
<td data-l="進貨單號"><a href="./?a=batch&id=<?= (int)$r['id'] ?>"><?= h($r['po_no'] ?: '（未填單號）') ?></a></td><td class="n" data-l="筆數"><?= (int)$r['n'] ?></td><td class="n" data-l="數量"><?= (int)$r['q'] ?></td>
<td data-l="收據起訖" class="mono"><?= $r['first_no'] ? h(rcpt_range_text($r['first_no'], $r['last_no'])) : '—' ?></td><td data-l="狀態"><?= $r['status'] === 'draft' ? '<span class="chip draft">草稿</span>' : '<span class="chip ok">已配號</span>' ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">還沒有批次。<?php if ($canEdit): ?>按上方「➕ 匯入名單」開始：先填收貨資料，儲存後在批次頁匯入名單。<?php endif; ?></td></tr><?php endif; ?>
</table></div></div>

<?php elseif ($a === 'new' || $a === 'edit'):
    need_edit($canEdit);
    $b = $a === 'edit' ? batch_get($d, $id) : null;
    $items = $b ? $d->query('SELECT * FROM batch_item WHERE batch_id = ' . (int)$b['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) : [];
    $locked = $b && $b['status'] !== 'draft';
    $fv = function (string $k, $dflt = '') use ($refill, $b) { return $refill !== null && array_key_exists($k, $refill) ? $refill[$k] : ($b[$k] ?? $dflt); };
    // 新增批次：信件與每套內含帶入最近一批同字軌（業務單位 11/12：信件每兩個月 1、3、5、7、9、11 月統一更新）
    $prevContents = '';
    if (!$b) {
        // 信件：物、食兩個字軌用同一封（業務單位 11/12）→ 取最近一批（不分字軌）有填信的；每套內含只有物用，取最近一批物
        $prevContents = (string)$d->query("SELECT contents FROM batch WHERE track = '物' AND contents <> '' ORDER BY in_date DESC, id DESC LIMIT 1")->fetchColumn();
    } ?>
<?php if ($locked): ?><div class="alert warn">已配號：只能補進貨單號、供應商、經手人與每套內含（給捐贈者的信在「⚙️ 設定」統一維護）。</div><?php endif; ?>
<?php if (!$b): ?>
<section class="panel"><h2>匯入文中進貨單 <small>選文中列印的進貨單 PDF，自動帶入下面的收貨資料；不匯入就直接手動填。</small></h2>
<form method="post" action="./?a=new" enctype="multipart/form-data" onsubmit="return rcBusy(this, '讀取進貨單中…')">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="pdf_prefill">
<div class="actions" style="margin-top:0"><input type="file" name="file" accept=".pdf,application/pdf" required aria-label="選擇進貨單 PDF"> <button type="submit" class="ghost">讀取並帶入</button></div>
</form>
<?php if (isset($pdfInfo)): ?><div class="alert ok" role="status">已從「<?= h($pdfInfo['file']) ?>」帶入：進貨單號、收貨日期、產品明細 <?= (int)$pdfInfo['n'] ?> 項<?= $refill['supplier_code'] !== '' ? '、供應商' : '' ?>，以及收據抬頭／收件人／寄件地址（儲存後帶到單筆輸入）。請核對後按「儲存」。</div>
<?php if ($pdfInfo['track'] !== ''): ?><div class="alert <?= $refill['source'] === '' ? 'warn' : 'ok' ?>">收據編號 <?= h($pdfInfo['receipt_no']) ?> 是「<?= h($pdfInfo['track']) ?>」字軌<?= $refill['source'] === '' ? '：請在「來源」選一個' . h($pdfInfo['track']) . '字軌的來源' . ($pdfInfo['srcs'] ? '（' . h(implode('、', $pdfInfo['srcs'])) . '）' : '（設定裡還沒有' . h($pdfInfo['track']) . '字軌的來源，請先新增）') : '，已選好來源，可以改選' ?>。</div><?php endif; ?>
<?php if ($pdfInfo['sup_missing'] !== ''): ?><div class="alert warn">供應商「<?= h($pdfInfo['sup_missing']) ?>」不在清單，請到「設定」新增後再選（或先空白，之後補）。</div><?php endif; ?>
<?php endif; ?>
</section>
<?php endif; ?>
<form method="post" action="./?a=<?= $b ? 'edit&id=' . (int)$b['id'] : 'new' ?>">
<?php foreach (['pf_name', 'pf_attn', 'pf_addr'] as $pk) if (!$b && ($refill[$pk] ?? '') !== ''): ?><input type="hidden" name="<?= $pk ?>" value="<?= h((string)$refill[$pk]) ?>"><?php endif; ?>
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
<p class="hint">給捐贈者的信改在「⚙️ 設定」統一維護，列印收據時一律套用設定裡的版本。</p>
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
    // 大沈審 4c2dfc0：單列存／全部儲存被擋時，畫面上保留使用者剛輸入的值（資料庫沒變），修正後再存
    $rowRefill = [];
    if ($err !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (($_POST['do'] ?? '') === 'row') $rowRefill[(int)($_POST['rid'] ?? 0)] = $_POST + ['send' => null];
        elseif (($_POST['do'] ?? '') === 'rows_bulk') foreach ((array)json_decode((string)($_POST['rows'] ?? ''), true) as $it) if (is_array($it)) $rowRefill[(int)($it['rid'] ?? 0)] = $it;
    }
    // 大沈複審 4744424：欄位初始值（defaultValue）維持資料庫值，回填值由頁尾腳本填進欄位 →
    // changed() 會把它們算成「未儲存的修改」，換頁會提醒、全部儲存也會一起送出
    $rfJs = [];
    foreach ($rowRefill as $rid => $rf) {
        if ($rid <= 0) continue;
        $o = [];
        foreach (['name', 'zip', 'city', 'address', 'order_no', 'product', 'qty', 'tax_id', 'mailed_on'] as $k) if (isset($rf[$k])) $o[$k] = (string)$rf[$k];
        if (array_key_exists('send', $rf)) $o['send'] = !empty($rf['send']);
        $rfJs[$rid] = $o;
    }
    $dup = $rows ? rcpt_dup_orders($d, array_column($rows, 'order_no'), $id) : [];
    $inb = rcpt_dup_in_batch($d, $id);
    $fmiss = $b['track'] === '食' ? array_column(rcpt_food_missing($d, $id), 1, 0) : [];   // 食字軌缺品名／單位的列（列號 => 說明）
    // 繁英姊 11/25：作廢的不算進筆數、數量與寄發；多品項的列數量以品項加總
    $rowItems = $b['track'] === '食' && $rows ? rcpt_items($d, array_column($rows, 'id')) : [];
    $live = array_values(array_filter($rows, fn($r) => !$r['void'])); $nVoid = count($rows) - count($live);
    $qty = array_sum(array_map(fn($r) => array_sum(array_column(rcpt_row_items($r, $rowItems), 'qty')), $live));
    $nY = count(array_filter($live, fn($r) => $r['send'])); $itemQty = $items ? max(array_map(fn($i) => (float)$i['qty'], $items)) : 0; ?>
<section class="panel">
<h2><?= h($b['source']) ?> <span class="chip"><?= h($b['track']) ?></span> <?= $b['status'] === 'draft' ? '<span class="chip draft">草稿</span>' : '<span class="chip ok">已配號</span>' ?>
<small>收貨 <?= h(rcpt_date_to_roc($b['in_date'])) ?>｜進貨單 <?= h($b['po_no'] ?: '未填') ?></small></h2>
<div class="stats">
  <div class="stat"><small>筆數</small><b><?= count($live) ?></b><?php if ($nVoid): ?><em><span class="chip warn">另作廢 <?= $nVoid ?></span></em><?php endif; ?></div>
  <div class="stat"><small>數量合計</small><b><?= $qty ?></b><?php if ($itemQty): ?><em><?= abs($qty - $itemQty) > 0.001 ? '<span class="chip warn">進貨單 ' . (0 + $itemQty) . '，不符</span>' : '<span class="chip ok">與進貨單相符</span>' ?></em><?php endif; ?></div>
  <div class="stat"><small>寄發</small><b><?= $nY ?></b></div>
  <div class="stat"><small>不寄</small><b><?= count($live) - $nY ?></b></div>
</div>
<?php if ($dup): ?><div class="alert warn">訂單編號已開過收據 <?= count($dup) ?> 筆（下方黃底）。</div><?php endif; ?>
<?php if ($rowRefill): ?><div class="alert warn" role="status">剛才的修改沒有存進去（原因見上方）；畫面保留了您輸入的內容，修正後按該列「存」。</div><?php endif; ?>
<?php if ($fmiss): ?><div class="alert warn">食字軌品名／單位不完整 <?= count($fmiss) ?> 列，補齊前不能配號。</div><?php endif; ?>
<?php // 繁英姊 11/20：只列「原本要寄、地址卻不完整」的——寄發仍勾著，或是系統因地址自動改成不寄（檢核說明有那句）；匯入時就是不寄的不用再校對
$addrBadIds = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => rcpt_addr_incomplete((string)$r['city'], (string)$r['address'])
    && ($r['send'] || str_contains((string)$r['check_note'], RCPT_ADDR_NOTE) || str_contains((string)$r['check_note'], RCPT_ADDR_NOTE_OLD))));
if ($addrBadIds): ?><div class="alert warn">地址不完整或有誤 <?= count($addrBadIds) ?> 筆（缺路／街／大道、門牌號，或有重複字，會被退件；下方紅底，匯入時已自動設為不寄）。<?= ($_GET['addr'] ?? '') === 'bad' ? '<a href="./?a=batch&amp;id=' . $id . '">顯示整批</a>' : '<a href="./?a=batch&amp;id=' . $id . '&amp;addr=bad">只看這些</a>' ?>
<?php $addrBadSend = count(array_filter($rows, fn($r) => $r['send'] && in_array((int)$r['id'], $addrBadIds, true))); if ($canEdit && $addrBadSend): ?>
<form method="post" action="./?a=batch&amp;id=<?= $id ?>" style="display:inline" onsubmit="return confirm('把這 <?= $addrBadSend ?> 筆地址不完整、目前仍勾「寄發」的，全部改成不寄？之後確認地址沒問題的，可以再逐筆勾回。')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="addr_bad_nosend"> <button type="submit" class="btn ghost">這 <?= $addrBadSend ?> 筆先全部改為不寄</button></form>
<?php endif; ?></div><?php endif; ?>
<?php if ($inb): ?><div class="alert warn">名單內訂單編號重複 <?= count($inb) ?> 個（下方黃底）。</div><?php endif; ?>
<?php if ($b['status'] !== 'draft'): ?>
<div class="alert ok">收據起訖（請會計填入文中進貨單「報關單號」）：<b class="mono"><?= h(rcpt_range_text($b['first_no'], $b['last_no'])) ?></b></div>
<div class="actions"><a class="btn" href="./print.php?id=<?= $id ?>" onclick="rcBusyLink('開啟列印頁面中，張數多時要等一下…')">列印收據</a><?php if ($canEdit): ?><a class="btn ghost" href="./?a=edit&id=<?= $id ?>">編輯批次資料</a><?php endif; ?></div>
<?php elseif ($canEdit): ?>
<div class="actions"><a class="btn ghost" href="./?a=edit&id=<?= $id ?>">編輯批次資料</a></div>
<?php endif; ?>
</section>
<?php if ($b['status'] === 'draft' && $canEdit): ?>
<?php $o1 = $refillOne ?? [];
    $pfData = null;
    if (!$refillOne && !empty($_GET['pf'])) {   // 只讀「這一批、目前登入者、1 小時內」存的；別人或別批讀不到
        $sp = $d->prepare("SELECT data FROM pdf_prefill WHERE batch_id = ? AND email = ? AND created_at > now() - interval '1 hour'");
        $sp->execute([$id, $email]);
        $pfData = json_decode((string)$sp->fetchColumn(), true) ?: null;
    }
    $pfOn = is_array($pfData) && trim((string)($pfData['name'] ?? '')) !== '';
    if ($pfOn) {   // 進貨單 PDF 帶入的捐贈者：收據抬頭 → 捐物人；寄件地址拆成縣市區＋地址（拆不出來就整段放地址）
        $pa = trim((string)($pfData['addr'] ?? ''));
        $pm = preg_match('/^(\d{3,6})?\s*(\S{2,3}[縣市]\S{1,4}?[區鄉鎮市])(.+)$/u', $pa, $pq) ? $pq : null;
        $o1 = ['name' => trim((string)$pfData['name']), 'zip' => $pm[1] ?? '', 'city' => $pm[2] ?? '', 'address' => $pm ? trim($pm[3]) : $pa, 'send' => '1'];
    }
    $ov = fn($k, $dflt = '') => h((string)($o1[$k] ?? $dflt)); ?>
<section class="panel" id="single"><h2>單筆輸入 <small>臨時來捐、電話捐贈：一次填一位，可連續加好幾位</small></h2>
<?php if ($pfOn): ?><div class="alert ok" role="status">已帶入進貨單的捐贈者：收據抬頭「<?= h($o1['name']) ?>」<?= trim((string)($pfData['attn'] ?? '')) !== '' ? '、收件人「' . h(trim((string)$pfData['attn'])) . '」' : '' ?>、寄件地址。可以修改，核對後按「加入這一筆」。<?= trim((string)($pfData['attn'] ?? '')) !== '' ? '（收件人沒有獨立欄位，需要的話請寫在地址最後，例如「立德路117號 Lion Lin主任收」。）' : '' ?></div><?php endif; ?>
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
<div><label for="s_tax">統一編號（公司行號，沒有可空白）</label><input type="text" id="s_tax" name="tax_id" value="<?= $ov('tax_id') ?>" inputmode="numeric" placeholder="8 位數字"></div>
</div>
<p><label style="display:inline-flex;align-items:center;gap:8px;margin:12px 0 0"><input type="checkbox" name="send" value="1"<?= $o1 && !isset($o1['send']) ? '' : ' checked' ?>> 寄收據</label></p>
<div class="actions"><button type="submit">加入這一筆</button><span class="hint">加完會出現在下方清單，可再修改；全部好了再按「配號」。</span></div>
</form>
</section>
<section class="panel"><h2>匯入名單 <small>.xlsx；平台原始檔或「地址整理」後的檔都可以。</small></h2>
<form method="post" action="./?a=batch&id=<?= $id ?>" enctype="multipart/form-data" onsubmit="return rcBusy(this, '匯入中，請稍候…（名單多時要幾分鐘，一萬筆約 5 分鐘內）')">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="import">
<?php if ($rows): /* 11/23 繁英姊（熊哥同意）：一張進貨單分兩個月份的檔時可以追加 */ ?>
<fieldset class="rc-mode" style="border:0;padding:0;margin:0 0 6px"><legend class="hint" style="padding:0">這一批已經有 <?= count($rows) ?> 筆，這次要：</legend>
<label><input type="radio" name="mode" value="replace"<?= $importMode === 'replace' ? ' checked' : '' ?>> 取代目前清單（重新匯入，包含單筆輸入的都會換掉）</label><br>
<label><input type="radio" name="mode" value="append"<?= $importMode === 'append' ? ' checked' : '' ?>> 追加到目前清單後面（例：同一張進貨單的第二個月份檔）</label></fieldset>
<?php endif; ?>
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
<?php if ($canEdit && $rows): ?>
<form id="bulkForm" method="post" action="./?a=batch&amp;id=<?= $id ?><?= ($_GET['addr'] ?? '') === 'bad' ? '&amp;addr=bad' : '' ?><?= h(rc_pq()) ?>" class="actions" style="margin:0 0 8px">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="rows_bulk"><input type="hidden" name="rows" id="bulkRows">
<button type="submit" id="bulkBtn">全部儲存（只存有改過的列）</button><span class="hint" id="bulkHint">可以連續改好幾列的地址、勾寄發，最後按這裡一次存（只存這一頁）。</span>
</form>
<script>
(function(){
  var f=document.getElementById('bulkForm'); if(!f) return;
  function changed(){
    var out=[];
    document.querySelectorAll('form[id^="f"] input[name="do"][value="row"]').forEach(function(d){
      var fm=d.form, id=fm.id, rid=fm.querySelector('input[name="rid"]').value, row={rid:rid}, diff=false;
      document.querySelectorAll('[form="'+id+'"]').forEach(function(el){
        if(el.type==='checkbox'){ row[el.name]=el.checked; if(el.checked!==el.defaultChecked) diff=true; }
        else { row[el.name]=el.value; if(el.value!==el.defaultValue) diff=true; }
      });
      if(diff) out.push(row);
    });
    return out;
  }
  // 11/24 大沈審查：換頁前檢查本頁有沒有未儲存的修改，有就先問（取消＝留在本頁儲存）
  window.rcPageGo=function(){var n=changed().length; return !n || confirm('這一頁還有 '+n+' 列改了沒儲存，換頁會放棄這些修改。\n\n按「確定」放棄修改並換頁；按「取消」留在本頁，先按「全部儲存」。');};
  document.addEventListener('input',function(){var n=changed().length;document.getElementById('bulkHint').textContent=n?('目前改了 '+n+' 列，按左邊一次儲存。'):'可以連續改好幾列的地址、勾寄發，最後按這裡一次存。';});
  document.addEventListener('change',function(){var n=changed().length;document.getElementById('bulkHint').textContent=n?('目前改了 '+n+' 列，按左邊一次儲存。'):'可以連續改好幾列的地址、勾寄發，最後按這裡一次存。';});
  f.addEventListener('submit',function(e){var rows=changed(); if(!rows.length){e.preventDefault();alert('還沒有改過任何一列');return;} document.getElementById('bulkRows').value=JSON.stringify(rows);});
})();
</script>
<?php endif; ?>
<?php // 查詢結果按「修改」只顯示那一筆（繁英姊 11/19：「按修改結果出現所有的資料，而不是這個人的資料」）；統計仍以整批計
$only = (int)($_GET['only'] ?? 0);
$shown = $only ? array_values(array_filter($rows, fn($r) => (int)$r['id'] === $only)) : $rows;
if (($_GET['addr'] ?? '') === 'bad') $shown = array_values(array_filter($shown, fn($r) => in_array((int)$r['id'], $addrBadIds, true)));   // 繁英姊 11/20：篩選標紅的
// 繁英姊 11/24：5,089 筆的批次一次畫出全部，頁面太慢、看不出有沒有載完 → 明細表每頁 RCPT_PAGE 筆；上方統計與警示仍以整批計
$pgTotal = count($shown); $pgN = max(1, (int)ceil($pgTotal / RCPT_PAGE)); $pg = min($pgN, max(1, (int)($_GET['p'] ?? 1)));
$shown = array_slice($shown, ($pg - 1) * RCPT_PAGE, RCPT_PAGE);
?>
<?php if ($pgN > 1): $pgBase = './?a=batch&amp;id=' . $id . (($_GET['addr'] ?? '') === 'bad' ? '&amp;addr=bad' : ''); ?>
<nav class="actions pager" aria-label="明細分頁" style="margin:8px 0;flex-wrap:wrap">第 <?= ($pg - 1) * RCPT_PAGE + 1 ?>–<?= min($pgTotal, $pg * RCPT_PAGE) ?> 筆，共 <?= $pgTotal ?> 筆
<?php if ($pg > 1): ?><a class="btn ghost sm" href="<?= $pgBase ?>&amp;p=<?= $pg - 1 ?>" onclick="return !window.rcPageGo || rcPageGo()">上一頁</a><?php endif; ?>
<?php for ($i = 1; $i <= $pgN; $i++): ?><?= $i === $pg ? '<b class="btn sm" aria-current="page">' . $i . '</b>' : '<a class="btn ghost sm" href="' . $pgBase . '&amp;p=' . $i . '" onclick="return !window.rcPageGo || rcPageGo()">' . $i . '</a>' ?> <?php endfor; ?>
<?php if ($pg < $pgN): ?><a class="btn ghost sm" href="<?= $pgBase ?>&amp;p=<?= $pg + 1 ?>" onclick="return !window.rcPageGo || rcPageGo()">下一頁</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php if ($canEdit && $b['track'] === '食'): ?><datalist id="rc_products"><?php foreach ($items as $it): ?><option value="<?= h($it['name']) ?>"><?php endforeach; ?></datalist><?php endif; ?>
<div class="panel flush"><div class="tbl">
<table class="rows"><tr class="hd"><th>#</th><th>收據編號</th><th>訂單編號</th><th>收件人</th><th>統編</th><th>郵遞區號／縣市區／地址</th><?php if ($b['track'] === '食'): ?><th>品名</th><?php endif; ?><th class="n">數量</th><th>寄發</th><th>檢核說明</th><th>寄出日期</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr>
<?php foreach ($shown as $r): $isDup = isset($dup[$r['order_no']]) || isset($inb[$r['order_no']]); $fid = 'f' . (int)$r['id'];
    $editRow = $canEdit && !$r['void']; $rIt = $rowItems[(int)$r['id']] ?? null;   // 繁英姊 11/25：作廢的列不能改；多品項的列在「品項」頁改
    $itLink = $b['track'] === '食' && ($rIt || $editRow) ? '<a href="./?a=items&amp;id=' . $id . '&amp;rid=' . (int)$r['id'] . '">' . ($rIt ? '品項（' . count($rIt) . '）' : '＋品項') . '</a>' : ''; ?>
<tr id="r<?= (int)$r['id'] ?>" class="<?= $r['void'] ? 'void' : ($isDup ? 'dup' : (in_array((int)$r['id'], $addrBadIds, true) ? 'addrbad' : (!$r['send'] ? 'bad' : ''))) ?>">
<td data-l="#"><?= (int)$r['row_no'] ?></td><td data-l="收據編號" class="mono"><?= h($r['receipt_no']) ?><?= $r['void'] ? ' <span class="chip warn">作廢</span>' : '' ?></td><td data-l="訂單編號" class="mono"><?php if ($editRow): ?><input form="<?= $fid ?>" type="text" name="order_no" value="<?= h($r['order_no']) ?>" style="width:9em" aria-label="訂單編號" placeholder="可補登"><?php else: ?><?= h($r['order_no']) ?><?php endif; ?><?= isset($dup[$r['order_no']]) ? '<br><small>已開：' . h($dup[$r['order_no']][1]) . '</small>' : '' ?><?= isset($inb[$r['order_no']]) ? '<br><small>名單內重複</small>' : '' ?></td>
<?php if ($editRow): ?>
<td data-l="收件人"><input form="<?= $fid ?>" type="text" name="name" value="<?= h($r['name']) ?>" style="width:7em" aria-label="收件人"></td>
<td data-l="統編"><input form="<?= $fid ?>" type="text" name="tax_id" value="<?= h($r['tax_id']) ?>" style="width:6.5em" inputmode="numeric" aria-label="統一編號" placeholder="8 碼"></td>
<td data-l="地址" class="addr"><input form="<?= $fid ?>" type="text" name="zip" value="<?= h($r['zip']) ?>" style="width:5em" aria-label="郵遞區號"> <input form="<?= $fid ?>" type="text" name="city" value="<?= h($r['city']) ?>" style="width:9em" aria-label="縣市區"> <input form="<?= $fid ?>" type="text" name="address" value="<?= h($r['address']) ?>" style="width:16em" aria-label="地址"><br><small><?= h($r['raw_addr']) ?></small></td>
<?php if ($b['track'] === '食'): ?><td data-l="品名" class="<?= isset($fmiss[(int)$r['row_no']]) ? 'bad' : '' ?>"><?php if ($rIt): ?><?= h(rcpt_items_text($rIt)) ?><?php else: ?><input form="<?= $fid ?>" type="text" name="product" value="<?= h($r['product']) ?>" list="rc_products" style="width:10em" aria-label="品名" placeholder="可補登"><?php endif; ?> <?= $itLink ?><?= isset($fmiss[(int)$r['row_no']]) ? '<br><small>' . h($fmiss[(int)$r['row_no']]) . '</small>' : '' ?></td><?php endif; ?><td data-l="數量" class="n"><?php if ($rIt): ?><small>見品項</small><?php else: ?><input form="<?= $fid ?>" type="text" name="qty" value="<?= (int)$r['qty'] ?>" inputmode="numeric" style="width:4.5em" aria-label="數量"><?php endif; ?></td><td data-l="寄發"><input form="<?= $fid ?>" type="checkbox" name="send" <?= $r['send'] ? 'checked' : '' ?> aria-label="寄發"></td><td data-l="檢核說明"><?= h($r['check_note']) ?></td>
<td data-l="寄出日期"><input form="<?= $fid ?>" type="text" name="mailed_on" value="<?= $r['mailed_on'] ? h(rcpt_date_to_roc($r['mailed_on'])) : '' ?>" placeholder="115/11/12" style="width:7em" aria-label="寄出日期"></td>
<td class="act"><form id="<?= $fid ?>" style="display:inline" method="post" action="./?a=batch&id=<?= $id ?><?= $only ? '&amp;only=' . $only : '' ?><?= ($_GET['addr'] ?? '') === 'bad' ? '&amp;addr=bad' : '' ?><?= h(rc_pq()) ?>"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="row"><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>"><button class="sm">存</button></form>
<?php if ($r['receipt_no']): ?><a href="./print.php?id=<?= $id ?>&one=<?= (int)$r['id'] ?>" onclick="rcBusyLink('開啟列印頁面中，張數多時要等一下…')">補印</a><?php endif; ?>
<?= rc_row_act_form($id, (int)$r['id'], $r['receipt_no'] ? 'row_void' : 'row_del', $csrf, $r['receipt_no'] ? '作廢' : '刪除',
    $r['receipt_no'] ? '作廢收據 ' . $r['receipt_no'] . '（' . $r['name'] . '）？編號保留、不重排，之後不再列印；需要時可以恢復。' : '刪除這一筆（#' . $r['row_no'] . ' ' . $r['name'] . '）？刪了就不能復原。') ?></td>
<?php else: ?>
<td data-l="收件人"><?= h($r['name']) ?></td><td data-l="統編" class="mono"><?= h($r['tax_id']) ?></td><td data-l="地址"><?= h($r['zip'] . ' ' . $r['city'] . $r['address']) ?></td><?php if ($b['track'] === '食'): ?><td data-l="品名" class="<?= isset($fmiss[(int)$r['row_no']]) ? 'bad' : '' ?>"><?= h($rIt ? rcpt_items_text($rIt) : $r['product']) ?> <?= $itLink ?><?= isset($fmiss[(int)$r['row_no']]) ? '<br><small>' . h($fmiss[(int)$r['row_no']]) . '</small>' : '' ?></td><?php endif; ?><td data-l="數量" class="n"><?= $rIt ? array_sum(array_column($rIt, 'qty')) : (int)$r['qty'] ?></td><td data-l="寄發"><?= $r['send'] ? '<span class="chip ok">寄</span>' : '<span class="chip">不寄</span>' ?></td><td data-l="檢核說明"><?= h($r['check_note']) ?></td>
<td data-l="寄出日期"><?= $r['mailed_on'] ? h(rcpt_date_to_roc($r['mailed_on'])) : '' ?></td>
<?php if ($canEdit): ?><td class="act"><?= $r['void'] ? rc_row_act_form($id, (int)$r['id'], 'row_unvoid', $csrf, '恢復', '恢復收據 ' . $r['receipt_no'] . '（' . $r['name'] . '）？恢復後會再列入列印。') : '' ?></td><?php endif; ?>
<?php endif; ?></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="12" class="empty">這一批還沒有名單<?= $canEdit && $b['status'] === 'draft' ? '，請先在上方匯入。' : '。' ?></td></tr><?php endif; ?>
</table></div></div>
<?php if ($canEdit && $rfJs): ?><script>
(function(){var rf=<?= json_encode($rfJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  Object.keys(rf).forEach(function(rid){var v=rf[rid];
    document.querySelectorAll('[form="f'+rid+'"]').forEach(function(el){if(!(el.name in v))return; if(el.type==='checkbox') el.checked=!!v[el.name]; else el.value=v[el.name];});});
  document.dispatchEvent(new Event('input'));})();
</script><?php endif; ?>
<?php if ($pgN > 1): $pgBase = './?a=batch&amp;id=' . $id . (($_GET['addr'] ?? '') === 'bad' ? '&amp;addr=bad' : ''); ?>
<nav class="actions pager" aria-label="明細分頁" style="margin:8px 0;flex-wrap:wrap">第 <?= ($pg - 1) * RCPT_PAGE + 1 ?>–<?= min($pgTotal, $pg * RCPT_PAGE) ?> 筆，共 <?= $pgTotal ?> 筆
<?php if ($pg > 1): ?><a class="btn ghost sm" href="<?= $pgBase ?>&amp;p=<?= $pg - 1 ?>" onclick="return !window.rcPageGo || rcPageGo()">上一頁</a><?php endif; ?>
<?php for ($i = 1; $i <= $pgN; $i++): ?><?= $i === $pg ? '<b class="btn sm" aria-current="page">' . $i . '</b>' : '<a class="btn ghost sm" href="' . $pgBase . '&amp;p=' . $i . '" onclick="return !window.rcPageGo || rcPageGo()">' . $i . '</a>' ?> <?php endfor; ?>
<?php if ($pg < $pgN): ?><a class="btn ghost sm" href="<?= $pgBase ?>&amp;p=<?= $pg + 1 ?>" onclick="return !window.rcPageGo || rcPageGo()">下一頁</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php } ?>

<?php elseif ($a === 'items'):   // 繁英姊 11/25：食字軌一張收據的品項（多品項）
    $b = batch_get($d, $id); $rid = (int)($_GET['rid'] ?? 0);
    $s0 = $d->prepare('SELECT * FROM donation WHERE id = ? AND batch_id = ?'); $s0->execute([$rid, $id]);
    $r = $s0->fetch(PDO::FETCH_ASSOC);
    if (!$b || !$r || $b['track'] !== '食') { echo '<div class="alert err">找不到這張食字軌收據。</div>'; } else {
    $bItems = $d->query('SELECT name, unit FROM batch_item WHERE batch_id = ' . $id . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $cur = rcpt_row_items($r, rcpt_items($d, [$rid]));
    if ($err !== '' && ($_POST['do'] ?? '') === 'items_save') {   // 存不進去時保留剛輸入的內容
        $cur = [];
        foreach ((array)($_POST['product'] ?? []) as $i => $p) $cur[] = ['product' => (string)$p, 'qty' => (string)(((array)($_POST['qty'] ?? []))[$i] ?? '')];
    }
    $cur = array_merge($cur, array_fill(0, 3, ['product' => '', 'qty' => '']));   // 多留 3 個空白列可加 ?>
<section class="panel"><h2><?= h($r['name']) ?> <small>收據 <?= h($r['receipt_no'] ?: '尚未配號') ?>｜第 <?= (int)$r['row_no'] ?> 列</small></h2>
<?php if ($r['void']): ?><div class="alert warn">這張收據已作廢，要修改請先回批次頁按「恢復」。</div><?php endif; ?>
<?php if ($canEdit && !$r['void']): ?>
<form method="post" action="./?a=items&amp;id=<?= $id ?>&amp;rid=<?= $rid ?>">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="items_save"><input type="hidden" name="rid" value="<?= $rid ?>">
<datalist id="it_products"><?php foreach ($bItems as $it): ?><option value="<?= h($it['name']) ?>"><?php endforeach; ?></datalist>
<div class="tbl"><table class="rows"><tr class="hd"><th>#</th><th>品名（要和進貨明細的品名一樣）</th><th class="n">數量</th><th>單位</th><th></th></tr>
<?php $units = array_column($bItems, 'unit', 'name'); foreach ($cur as $i => $it): ?>
<tr><td data-l="#"><?= $i + 1 ?></td><td data-l="品名"><input type="text" name="product[]" value="<?= h($it['product']) ?>" list="it_products" style="width:16em" aria-label="第 <?= $i + 1 ?> 項品名"></td>
<td data-l="數量" class="n"><input type="text" name="qty[]" value="<?= h($it['qty']) ?>" inputmode="numeric" style="width:6em" aria-label="第 <?= $i + 1 ?> 項數量"></td>
<td data-l="單位"><?= h($units[$it['product']] ?? '') ?></td><td><button type="button" class="ghost sm" onclick="var t=this.closest('tr');t.querySelectorAll('input').forEach(function(x){x.value='';})">移除</button></td></tr>
<?php endforeach; ?></table></div>
<div class="actions"><button type="submit">儲存品項</button><a class="btn ghost" href="./?a=batch&amp;id=<?= $id ?>#r<?= $rid ?>">回批次</a><span class="hint">要多加品項就填空白列，不夠可以先存再加；按「移除」會清空該列，儲存後就拿掉。</span></div>
</form>
<?php else: ?>
<div class="tbl"><table class="rows"><tr class="hd"><th>品名</th><th class="n">數量</th></tr><?php foreach (rcpt_row_items($r, rcpt_items($d, [$rid])) as $it): ?><tr><td data-l="品名"><?= h($it['product']) ?></td><td data-l="數量" class="n"><?= (int)$it['qty'] ?></td></tr><?php endforeach; ?></table></div>
<div class="actions"><a class="btn ghost" href="./?a=batch&amp;id=<?= $id ?>#r<?= $rid ?>">回批次</a></div>
<?php endif; ?>
</section>
<?php } ?>
<?php elseif ($a === 'settings'):
    need_edit($canEdit);
    $eid = (int)($_GET['e'] ?? 0); ?>
<nav class="setnav" aria-label="設定項目"><span>跳到：</span><?php foreach (RCPT_OPT_KINDS as $k => $kn): ?><a href="#<?= h($k) ?>"><?= h($kn) ?></a><?php endforeach; ?><a href="#letter">給捐贈者的信</a></nav>
<?php
    // 11/25 繁英姊：「供應商或來源多筆的時候，要找後面的功能就不好找」→ 上方跳轉列、新增欄位移到清單上方、清單超過 8 筆可篩選並限高捲動
    foreach (RCPT_OPT_KINDS as $kind => $kname):
        $opts = rcpt_options($d, $kind, false);
        $col1 = $kind === 'supplier' ? '編號' : ($kind === 'source' ? '名稱' : '金額'); ?>
<section class="panel" id="<?= h($kind) ?>"><h2><?= h($kname) ?> <small><?= count(array_filter($opts, fn($o) => $o['active'])) ?> 項啟用中</small></h2>
<form method="post" action="./?a=settings" class="actions">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="opt_save"><input type="hidden" name="kind" value="<?= h($kind) ?>">
<input type="text" name="code" placeholder="<?= $kind === 'supplier' ? '新供應商編號（例：0000036）' : ($kind === 'source' ? '新來源名稱' : '新金額（例：360）') ?>" required style="max-width:240px" aria-label="新增<?= h($kname) ?>">
<?php if ($kind === 'supplier'): ?><input type="text" name="name" placeholder="供應商名稱（例：統一超商）" required style="max-width:260px" aria-label="供應商名稱"><?php endif; ?>
<?php if ($kind === 'source'): ?><select name="extra" style="max-width:150px" aria-label="字軌"><option value="物">物（預印紙）</option><option value="食">食（白紙）</option></select><?php endif; ?>
<button>＋ 新增</button></form>
<?php $many = count($opts) > 8; if ($many): ?><input type="search" class="setfilter" data-for="tb-<?= h($kind) ?>" placeholder="輸入文字篩選<?= h($kname) ?>…" aria-label="篩選<?= h($kname) ?>"><?php endif; ?>
<div class="tbl<?= $many ? ' setlist' : '' ?>" id="tb-<?= h($kind) ?>"><table class="rows"><tr class="hd"><th><?= $col1 ?></th><?php if ($kind === 'supplier'): ?><th>名稱</th><?php endif; ?><?php if ($kind === 'source'): ?><th>字軌</th><?php endif; ?><th>狀態</th><th></th></tr>
<?php foreach ($opts as $o): $isE = $eid === (int)$o['id']; $fid = 'oe' . (int)$o['id']; ?>
<tr id="o<?= (int)$o['id'] ?>" class="<?= $o['active'] ? '' : 'bad' ?><?= $isE ? ' editing' : '' ?>">
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
<?php endforeach; if (!$opts): ?><tr><td colspan="5" class="empty">還沒有<?= h($kname) ?>，請在上面新增。</td></tr><?php endif; ?>
</table></div>

</section>
<?php endforeach; $curLetter = rcpt_letter($d); ?>
<section class="panel" id="letter"><h2>給捐贈者的信 <small>印在物字軌（預印紙）收據下半部；食字軌收據目前沒有信件欄位</small></h2>
<form method="post" action="./?a=settings#letter">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="letter_save">
<label for="f_letter">信件內容（按 Enter 換行就是新的一段，不用空行）</label><textarea id="f_letter" name="letter" rows="10"><?= h($curLetter) ?></textarea>
<p class="hint">儲存後，之後列印的物字軌收據（含重印）都套用這個版本；食字軌不印信。1、3、5、7、9、11 月換新信時在這裡改。</p>
<div class="actions"><button>儲存信件</button></div></form>
</section>
<script>(function(){document.querySelectorAll('.setfilter').forEach(function(f){var t=document.getElementById(f.dataset.for);if(!t)return;f.addEventListener('input',function(){var q=f.value.trim().toLowerCase();t.querySelectorAll('tr').forEach(function(r){if(r.classList.contains('hd'))return;if(r.classList.contains('editing')){r.hidden=false;return;}var txt=r.textContent;r.hidden=q!==''&&txt.toLowerCase().indexOf(q)<0;});});});})();</script>


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
<?php foreach ($res as $r): ?><tr><td data-l="收貨日期" class="mono"><?= h(rcpt_date_to_roc($r['in_date'])) ?></td><td data-l="來源"><?= h($r['source']) ?></td><td data-l="收據編號" class="mono"><?= h($r['receipt_no']) ?><?= $r['void'] ? ' <span class="chip warn">作廢</span>' : '' ?></td><td data-l="訂單編號" class="mono"><?= h($r['order_no']) ?></td><td data-l="姓名"><?= h($r['name']) ?></td>
<td data-l="電話"><?= h(trim($r['mobile'] . ' ' . $r['phone'])) ?></td><td data-l="地址"><?= h($r['zip'] . ' ' . $r['city'] . $r['address']) ?></td><td data-l="數量" class="n"><?= (int)$r['qty'] ?></td><td data-l="寄發"><?= $r['send'] ? '<span class="chip ok">寄</span>' : '<span class="chip">不寄</span>' ?></td><td data-l="寄出日期"><?= $r['mailed_on'] ? h(rcpt_date_to_roc($r['mailed_on'])) : '' ?></td>
<td class="act"><?php if ($canEdit): ?><a href="./?a=batch&id=<?= (int)$r['batch_id'] ?>&only=<?= (int)$r['id'] ?>#r<?= (int)$r['id'] ?>">修改</a><?php endif; ?><?php if ($r['receipt_no'] && !$r['void']): ?><a href="./print.php?id=<?= (int)$r['batch_id'] ?>&one=<?= (int)$r['id'] ?>" onclick="rcBusyLink('開啟列印頁面中，張數多時要等一下…')">看收據／補印</a><?php endif; ?></td></tr>
<?php endforeach; if (!$res): ?><tr><td colspan="11" class="empty">查無資料。</td></tr><?php endif; ?></table></div></div><?php endif; ?>
<?php endif; ?>
</main>
<?php /* 匯入／配號進行中與完成的小視窗（繁英姊 11/19：「匯入或配號時可以 popup 一個小視窗『匯入中』或『配號中』，完成時出現『完成』」） */ ?>
<dialog id="rcBusy" class="rc-dlg" aria-live="polite"><p class="rc-dlg-t"><span class="spin" aria-hidden="true"></span> <span id="rcBusyMsg">處理中…</span></p><p class="hint">請不要關閉或重新整理這個頁面，完成後會自動顯示結果。</p></dialog>
<?php $doneMsg = ['imported' => '匯入完成', 'appended' => '追加匯入完成', 'assigned' => '配號完成'][$_GET['ok'] ?? ''] ?? ''; if ($doneMsg): ?>
<dialog id="rcDone" class="rc-dlg"><p class="rc-dlg-t">✓ <?= h($doneMsg) ?></p><p><?= h($okMsg) ?></p><form method="dialog"><button autofocus>知道了</button></form></dialog>
<script>document.getElementById('rcDone').showModal()</script>
<?php endif; ?>
<script>
// 繁英姊 11/20：按「列印收據」後頁面要準備全部收據，畫面沒變化會以為當機——先跳出處理中
function rcBusyLink(msg) {
  var d = document.getElementById('rcBusy'); if (!d) return true;
  document.getElementById('rcBusyMsg').textContent = msg;
  d.addEventListener('cancel', function (e) { e.preventDefault(); });
  if (d.showModal) d.showModal();
  return true;
}
addEventListener('pageshow', function () { var d = document.getElementById('rcBusy'); if (d && d.open) d.close(); });   // 按「上一頁」回來時把處理中關掉
// 繁英姊 11/20：「每個按鈕若在執行中，請給個提示」——所有表單送出、站內換頁連結一律先顯示處理中
document.addEventListener('submit', function (e) {
  if (e.defaultPrevented) return;   // 確認框按取消、或前端檢查擋下時不顯示
  // 11/23 繁英姊回報：「匯入完成」小視窗的「知道了」是 <form method="dialog">，只關視窗不換頁，
  // 被這裡當成送出而跳出「處理中」且永遠不關 → dialog 表單一律不顯示處理中
  if ((e.target.getAttribute('method') || '').toLowerCase() === 'dialog' || (e.submitter && (e.submitter.getAttribute('formmethod') || '').toLowerCase() === 'dialog')) return;
  var d = document.getElementById('rcBusy'); if (!d || d.open) return;
  var b = e.submitter, label = b ? (b.textContent || b.value || '').trim() : '';
  rcBusyLink(label ? '「' + label.slice(0, 20) + '」處理中，請稍候…' : '處理中，請稍候…');
  if (b) setTimeout(function () { b.disabled = true; }, 0);   // 防重按（等送出後再停用，才不會擋掉按鈕的值）
});
document.addEventListener('click', function (e) {
  var a = e.target.closest && e.target.closest('a[href]'); if (!a || e.defaultPrevented) return;
  var h = a.getAttribute('href') || '';
  if (h.charAt(0) === '#' || a.target === '_blank' || a.hasAttribute('download') || e.ctrlKey || e.metaKey || e.shiftKey || /^(mailto|tel|javascript):/i.test(h)) return;
  if (a.host && a.host !== location.host) return;   // 站外連結不管
  var d = document.getElementById('rcBusy'); if (!d || d.open) return;
  rcBusyLink('開啟中，請稍候…');
});
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
