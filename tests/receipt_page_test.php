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

echo "查詢\n";
$p = req('vw@test', ['a' => 'search', 'q' => 'O-1']);
ok(str_contains($p, '物(115)09140001'), '唯讀者可用訂單編號查到');
ok((int)$d->query("SELECT count(*) FROM audit WHERE action='search'")->fetchColumn() >= 1, '查詢有留紀錄');
@unlink($tmp);
echo $fail ? "❌ 失敗 {$fail}／{$n}\n" : "✅ 全部通過 {$n} 項\n";
exit($fail ? 1 : 0);
