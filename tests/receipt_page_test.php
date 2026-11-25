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

echo "設定（來源／供應商／套餐金額；繁英姊 11/18 第 8 項）\n";
ok((int)$d->query("SELECT count(*) FROM option_item WHERE kind='source' AND active")->fetchColumn() === count(RCPT_SOURCES)
   && (int)$d->query("SELECT count(*) FROM option_item WHERE kind='set_price' AND active")->fetchColumn() === 2, '首次建表帶入原本的 5 個來源與 360／300');
ok(str_contains(req('vw@test', ['a' => 'settings']), '只能查詢'), '唯讀者不能開設定頁');
ok(str_contains(req('vw@test', [], 'POST', ['csrf' => $csrf('vw@test'), 'do' => 'opt_save', 'kind' => 'supplier', 'code' => 'X', 'name' => 'Y']), '只能查詢'), '唯讀者不能改設定');
req($E, ['a' => 'settings'], 'POST', ['csrf' => $csrf($E), 'do' => 'opt_save', 'kind' => 'supplier', 'code' => '0000036', 'name' => '統一超商']);
ok($d->query("SELECT name FROM option_item WHERE kind='supplier' AND code='0000036'")->fetchColumn() === '統一超商', '新增供應商 0000036 統一超商');
ok(str_contains(req($E, ['a' => 'settings'], 'POST', ['csrf' => $csrf($E), 'do' => 'opt_save', 'kind' => 'supplier', 'code' => '0000036', 'name' => '重複']), '已經有了'), '重複的供應商編號擋下');
ok(str_contains(req($E, ['a' => 'settings'], 'POST', ['csrf' => $csrf($E), 'do' => 'opt_save', 'kind' => 'set_price', 'code' => 'abc']), '正整數'), '金額不是數字擋下');
ok(str_contains(req($E, ['a' => 'settings'], 'POST', ['csrf' => $csrf($E), 'do' => 'opt_save', 'kind' => 'source', 'code' => '測來源', 'extra' => '水']), '字軌'), '來源字軌只能物／食');
req($E, ['a' => 'settings'], 'POST', ['csrf' => $csrf($E), 'do' => 'opt_save', 'kind' => 'set_price', 'code' => '５００']);
ok((bool)$d->query("SELECT 1 FROM option_item WHERE kind='set_price' AND code='500'")->fetchColumn(), '新增金額（全形５００轉成 500）');
$sg = req($E, ['a' => 'settings']);
ok(str_contains($sg, '統一超商') && str_contains($sg, '>⚙️ 設定</a>'), '設定頁列出新增的供應商、開收據者有「設定」分頁');
ok(!str_contains(req('vw@test', []), '>設定</a>'), '唯讀者沒有「設定」分頁');
ok((int)$d->query("SELECT count(*) FROM audit WHERE action='opt_add'")->fetchColumn() >= 2, '新增設定留稽核紀錄');

echo "建批次\n";
$o0 = req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'po_no' => '', 'in_date' => '115/09/14',
    'supplier_code' => '0000036', 'supplier_name' => '統一超商', 'set_price' => '360', 'handler' => '測試經手', 'contents' => '米1包', 'letter' => '測試信', 'items' => "A01,壽司米,3,包,198"]);
$bid = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
ok($bid > 0 && $d->query("SELECT po_no IS NULL AND track='物' FROM batch WHERE id=$bid")->fetchColumn(), '進貨單號可空白、ibon 自動帶物字軌');
ok((int)$d->query("SELECT count(*) FROM batch_item WHERE batch_id=$bid")->fetchColumn() === 1, '產品明細存入' . ((int)$d->query("SELECT count(*) FROM batch_item WHERE batch_id=$bid")->fetchColumn() === 1 ? '' : '：' . substr(strip_tags($o0), 0, 400)));
ok($d->query("SELECT supplier_name FROM batch WHERE id=$bid")->fetchColumn() === '統一超商', '供應商名稱由設定帶入（選編號存名稱）');
// 停用：新增批次的選單看不到；用它的舊批次仍顯示（標已停用）、存檔保留原值
$sid = (int)$d->query("SELECT id FROM option_item WHERE kind='supplier' AND code='0000036'")->fetchColumn();
req($E, ['a' => 'settings'], 'POST', ['csrf' => $csrf($E), 'do' => 'opt_toggle', 'oid' => $sid]);
ok($d->query("SELECT active FROM option_item WHERE id=$sid")->fetchColumn() === false, '停用供應商');
ok(!str_contains(req($E, ['a' => 'new']), 'value="0000036"'), '停用後新增批次的選單沒有它');
$eo = req($E, ['a' => 'edit', 'id' => $bid]);
ok(preg_match('#<option value="0000036"\s+selected>[^<]*（已停用）#u', $eo) === 1, '舊批次編輯頁仍顯示它並標「已停用」');
req($E, ['a' => 'edit', 'id' => $bid], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/09/14', 'supplier_code' => '0000036', 'set_price' => '360', 'handler' => '測試經手', 'items' => "A01,壽司米,3,包,198"]);
ok($d->query("SELECT supplier_code || '/' || supplier_name FROM batch WHERE id=$bid")->fetchColumn() === '0000036/統一超商', '舊批次存檔保留已停用供應商的原值');
req($E, ['a' => 'settings'], 'POST', ['csrf' => $csrf($E), 'do' => 'opt_toggle', 'oid' => $sid]);
ok($d->query("SELECT active FROM option_item WHERE id=$sid")->fetchColumn() === true, '重新啟用');
req($E, ['a' => 'settings'], 'POST', ['csrf' => $csrf($E), 'do' => 'opt_save', 'kind' => 'supplier', 'oid' => $sid, 'code' => '0000036', 'name' => '統一超商股份有限公司']);
ok($d->query("SELECT name FROM option_item WHERE id=$sid")->fetchColumn() === '統一超商股份有限公司' && $d->query("SELECT supplier_name FROM batch WHERE id=$bid")->fetchColumn() === '統一超商', '修改供應商名稱：設定更新、舊批次保留原名');
ok(str_contains(req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/02/30']), '收貨日期格式不對'), '不存在的日期擋下');
// 繁英姊 11/18 驗收：空白要說「請填」、出錯要保留剛填的內容、全形與日曆（西元）日期都收
$oe = req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '', 'supplier_code' => '0000036', 'set_price' => '500', 'handler' => '回填經手', 'items' => "B01,回填品名,1,包,"]);
ok(str_contains($oe, '請填收貨日期') && !str_contains($oe, '格式不對'), '收貨日期空白回「請填收貨日期」，不說格式不對');
ok(preg_match('#<option value="0000036"\s+selected#u', $oe) === 1 && preg_match('#<option value="500"\s+selected#u', $oe) === 1 && str_contains($oe, 'value="回填經手"') && str_contains($oe, 'value="B01"') && str_contains($oe, 'value="回填品名"'), '出錯後保留剛選的供應商、金額與經手人、產品明細（表格逐格帶回）');
ok(str_contains(req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/09/14', 'supplier_code' => '9999999']), '請從清單選供應商'), '不在清單的供應商擋下');
ok(str_contains(req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => '不存在來源', 'in_date' => '115/09/14']), '請選來源'), '不在清單的來源擋下');
ok(preg_match('#<option value="ibon"\s+selected#u', $oe) === 1, '出錯後來源保持原選項');
$nfw = (int)$d->query('SELECT count(*) FROM batch')->fetchColumn();
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '１１５／１１／１８']);
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '2026-11-19']);
ok((int)$d->query("SELECT count(*) FROM batch WHERE in_date IN ('2026-11-18','2026-11-19')")->fetchColumn() === 2 && (int)$d->query('SELECT count(*) FROM batch')->fetchColumn() === $nfw + 2, '全形「１１５／１１／１８」與日曆西元「2026-11-19」都建得起來');
$d->exec("DELETE FROM batch WHERE in_date IN ('2026-11-18','2026-11-19')");
$nw = req($E, ['a' => 'new']);
ok(str_contains($nw, 'type="date"') && str_contains($nw, 'value="' . date('Y-m-d') . '"'), '新增批次的收貨日期是日曆欄位、預設今天');
ok(str_contains(req($E, []), '➕ 匯入名單') && str_contains(req($E, []), '📋 收據清單') && str_contains(req($E, []), '🔍 查詢捐贈人'), '功能表名稱（繁英姊 11/24：收據清單／匯入名單／新增收據／查詢捐贈人／設定）');

