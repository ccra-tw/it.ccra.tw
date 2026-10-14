<?php
// 資訊服務申請（GitHub #8 骨架）伺服器端測試：以替身身分打申請頁與戰情室，再讀測試資料表。
// 用法：php tests/svc_apply_test.php <site 目錄> <lib 目錄>（由 tests/run_svc_test.py 準備好暫存副本後呼叫）
$SITE = $argv[1]; $LIB = $argv[2];
putenv('ITCCRA_TEST_DB=1');
require $LIB . '/svc_apply.php';
require $LIB . '/itstaff.php';
$secret = trim((string)file_get_contents('/var/lib/it-ccra/form_secret'));
$STAFF = 'ituncle@ccra.org.tw'; $STAFF2 = 'black@ccra.org.tw'; $OUT = 'nobody@example.org';
$fail = 0; $n = 0;
function ok($cond, $msg) { global $fail, $n; $n++; echo ($cond ? "  ✅ " : "  ❌ ") . $msg . "\n"; if (!$cond) $fail++; }
function req($email, $page, $method = 'GET', $post = [], $get = []) {
    global $SITE;
    $env = 'ITCCRA_TEST_DB=1 ITCCRA_TEST_EMAIL=' . escapeshellarg($email) . ' ITCCRA_REQ=' . escapeshellarg(json_encode(['page' => "$SITE/$page", 'method' => $method, 'post' => $post, 'get' => $get]));
    return (string)shell_exec("env $env php " . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
}
$d = svc_db();
$d->exec('TRUNCATE req RESTART IDENTITY');
$cnt = fn() => (int)$d->query('SELECT count(*) FROM req')->fetchColumn();
$row = fn($id) => $d->query('SELECT * FROM req WHERE id = ' . (int)$id)->fetch(PDO::FETCH_ASSOC);
$tok = fn($pfx, $em) => hash_hmac('sha256', $pfx . $em . date('Y-m-d'), $secret);

echo "申請頁\n";
$o = req($STAFF, 'apply/index.php');
ok(str_contains($o, '資訊服務申請') && str_contains($o, 'name="purpose"'), 'GET 顯示表單');
$good = ['csrf' => $tok('apply', $STAFF), 'type' => 'other', 'name' => '測試甲', 'purpose' => '測試用途', 'wanted_by' => ''];
req($STAFF, 'apply/index.php', 'POST', array_merge($good, ['csrf' => 'bad']));
ok($cnt() === 0, 'CSRF 錯誤不新增');
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($good, ['purpose' => '']));
ok($cnt() === 0 && str_contains($o, '請說明用途'), '用途空白被擋並提示');
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($good, ['wanted_by' => '2000-01-01']));
ok($cnt() === 0 && str_contains($o, '不能早於今天'), '希望完成日早於今天被擋');
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($good, ['type' => 'nope']));
ok($cnt() === 0 && str_contains($o, '請選擇申請類型'), '不存在的類型被擋');
req($STAFF, 'apply/index.php', 'POST', $good);
ok($cnt() === 1, '有效申請新增一筆');
$r = $row(1);
ok($r && $r['status'] === 'intake' && $r['type'] === 'other' && $r['applicant_email'] === $STAFF, '「其他」類直接進待受理，申請人正確');
req($STAFF, 'apply/index.php', 'POST', $good);
ok($cnt() === 1, '同人同類同對象重送不重複');
$o = req($STAFF, 'apply/index.php', 'GET', [], ['sent' => '1']);
ok(str_contains($o, 'S' . date('ymd') . '-001') && str_contains($o, '待受理'), '送出後顯示申請編號與狀態');
$o = req($STAFF2, 'apply/index.php', 'GET', [], ['sent' => '1']);
ok(!str_contains($o, 'S' . date('ymd') . '-001'), '別人看不到我的申請編號');

echo "戰情室\n";
$o = req($OUT, 'ops/index.php');
ok(str_contains($o, '戰情室只開放給資訊部帳號'), '非資訊部帳號被擋');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => 'bad', 'act' => 'svc', 'do' => 'accept', 'id' => 1]);
ok($row(1)['status'] === 'intake', 'CSRF 錯誤不受理');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF), 'act' => 'svc', 'do' => 'accept', 'id' => 1]);
$r = $row(1);
ok($r['status'] === 'doing' && $r['accepted_by'] === $STAFF && $r['first_response_at'], '資訊部受理 → 處理中、受理人即負責人、記第一次回應');
req($STAFF2, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF2), 'act' => 'svc', 'do' => 'accept', 'id' => 1]);
ok($row(1)['accepted_by'] === $STAFF, '已受理的不會被別人再受理');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF), 'act' => 'svc', 'do' => 'done', 'id' => 1, 'note' => '已處理']);
$r = $row(1);
ok($r['status'] === 'done' && $r['done_by'] === $STAFF && $r['done_note'] === '已處理' && $r['done_at'], '完成 → 已完成、記完成人與說明');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF), 'act' => 'svc', 'do' => 'done', 'id' => 1, 'note' => '再按一次']);
ok($row(1)['done_note'] === '已處理', '已完成的再按完成不改內容');

$d->exec('TRUNCATE req RESTART IDENTITY');
echo ($fail ? "🔴 失敗 {$fail}／{$n}\n" : "✅ 全部通過 {$n} 項\n");
exit($fail ? 1 : 0);
