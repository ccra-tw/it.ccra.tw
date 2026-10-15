<?php
// 戰情室推播個人設定（熊哥 2026-10-15：個人開關要收哪幾類＋勿擾時間）伺服器端測試。
// 用法：php tests/push_prefs_test.php <site 目錄> <lib 目錄>（由 tests/run_svc_test.py 準備好暫存副本後呼叫）
$SITE = $argv[1]; $LIB = $argv[2];
putenv('ITCCRA_TEST_DB=1');
require $LIB . '/webpush.php';
$fail = 0; $n = 0;
function ok($cond, $msg) { global $fail, $n; $n++; echo ($cond ? "  ✅ " : "  ❌ ") . $msg . "\n"; if (!$cond) $fail++; }
function req($email, $page, $method = 'GET', $raw = null) {
    global $SITE;
    $r = ['page' => "$SITE/$page", 'method' => $method, 'get' => []];
    if ($raw !== null) $r['raw'] = $raw;
    $env = 'ITCCRA_TEST_DB=1 ITCCRA_TEST_EMAIL=' . escapeshellarg($email) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($r));
    return (string)shell_exec("env $env php " . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
}

echo "勿擾時段判斷\n";
ok(push_in_quiet('22:00', '07:00', '23:30'), '跨午夜：23:30 在 22:00～07:00 內');
ok(push_in_quiet('22:00', '07:00', '06:59'), '跨午夜：06:59 在內');
ok(!push_in_quiet('22:00', '07:00', '07:00'), '跨午夜：07:00 已結束');
ok(!push_in_quiet('22:00', '07:00', '12:00'), '跨午夜：中午不在內');
ok(push_in_quiet('12:00', '13:30', '12:45'), '同一天：12:45 在 12:00～13:30 內');
ok(!push_in_quiet('12:00', '13:30', '13:30'), '同一天：13:30 已結束');
ok(!push_in_quiet('', '', '03:00'), '沒設勿擾就不擋');
ok(!push_in_quiet('08:00', '08:00', '08:00'), '起訖相同視為沒設');

echo "類別對應與是否送出\n";
ok(push_cat('zabbix') === 'host' && push_cat('probe') === 'site' && push_cat('security') === 'security', 'zabbix／probe／security 對到三類');
ok(push_cat('svc_apply') === 'apply' && push_cat('repair') === 'apply' && push_cat('cafe_join') === 'apply', '申請單各來源都歸新申請單');
ok(push_cat('test') === null && push_cat('cli') === null, '測試與手動推播不分類');
$p = ['off' => 'host,apply', 'quiet_from' => '22:00', 'quiet_to' => '07:00'];
ok(!push_wants($p, 'host', '12:00'), '關掉主機異常就不送');
ok(push_wants($p, 'site', '12:00'), '沒關的網站斷線白天照送');
ok(!push_wants($p, 'site', '23:00'), '勿擾時段內網站斷線不送');
ok(push_wants($p, 'security', '23:00'), '資安事件勿擾時段照送');
ok(push_wants(['off' => 'security'], 'security', '12:00'), '資安事件就算被寫成關掉也照送');
ok(push_wants($p, null, '23:00'), '測試通知不受設定影響');

echo "存取設定\n";
$d = push_db();
$d->exec('DELETE FROM prefs');
$me = 'ituncle@ccra.org.tw';
ok(push_prefs_set($d, $me, ['host', 'security', 'nope'], '22:00', '07:00') === '', '存設定成功');
$g = push_prefs_get($d, $me);
ok($g['off'] === 'host', '只留合法且可關的類別（security、亂填的被濾掉）：' . $g['off']);
ok($g['quiet_from'] === '22:00' && $g['quiet_to'] === '07:00', '勿擾時段存進去');
ok(push_prefs_set($d, $me, [], '25:00', '07:00') !== '', '時間格式錯誤會擋');
ok(push_prefs_set($d, $me, [], '22:00', '') !== '', '只填一邊會擋');
ok(push_prefs_get($d, $me)['quiet_from'] === '22:00', '被擋的不會覆蓋原設定');
ok(push_prefs_set($d, $me, [], '', '') === '' && push_prefs_get($d, $me)['quiet_from'] === '', '兩格清空＝取消勿擾');

echo "push.php 端點\n";
$out = req($me, 'ops/push.php', 'POST', json_encode(['op' => 'prefs', 'off' => ['apply'], 'quiet_from' => '23:00', 'quiet_to' => '06:00']));
ok(str_contains($out, '"ok":true'), 'POST prefs 回 ok：' . trim(substr($out, 0, 80)));
$j = json_decode(trim(req($me, 'ops/push.php')), true);
ok(($j['off'] ?? null) === ['apply'] && ($j['quiet_from'] ?? '') === '23:00', 'GET 讀回剛存的設定');
ok(isset($j['cats']['host']) && in_array('security', $j['always'] ?? [], true), 'GET 帶類別清單與不可關的類別');
$out = req('nobody@example.org', 'ops/push.php', 'POST', json_encode(['op' => 'prefs', 'off' => ['host']]));
ok(!str_contains($out, '"ok":true'), '非資訊部帳號不能存');
$d->exec('DELETE FROM prefs');

echo "\n共 $n 項，失敗 $fail 項\n";
exit($fail ? 1 : 0);
