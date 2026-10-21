<?php
// 捐款名單地址整理（/addr/、lib/addr.php）伺服器端測試。全部用假資料；主機需有 /opt/addr-tool。
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

echo "存取控制\n";
ok(str_contains(req('nobody@example.org', 'addr/index.php'), '只開放給捐款服務中心與資訊部'), '非資訊部、非捐款服務中心看不到');
$o = req('irenek@ccra.org.tw', 'addr/index.php');
ok(str_contains($o, 'name="files[]"') && str_contains($o, 'name="csrf"'), '資訊部看得到上傳表單');
ok(str_contains(req('irenek@ccra.org.tw', 'addr/index.php', 'POST', ['csrf' => 'x']), '頁面已過期'), '錯的 csrf 擋下');

echo "檔名處理\n";
ok(addr_safe_name(0, '../../etc/passwd.xlsx') === '01_passwd.xlsx', '路徑跳脫被去掉');
ok(addr_safe_name(2, 'a:b*c?.xlsx') === '03_a_b_c_.xlsx', '特殊字元換成底線');

echo "實際整理（假資料）\n";
$job = addr_new_job($root);
ok(is_dir("$job/data") && (fileperms($job) & 0777) === 0700, '暫存資料夾 0700');
fake_xlsx("$job/data/01_測試.xlsx", ['收據號碼', '訂購人', '收件人', '地址'], [
    ['T1', '測試甲', '測試甲', '新北市永和區保生路1號20樓'],
    ['T2', '測試乙', '測試乙', '台北市大直街1號'],
    ['T3', '無名', '無名', '愛心捐贈'],
]);
$r = addr_run($job);
ok($r['ok'] === true && $r['reports'] === 2, '產出兩份 xlsx（整理檔＋摘要）');
ok($r['ok'] && is_file($r['zip']) && filesize($r['zip']) > 0, '打包成 zip');
if ($r['ok']) {
    $list = shell_exec('python3 -c ' . escapeshellarg('import zipfile,sys;print("\n".join(zipfile.ZipFile(sys.argv[1]).namelist()))') . ' ' . escapeshellarg($r['zip']));
    ok(str_contains($list, '00_處理摘要與待確認清單.xlsx') && str_contains($list, '01_測試_地址整理.xlsx'), 'zip 內容是兩份整理結果');
    $py = 'import pandas as pd,sys; d=pd.read_excel(sys.argv[1],dtype=str); print("|".join(d["zip"].fillna("")+":"+d["收據"]))';
    $got = trim((string)shell_exec('python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg("$job/report/01_測試_地址整理.xlsx")));
    ok($got === '234:Y|104:Y|:N', "郵遞區號與收據判斷正確（{$got}）");
}
addr_cleanup($job);
ok(!is_dir($job), '整理完暫存資料夾刪除');

echo "失敗路徑\n";
$job = addr_new_job($root);
fake_xlsx("$job/data/01_沒有收據欄.xlsx", ['姓名', '地址'], [['測試丙', '台北市大直街1號']]);
$r = addr_run($job);
ok($r['ok'] === false && str_contains($r['err'], '收據號碼'), '沒有收據欄位：回清楚的錯誤說明');
addr_cleanup($job);
$job = addr_new_job($root);
fake_xlsx("$job/data/01_測試.xlsx", ['收據號碼', '地址'], [['T1', '台北市大直街1號']]);
$r = addr_run($job, '/nonexistent/build.py');
ok($r['ok'] === false && !isset($r['zip']), '工具不存在：不會產出 zip');
addr_cleanup($job);
@rmdir($root);
ok(!is_dir($root), '測試暫存根目錄清乾淨');

echo "$n 項，失敗 $fail\n";
exit($fail ? 1 : 0);