echo "匯入（已整理過的檔，不跑地址整理）\n";
$tmp = sys_get_temp_dir() . '/rcpt-page-' . getmypid() . '.xlsx';
xlsx($tmp, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據', '檢核說明'],
            ['O-1', '王測試', '臺北市中正區測試路1號', 2, '100', '臺北市中正區', '測試路1號', 'Y', ''],
            ['O-2', '愛心捐贈', '愛心捐贈', 1, '', '', '', 'N', '愛心捐贈']]);
$o = req($E, ['a' => 'batch', 'id' => $bid], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 'list.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
ok((int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$bid")->fetchColumn() === 2, '匯入 2 筆' . ((int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$bid")->fetchColumn() === 2 ? '' : '：' . substr(strip_tags($o), 0, 300)));
ok($d->query("SELECT send FROM donation WHERE batch_id=$bid AND order_no='O-2'")->fetchColumn() === false, '收據 N 照檔案帶入');
$p = req($E, ['a' => 'batch', 'id' => $bid]);
ok(preg_match('#<small>數量合計</small><b>3</b>#u', $p) && str_contains($p, '與進貨單相符'), '數量合計 3 且顯示「與進貨單相符」（11/18 介面改版：數字卡＋狀態標籤）');

echo "匯入（平台原始檔，經地址整理程式）\n";
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => '家庭雲端', 'in_date' => '115/09/20', 'items' => 'F01,生活用品,2,箱,100']);
$b3 = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
xlsx($tmp, [['平台訂單編號', '收件人', '電話', '地址', '商品', '數量'], ['F-1', '林測試', '0911', '新北市永和區保生路1號', '生活用品', 1], ['F-2', '無名', '', '無名', '生活用品', 1]]);
$o = req($E, ['a' => 'batch', 'id' => $b3], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 'raw.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
$r3 = $d->query("SELECT order_no, zip, city, send FROM donation WHERE batch_id=$b3 ORDER BY row_no")->fetchAll(PDO::FETCH_ASSOC);
ok(count($r3) === 2 && $r3[0]['zip'] === '234' && str_contains($r3[0]['city'], '永和'), '原始檔經地址整理：補上郵遞區號與縣市區' . (count($r3) ? '' : '：' . substr(strip_tags($o), 0, 300)));
ok(count($r3) === 2 && $r3[1]['send'] === false, '「無名」照整理規則判為不寄');
ok($d->query("SELECT track FROM batch WHERE id=$b3")->fetchColumn() === '食', '家庭雲端自動帶食字軌');

echo "追加匯入（11/23 繁英：一張進貨單兩個月份的檔）\n";
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/10/01', 'items' => '']);
$ba = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
$pa = req($E, ['a' => 'batch', 'id' => $ba]);
ok(!str_contains($pa, 'name="mode"'), '空批次不顯示取代／追加選項');
xlsx($tmp, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據'],
            ['A-1', '甲', 'x', 1, '100', '臺北市中正區', '測試路1號', 'Y'], ['A-2', '乙', 'x', 2, '100', '臺北市中正區', '測試路2號', 'Y']]);
req($E, ['a' => 'batch', 'id' => $ba], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 'oct.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
$pa = req($E, ['a' => 'batch', 'id' => $ba]);
ok(str_contains($pa, 'name="mode" value="append"') && str_contains($pa, 'value="replace" checked'), '已有資料時顯示選項，預設「取代」');
xlsx($tmp, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據'],
            ['A-2', '乙', 'x', 2, '100', '臺北市中正區', '測試路2號', 'Y'], ['B-1', '丙', 'x', 3, '100', '臺北市中正區', '測試路3號', 'Y']]);
