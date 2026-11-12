<?php
// /receipt/ 頁面測試（全部虛構資料，寫 receipt_test schema）。用法：php tests/receipt_page_test.php <site> <lib>
$SITE = $argv[1]; $LIB = $argv[2];
putenv('ITCCRA_TEST_DB=1');
require $LIB . '/receipt.php';
$fail = 0; $n = 0;
function ok($c, $m) { global $fail, $n; $n++; echo ($c ? "  ✅ " : "  ❌ ") . $m . "\n"; if (!$c) $fail++; }
function req($email, $get, $method = 'GET', $post = [], $files = []) {
    global $SITE;
    $r = ['page' => "$SITE/receipt/index.php", 'method' => $method, 'get' => $get, 'post' => $post, 'files' => $files];
    $env = 'ITCCRA_TEST_DB=1 ITCCRA_TEST_EMAIL=' . escapeshellarg($email) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($r));
    return (string)shell_exec("env $env php " . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
}
function xlsx($path, $rows) {
    $py = 'import json,sys,openpyxl; d=json.loads(sys.argv[2]); wb=openpyxl.Workbook(); ws=wb.active; ws.title="全部訂單"; [ws.append(r) for r in d]; wb.save(sys.argv[1])';
    shell_exec('python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg($path) . ' ' . escapeshellarg(json_encode($rows, JSON_UNESCAPED_UNICODE)));
}
itdb('receipt')->exec('DROP SCHEMA IF EXISTS receipt_test CASCADE; CREATE SCHEMA receipt_test');
$d = rcpt_db();
$d->exec("INSERT INTO users VALUES ('ed@test','edit',''),('vw@test','view','')");
$secret = trim((string)file_get_contents('/var/lib/it-ccra/form_secret'));
$csrf = fn($e) => hash_hmac('sha256', 'receipt' . $e . date('Y-m-d'), $secret);
$E = 'ed@test';

echo "權限\n";
ok(str_contains(req('nobody@test', []), '只開放給開收據的同仁'), '不在名單看不到');
$v = req('vw@test', []);
ok(str_contains($v, '唯讀') && !str_contains($v, '新增批次'), '唯讀者沒有新增批次');
ok(str_contains(req('vw@test', ['a' => 'new']), '只能查詢'), '唯讀者不能開新增頁');
ok(str_contains(req('vw@test', [], 'POST', ['csrf' => $csrf('vw@test'), 'do' => 'save_batch']), '只能查詢'), '唯讀者 POST 擋下');
ok(str_contains(req($E, [], 'POST', ['csrf' => 'x', 'do' => 'save_batch']), '頁面已過期'), '錯的 csrf 擋下');

echo "建批次\n";
$o0 = req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'po_no' => '', 'in_date' => '115/09/14',
    'supplier_code' => '0000036', 'supplier_name' => '統一超商', 'set_price' => '360', 'handler' => '測試經手', 'contents' => '米1包', 'letter' => '測試信', 'items' => "A01,壽司米,3,包,198"]);
