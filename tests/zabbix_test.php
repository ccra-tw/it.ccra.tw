<?php
// 戰情室設備監控：ping-only 主機的狀態判斷、主機→網站對照讀檔（2026-11-02）。
// 用法：php tests/zabbix_test.php <site 目錄> <lib 目錄>（由 tests/run_svc_test.py 準備好暫存副本後呼叫）
$SITE = $argv[1]; $LIB = $argv[2];
// 每次執行用獨立的狀態目錄，平行測試不會共用同一個快取檔（大沈 11-25）
if (!defined('STATE_DIR')) { $_sd = sys_get_temp_dir() . '/zbx-state-' . getmypid() . '-' . bin2hex(random_bytes(4)); mkdir($_sd, 0700, true); define('STATE_DIR', $_sd); }
require $LIB . '/zabbix.php';
$fail = 0; $n = 0;

echo "主機 IP：依 main／useip／dns 取，迴路位址交給本機代理補值（大沈 11-20 審查）\n";
ok(zbx_pick_ip([['type'=>1,'main'=>1,'useip'=>1,'ip'=>'192.168.200.50','dns'=>'']]) === '192.168.200.50', '單一介面 → 它的 ip');
ok(zbx_pick_ip([['type'=>1,'main'=>0,'useip'=>0,'ip'=>'10.0.0.9','dns'=>'old.test'],['type'=>1,'main'=>1,'useip'=>1,'ip'=>'192.168.200.7','dns'=>'']]) === '192.168.200.7', '第一筆不是主要介面且保留舊 ip → 取第二筆主要介面，不顯示舊值');
ok(zbx_pick_ip([['type'=>1,'main'=>1,'useip'=>0,'ip'=>'10.0.0.9','dns'=>'hq.ccra.test']]) === 'hq.ccra.test', 'DNS-only 主要介面（useip=0）→ 顯示 dns，不顯示殘留的 ip');
ok(zbx_pick_ip([['type'=>2,'main'=>1,'useip'=>1,'ip'=>'192.168.1.2'],['type'=>1,'main'=>1,'useip'=>1,'ip'=>'192.168.1.1']]) === '192.168.1.1', '兩個主要介面 → 代理介面優先於 SNMP');
ok(zbx_pick_ip([['type'=>1,'main'=>1,'useip'=>1,'ip'=>'::1']]) === '', 'IPv6 迴路 ::1 → 空字串（交給本機代理補值）');
ok(zbx_pick_ip([['type'=>1,'main'=>1,'useip'=>1,'ip'=>'127.0.0.1']]) === '', 'IPv4 迴路 → 空字串');
ok(zbx_pick_ip([['type'=>1,'main'=>1,'useip'=>1,'ip'=>'127.0.0.1'],['type'=>2,'main'=>1,'useip'=>1,'ip'=>'192.168.100.9']]) === '192.168.100.9', '主要介面是迴路、另有真實介面 → 取真實介面');
ok(zbx_pick_ip([]) === '', '沒有介面 → 空字串');
ok((zbx_access_tag('10084')['level'] ?? '') === 'manage', 'ITSrv01 標可管理');
ok((zbx_access_tag('10689')['text'] ?? '') === '大蘇可查看', 'HQ 標可查看（唯讀）');
ok(zbx_os_short('Linux version 7.0.0-1020-azure (buildd@lcy02-amd64-058) (x86_64-linux-gnu-gcc-13 (Ubuntu 13.3.0-6ubuntu2~24.04.1) 13.3.0) #21~24.04.2-Ubuntu SMP') === 'Linux 7.0.0-1020-azure', 'Linux 版本字串只留核心版號');
ok(zbx_os_short('Windows Server 2022 Standard 20348.1.amd64fre.fe_release.210507-1500 Build 20348.5761') === 'Windows Server 2022 Standard（Build 20348.5761）', 'Windows Server 去掉內部版號、留組建號');
ok(zbx_os_short('Windows 10 Pro 26100.1.amd64fre.ge_release.240331-1435 Build 26300.997') === 'Windows 11 Pro（Build 26300.997）', '組建號 22000 以上的「Windows 10」改稱 Windows 11');
ok(zbx_os_short('Windows 10 Pro 19041.1.amd64fre.vb_release.191206-1406 Build 19045.784') === 'Windows 10 Pro（Build 19045.784）', '真正的 Windows 10 不改名');
ok(zbx_os_short('Linux neh 5.10.55+ #90080 SMP Wed Jul 22 00:25:34 CST 2026 x86_64') === 'Linux 5.10.55+', 'NAS 的 SNMP sysDescr');
ok(zbx_os_short('USG FLEX 700H') === 'USG FLEX 700H', '防火牆型號原樣');
ok(zbx_sysline(['os' => 'Linux 7.0.0-43-generic', 'cores' => 4, 'memtotal' => 7784030208, 'uptime' => 845488]) === 'Linux 7.0.0-43-generic｜4 核｜記憶體 7 GB｜開機 9 天', '完整一行');
ok(zbx_sysline(['os' => '', 'cores' => null, 'memtotal' => null, 'uptime' => 12186]) === '開機 3 小時', '不到一天顯示小時、缺欄位略過');
ok(zbx_sysline(['os' => '', 'cores' => null, 'memtotal' => null, 'uptime' => null]) === '', '全缺 → 空字串（頁面不顯示該行）');
ok(zbx_access_tag('10690') === null, 'SERVER3 沒開通 → 不標，不假裝有權限');
ok(zbx_pick_ip([['type'=>1,'main'=>1,'useip'=>0,'ip'=>'','dns'=>'127.monitor.example']]) === '127.monitor.example', 'DNS 名稱以 127. 開頭 → 照樣顯示，不當迴路（大沈二審）');
ok(zbx_pick_ip([['type'=>1,'main'=>1,'useip'=>1,'ip'=>'0:0:0:0:0:0:0:1']]) === '', 'IPv6 迴路展開寫法 0:0:0:0:0:0:0:1 → 空字串（大沈二審）');
ok(zbx_pick_ip([['type'=>1,'main'=>1,'useip'=>1,'ip'=>'127.12.34.56']]) === '', '127/8 其他位址 → 空字串');
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