$o = req($E, ['a' => 'batch', 'id' => $ba], 'POST', ['csrf' => $csrf($E), 'do' => 'import', 'mode' => 'append'], ['file' => ['name' => 'nov.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
$ra = $d->query("SELECT row_no, order_no, check_note FROM donation WHERE batch_id=$ba ORDER BY row_no")->fetchAll(PDO::FETCH_ASSOC);
ok(count($ra) === 4 && array_column($ra, 'row_no') === [1, 2, 3, 4], '追加後 4 筆、序號接續 1～4' . (count($ra) === 4 ? '' : '：' . substr(strip_tags($o), 0, 300)));
ok(count($ra) === 4 && str_contains($ra[2]['check_note'], RCPT_DUP_PREV_NOTE) && !str_contains($ra[3]['check_note'], RCPT_DUP_PREV_NOTE), '和前次重複的訂單編號（A-2）在檢核說明標出，新的（B-1）不標');
ok((int)$d->query("SELECT count(*) FROM audit WHERE batch_id=$ba AND action='import_append'")->fetchColumn() === 1, '稽核記 import_append');
$pa = req($E, ['a' => 'batch', 'id' => $ba, 'ok' => 'appended', 'dupprev' => 1]);
ok(str_contains($pa, '追加匯入完成') && str_contains($pa, '有 1 筆訂單編號和先前匯入的重複'), '完成視窗與提示顯示追加與重複筆數');
// 大沈 11/23 審查：追加失敗回頁後模式要留在「追加」，換正確檔直接重送不能蓋掉原清單
xlsx($tmp, [['亂欄', '沒有表頭'], ['x', 'y']]);
$o = req($E, ['a' => 'batch', 'id' => $ba], 'POST', ['csrf' => $csrf($E), 'do' => 'import', 'mode' => 'append'], ['file' => ['name' => 'bad.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
ok(str_contains($o, 'alert err') && str_contains($o, 'value="append" checked') && !str_contains($o, 'value="replace" checked'), '追加失敗：報錯且回頁仍勾「追加」');
ok((int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$ba")->fetchColumn() === 4, '追加失敗：原 4 筆不變');
xlsx($tmp, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據'], ['D-1', '戊', 'x', 1, '100', '臺北市中正區', '測試路5號', 'Y']]);
req($E, ['a' => 'batch', 'id' => $ba], 'POST', ['csrf' => $csrf($E), 'do' => 'import', 'mode' => 'append'], ['file' => ['name' => 'fix.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
ok((int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$ba")->fetchColumn() === 5, '換正確檔照回頁的「追加」重送：變 5 筆、原清單保留');
$pa = req($E, ['a' => 'batch', 'id' => $ba]);
ok(str_contains($pa, 'value="replace" checked'), '一般進頁仍預設「取代」');
xlsx($tmp, [['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據'], ['C-1', '丁', 'x', 1, '100', '臺北市中正區', '測試路4號', 'Y']]);
req($E, ['a' => 'batch', 'id' => $ba], 'POST', ['csrf' => $csrf($E), 'do' => 'import', 'mode' => 'replace'], ['file' => ['name' => 'r.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
ok((int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$ba")->fetchColumn() === 1, '選「取代」照舊整批換掉（剩 1 筆）');
$d->exec("DELETE FROM donation WHERE batch_id=$ba"); $d->exec("DELETE FROM audit WHERE batch_id=$ba"); $d->exec("DELETE FROM batch WHERE id=$ba");

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
ok(str_contains(preq($E, ['id' => $bid, 'dx' => '2', 'dy' => '-1']), 'left:166.4mm;top:26.2mm'), '位置微調套到座標（11/20 收據編號下調 3mm 後基準 164.4,27.2）');
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

ok(!str_contains($fp, '對光檢查') && str_contains($s1, '對光檢查'), '「疊在預印紙上對光」提示只在物字軌出現');
ok(str_contains($fp, '生活用品') && str_contains($fp, '<td>箱</td>'), '食字軌收據印出品名與進貨明細的單位');

echo "食字軌缺品名／單位不得配號、不得列印\n";
$FH = ['訂單編號', '收件人', '送貨地點', '訂單數量', 'zip', 'city', 'address', '收據'];
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => '家庭雲端', 'in_date' => '115/10/20', 'items' => 'A01,測試米,3,包,198']);
$b9 = (int)$d->query('SELECT max(id) FROM batch')->fetchColumn();
xlsx($tmp, [$FH, ['G-1', '庚測試', 'x', 1, '100', '臺北市中正區', '庚路1號', 'Y'], ['G-2', '辛測試', 'x', 2, '100', '臺北市中正區', '辛路1號', 'Y']]);
req($E, ['a' => 'batch', 'id' => $b9], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 'g.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
$pg = req($E, ['a' => 'batch', 'id' => $b9]);
ok(str_contains($pg, '品名／單位不完整 2 列') && str_contains($pg, '<th>品名</th>'), '名單沒有商品欄：批次頁顯示品名欄與「不完整 2 列」警告');
$o9 = req($E, ['a' => 'batch', 'id' => $b9], 'POST', ['csrf' => $csrf($E), 'do' => 'assign']);
ok(str_contains($o9, '第 1 列缺品名') && str_contains($o9, '第 2 列缺品名') && $d->query("SELECT status FROM batch WHERE id=$b9")->fetchColumn() === 'draft'
   && (int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$b9 AND receipt_no IS NOT NULL")->fetchColumn() === 0, '缺品名：配號被擋、指出列號、沒有開出任何收據');
xlsx($tmp, [array_merge($FH, ['商品名稱']), ['G-1', '庚測試', 'x', 1, '100', '臺北市中正區', '庚路1號', 'Y', '測試米'], ['G-2', '辛測試', 'x', 2, '100', '臺北市中正區', '辛路1號', 'Y', '別的米']]);
req($E, ['a' => 'batch', 'id' => $b9], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 'g.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
$o9 = req($E, ['a' => 'batch', 'id' => $b9], 'POST', ['csrf' => $csrf($E), 'do' => 'assign']);
ok(str_contains($o9, '第 2 列品名「別的米」不在進貨明細') && !str_contains($o9, '第 1 列') && $d->query("SELECT status FROM batch WHERE id=$b9")->fetchColumn() === 'draft', '品名不在進貨明細（對不到單位）：擋下並只指出那一列');
xlsx($tmp, [array_merge($FH, ['商品名稱']), ['G-1', '庚測試', 'x', 1, '100', '臺北市中正區', '庚路1號', 'Y', '測試米'], ['G-2', '辛測試', 'x', 2, '100', '臺北市中正區', '辛路1號', 'Y', '測試米']]);
req($E, ['a' => 'batch', 'id' => $b9], 'POST', ['csrf' => $csrf($E), 'do' => 'import'], ['file' => ['name' => 'g.xlsx', 'tmp_name' => $tmp, 'error' => 0]]);
req($E, ['a' => 'batch', 'id' => $b9], 'POST', ['csrf' => $csrf($E), 'do' => 'assign']);
$f9 = preq($E, ['id' => $b9, 'scope' => 'all', 'part' => 'all']);
ok($d->query("SELECT status FROM batch WHERE id=$b9")->fetchColumn() === 'numbered' && substr_count($f9, '<td>測試米</td>') === 2 && substr_count($f9, '<td>包</td>') === 2, '補上品名後可配號，兩張收據都印出品名與單位');
$d->exec("UPDATE donation SET product = '' WHERE batch_id = $b9 AND order_no = 'G-2'");
$f9 = preq($E, ['id' => $b9, 'scope' => 'all', 'part' => 'all']);
ok(!str_contains($f9, 'class="page food"') && str_contains($f9, '第 2 列缺品名') && str_contains($f9, '已配號') && !str_contains($f9, '重新匯入'), '已配號後品名被清空：列印頁拒絕輸出收據，且不叫使用者重匯（已鎖）');
$pl = (int)$d->query("SELECT count(*) FROM print_log WHERE batch_id=$b9")->fetchColumn();
preq($E, ['id' => $b9, 'scope' => 'all', 'part' => 'all', 'go' => '1']);
ok((int)$d->query("SELECT count(*) FROM print_log WHERE batch_id=$b9")->fetchColumn() === $pl, '拒絕列印時也不寫列印紀錄');

echo "手機版面\n";
$rcss = (string)file_get_contents($SITE . '/assets/receipt.css');   // 11/18 介面改版：手機卡片規則移到 assets/receipt.css
ok(str_contains($pg, 'table class="rows"') && str_contains($pg, '/assets/receipt.css') && str_contains($rcss, '@media (max-width:700px)') && str_contains($rcss, ".rows td[data-l]::before") && str_contains($pg, 'data-l="寄出日期"') && str_contains($pg, 'data-l="收件人"'), '批次表在窄螢幕改卡片（欄名標籤、儲存鍵留在卡片內）');

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
echo "給捐贈者的信改由設定統一維護（繁英姊 11/25）
";
$d->exec("DELETE FROM settings WHERE k = 'letter'");
$d->exec("UPDATE batch SET letter = '十一月的信' WHERE id = $b4");
$nw = req($E, ['a' => 'new']);
ok(!str_contains($nw, 'name="letter"') && str_contains($nw, '改在「⚙️ 設定」統一維護'), '新增收據頁不再有信件欄，改提示到設定維護');
ok(str_contains(req($E, ['a' => 'settings']), '十一月的信'), '設定還沒存過信：沿用最近一批有填的信');
$o = req($E, ['a' => 'settings'], 'POST', ['csrf' => $csrf($E), 'do' => 'letter_save', 'letter' => "親愛的朋友：\r\n\r\n設定裡的新信"]);
ok($d->query("SELECT v FROM settings WHERE k = 'letter'")->fetchColumn() === "親愛的朋友：\n\n設定裡的新信", '設定頁儲存信件（換行統一為 \n）');
ok(str_contains(req($E, ['a' => 'settings']), '設定裡的新信'), '設定頁顯示目前的信');
$pp = preq($E, ['id' => $b4, 'part' => 'all']);
ok(str_contains($pp, '設定裡的新信') && !str_contains($pp, '十一月的信'), '列印套用設定裡的信，不用批次舊存的信');
ok(str_contains($pp, '<p>親愛的朋友：</p><p>設定裡的新信</p>'), '列印時段落依空行切開');
ok(str_contains((string)$d->query("SELECT action FROM audit WHERE action='letter_save' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'letter_save'), '儲存信件留稽核紀錄');
req('vw@test', ['a' => 'settings'], 'POST', ['csrf' => $csrf('vw@test'), 'do' => 'letter_save', 'letter' => '唯讀者改的']);
ok($d->query("SELECT v FROM settings WHERE k = 'letter'")->fetchColumn() === "親愛的朋友：\n\n設定裡的新信", '唯讀者不能改信件');
$d->exec("DELETE FROM settings WHERE k = 'letter'");
echo "設定頁好找（繁英姊 11/25）
";
$st = req($E, ['a' => 'settings']);
ok(str_contains($st, 'class="setnav"') && str_contains($st, 'href="#supplier"') && str_contains($st, 'href="#letter"'), '設定頁上方有跳轉列（含供應商與信件）');
ok(strpos($st, 'value="opt_save"><input type="hidden" name="kind" value="supplier"') < strpos($st, 'id="tb-supplier"'), '新增欄位在清單上方');
$nsup = (int)$d->query("SELECT count(*) FROM option_item WHERE kind = 'supplier'")->fetchColumn();
for ($x = $nsup; $x <= 9; $x++) $d->prepare("INSERT INTO option_item (kind, code, name, extra, sort, updated_by) VALUES ('supplier', ?, ?, '', 900 + ?, 'test')")->execute([sprintf('T%06d', $x), '測試供應商' . $x, $x]);
$st = req($E, ['a' => 'settings']);
ok(str_contains($st, 'data-for="tb-supplier"') && str_contains($st, 'class="tbl setlist" id="tb-supplier"'), '供應商超過 8 筆：出現篩選框、清單限高捲動');
ok(!str_contains($st, 'data-for="tb-set_price"'), '筆數少的區塊不出現篩選框');
ok(str_contains($st, '印在物字軌（預印紙）收據下半部') && !str_contains($st, '物、食兩個字軌都用這一封'), '信件區說明與列印一致：只有物字軌印信（大沈 1b88304）');
ok(str_contains($st, "if(r.classList.contains('editing'))"), '篩選只保留標了 editing 的編輯列（大沈 9845cc6：一般列的停用表單也有 hidden input）');
$sid = (int)$d->query("SELECT id FROM option_item WHERE kind = 'supplier' ORDER BY id LIMIT 1")->fetchColumn();
$se = req($E, ['a' => 'settings', 'e' => $sid]);
ok(preg_match('/<tr id="o' . $sid . '" class="[^"]*editing/', $se) === 1 && substr_count($se, ' editing"') === 1, '只有正在修改的那一列帶 editing 標記');
@file_put_contents(sys_get_temp_dir() . '/rcpt_settings_page.html', $se);   // 給 tests/receipt_filter_test.js 用
ok(!str_contains($st, '請在下面新增'), '空清單提示改成「上面新增」');
$ed = req($E, ['a' => 'edit', 'id' => $b4]);
ok(str_contains($ed, '已配號') && !str_contains($ed, '與信件內容'), '已配號提示不再說批次可補信件');
$d->exec("DELETE FROM option_item WHERE updated_by = 'test' AND code LIKE 'T0%'");


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
ok(!str_contains($p, '>修改</a>'), '唯讀者查詢結果沒有「修改」');
$pe = req($E, ['a' => 'search', 'q' => 'O-1']);
ok(preg_match('#href="\./\?a=batch&id=(\d+)&only=(\d+)\#r\2">修改</a>#', $pe, $mo) === 1, '開收據者查詢結果有「修改」，連到該批次、只顯示該筆');
$po = req($E, ['a' => 'batch', 'id' => $mo[1], 'only' => $mo[2]]);
ok(substr_count($po, '<tr id="r') === 1 && str_contains($po, '只顯示查詢的這一筆') && str_contains($po, '顯示整批'), '按「修改」後只出現這個人的那一列，並可回到整批');
ok(str_contains($po, 'only=' . $mo[2] . '">') || str_contains($po, '&amp;only=' . $mo[2]), '這一列的存檔表單帶著 only，存完仍只顯示這一筆');
echo "單筆輸入（繁英姊 11/18 第 7 項）\n";
ok(str_contains(req($E, []), '➕ 新增收據'), '分頁有「➕ 新增收據」（單筆輸入）入口');
ok(!str_contains(req('vw@test', []), '單筆輸入'), '唯讀者看不到單筆輸入');
req($E, ['a' => 'new', 'single' => '1'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'single' => '1', 'source' => 'ibon', 'in_date' => '115/09/21', 'items' => '']);
$bs = (int)$d->query("SELECT max(id) FROM batch")->fetchColumn();
ok(str_contains(req($E, ['a' => 'batch', 'id' => $bs]), 'id="single"'), '草稿批次頁有單筆輸入表單');
ok(str_contains(req('vw@test', ['a' => 'batch', 'id' => $bs], 'POST', ['csrf' => $csrf('vw@test'), 'do' => 'add_row', 'name' => '甲', 'qty' => '1']), '只能查詢'), '唯讀者 POST 單筆輸入擋下');
$o = req($E, ['a' => 'batch', 'id' => $bs], 'POST', ['csrf' => $csrf($E), 'do' => 'add_row', 'name' => '', 'qty' => '1', 'mobile' => '0912000111']);
ok(str_contains($o, '請填捐物人') && str_contains($o, '0912000111'), '沒填姓名擋下，已填的手機留著');
ok(str_contains(req($E, ['a' => 'batch', 'id' => $bs], 'POST', ['csrf' => $csrf($E), 'do' => 'add_row', 'name' => '乙', 'qty' => '0']), '數量請填正整數'), '數量 0 擋下');
ok(str_contains(req($E, ['a' => 'batch', 'id' => $bs], 'POST', ['csrf' => $csrf($E), 'do' => 'add_row', 'name' => '乙', 'qty' => '1', 'send' => '1', 'address' => '']), '請填地址'), '要寄但沒地址擋下');
req($E, ['a' => 'batch', 'id' => $bs], 'POST', ['csrf' => $csrf($E), 'do' => 'add_row', 'name' => '臨時捐物人', 'qty' => '2', 'zip' => '234', 'city' => '新北市永和區', 'address' => '保生路1號', 'send' => '1', 'order_no' => 'SINGLE-1119-1']);
req($E, ['a' => 'batch', 'id' => $bs], 'POST', ['csrf' => $csrf($E), 'do' => 'add_row', 'name' => '電話捐物人', 'qty' => '1']);
$rs = $d->query("SELECT row_no, name, qty, send, check_note FROM donation WHERE batch_id = $bs ORDER BY row_no")->fetchAll(PDO::FETCH_ASSOC);
ok(count($rs) === 2 && $rs[0]['row_no'] == 1 && $rs[1]['row_no'] == 2 && $rs[0]['qty'] == 2, '連加兩筆，row_no 依序 1、2');
ok($rs[0]['send'] && !$rs[1]['send'] && $rs[0]['check_note'] === '單筆輸入', '寄／不寄照勾選，標記為單筆輸入');
ok((int)$d->query("SELECT count(*) FROM audit WHERE action='add_row' AND batch_id=$bs")->fetchColumn() === 2, '每筆都留稽核紀錄');
req($E, ['a' => 'batch', 'id' => $bs], 'POST', ['csrf' => $csrf($E), 'do' => 'assign']);
ok((int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$bs AND receipt_no IS NOT NULL")->fetchColumn() === 2, '單筆輸入的批次可以配號');
ok(str_contains(req($E, ['a' => 'batch', 'id' => $bs], 'POST', ['csrf' => $csrf($E), 'do' => 'add_row', 'name' => '丙', 'qty' => '1']), '已經配號'), '配號後不能再單筆輸入');
echo "產品明細表格輸入（繁英姊 11/19）\n";
$fm = req($E, ['a' => 'new']);
ok(str_contains($fm, 'name="it_name[]"') && str_contains($fm, '＋ 加一列') && !str_contains($fm, 'name="items"'), '新增頁是表格（每格一欄）、有「加一列」');
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/09/22',
    'it_code' => ['A01218', '', 'B02052', ''], 'it_name' => ['壽司米2Kg', '', '統一大豆沙拉油760g, 家庭號', ''], 'it_qty' => ['2', '', '1', ''], 'it_unit' => ['包', '', '瓶', ''], 'it_price' => ['120', '', '', '']]);
$bt = (int)$d->query("SELECT max(id) FROM batch")->fetchColumn();
$its = $d->query("SELECT code, name, qty, unit, price FROM batch_item WHERE batch_id = $bt ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
ok(count($its) === 2, '品名空白的列不存（4 列存 2 筆）');
ok($its[0]['code'] === 'A01218' && $its[0]['name'] === '壽司米2Kg' && (float)$its[0]['qty'] === 2.0 && $its[0]['unit'] === '包' && (float)$its[0]['price'] === 120.0, '每格對到正確欄位');
ok($its[1]['name'] === '統一大豆沙拉油760g, 家庭號' && $its[1]['price'] === null, '品名有逗號不會被切開；單價空白存成空');
$fe = req($E, ['a' => 'edit', 'id' => $bt]);
ok(str_contains($fe, 'value="壽司米2Kg"') && str_contains($fe, 'value="統一大豆沙拉油760g, 家庭號"'), '編輯頁表格帶回原本的品項');
$er = req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '',
    'it_code' => ['X9'], 'it_name' => ['測試出錯品項'], 'it_qty' => ['3'], 'it_unit' => ['箱'], 'it_price' => ['']]);
ok(str_contains($er, '請填收貨日期') && str_contains($er, 'value="測試出錯品項"') && str_contains($er, 'value="X9"'), '出錯時表格裡已填的品項留著');
req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => 'ibon', 'in_date' => '115/09/23', 'items' => "T1,文字舊格式,5,包,10"]);
$bo = (int)$d->query("SELECT max(id) FROM batch")->fetchColumn();
ok((int)$d->query("SELECT count(*) FROM batch_item WHERE batch_id=$bo AND name='文字舊格式'")->fetchColumn() === 1, '沒有表格欄位時仍接受舊的文字格式');
echo "匯入／配號小視窗（繁英姊 11/19）\n";
$pb = req($E, ['a' => 'batch', 'id' => $bt]);
ok(str_contains($pb, 'id="rcBusy"') && str_contains($pb, 'rcBusy(this'), '批次頁有「處理中」小視窗並接在送出上');
$pd = req($E, ['a' => 'batch', 'id' => $bs, 'ok' => 'assigned']);
ok(str_contains($pd, 'id="rcDone"') && str_contains($pd, '配號完成') && !str_contains(req($E, ['a' => 'batch', 'id' => $bs]), 'id="rcDone"'), '完成後跳「配號完成」，平常不跳');
echo "分次列印 500 張一段（繁英姊 11/19）\n";
$d->exec("INSERT INTO batch (source, track, in_date, status, created_by) VALUES ('ibon', '物', '2026-10-01', 'numbered', 'ed@test')");
$bc = (int)$d->query("SELECT max(id) FROM batch")->fetchColumn();
$insC = $d->prepare("INSERT INTO donation (batch_id, row_no, seq, receipt_no, name, zip, city, address, qty, send) VALUES (?,?,?,?,?,?,?,?,1,true)");
for ($i = 1; $i <= 1101; $i++) $insC->execute([$bc, $i, $i, sprintf('CH-%05d', $i), '分段' . $i, sprintf('%03d', 100 + $i), '台北市', '路' . $i]);
$pc = preq($E, ['id' => $bc, 'part' => 'all']);
ok(substr_count($pc, 'class="page"') === 500 && substr_count($pc, '張</a>') === 3 && str_contains($pc, '第 1～500 張') && str_contains($pc, '第 1001～1101 張'), '1101 張分 3 段，預設只出第 1 段 500 張');
$pc2 = preq($E, ['id' => $bc, 'part' => 'all', 'chunk' => '2']);
ok(substr_count($pc2, 'class="page"') === 500 && str_contains($pc2, '第 2 段／共 3 段：第 501～1000 張'), '第 2 段是第 501～1000 張');
$pc3 = preq($E, ['id' => $bc, 'part' => 'all', 'chunk' => '3']);
ok(substr_count($pc3, 'class="page"') === 101, '最後一段只印剩下的 101 張');
preq($E, ['id' => $bc, 'part' => 'all', 'chunk' => '2', 'go' => '1']);
ok((int)$d->query("SELECT count FROM print_log WHERE batch_id = $bc ORDER BY id DESC LIMIT 1")->fetchColumn() === 500 && (int)$d->query("SELECT count(*) FROM audit WHERE batch_id = $bc AND detail LIKE '%第 2 段／共 3 段%'")->fetchColumn() === 1, '分段列印只記這一段 500 張，稽核寫明第幾段');
ok(str_contains($pc, 'id="rcBusy"') && str_contains($pc, 'rcPrintBusy(event)'), '列印頁有「準備列印中／產生預覽中」小視窗');
echo "大沈 d836044 審查補件\n";
$oq = req($E, ['a' => 'batch', 'id' => $bt], 'POST', ['csrf' => $csrf($E), 'do' => 'add_row', 'name' => '小數測試', 'qty' => '1.5']);
ok(str_contains($oq, '數量請填正整數') && str_contains($oq, 'value="1.5"') && (int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$bt AND name='小數測試'")->fetchColumn() === 0, '數量 1.5 擋下、不存、保留輸入');
$oa = req($E, ['a' => 'batch', 'id' => $bt], 'POST', ['csrf' => $csrf($E), 'do' => 'add_row', 'name' => '全形數量', 'qty' => '２']);
$nid = (int)$d->query("SELECT id FROM donation WHERE batch_id=$bt AND name='全形數量'")->fetchColumn();
ok($nid > 0 && (int)$d->query("SELECT qty FROM donation WHERE id=$nid")->fetchColumn() === 2, '全形數字 ２ 收成 2');
ok(str_contains($oa, 'X-TEST-LOCATION: ./?a=batch&id=' . $bt . '&ok=added#r' . $nid), '新增後導向的定位點是 donation.id（#r' . $nid . '）');
$or = req($E, ['a' => 'batch', 'id' => $mo[1], 'only' => $mo[2]], 'POST', ['csrf' => $csrf($E), 'do' => 'row', 'rid' => $mo[2], 'name' => '改後姓名', 'zip' => '100', 'city' => '台北市', 'address' => '改後地址1號', 'send' => '1']);
ok(str_contains($or, 'X-TEST-LOCATION: ./?a=batch&id=' . $mo[1] . '&only=' . $mo[2] . '&ok=row#r' . $mo[2]), 'only 頁存檔後 Location 保留 only');
$pf = req($E, ['a' => 'batch', 'id' => $mo[1], 'only' => $mo[2], 'ok' => 'row']);
ok(substr_count($pf, '<tr id="r') === 1 && str_contains($pf, '改後地址1號'), '跟隨導向後只顯示原列，畫面是新值');
ok($d->query("SELECT address FROM donation WHERE id=" . (int)$mo[2])->fetchColumn() === '改後地址1號', '重新查詢資料庫讀回新地址');
ok(str_contains($pc, 'oninput=') && str_contains($pc, 'c.value = 1'), '列印設定改份別／範圍／流水號時 chunk 回第 1 段');
$o01 = req($E, ['a' => 'batch', 'id' => $bt], 'POST', ['csrf' => $csrf($E), 'do' => 'add_row', 'name' => '前導零測試', 'qty' => '01']);
ok(str_contains($o01, '不能以 0 開頭') && str_contains($o01, 'value="01"') && (int)$d->query("SELECT count(*) FROM donation WHERE batch_id=$bt AND name='前導零測試'")->fetchColumn() === 0, '數量 01 擋下並說明不能以 0 開頭');
$fq = req($E, ['a' => 'batch', 'id' => $bt]);
ok(str_contains($fq, 'pattern="[1-9１-９][0-9０-９]{0,4}"'), '前端 pattern 與伺服器規則一致（首字 1～9、至多 5 位）');
echo "批次明細分頁（繁英姊 11/24：5,089 筆一次畫出太慢）
";
$d->exec("UPDATE batch SET first_no='CH-00001', last_no='CH-01101' WHERE id=$bc");   // 已配號批次要有收據起訖，批次頁才畫得出來
$g1 = req($E, ['a' => 'batch', 'id' => $bc]);
ok(substr_count($g1, '<tr id="r') === RCPT_PAGE && str_contains($g1, '共 1101 筆') && str_contains($g1, '下一頁') && str_contains($g1, '<b>1101</b>'), '第 1 頁只畫 ' . RCPT_PAGE . ' 列，上方筆數仍是整批 1101');
$g6 = req($E, ['a' => 'batch', 'id' => $bc, 'p' => '6']);
ok(substr_count($g6, '<tr id="r') === 101 && str_contains($g6, '第 1001–1101 筆') && !str_contains($g6, '下一頁'), '最後一頁 101 列、沒有下一頁');
$g99 = req($E, ['a' => 'batch', 'id' => $bc, 'p' => '99']);
ok(substr_count($g99, '<tr id="r') === 101, '頁碼超過時停在最後一頁');
ok(str_contains($g1, 'window.rcPageGo=function(){var n=changed().length') && substr_count($g1, 'onclick="return !window.rcPageGo || rcPageGo()"') === 2 * 6, '換頁前檢查本頁未存修改：上下頁碼與下一頁都接 rcPageGo（大沈 11/24 審查）');
ok(str_contains($g6, 'onclick="return !window.rcPageGo || rcPageGo()">上一頁</a>'), '上一頁也有未存檢查');
// 11/24 大沈審查 bb2b1e3：腳本語法錯誤時整段不執行、onclick 照樣放行，字串比對驗不到 → 把頁面腳本交給 node --check（主機沒有 node 時另存檔，在有 node 的機器補驗）
preg_match_all('#<script>(.*?)</script>#s', $g1, $jsm);
// 11/24 大沈審查 c6a0e1a：暫存檔名每次不同（平行重跑不互蓋），寫檔失敗就算失敗、不去 node --check 一個空檔
$jsf = sys_get_temp_dir() . '/rc_batch_scripts_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.js';
$jsw = file_put_contents($jsf, implode("\n;\n", $jsm[1]));
ok($jsw !== false && $jsw > 0 && count($jsm[1]) > 0, '批次頁腳本已抽出並寫入暫存檔');
if ($jsw === false) echo "  （略過 node --check：暫存檔寫入失敗）\n";
elseif (trim((string)shell_exec('command -v node 2>/dev/null')) !== '') { exec('node --check ' . escapeshellarg($jsf) . ' 2>&1', $jo, $jrc); ok($jrc === 0, '批次頁腳本 node --check 通過' . ($jrc ? '：' . implode(' ', array_slice($jo, 0, 3)) : '')); @unlink($jsf); }
else echo "  （略過：本機沒有 node，腳本已存 {$jsf}，請在有 node 的機器執行 node --check）\n";
$rid3 = (int)$d->query("SELECT id FROM donation WHERE batch_id=$bc AND row_no=450")->fetchColumn();
$g3 = req($E, ['a' => 'batch', 'id' => $bc, 'p' => '3']);
ok(str_contains($g3, 'id="r' . $rid3 . '"') && str_contains($g3, 'p=3"><input type="hidden" name="csrf"'), '第 3 頁有第 450 列，單列存檔表單帶 p=3');
$gs = req($E, ['a' => 'batch', 'id' => $bc, 'p' => '3'], 'POST', ['csrf' => $csrf($E), 'do' => 'row', 'rid' => $rid3, 'name' => '分頁存檔', 'zip' => '100', 'city' => '台北市', 'address' => '分頁路1號', 'send' => '1']);
ok(str_contains($gs, 'X-TEST-LOCATION: ./?a=batch&id=' . $bc . '&p=3&ok=row#r' . $rid3), '在第 3 頁存檔後回到第 3 頁');
$gb = req($E, ['a' => 'batch', 'id' => $bc, 'p' => '3'], 'POST', ['csrf' => $csrf($E), 'do' => 'rows_bulk', 'rows' => json_encode([['rid' => $rid3, 'name' => '分頁批次', 'zip' => '100', 'city' => '台北市', 'address' => '分頁路2號', 'send' => true, 'mailed_on' => '']])]);
ok(str_contains($gb, 'X-TEST-LOCATION: ./?a=batch&id=' . $bc . '&p=3&ok=bulk'), '全部儲存後也回到第 3 頁');
echo "統一編號、訂單編號與品名補登（繁英姊 11/24）\n";
$r2 = $d->query("SELECT id, name FROM donation WHERE batch_id = $bs AND row_no = 2")->fetch(PDO::FETCH_ASSOC);
$gb2 = req($E, ['a' => 'batch', 'id' => $bs]);
ok(str_contains($gb2, 'name="tax_id"') && str_contains($gb2, 'name="order_no"') && str_contains($gb2, '<th>統編</th>'), '已配號批次的清單有統編欄、訂單編號可編輯');
$rowPost = fn($extra) => req($E, ['a' => 'batch', 'id' => $bs], 'POST', array_merge(['csrf' => $csrf($E), 'do' => 'row', 'rid' => $r2['id'], 'name' => $r2['name'], 'zip' => '', 'city' => '', 'address' => ''], $extra));
ok(str_contains($rowPost(['order_no' => 'SINGLE-1119-1']), '已經開過收據'), '補登的訂單編號已有有效收據時擋下（看得懂的訊息）');
ok(str_contains($rowPost(['order_no' => '', 'tax_id' => '1234']), '統一編號要是 8 位數字'), '統編不是 8 位數字擋下');
$rowPost(['order_no' => 'LATE-0001', 'product' => '補登品項', 'tax_id' => '１２３４ ５６７８']);
$v2 = $d->query("SELECT order_no, product, tax_id, receipt_no FROM donation WHERE id = {$r2['id']}")->fetch(PDO::FETCH_ASSOC);
ok($v2['order_no'] === 'LATE-0001' && $v2['product'] === '補登品項' && $v2['tax_id'] === '12345678' && $v2['receipt_no'] !== null, '配號後補登訂單編號、品名、統編（全形與空白整理掉），收據編號不變');
ok(str_contains((string)$d->query("SELECT detail FROM audit WHERE action='edit_after_number' AND batch_id=$bs ORDER BY id DESC LIMIT 1")->fetchColumn(), 'order_no:  → LATE-0001'), '補登留改前改後稽核');
req($E, ['a' => 'batch', 'id' => $bs], 'POST', ['csrf' => $csrf($E), 'do' => 'rows_bulk', 'rows' => json_encode([['rid' => $r2['id'], 'name' => $r2['name'], 'zip' => '', 'city' => '', 'address' => '', 'send' => false, 'mailed_on' => '', 'order_no' => 'LATE-0001', 'product' => '補登品項', 'tax_id' => '87654321']])]);
ok($d->query("SELECT tax_id FROM donation WHERE id = {$r2['id']}")->fetchColumn() === '87654321', '全部儲存也存統編');
// 大沈審 4c2dfc0：統編欄不可在整理前就被截斷；食字軌已配號後品名改成未列品項要被擋，補印不受影響；被擋時畫面保留輸入
ok(!preg_match('#name="tax_id"[^>]*maxlength#', $gb2), '統編欄沒有字數上限（先整理分隔符號再判斷 8 位）');
$rowPost(['order_no' => 'LATE-0001', 'product' => '補登品項', 'tax_id' => '１２-３４-５６-７８']);
ok($d->query("SELECT tax_id FROM donation WHERE id = {$r2['id']}")->fetchColumn() === '12345678', '貼上「１２-３４-５６-７８」整理成 12345678');
$rjt = $rowPost(['order_no' => 'LATE-0001', 'product' => '補登品項', 'tax_id' => '12-34']);
ok(str_contains($rjt, '統一編號要是 8 位數字') && str_contains($rjt, '"tax_id":"12-34"') && str_contains($rjt, 'name="tax_id" value="12345678"') && str_contains($rjt, '畫面保留了您輸入的內容'), '統編被擋時保留剛輸入的值（由頁尾腳本填入；欄位初始值仍是資料庫值，換頁會算成未存修改——大沈複審 4744424）');
$d->exec("INSERT INTO batch (source, track, in_date, status, first_no, last_no, created_by) VALUES ('食測', '食', '2026-11-24', 'numbered', '食(115)11240001', '食(115)11240001', 'test')");
$bf = (int)$d->query("SELECT max(id) FROM batch")->fetchColumn();
$d->exec("INSERT INTO batch_item (batch_id, code, name, qty, unit) VALUES ($bf, 'B03004', '新東陽原味肉醬罐頭160g', 10, '罐')");
$d->exec("INSERT INTO donation (batch_id, row_no, seq, receipt_no, name, zip, city, address, product, qty, send) VALUES ($bf, 1, 1, '食(115)11240001', '好市多股份有限公司', '112', '台北市北投區', '立德路117號', '新東陽原味肉醬罐頭160g', 10, true)");
$rf = $d->query("SELECT id, name FROM donation WHERE batch_id = $bf")->fetch(PDO::FETCH_ASSOC);
$fPost = fn($extra) => req($E, ['a' => 'batch', 'id' => $bf], 'POST', array_merge(['csrf' => $csrf($E), 'do' => 'row', 'rid' => $rf['id'], 'name' => $rf['name'], 'zip' => '112', 'city' => '台北市北投區', 'address' => '立德路117號', 'send' => '1'], $extra));
$fr = $fPost(['product' => 'SHEN-UAT-未列品項']);
ok(str_contains($fr, '不在這一批的進貨明細') && str_contains($fr, '第 1 列'), '食字軌已配號後填未列品名 → 單列儲存被拒並指出列號');
ok($d->query("SELECT product FROM donation WHERE id = {$rf['id']}")->fetchColumn() === '新東陽原味肉醬罐頭160g', '被拒後舊品名不變');
ok(str_contains($fr, '"product":"SHEN-UAT-未列品項"') && str_contains($fr, 'name="product" value="新東陽原味肉醬罐頭160g"'), '被拒時保留剛輸入的品名（腳本填入），初始值仍是舊品名');
preg_match_all('#<script>(.*?)</script>#s', $fr, $frm); $frf = sys_get_temp_dir() . '/rc_refill_' . getmypid() . '.js'; file_put_contents($frf, implode("
;
", $frm[1]));
echo "  （回填頁腳本已存 {$frf}，有 node 的機器可 node --check）
";
$fb = req($E, ['a' => 'batch', 'id' => $bf], 'POST', ['csrf' => $csrf($E), 'do' => 'rows_bulk', 'rows' => json_encode([['rid' => $rf['id'], 'name' => $rf['name'], 'zip' => '112', 'city' => '台北市北投區', 'address' => '立德路117號', 'send' => true, 'mailed_on' => '', 'product' => 'SHEN-UAT-未列品項']])]);
ok(str_contains($fb, '不在這一批的進貨明細') && $d->query("SELECT product FROM donation WHERE id = {$rf['id']}")->fetchColumn() === '新東陽原味肉醬罐頭160g', '全部儲存同樣擋下、舊品名不變');
$fp = preq($E, ['id' => $bf, 'one' => $rf['id']]);
ok(str_contains($fp, 'class="page food"') && str_contains($fp, '新東陽原味肉醬罐頭160g') && !str_contains($fp, '收據不能列印'), '被拒後補印不受影響（食字軌收據照常印出舊品名）');
$fPost(['product' => '新東陽原味肉醬罐頭160g', 'tax_id' => '96979933']);
ok($d->query("SELECT tax_id FROM donation WHERE id = {$rf['id']}")->fetchColumn() === '96979933' && str_contains(preq($E, ['id' => $bf, 'one' => $rf['id']]), '統編 96979933'), '品名未改時照常存；收據印出統編');
echo "文中進貨單 PDF 帶入（繁英姊 11/24）\n";
// 仿文中列印的 PDF：Big5 字串、ASCII85 串流、沒有 ToUnicode；假資料
function wz_pdf(array $tokens): string {
    $a85 = function (string $b): string { $o = ''; $n = strlen($b);
        for ($i = 0; $i < $n; $i += 4) { $c = substr($b, $i, 4); $k = strlen($c); $c = str_pad($c, 4, "\0"); $v = unpack('N', $c)[1];
            if ($v === 0 && $k === 4) { $o .= 'z'; continue; } $g = ''; for ($j = 0; $j < 5; $j++) { $g = chr($v % 85 + 33) . $g; $v = intdiv($v, 85); } $o .= substr($g, 0, $k + 1); }
        return $o . '~>'; };
    $cs = "BT\n/F0 9 Tf\n";
    foreach ($tokens as $t) { $b = mb_convert_encoding($t, 'CP950', 'UTF-8'); $cs .= '(' . strtr($b, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ") Tj\n"; }
    $cs .= "ET\n"; $st = $a85($cs);
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n5 0 obj\n<< /Length " . strlen($st) . " /Filter /ASCII85Decode >>\nstream\n$st\nendstream\nendobj\n%%EOF\n";
}
$wzTok = ['社團法人中華基督教救助協會', '客戶代號:', '客戶名稱:', '聯 絡 人:', '聯絡電話:', '聯絡地址:', '單據日期:', '單據號碼:', '收貨日期:', '收據抬頭:', '收 件 人:', '寄件地址:', '捐贈用途:', '頁　　次:',
    '進貨單', '990001', '測試量販股份有限公司', '王小明', '', '台北市中正區測試路1號', '115/11/05', '11511050009', '115/11/06', '測試量販股份有限公司', '測試分店 陳主任', '新北市板橋區文化路一段100號', '食物銀行', '1 / 1',
    '批號', '有效期限', '收據編號:', '食(115)11060009',
    '0001', '測試肉醬罐頭160g(大)', '罐', '八里倉庫', '1,200.0', '45.000', '54,000.00', 'B03004', '備註:', '12罐/盒', '115', '20290726', '4710057500120',
    '0002', '許功蓋測試麵', '包', '八里倉庫', '30.0', '', '0.00', 'B04035', '115', '20271113', '4710043025569', '以下空白'];
$wzFile = tempnam(sys_get_temp_dir(), 'wz') . '.pdf'; file_put_contents($wzFile, wz_pdf($wzTok));
$pz = rcpt_parse_wenzhong_pdf((string)file_get_contents($wzFile));
ok($pz['po_no'] === '11511050009' && $pz['in_date'] === '115/11/06' && $pz['supplier_code'] === '990001' && $pz['track'] === '食', '解析：單據號碼、收貨日期（不是單據日期）、客戶代號、字軌');
ok($pz['title'] === '測試量販股份有限公司' && $pz['attn'] === '測試分店 陳主任' && $pz['addr'] === '新北市板橋區文化路一段100號', '解析：收據抬頭、收件人、寄件地址');
ok(count($pz['items']) === 2 && $pz['items'][0] === ['B03004', '測試肉醬罐頭160g(大)', 1200, '罐', 45] && $pz['items'][1][1] === '許功蓋測試麵' && $pz['items'][1][4] === null, '解析：明細（括號跳脫、Big5 第二位元組 0x5C 的字、空白單價）');
$bad = false; try { rcpt_parse_wenzhong_pdf("%PDF-1.4\n%%EOF"); } catch (RuntimeException $e) { $bad = str_contains($e->getMessage(), '進貨單'); }
ok($bad, '不是進貨單的 PDF 給看得懂的錯誤');
$pu = req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'pdf_prefill'], ['file' => ['name' => '文中進貨單.pdf', 'tmp_name' => $wzFile, 'error' => 0, 'size' => filesize($wzFile)]]);
ok(str_contains($pu, 'value="11511050009"') && str_contains($pu, 'value="2026-11-06"') && str_contains($pu, 'value="測試肉醬罐頭160g(大)"') && str_contains($pu, '已從「文中進貨單.pdf」帶入'), '上傳後新增批次表單已帶入單號、收貨日期、明細');
ok(str_contains($pu, '「食」字軌') && str_contains($pu, '供應商「990001 測試量販股份有限公司」不在清單') && str_contains($pu, 'name="pf_name" value="測試量販股份有限公司"'), '提示字軌與不在清單的供應商；捐贈者以隱藏欄位帶到儲存');
ok(!(int)$d->query("SELECT count(*) FROM batch WHERE po_no = '11511050009'")->fetchColumn(), '只帶入表單，還沒建批次');
$srcF = (string)$d->query("SELECT code FROM option_item WHERE kind = 'source' AND extra = '食' AND active ORDER BY sort, id LIMIT 1")->fetchColumn();
$sv = req($E, ['a' => 'new'], 'POST', ['csrf' => $csrf($E), 'do' => 'save_batch', 'source' => $srcF, 'po_no' => '11511050009', 'in_date' => '115/11/06',
    'it_code' => ['B03004'], 'it_name' => ['測試肉醬罐頭160g(大)'], 'it_qty' => ['1200'], 'it_unit' => ['罐'], 'it_price' => ['45'],
    'pf_name' => '測試量販股份有限公司', 'pf_attn' => '測試分店 陳主任', 'pf_addr' => '新北市板橋區文化路一段100號']);
$bpz = (int)$d->query("SELECT id FROM batch WHERE po_no = '11511050009'")->fetchColumn();
ok($bpz > 0 && str_contains($sv, 'X-TEST-LOCATION: ./?a=batch&id=' . $bpz . '&ok=saved&pf=1#single'), '儲存後轉到批次頁，網址只帶批次與 pf=1' . ($bpz ? '' : '：' . substr(strip_tags($sv), 0, 200)));
ok(!preg_match('#X-TEST-LOCATION:[^\n]*(pf_name|pf_addr|pf_attn|%E6%B8%AC|測試量販|文化路)#u', $sv), '轉址網址不含收據抬頭、收件人、地址（大沈審 5b711e9）');
$ps = req($E, ['a' => 'batch', 'id' => $bpz, 'pf' => '1']);
ok(str_contains($ps, 'id="s_name" name="name" value="測試量販股份有限公司"') && str_contains($ps, 'id="s_city" name="city" value="新北市板橋區"') && str_contains($ps, 'value="文化路一段100號"') && str_contains($ps, '收件人「測試分店 陳主任」'), '單筆輸入從伺服器暫存帶入收據抬頭、縣市區與地址，並提示收件人');
$d->exec("INSERT INTO users VALUES ('ed2@test','edit','') ON CONFLICT DO NOTHING");
ok(!str_contains(req('ed2@test', ['a' => 'batch', 'id' => $bpz, 'pf' => '1']), '測試量販股份有限公司'), '別的編輯帳號開同一批讀不到這份暫存');
$bOther = (int)$d->query("SELECT id FROM batch WHERE id <> $bpz AND status = 'draft' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok($bOther && !str_contains(req($E, ['a' => 'batch', 'id' => $bOther, 'pf' => '1']), '測試量販股份有限公司'), '同一帳號開別批也讀不到');
$d->exec("UPDATE pdf_prefill SET created_at = now() - interval '2 hours' WHERE batch_id = $bpz");
ok(!str_contains(req($E, ['a' => 'batch', 'id' => $bpz, 'pf' => '1']), '測試量販股份有限公司'), '超過 1 小時的暫存不再帶入');
$d->exec("UPDATE pdf_prefill SET created_at = now() WHERE batch_id = $bpz");
req($E, ['a' => 'batch', 'id' => $bpz], 'POST', ['csrf' => $csrf($E), 'do' => 'add_row', 'name' => '測試量販股份有限公司', 'qty' => '1', 'city' => '新北市板橋區', 'address' => '文化路一段100號']);
ok(!(int)$d->query("SELECT count(*) FROM pdf_prefill WHERE batch_id = $bpz")->fetchColumn(), '單筆輸入加入後刪除暫存');
@unlink($wzFile);
$bdraft = (int)$d->query("SELECT id FROM batch WHERE status = 'draft' ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($bdraft) {
    ok(str_contains(req($E, ['a' => 'batch', 'id' => $bdraft]), 'name="tax_id"'), '單筆輸入表單有統一編號欄');
    req($E, ['a' => 'batch', 'id' => $bdraft], 'POST', ['csrf' => $csrf($E), 'do' => 'add_row', 'name' => '公司捐贈', 'qty' => '1', 'tax_id' => '24536806']);
    ok($d->query("SELECT tax_id FROM donation WHERE batch_id = $bdraft AND name = '公司捐贈'")->fetchColumn() === '24536806', '單筆輸入存統編');
}
@unlink($tmp);
echo $fail ? "❌ 失敗 {$fail}／{$n}\n" : "✅ 全部通過 {$n} 項\n";
exit($fail ? 1 : 0);
