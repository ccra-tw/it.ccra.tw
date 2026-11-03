<?php
// 主機操作紀錄（lib/hostlog.php、/ops/hostlog.php、deploy/hostlog_cli.php）伺服器端測試（2026-11-03）。
// 用法：php tests/hostlog_test.php <site 目錄> <lib 目錄>（由 tests/run_svc_test.py 準備好暫存副本後呼叫）
// 資料一律寫 hostlog_test schema（ITCCRA_TEST_DB=1），不碰正式表。
$SITE = $argv[1]; $LIB = $argv[2];
$CLI = dirname($SITE) . '/deploy/hostlog_cli.php';
putenv('ITCCRA_TEST_DB=1');
require $LIB . '/hostlog.php';
require $LIB . '/itstaff.php';
$fail = 0; $n = 0;
function ok($cond, $msg) { global $fail, $n; $n++; echo ($cond ? "  ✅ " : "  ❌ ") . $msg . "\n"; if (!$cond) $fail++; }
function req($email, $page, $method = 'GET', $get = [], $post = []) {
    global $SITE;
    $r = ['page' => "$SITE/$page", 'method' => $method, 'get' => $get, 'post' => $post];
    $env = 'ITCCRA_TEST_DB=1 ITCCRA_TEST_EMAIL=' . escapeshellarg($email) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($r));
    return (string)shell_exec("env $env php " . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
}
function cli(array $args, string $stdin = ''): array {
    global $CLI;
    $p = proc_open(array_merge(['env', 'ITCCRA_TEST_DB=1', 'php', $CLI], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}
function base(array $x = []): array {
    return $x + ['host' => 'TESTHOST1', 'kind' => '設定變更', 'operator' => '測試甲', 'operator_email' => 'tester@example.org', 'created_by' => 'test',
        'ts' => null, 'ts_note' => '', 'what' => '', 'why' => '', 'change' => '', 'verify' => '', 'undo' => '', 'ticket' => ''];
}
$d = hostlog_db();
$d->exec('DELETE FROM entry');

echo "寫入與驗證\n";
[$ts1] = hostlog_parse_time('2026-10-05 09:30');
$id1 = hostlog_add(base(['ts' => $ts1, 'what' => 'systemctl restart nginx', 'why' => '設定更新', 'undo' => '還原 nginx.conf.bak 後重新載入', 'ticket' => 'PL-1111']));
$id2 = hostlog_add(base(['host' => 'testhost2', 'kind' => '程式部署', 'ts' => hostlog_parse_time('2026-11-01 22:10')[0], 'what' => 'deploy.sh --force', 'operator' => '測試乙', 'operator_email' => 'other@example.org']));
$id3 = hostlog_add(base(['ts_note' => '10 月底某晚（確切時間不明）', 'what' => '搬移 /srv/old 到 /srv/new', 'kind' => '檔案搬移']));
ok($id1 > 0 && $id2 > $id1 && $id3 > $id2, '透過 lib 新增三筆，回傳遞增編號');
$thrown = function (array $e) { try { hostlog_add($e); return false; } catch (InvalidArgumentException $x) { return true; } };
ok($thrown(base(['what' => 'x', 'kind' => '亂寫'])), '類型不在清單內拒收');
ok($thrown(base(['what' => 'x', 'host' => 'bad host;rm'])), '主機名稱含空白或怪字元拒收');
ok($thrown(base(['what' => ''])), '「做了什麼」空白拒收');
ok($thrown(base(['what' => 'x', 'ts' => null, 'ts_note' => ''])), '沒有時間也沒有時間說明拒收');
ok($thrown(base(['what' => 'mysql -u root password=abc123'])) && $thrown(base(['what' => 'x', 'undo' => '密碼：abc'])), '看起來含密碼的內容拒收');
ok(!$thrown(base(['ts' => $ts1, 'what' => 'chmod 600 /var/lib/x/zabbix-ops.token: 只改權限'])) , '只提到 token 檔名不誤擋');
$d->exec("DELETE FROM entry WHERE what LIKE 'chmod 600%'");

echo "時間解析\n";
ok(hostlog_parse_time('2026-10-05 09:30')[0] === '2026-10-05T09:30:00+08:00', '「YYYY-MM-DD HH:MM」以台北時間存');
ok(hostlog_parse_time('2026-10-05T09:30')[0] === '2026-10-05T09:30:00+08:00', '網頁 datetime-local 的 T 分隔也認');
ok(hostlog_parse_time('2026-02-30 09:30') === [null, '2026-02-30 09:30'], '不存在的日期不硬轉，改存說明');
ok(hostlog_parse_time('11/02 晚上') === [null, '11/02 晚上'], '非標準寫法存進時間說明');

echo "篩選\n";
[$r, $t] = hostlog_search(['host' => 'TestHost2']);
ok($t === 1 && $r[0]['id'] == $id2, '主機篩選不分大小寫');
[$r, $t] = hostlog_search(['q' => 'NGINX.CONF']);
ok($t === 1 && $r[0]['id'] == $id1, '關鍵字也搜「還原方式」欄、不分大小寫');
[$r, $t] = hostlog_search(['q' => '100%']);
ok($t === 0, '關鍵字裡的 % 當一般字元，不變成萬用字元');
[$r, $t] = hostlog_search(['from' => '2026-10-05', 'to' => '2026-10-05']);
ok($t === 1 && $r[0]['id'] == $id1, '日期區間含當天（台北時間）');
[$r, $t] = hostlog_search(['from' => '2026-10-06', 'to' => '2026-11-01']);
ok($t === 1 && $r[0]['id'] == $id2, '區間外的不出現');
[$r, $t] = hostlog_search(['operator' => '測試乙']);
ok($t === 1 && $r[0]['id'] == $id2, '操作人篩選');
[$r, $t] = hostlog_search(['ticket' => 'pl-1111']);
ok($t === 1 && $r[0]['id'] == $id1, '工單篩選');
[$r, $t] = hostlog_search([]);
ok($t === 3 && $r[0]['id'] == $id3 && $r[1]['id'] == $id2, '全部列出，新的在前（時間不明的以建立時間排序）');
ok($r[2]['ts_local'] === '2026-10-05 09:30', '列表時間以台北時間顯示');
[$r, $t] = hostlog_search([], 2, 2);
ok($t === 3 && count($r) === 1 && $r[0]['id'] == $id1, '分頁：第二頁取到剩下的');
ok(hostlog_hosts() === ['TESTHOST1', 'testhost2'], '主機下拉選單取不重複主機');
$latest = hostlog_latest_by_host(['testhost1', 'TESTHOST2', 'NOPE']);
ok(count($latest['testhost1'] ?? []) === 2 && count($latest['testhost2'] ?? []) === 1 && !isset($latest['nope']), '設備監控用：依 Zabbix 主機名不分大小寫取最新幾筆');

echo "頁面\n";
$o = req('someone@example.org', 'ops/hostlog.php');
ok(strpos($o, '只開放給資訊部') !== false && strpos($o, 'systemctl') === false, '非資訊部看不到');
hostlog_add(base(['ts' => $ts1, 'what' => '<script>alert(1)</script>', 'why' => '"><img src=x onerror=alert(2)>']));
$o = req('ituncle@ccra.org.tw', 'ops/hostlog.php');
ok(strpos($o, '<script>alert(1)') === false && strpos($o, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, '內容裡的 <script> 被跳脫');
ok(strpos($o, '<img src=x') === false, '屬性注入也被跳脫');
ok(strpos($o, 'systemctl restart nginx') !== false && strpos($o, 'name="csrf"') !== false, '資訊部看得到列表與新增表單');
$o2 = req('ituncle@ccra.org.tw', 'ops/hostlog.php', 'GET', ['host' => 'testhost2']);
ok(strpos($o2, 'deploy.sh --force') !== false && strpos($o2, 'systemctl restart nginx') === false && strpos($o2, '符合 <b>1</b> 筆') !== false, '頁面主機篩選');
preg_match('/name="csrf" value="([0-9a-f]+)"/', $o, $m);
$csrf = $m[1] ?? '';
$post = ['host' => 'TESTHOST3', 'kind' => '唯讀查詢', 'time' => '2026-11-03T10:00', 'what' => 'df -h', 'operator' => '冒名者'];
$before = hostlog_search([])[1];
$o = req('ituncle@ccra.org.tw', 'ops/hostlog.php', 'POST', [], $post);
ok(strpos($o, '頁面已過期') !== false && hostlog_search([])[1] === $before, '沒帶 csrf 不收');
$o = req('ituncle@ccra.org.tw', 'ops/hostlog.php', 'POST', [], $post + ['csrf' => 'bad']);
ok(hostlog_search([])[1] === $before, 'csrf 錯的不收');
$o = req('ituncle@ccra.org.tw', 'ops/hostlog.php', 'POST', [], $post + ['csrf' => $csrf]);
[$r, $t] = hostlog_search(['host' => 'TESTHOST3']);
ok($t === 1 && strpos($o, '已新增一筆') !== false, '帶正確 csrf 新增成功');
ok(($r[0]['operator'] ?? '') === IT_STAFF['ituncle@ccra.org.tw'] && $r[0]['operator_email'] === 'ituncle@ccra.org.tw', '操作人取登入身分，表單送來的名字不採用');
ok(($r[0]['ts_local'] ?? '') === '2026-11-03 10:00', '表單時間照台北時間存');
$o = req('ituncle@ccra.org.tw', 'ops/hostlog.php', 'POST', [], ['csrf' => $csrf, 'host' => 'TESTHOST3', 'kind' => '其他', 'time' => '', 'ts_note' => '', 'what' => 'x']);
ok(strpos($o, '要有操作時間') !== false && hostlog_search(['host' => 'TESTHOST3'])[1] === 1, '沒時間也沒說明：顯示錯誤、不寫入');

echo "命令列\n";
$rows = [
    ['host' => 'CLIHOST', 'time' => '2026-11-02 23:05', 'type' => '資料推送', 'what' => '推 host_sites.json', 'why' => '每日更新', 'change' => '舊檔 → 新檔', 'verify' => '戰情室看得到', 'undo' => '還原前一天的檔', 'ticket' => 'PL-2222'],
    ['host' => 'clihost', 'time' => '11/01 下午（約略）', 'type' => '唯讀查詢', 'what' => 'Get-Service'],
];
[$rc, $out, $err] = cli(['add'], json_encode($rows, JSON_UNESCAPED_UNICODE));
ok($rc === 0 && strpos($out, '已新增 2 筆') !== false, '命令列 add 印出新增筆數');
[$r, $t] = hostlog_search(['host' => 'CLIHOST']);
$byWhat = array_column($r, null, 'what');
ok($t === 2, '兩筆都寫入');
ok(($byWhat['推 host_sites.json']['ts_local'] ?? '') === '2026-11-02 23:05' && $byWhat['推 host_sites.json']['ts_note'] === null, '確切時間存成時間欄（台北時間）');
ok(isset($byWhat['Get-Service']) && $byWhat['Get-Service']['ts'] === null && $byWhat['Get-Service']['ts_note'] === '11/01 下午（約略）', '不確定的時間存成時間說明、時間欄留空');
ok(($byWhat['Get-Service']['operator'] ?? '') === 'IT大蘇' && $byWhat['Get-Service']['operator_email'] === 'ituncle@ccra.org.tw', 'who／who_email 預設 IT大蘇');
[$rc, $out, $err] = cli(['add'], json_encode([['host' => 'CLIHOST', 'time' => '2026-11-02 23:05', 'type' => '其他', 'what' => 'ok'], ['host' => 'CLIHOST', 'type' => '亂寫', 'what' => 'x']], JSON_UNESCAPED_UNICODE));
ok($rc !== 0 && strpos($err, '第 2 筆') !== false && hostlog_search(['host' => 'CLIHOST'])[1] === 2, '有一筆不合格就整批不寫（重送不會一半重複）');
[$rc, $out, $err] = cli(['add'], '{"host":"x"}');
ok($rc === 2, 'stdin 不是陣列：拒絕');
[$rc, $out, $err] = cli(['export', '--month', '2026-11']);
ok($rc === 0 && str_starts_with($out, "\xEF\xBB\xBF") && strpos($out, '推 host_sites.json') !== false, '匯出 CSV 帶 BOM、含當月資料');
ok(strpos($out, 'systemctl restart nginx') === false, '匯出只含指定月份');
hostlog_add(base(['ts' => hostlog_parse_time('2026-11-03 08:00')[0], 'what' => '=HYPERLINK("http://x")']));
[$rc, $out, $err] = cli(['export', '--month', '2026-11']);
ok(strpos($out, "'=HYPERLINK") !== false, '匯出時公式開頭加單引號，Excel 不執行');
[$rc, $out, $err] = cli(['export', '--month', '2026-13']);
ok($rc === 2, '月份格式錯：拒絕');

$d->exec('DELETE FROM entry');
echo "\n$n 項，失敗 $fail\n";
exit($fail ? 1 : 0);