$bid = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
ok($bid > 0 && $d->query("SELECT po_no IS NULL AND track='物' FROM batch WHERE id=$bid")->fetchColumn(), '進貨單號可空白、ibon 自動帶物字軌');
ok((int)$d->query("SELECT count(*) FROM batch_item WHERE batch_id=$bid")->fetchColumn() === 1, '產品明細存入' . ((int)$d->query("SELECT count(*) FROM batch_item WHERE batch_id=$bid")->fetchColumn() === 1 ? '' : '：' . substr(strip_tags($o0), 0, 400)));
ok(str_contains(req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/02/30']), '收貨日期格式不對'), '不存在的日期擋下');

echo "匯入（已整理過的檔，不跑地址整理）\n";
$tmp = sys_get_temp_dir() . '/rcpt-page-' . getmypid() . '.xlsx';
xlsx($tmp, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據', '檢核說明'],
            ['O-1', '王測試', '臺北市中正區測試路1號', 2, '100', '臺北市中正區', '測試路1號', 'Y', ''],
            ['O-2', '愛心捐贈', '愛心捐贈', 1, '', '', '', 'N', '愛心捐贈']]);
$o = req($E, ['a' => 'batch', 'id' => $bid], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 'list.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
ok((int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$bid")->fetchColumn() === 2, '匯入 2 筆' . ((int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$bid")->fetchColumn() === 2 ? '' : '：' . substr(strip_tags($o), 0, 300)));
ok($d->query("SELECT send FROM donation WHERE batch_id=$bid AND order_no='O-2'")->fetchColumn() === false, '收據 N 照檔案帶入');
$p = req($E, ['a' => 'batch', 'id' => $bid]);
ok(str_contains($p, '數量合計 <b>3</b>') && str_contains($p, '✅'), '數量合計與進貨單相符顯示 ✅');

echo "匯入（平台原始檔，經地址整理程式）\n";
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => '家庭雲端', 'in_date' => '115/09/20', 'items' => '']);
$b3 = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
xlsx($tmp, [['平台訂單編號', '收件人', '電話', '地址', '商品', '數量'], ['F-1', '林測試', '0911', '新北市永和區保生路1號', '生活用品', 1], ['F-2', '無名', '', '無名', '生活用品', 1]]);
$o = req($E, ['a' => 'batch', 'id' => $b3], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 'raw.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
$r3 = $d->query("SELECT order_no, zip, city, send FROM donation WHERE batch_id=$b3 ORDER BY row_no")->fetchAll(PDO::FETCH_ASSOC);
ok(count($r3) === 2 && $r3[0]['zip'] === '234' && str_contains($r3[0]['city'], '永和'), '原始檔經地址整理：補上郵遞區號與縣市區' . (count($r3) ? '' : '：' . substr(strip_tags($o), 0, 300)));
ok(count($r3) === 2 && $r3[1]['send'] === false, '「無名」照整理規則判為不寄');
ok($d->query("SELECT track FROM batch WHERE id=$b3")->fetchColumn() === '食', '家庭雲端自動帶食字軌');

echo "配號\n";
req($E, ['a' => 'batch', 'id' => $bid], 'POST', ['csrf' => $csrf($E), 'do' => 'assign']);
$p = req($E, ['a' => 'batch', 'id' => $bid]);
ok(str_contains($p, '物(115)09140001~0002'), '配號後顯示起訖號（回填文中用）');
ok(!str_contains($p, 'value="import"'), '配號後不能再匯入');

echo "訂單編號重複\n";
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/09/15', 'items' => '']);
$b2 = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
xlsx($tmp, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據'], ['O-1', '王測試', 'x', 1, '100', '臺北市中正區', '測試路1號', 'Y']]);
req($E, ['a' => 'batch', 'id' => $b2], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 'l.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
$p = req($E, ['a' => 'batch', 'id' => $b2]);
ok(str_contains($p, '已開過收據 1 筆') && str_contains($p, '已開：物(115)09140001'), '重複的訂單編號標出、列出已開的收據號');
$o = req($E, ['a' => 'batch', 'id' => $b2], 'POST', ['csrf' => $csrf($E), 'do' => 'assign']);
ok(str_contains($o, '已經開過收據') && $d->query("SELECT status FROM batch WHERE id=$b2")->fetchColumn() === 'draft', '有重複訂單編號時一律擋下不能配號（業務單位選乙）');

echo "列印\n";
function preq($email, $get) {
    global $SITE;
    $r = ['page' => "$SITE/receipt/print.php", 'method' => 'GET', 'get' => $get, 'post' => []];
    return (string)shell_exec('env ITCCRA_TEST_DB=1 ITCCRA_TEST_EMAIL=' . escapeshellarg($email) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($r)) . ' php ' . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
}
$p = preq($E, ['id' => $bid]);
ok(substr_count($p, 'class="page"') === 1 && str_contains($p, '物(115)09140001') && str_contains($p, '115/09/14'), '預設只印要寄的（2 筆裡 1 筆）、印收據編號與收貨日期');
ok(substr_count(preq($E, ['id' => $bid, 'scope' => 'all']), 'class="page"') === 2, '選「全部」印 2 張');
ok(str_contains(preq($E, ['id' => $bid, 'dx' => '2', 'dy' => '-1']), 'left:166.4mm;top:23.2mm'), '位置微調套到座標');
preq($E, ['id' => $bid, 'go' => '1']);
ok((int)$d->query("SELECT count(*) FROM print_log WHERE batch_id=$bid AND kind='print'")->fetchColumn() === 1, '按列印才記錄 print_log');
preq('vw@test', ['id' => $bid, 'go' => '1']);
ok((int)$d->query("SELECT count(*) FROM print_log WHERE batch_id=$bid")->fetchColumn() === 1, '唯讀者看得到但不記錄列印');
ok(str_contains(preq($E, ['id' => $b2]), '找不到已配號的批次'), '未配號批次不能印');
// 單張份／多張份（業務單位選甲）：同一地址＋收件人 2 張以上進多張份
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/09/16', 'items' => '']);
$b4 = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
xlsx($tmp, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據'],
            ['M-1', '陳測試', 'x', 1, '234', '新北市永和區', '測試路9號', 'Y'], ['M-2', '陳測試', 'x', 1, '234', '新北市永和區', '測試路9號', 'Y'],
            ['M-3', '李測試', 'y', 1, '100', '臺北市中正區', '測試路2號', 'Y']]);
req($E, ['a' => 'batch', 'id' => $b4], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 'm.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
req($E, ['a' => 'batch', 'id' => $b4], 'POST', ['csrf' => $csrf($E), 'do' => 'assign']);
$s1 = preq($E, ['id' => $b4]); $s2 = preq($E, ['id' => $b4, 'part' => 'multi']);
ok(substr_count($s1, 'class="page"') === 1 && str_contains($s1, '李測試'), '預設單張份：只印 1 張（李）');
ok(substr_count($s2, 'class="page"') === 2 && !str_contains($s2, '李測試'), '多張份：同地址同收件人的 2 張（陳）');

req($E, ['a' => 'batch', 'id' => $b3], 'POST', ['csrf' => $csrf($E), 'do' => 'assign']);
$fp = preq($E, ['id' => $b3, 'scope' => 'all']);
ok(str_contains($fp, 'class="page food"') && str_contains($fp, '食(115)09200001') && str_contains($fp, 'stamp.php?k=chair') && str_contains($fp, '民國 115 年 9 月 20 日'), '食字軌整張白紙版面：編號、印章、收貨日期');

echo "配號後修改與寄出日期\n";
$rid = (int)$d->query("SELECT id FROM donation WHERE batch_id=$bid AND order_no='O-2'")->fetchColumn();
req($E, ['a' => 'batch', 'id' => $bid], 'POST', ['csrf' => $csrf($E), 'do' => 'row', 'rid' => $rid, 'name' => '林來電', 'zip' => '234', 'city' => '新北市永和區', 'address' => '保生路1號', 'send' => 'on', 'mailed_on' => '115/11/20']);
$r = $d->query("SELECT name, send, receipt_no, mailed_on FROM donation WHERE id=$rid")->fetch(PDO::FETCH_ASSOC);
ok($r['name'] === '林來電' && $r['send'] === true && $r['receipt_no'] === '物(115)09140002' && $r['mailed_on'] === '2026-11-20', '配號後可改收件人、地址、改成要寄、填寄出日期，收據編號不變');
ok(str_contains((string)$d->query("SELECT detail FROM audit WHERE action='edit_after_number' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'send: N → Y'), '配號後修改留改前改後紀錄');
ok(str_contains(req('vw@test', ['a' => 'batch', 'id' => $bid], 'POST', ['csrf' => $csrf('vw@test'), 'do' => 'row', 'rid' => $rid, 'name' => 'x']), '只能查詢'), '唯讀者不能改');
$ids = implode(',', $d->query("SELECT id FROM donation WHERE batch_id=$b4")->fetchAll(PDO::FETCH_COLUMN));
req($E, ['a' => 'batch', 'id' => $b4], 'POST', ['csrf' => $csrf($E), 'do' => 'mailed', 'ids' => $ids, 'mailed_on' => '115/11/21']);
ok((int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$b4 AND mailed_on='2026-11-21'")->fetchColumn() === 3, '整份標記寄出日期');
ok(str_contains(preq($E, ['id' => $b4, 'part' => 'all']), 'value="mailed"'), '列印頁有「標記已寄出」');
$d->exec("UPDATE batch SET letter = '十一月的信' WHERE id = $b4");
$nw = req($E, ['a' => 'new']);
ok(str_contains($nw, '十一月的信') && str_contains($nw, '已帶入最近一批的信'), '新增批次自動帶入最近一批的信件');
$d->exec("UPDATE batch SET letter = '食也用的信', in_date = '2026-09-30' WHERE id = $b3");
ok(str_contains(req($E, ['a' => 'new']), '食也用的信'), '物、食用同一封信：最近一批不分字軌都會帶入');

echo "大沈第一輪（R-itsu-1112-01）\n";
// P1-1 同檔兩列同訂單
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/10/01', 'items' => '']);
$b5 = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
xlsx($tmp, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據'], ['D-1', '甲測試', 'x', 1, '100', '臺北市中正區', '甲路1號', 'Y'], ['D-2', '乙測試', 'x', 1, '100', '臺北市中正區', '乙路1號', 'Y'], ['D-1', '甲測試', 'x', 1, '100', '臺北市中正區', '甲路1號', 'Y']]);
req($E, ['a' => 'batch', 'id' => $b5], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 'd.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
ok(str_contains(req($E, ['a' => 'batch', 'id' => $b5]), '名單內訂單編號重複 1 個'), '同檔重複訂單在檢核畫面標出');
$o = req($E, ['a' => 'batch', 'id' => $b5], 'POST', ['csrf' => $csrf($E), 'do' => 'assign']);
ok(str_contains($o, '重複出現') && $d->query("SELECT status FROM batch WHERE id=$b5")->fetchColumn() === 'draft' && (int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$b5 AND receipt_no IS NOT NULL")->fetchColumn() === 0, '同檔重複訂單整批不配號');
// 資料庫層保護：繞過程式直接寫同訂單第二張有效收據要失敗
$bad = false; try { $d->exec("UPDATE donation SET receipt_no = 'X-TEST-1' WHERE batch_id=$b5 AND order_no='D-1' AND row_no=1"); $d->exec("UPDATE donation SET receipt_no = 'X-TEST-2' WHERE batch_id=$b5 AND order_no='D-1' AND row_no=3"); } catch (PDOException $e) { $bad = ($e->errorInfo[0] ?? '') === '23505'; }
ok($bad, '資料庫唯一索引：同一訂單不能有兩張有效收據');
$d->exec("UPDATE donation SET receipt_no = NULL WHERE batch_id=$b5");
// P2 兩個草稿含同一訂單：不互擋；先配號的成功，後配號的被擋並指出已開的收據號
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/10/02', 'items' => '']);
$b6 = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/10/03', 'items' => '']);
$b7 = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
xlsx($tmp, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據'], ['S-1', '丙測試', 'x', 1, '100', '臺北市中正區', '丙路1號', 'Y']]);
foreach ([$b6, $b7] as $bb) req($E, ['a' => 'batch', 'id' => $bb], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 's.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
ok(!str_contains(req($E, ['a' => 'batch', 'id' => $b6]), '已開過收據'), '兩個草稿同訂單時不顯示「已開過收據」（還沒有收據號）');
req($E, ['a' => 'batch', 'id' => $b6], 'POST', ['csrf' => $csrf($E), 'do' => 'assign']);
$o = req($E, ['a' => 'batch', 'id' => $b7], 'POST', ['csrf' => $csrf($E), 'do' => 'assign']);
ok($d->query("SELECT status FROM batch WHERE id=$b6")->fetchColumn() === 'numbered' && $d->query("SELECT status FROM batch WHERE id=$b7")->fetchColumn() === 'draft' && str_contains($o, '物(115)10020001'), '先配號的成功、後配號的被擋並指出已開的收據號');
// P1-2 配號後的匯入、改字軌日期都在持鎖後重核狀態
$o = req($E, ['a' => 'batch', 'id' => $b6], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 's.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
ok((int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$b6 AND receipt_no IS NOT NULL")->fetchColumn() === 1, '已配號批次再匯入不會刪掉已開的收據');
$p6 = req($E, ['a' => 'edit', 'id' => $b6], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => '家庭雲端', 'in_date' => '115/12/31', 'po_no' => 'PO-B6', 'items' => 'Z,改不到,1']);
$r6 = $d->query("SELECT track, in_date, po_no FROM batch WHERE id=$b6")->fetch(PDO::FETCH_ASSOC);
ok($r6['track'] === '物' && $r6['in_date'] === '2026-10-02' && $r6['po_no'] === 'PO-B6' && (int)$d->query("SELECT count(*) FROM batch_item WHERE batch_id=$b6")->fetchColumn() === 0, '已配號：字軌、日期、產品明細不變，進貨單號可補');
// 雙連線：一條持有批次列鎖時，另一條的 rcpt_lock_draft 等不到鎖（lock_timeout）
$c = json_decode((string)file_get_contents(PG_CONF), true);
$d2 = new PDO("pgsql:host={$c['host']};dbname={$c['db']}", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$d2->exec('SET search_path TO receipt_test'); $d2->exec("SET lock_timeout = '500ms'");
$d->beginTransaction(); rcpt_lock_draft($d, $b7);
$blocked = false; try { $d2->beginTransaction(); rcpt_lock_draft($d2, $b7); } catch (PDOException $e) { $blocked = ($e->errorInfo[0] ?? '') === '55P03'; } finally { if ($d2->inTransaction()) $d2->rollBack(); }
$d->rollBack();
ok($blocked, '雙連線交錯：批次被鎖住時另一個匯入／配號拿不到鎖、不會動資料');
// P1-3 資料庫錯誤不把個資寫進紀錄
$fake = new PDOException('SQLSTATE[23502]: Not null violation DETAIL: Failing row contains (假姓名王小明, 0912000000, 假地址測試路99號)');
$fake->errorInfo = ['23502', 7, 'x'];
$log = sys_get_temp_dir() . '/rcpt-log-' . getmypid(); ini_set('error_log', $log); @unlink($log);
rcpt_log_db_error($fake, 'import');
$lg = (string)@file_get_contents($log); @unlink($log);
ok(str_contains($lg, 'sqlstate=23502') && !str_contains($lg, '王小明') && !str_contains($lg, '0912000000') && !str_contains($lg, '測試路99號'), '資料庫錯誤只記 SQLSTATE，不含姓名電話地址');
ok(!str_contains((string)file_get_contents("$SITE/receipt/index.php"), 'error_log('), '頁面不直接呼叫 error_log');

echo "大沈複驗（稽核與修改同一交易）\n";
function req_af($email, $get, $post) {   // 模擬稽核寫入失敗
    global $SITE;
    $r = ['page' => "$SITE/receipt/index.php", 'method' => 'POST', 'get' => $get, 'post' => $post, 'files' => []];
    return (string)shell_exec('env ITCCRA_TEST_DB=1 ITCCRA_TEST_AUDIT_FAIL=1 ITCCRA_TEST_EMAIL=' . escapeshellarg($email) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($r)) . ' php ' . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
}
$rowB4 = $d->query("SELECT id, address, mailed_on FROM donation WHERE batch_id=$b4 ORDER BY row_no LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$nAudit = (int)$d->query('SELECT count(*) FROM audit')->fetchColumn();
$o = req_af($E, ['a' => 'batch', 'id' => $b4], ['csrf' => $csrf($E), 'do' => 'row', 'rid' => $rowB4['id'], 'name' => '陳測試', 'zip' => '234', 'city' => '新北市永和區', 'address' => '稽核失敗不該寫進去', 'send' => 'on']);
$after = $d->query("SELECT address FROM donation WHERE id={$rowB4['id']}")->fetchColumn();
ok($after === $rowB4['address'] && (int)$d->query('SELECT count(*) FROM audit')->fetchColumn() === $nAudit && str_contains($o, '資料庫錯誤') && preg_match('/代碼 [0-9a-f]{8}）/u', $o), '單筆修改：稽核失敗整筆回滾（地址沒被改、畫面報錯並顯示代碼）');
$o = req_af($E, ['a' => 'batch', 'id' => $b4], ['csrf' => $csrf($E), 'do' => 'mailed', 'ids' => (string)$rowB4['id'], 'mailed_on' => '115/12/01']);
ok($d->query("SELECT mailed_on FROM donation WHERE id={$rowB4['id']}")->fetchColumn() === $rowB4['mailed_on'], '整份標寄出日期：稽核失敗整筆回滾');
// 兩連線交錯修改：連線一持有該批次列鎖（例如正在配號或修改），連線二的單筆修改等不到鎖就失敗、不寫入
$d->beginTransaction(); $d->query("SELECT id FROM batch WHERE id=$b4 FOR UPDATE");
$r2 = ['page' => "$SITE/receipt/index.php", 'method' => 'POST', 'get' => ['a' => 'batch', 'id' => $b4], 'post' => ['csrf' => $csrf($E), 'do' => 'row', 'rid' => $rowB4['id'], 'name' => '陳測試', 'zip' => '1', 'city' => 'x', 'address' => '交錯中寫入', 'send' => 'on'], 'files' => []];
$o2 = (string)shell_exec('env ITCCRA_TEST_DB=1 PGOPTIONS=' . escapeshellarg('-c lock_timeout=500ms') . ' ITCCRA_TEST_EMAIL=' . escapeshellarg($E) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($r2)) . ' php ' . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
$d->rollBack();
ok($d->query("SELECT address FROM donation WHERE id={$rowB4['id']}")->fetchColumn() === $rowB4['address'] && str_contains($o2, '資料庫錯誤'), '兩連線交錯：批次被鎖時單筆修改拿不到鎖、不寫入');
req($E, ['a' => 'batch', 'id' => $b4], 'POST', ['csrf' => $csrf($E), 'do' => 'row', 'rid' => $rowB4['id'], 'name' => '陳測試', 'zip' => '234', 'city' => '新北市永和區', 'address' => '正常修改', 'send' => 'on']);
ok($d->query("SELECT address FROM donation WHERE id={$rowB4['id']}")->fetchColumn() === '正常修改' && str_contains((string)$d->query("SELECT detail FROM audit WHERE action='edit_after_number' ORDER BY id DESC LIMIT 1")->fetchColumn(), '→ 正常修改'), '鎖放開後正常修改成功，稽核的改後值與實際一致');
req($E, ['a' => 'batch', 'id' => $b4], 'POST', ['csrf' => $csrf($E), 'do' => 'row', 'rid' => $rowB4['id'], 'name' => '陳測試', 'zip' => '234', 'city' => '新北市永和區', 'address' => '第二次修改', 'send' => 'on']);
ok(str_contains((string)$d->query("SELECT detail FROM audit WHERE action='edit_after_number' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'address: 正常修改 → 第二次修改'), '連續兩次修改：第二次稽核的改前＝第一次的改後');
// 列印：print_log 與稽核同一交易，稽核失敗時兩筆都不寫、畫面報錯
$nPL = (int)$d->query("SELECT count(*) FROM print_log WHERE batch_id=$b4")->fetchColumn();
$rp = ['page' => "$SITE/receipt/print.php", 'method' => 'GET', 'get' => ['id' => $b4, 'part' => 'all', 'go' => '1'], 'post' => []];
$op = (string)shell_exec('env ITCCRA_TEST_DB=1 ITCCRA_TEST_AUDIT_FAIL=1 ITCCRA_TEST_EMAIL=' . escapeshellarg($E) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($rp)) . ' php ' . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
ok((int)$d->query("SELECT count(*) FROM print_log WHERE batch_id=$b4")->fetchColumn() === $nPL && !str_contains($op, "print()"), '列印：稽核失敗時列印紀錄也不寫、不觸發列印');
$nA2 = (int)$d->query('SELECT count(*) FROM audit')->fetchColumn();
$op2 = (string)shell_exec('env ITCCRA_TEST_DB=1 ITCCRA_TEST_AUDIT_FAIL=1 ITCCRA_TEST_EMAIL=' . escapeshellarg($E) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($rp)) . ' php -d display_errors=0 -r ' . escapeshellarg('register_shutdown_function(function(){ fwrite(STDERR, "HTTP=" . http_response_code() . "\n"); }); include ' . var_export(__DIR__ . '/req.php', true) . ';') . ' 2>&1');
ok(str_contains($op2, 'HTTP=500') && str_contains($op2, '列印紀錄寫入失敗') && preg_match('/代碼 [0-9a-f]{8}/', $op2) && (int)$d->query('SELECT count(*) FROM audit')->fetchColumn() === $nA2 && !str_contains($op2, 'class="page'), '列印稽核失敗：回 500、顯示錯誤訊息與代碼、audit 筆數不變、不輸出收據頁');

// 三段式時序：匯入整理完暫停 → 另一請求配號並提交 → 匯入恢復（應被擋、已開收據不被刪）
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/10/05', 'items' => '']);
$b8 = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
xlsx($tmp, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據'], ['T-1', '丁測試', 'x', 1, '100', '臺北市中正區', '丁路1號', 'Y'], ['T-2', '戊測試', 'x', 1, '100', '臺北市中正區', '戊路1號', 'Y']]);
req($E, ['a' => 'batch', 'id' => $b8], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 't.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
$tmp2 = $tmp . '.2.xlsx'; xlsx($tmp2, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據'], ['T-9', '己測試', 'x', 1, '100', '臺北市中正區', '己路1號', 'Y']]);
$ri = ['page' => "$SITE/receipt/index.php", 'method' => 'POST', 'get' => ['a' => 'batch', 'id' => $b8], 'post' => ['csrf' => $csrf($E), 'do' => 'import'], 'files' => ['file' => ['name' => 't2.xlsx', 'tmp_name' => $tmp2, 'error' => 0]]];
// 時序用檔案交握（大沈 R-itsu-1112-01 建議）：匯入到暫停點寫 ready → 這裡才配號並提交 → 寫 release 讓匯入繼續
$sync = sys_get_temp_dir() . '/rcpt-sync-' . getmypid(); @mkdir($sync); chmod($sync, 0777);
$outf = "$sync/out";
$snap = fn() => [$d->query("SELECT status, first_no, last_no FROM batch WHERE id=$b8")->fetch(PDO::FETCH_ASSOC),
                 $d->query("SELECT * FROM donation WHERE batch_id=$b8 ORDER BY row_no")->fetchAll(PDO::FETCH_ASSOC)];
$before = $snap();
$ph = popen('env ITCCRA_TEST_DB=1 ITCCRA_TEST_IMPORT_SYNC=' . escapeshellarg($sync) . ' ITCCRA_TEST_EMAIL=' . escapeshellarg($E) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($ri)) . ' php ' . escapeshellarg(__DIR__ . '/req.php') . ' > ' . escapeshellarg($outf) . ' 2>&1', 'r');
for ($w = 0; $w < 200 && !is_file("$sync/ready"); $w++) usleep(100000);   // 最多等 20 秒
$ready = is_file("$sync/ready");
ok($ready, '三段式時序：匯入確實到達暫停點（整理完檔案、尚未動資料）');
ok($snap() == $before, '三段式時序：暫停點之前匯入沒有動到批次與明細');
$ra = $ready ? rcpt_assign($d, $b8, $E) : ['count' => 0];
$assigned = $snap();
touch("$sync/release");
pclose($ph);   // 等匯入恢復並結束
$oi = (string)@file_get_contents($outf);
array_map('unlink', glob("$sync/*")); @rmdir($sync); @unlink($tmp2);
$after = $snap();
ok($ra['count'] === 2 && $assigned[0]['status'] === 'numbered' && $assigned[1][0]['receipt_no'] === '物(115)10050001' && $assigned[1][1]['receipt_no'] === '物(115)10050002', '三段式時序：暫停中配號成功（兩張收據、批次已配號）');
ok(str_contains($oi, '已經配號'), '三段式時序：匯入恢復後被擋');
ok($after == $assigned, '三段式時序：恢復後批次狀態與兩列完整資料和配號當下一模一樣（沒有被刪除或改寫）');

// 靜態檢查：雙引號字串裡變數後面緊接中文要用 {} 包起來（否則中文被吃進變數名；11/12 錯誤訊息的代碼就這樣不見了）
$bad = [];
foreach (array_merge(glob("$SITE/receipt/*.php"), [$LIB . '/receipt.php']) as $f)
    foreach (file($f) as $i => $line)
        if (preg_match('/"[^"]*(?<!\{)\$[A-Za-z_][A-Za-z0-9_]*[^\x00-\x7F]/u', $line)) $bad[] = basename($f) . ':' . ($i + 1);
ok(!$bad, '字串裡的變數後面緊接中文時都有用 {} 包起來' . ($bad ? '：' . implode('、', $bad) : ''));

echo "查詢\n";
$p = req('vw@test', ['a' => 'search', 'q' => 'O-1']);
ok(str_contains($p, '物(115)09140001'), '唯讀者可用訂單編號查到');
ok((int)$d->query("SELECT count(*) FROM audit WHERE action='search'")->fetchColumn() >= 1, '查詢有留紀錄');
@unlink($tmp);
echo $fail ? "❌ 失敗 {$fail}／{$n}\n" : "✅ 全部通過 {$n} 項\n";
exit($fail ? 1 : 0);