echo "快取格式版本與主機列驗證（大沈 11-25：舊快取缺 host 查錯主機；格式不符的列讓分組拋 TypeError）\n";
$zc = STATE_DIR . '/zabbix_cache.json';
$zrow = fn(array $x = []) => $x + ['hostid' => '1', 'name' => 'Demo Host', 'group' => 'Linux 主機', 'avail' => 1, 'problems' => []];
file_put_contents($zc, json_encode(['at' => time(), 'hosts' => [$zrow()]]));
[$zd, $zmsg] = zabbix_hosts();
ok(($zd['v'] ?? 0) === ZBX_CACHE_VER || $zmsg !== '', '有效的舊版快取（缺 host、未滿 60 秒）不當新鮮資料');
ok(zbx_cache_read($zc, false) !== null && zbx_cache_read($zc, true) === null, '有效舊版：刷新失敗時可回退，但不當新鮮資料（缺 host）');
file_put_contents($zc, json_encode(['v' => ZBX_CACHE_VER, 'at' => time(), 'hosts' => ['只是字串']]));
ok(zbx_cache_read($zc, false) === null && zbx_cache_read($zc, true) === null, '目前版本但主機列是字串 → 整份不採用');
[$zd, $zmsg] = zabbix_hosts();
ok($zd === null || zbx_row_ok($zd['hosts'][0] ?? [], false), '格式不符的快取不會流到設備監控（不是重抓成功，就是回傳 null）');
foreach ([['avail' => '1'], ['group' => null], ['problems' => 'x'], ['problems' => ['x']], ['problems' => [['name' => ['陣列'], 'severity' => 2]]], ['problems' => [['name' => '磁碟過慢', 'severity' => '2']]]] as $bad) {
    file_put_contents($zc, json_encode(['v' => ZBX_CACHE_VER, 'at' => time(), 'hosts' => [$zrow(['host' => 'demo-host-01'] + $bad)]]));
    ok(zbx_cache_read($zc, false) === null, '欄位型別不符拒收：' . json_encode($bad, JSON_UNESCAPED_UNICODE));
}
file_put_contents($zc, json_encode(['v' => ZBX_CACHE_VER, 'at' => time(), 'hosts' => [$zrow(['host' => 'demo-host-01', 'problems' => [['name' => '磁碟過慢', 'severity' => 2, 'clock' => 1]]])]]));
[$zd, $zmsg] = zabbix_hosts();
ok($zmsg === '' && ($zd['hosts'][0]['host'] ?? '') === 'demo-host-01', '新版有效快取（含 host）未滿 60 秒直接採用');
file_put_contents($zc, '{壞掉的 JSON');
ok(zbx_cache_read($zc) === null, '快取壞掉 → 讀不到，不回傳半套資料');
@unlink($zc);
ok(zbx_cache_read($zc) === null, '快取不存在 → 讀不到');
@rmdir(STATE_DIR);

echo "\n$n 項，失敗 $fail\n";
exit($fail ? 1 : 0);
