<?php
// 戰情室設備監控：ping-only 主機的狀態判斷、主機→網站對照讀檔（2026-11-02）。
// 用法：php tests/zabbix_test.php <site 目錄> <lib 目錄>（由 tests/run_svc_test.py 準備好暫存副本後呼叫）
$SITE = $argv[1]; $LIB = $argv[2];
if (!defined('STATE_DIR')) define('STATE_DIR', sys_get_temp_dir());
require $LIB . '/zabbix.php';
$fail = 0; $n = 0;
function ok($cond, $msg) { global $fail, $n; $n++; echo ($cond ? "  ✅ " : "  ❌ ") . $msg . "\n"; if (!$cond) $fail++; }

echo "可用狀態：有代理看代理，只有 ping 看 ping\n";
ok(zbx_avail([['available' => 1]], null) === 1, '代理正常 → 正常');
ok(zbx_avail([['available' => 2]], '1') === 2, '代理連不到，就算 ping 通也算連不到（服務掛了）');
ok(zbx_avail([['available' => 0]], '1') === 1, '只有 ping、ping 通 → 正常（不再顯示狀態未知）');
ok(zbx_avail([['available' => 0]], '0') === 2, '只有 ping、ping 不通 → 連不到（紅燈印得出來）');
ok(zbx_avail([['available' => 0]], null) === 0, '沒介面狀態也沒 ping 資料 → 仍是未知，不假裝正常');
ok(zbx_avail([], '1') === 1, '完全沒有介面、只有 ping 項目 → 看 ping');

echo "網站對照讀檔\n";
$dir = sys_get_temp_dir() . '/zbx-test-' . getmypid(); @mkdir($dir, 0700, true);
$f = "$dir/host_sites.json";
@unlink($f);
[$m, $at] = zbx_sites($f);
ok($m === [] && $at === '', '檔案不在 → 空對照，不報錯');
file_put_contents($f, '{bad json');
[$m, $at] = zbx_sites($f);
ok($m === [], '壞 JSON → 空對照');
file_put_contents($f, json_encode(['updated_at' => '11/02 22:00', 'hosts' => ['10686' => ['www.example.org', 123, 'b.example.org'], '10688' => 'not-a-list']]));
[$m, $at] = zbx_sites($f);
ok(($m['10686'] ?? null) === ['www.example.org', 'b.example.org'], '只收字串網址，數字之類的雜值丟掉');
ok(!isset($m['10688']), '不是清單的主機略過');
ok($at === '11/02 22:00', '更新時間讀得到');
@unlink($f); @rmdir($dir);

echo "\n$n 項，失敗 $fail\n";
exit($fail ? 1 : 0);
