<?php
// 捐款名單地址整理（/addr/、lib/addr.php、deploy/addr_sweep.php）伺服器端測試。全部用假資料；主機需有 /opt/addr-tool。
// 用法：php tests/addr_test.php <site 目錄> <lib 目錄>（由 tests/run_svc_test.py 準備好暫存副本後呼叫）
$SITE = $argv[1]; $LIB = $argv[2];
require $LIB . '/addr.php';
$fail = 0; $n = 0;
function ok($cond, $msg) { global $fail, $n; $n++; echo ($cond ? "  ✅ " : "  ❌ ") . $msg . "\n"; if (!$cond) $fail++; }
function req($email, $page, $method = 'GET', $post = []) {
    global $SITE;
    $r = ['page' => "$SITE/$page", 'method' => $method, 'get' => [], 'post' => $post];
    $env = 'ITCCRA_TEST_EMAIL=' . escapeshellarg($email) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($r));
    return (string)shell_exec("env $env php " . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
}
// 假名單：用 python3＋openpyxl 產生（與工具同一套函式庫）
function fake_xlsx(string $path, array $header, array $rows) {
    $py = 'import json,sys,openpyxl; d=json.loads(sys.argv[2]); wb=openpyxl.Workbook(); ws=wb.active; ws.title="訂單"; '
        . 'ws.append(d["h"]); [ws.append(r) for r in d["r"]]; wb.save(sys.argv[1])';
    shell_exec('python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg($path) . ' ' . escapeshellarg(json_encode(['h' => $header, 'r' => $rows], JSON_UNESCAPED_UNICODE)));
}
$root = sys_get_temp_dir() . '/addr-test-' . getmypid();
$scratch = sys_get_temp_dir() . '/addr-test-src-' . getmypid();
@mkdir($scratch, 0700, true);

echo "存取控制\n";
ok(str_contains(req('nobody@example.org', 'addr/index.php'), '只開放給捐款服務中心與資訊部'), '非資訊部、非捐款服務中心看不到');
$o = req('irenek@ccra.org.tw', 'addr/index.php');
ok(str_contains($o, 'name="files[]"') && str_contains($o, 'name="csrf"'), '資訊部看得到上傳表單');
ok(str_contains($o, '每 10 分鐘一次的排程補清'), '頁面寫的是實際補清方式（不承諾做不到的上限）');
ok(str_contains(req('irenek@ccra.org.tw', 'addr/index.php', 'POST', ['csrf' => 'x']), '頁面已過期'), '錯的 csrf 擋下');
$src = file_get_contents("$SITE/addr/index.php");
file_put_contents("$scratch/nosecret.php", str_replace("STATE_DIR . '/form_secret'", "'/nonexistent/form_secret'", $src));
ok(str_contains(req('irenek@ccra.org.tw', '../../' . basename($scratch) . '/nosecret.php'), '系統設定異常'), 'form_secret 讀不到：停用頁面，不用空金鑰');
unlink("$scratch/nosecret.php");

echo "檔名處理\n";
ok(addr_safe_name(0, '../../etc/passwd.xlsx') === '01_passwd.xlsx', '路徑跳脫被去掉');
ok(addr_safe_name(2, 'a:b*c?.xlsx') === '03_a_b_c_.xlsx', '特殊字元換成底線');

echo "搬檔失敗整批中止\n";
$job = addr_new_job($root);
ok($job !== null && (fileperms($job['dir']) & 0777) === 0700, '工單資料夾 0700');
fake_xlsx("$scratch/a.xlsx", ['收據號碼', '地址'], [['T1', '台北市大直街1號']]);
copy("$scratch/a.xlsx", "$scratch/b.xlsx");
$calls = 0;
$mover = function ($src, $dst) use (&$calls) { $calls++; return $calls === 2 ? false : copy($src, $dst); };   // 第二個檔失敗
ok(addr_stage($job['dir'], ["$scratch/a.xlsx", "$scratch/b.xlsx"], ['a.xlsx', 'b.xlsx'], $mover) === false, '第二個檔搬移失敗 → addr_stage 回 false');
ok(count(glob($job['dir'] . '/data/*')) === 1, '（只搬進一個，呼叫端據此中止，不會進整理）');
ok(addr_finish($job) === [] && !is_dir($job['dir']), '中止後工單刪除');

echo "實際整理（假資料）\n";
$job = addr_new_job($root);
fake_xlsx($job['dir'] . '/data/01_測試.xlsx', ['收據號碼', '訂購人', '收件人', '地址'], [
    ['T1', '測試甲', '測試甲', '新北市永和區保生路1號20樓'],
    ['T2', '測試乙', '測試乙', '台北市大直街1號'],
    ['T3', '無名', '無名', '愛心捐贈'],
]);
$r = addr_run($job['dir']);
ok($r['ok'] === true && $r['reports'] === 2, '產出兩份 xlsx（整理檔＋摘要）');
ok($r['ok'] && is_file($r['zip']) && filesize($r['zip']) > 0, '打包成 zip');
if ($r['ok']) {
    $list = shell_exec('python3 -c ' . escapeshellarg('import zipfile,sys;print("\n".join(zipfile.ZipFile(sys.argv[1]).namelist()))') . ' ' . escapeshellarg($r['zip']));
    ok(str_contains($list, '00_處理摘要與待確認清單.xlsx') && str_contains($list, '01_測試_地址整理.xlsx'), 'zip 內容是兩份整理結果');
    $py = 'import pandas as pd,sys; d=pd.read_excel(sys.argv[1],dtype=str); print("|".join(d["zip"].fillna("")+":"+d["收據"]))';
    $got = trim((string)shell_exec('python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg($job['dir'] . '/report/01_測試_地址整理.xlsx')));
    ok($got === '234:Y|104:Y|:N', "郵遞區號與收據判斷正確（{$got}）");
}
ok(addr_finish($job) === [] && !is_dir($job['dir']), '整理完工單刪除');

echo "失敗路徑\n";
$job = addr_new_job($root);
fake_xlsx($job['dir'] . '/data/01_沒有收據欄.xlsx', ['姓名', '地址'], [['測試丙', '台北市大直街1號']]);
$r = addr_run($job['dir']);
ok($r['ok'] === false && str_contains($r['err'], '收據號碼'), '沒有收據欄位：回清楚的錯誤說明');
addr_finish($job);
$job = addr_new_job($root);
fake_xlsx($job['dir'] . '/data/01_測試.xlsx', ['收據號碼', '地址'], [['T1', '台北市大直街1號']]);
$r = addr_run($job['dir'], '/nonexistent/build.py');
ok($r['ok'] === false && !isset($r['zip']), '工具不存在：不會產出 zip');
addr_finish($job);

echo "總期限、提前收尾與殘留程序\n";
// 用 [a]ddrmark 這種寫法：比對字串本身不會出現在 pgrep 外面那層 sh 的命令列，避免數到自己。
// ⚠️ 標記程序不能用 sleep 60 <tag>：GNU sleep 遇到多餘參數會直接結束，殘留檢查會因此永遠通過（10/21 自我檢查抓到）。
function residue(string $tag): int { return (int)trim((string)shell_exec('pgrep -fc ' . escapeshellarg('[a]ddrmark-' . $tag) . ' || true')); }
$tag = 'addrtest' . getmypid();
$probe = proc_open(['python3', '-c', 'import time;time.sleep(60)', "addrmark-$tag-z"], [], $pp); usleep(300000);
ok(residue("$tag-z") === 1, '（自我檢查：殘留偵測看得到真的在跑的程序）');
proc_terminate($probe, 9); proc_close($probe);
file_put_contents("$scratch/slow.py", "import time\ntime.sleep(60)\n");
file_put_contents("$scratch/closer.py", "import os,subprocess,time\nsubprocess.Popen(['python3','-c','import time;time.sleep(60)','addrmark-$tag-a'])\nos.close(1); os.close(2)\ntime.sleep(60)\n");
file_put_contents("$scratch/flood.py", "import subprocess,sys,time\nsubprocess.Popen(['python3','-c','import time;time.sleep(60)','addrmark-$tag-b'])\nsys.stdout.write('y'*20000000); sys.stdout.flush()\ntime.sleep(60)\n");
file_put_contents("$scratch/slowzip.py", "import subprocess,time\nsubprocess.Popen(['python3','-c','import time;time.sleep(60)','addrmark-$tag-c'])\ntime.sleep(60)\n");
$job = addr_new_job($root);
$t0 = microtime(true); $r = addr_run($job['dir'], "$scratch/slow.py", 2); $dt = microtime(true) - $t0;
ok($r['ok'] === false && str_contains($r['err'], '5 分鐘') && $dt < 15, sprintf('整理超過期限被中止（%.1f 秒）', $dt));
$t0 = microtime(true); $r = addr_run($job['dir'], "$scratch/closer.py", 3); $dt = microtime(true) - $t0;
ok($r['ok'] === false && $dt >= 2.5 && $dt < 15, sprintf('關掉兩個輸出後繼續跑：不提前收尾，等到期限（%.1f 秒）', $dt));
ok(residue("$tag-a") === 0, '…返回後子程序（含孫程序）都已結束');
$t0 = microtime(true); $r = addr_run($job['dir'], "$scratch/flood.py", 3); $dt = microtime(true) - $t0;
ok($r['ok'] === false && $dt < 15, sprintf('輸出 20MB 後掛住：期限內收尾（%.1f 秒）', $dt));
ok(residue("$tag-b") === 0, '…返回後沒有殘留程序');
addr_finish($job);
$job = addr_new_job($root);
fake_xlsx($job['dir'] . '/data/01_測試.xlsx', ['收據號碼', '地址'], [['T1', '台北市大直街1號']]);
$t0 = microtime(true); $r = addr_run($job['dir'], ADDR_TOOL, 12, "$scratch/slowzip.py"); $dt = microtime(true) - $t0;
ok($r['ok'] === false && str_contains($r['err'], '5 分鐘') && $dt < 25, sprintf('打包階段逾時也被中止（%.1f 秒）', $dt));
ok(residue("$tag-c") === 0, '…打包的子程序沒有殘留');
addr_finish($job);

echo "補清（被殺留下的工單）\n";
$old = addr_new_job($root);                  // 模擬 worker 被殺：鎖釋放、資料夾還在
fake_xlsx($old['dir'] . '/data/01_x.xlsx', ['收據號碼'], [['T9']]);
flock($old['lock'], LOCK_UN); fclose($old['lock']);
$busy = addr_new_job($root);                 // 仍在處理中：鎖還握著
$young = addr_new_job($root);                // 剛建立、沒人用
flock($young['lock'], LOCK_UN); fclose($young['lock']);
foreach ([$old['dir'], $busy['dir']] as $d) touch($d, time() - 3600);
@mkdir("$root/not-a-job", 0700); touch("$root/not-a-job", time() - 3600);
[$del, $failed] = addr_sweep($root, ADDR_MAX_AGE, null, []);
ok($del === 1 && $failed === [] && !is_dir($old['dir']), '超過 30 分鐘、沒人在用的工單被刪');
ok(is_dir($busy['dir']), '還握著鎖的工單不刪');
ok(is_dir($young['dir']), '未滿 30 分鐘的不刪');
ok(is_dir("$root/not-a-job"), '不是工單格式的資料夾不碰');
addr_finish($busy); addr_cleanup($young['dir']); @rmdir("$root/not-a-job");
$lk = addr_new_job($root); flock($lk['lock'], LOCK_UN); fclose($lk['lock']);
unlink($lk['dir'] . '/.lock'); mkdir($lk['dir'] . '/.lock'); touch($lk['dir'], time() - 3600);   // 鎖檔開不了
[$del, $failed] = addr_sweep($root, ADDR_MAX_AGE, null, []);
ok($del === 0 && count($failed) === 1 && str_starts_with($failed[0], 'lock ') && is_dir($lk['dir']), '工單鎖開不了：不刪、回報失敗');
rmdir($lk['dir'] . '/.lock'); addr_cleanup($lk['dir']);
chmod($root, 0000);
[$del, $failed] = addr_sweep($root, ADDR_MAX_AGE, null, []);
chmod($root, 0700);
ok($failed && str_starts_with($failed[0], 'scan '), '補清讀不到根目錄：回報失敗，不當成功');
$stray = "$scratch/stray"; mkdir($stray, 0700);
file_put_contents("$stray/phpOLD", 'x'); touch("$stray/phpOLD", time() - 3600);
file_put_contents("$stray/phpNEW", 'x');
[$del, $failed] = addr_sweep("$root-none", ADDR_MAX_AGE, null, ["$stray/php*"]);
ok($del === 1 && !file_exists("$stray/phpOLD") && file_exists("$stray/phpNEW"), 'PHP／nginx 暫存：只清滿 30 分鐘的');
@rmdir($root);
ok(!is_dir($root), '測試暫存根目錄清乾淨');
addr_cleanup($scratch);

echo "$n 項，失敗 $fail\n";
exit($fail ? 1 : 0);
