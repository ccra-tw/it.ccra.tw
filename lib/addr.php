<?php
// 捐款名單地址整理：呼叫繁英姊的 Python 工具（主機 /opt/addr-tool，不在本 repo；
// 規則與郵遞區號對照表由她維護）。網頁 /addr/ 與 tests/addr_test.php 共用這裡。
// 🔴 名單含捐款人個資：每次一個獨立暫存資料夾（0700，在網站根目錄外），呼叫端必須在結束時 addr_cleanup()。
const ADDR_TOOL = '/opt/addr-tool/tools/build.py';
const ADDR_MAX_FILES = 10;

function addr_new_job(string $root): string {
    @mkdir($root, 0700, true);
    $job = $root . '/' . bin2hex(random_bytes(12));
    mkdir("$job/data", 0700, true);
    mkdir("$job/report", 0700, true);
    return $job;
}

function addr_safe_name(int $i, string $name): string {
    return sprintf('%02d_', $i + 1) . preg_replace('/[\/\\\\:*?"<>|\x00-\x1f]/u', '_', basename($name));
}

function addr_cleanup(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) { $p = "$dir/$f"; is_dir($p) ? addr_cleanup($p) : @unlink($p); }
    @rmdir($dir);
}

// 跑工具並打包。回傳 ['ok'=>true,'zip'=>路徑,'reports'=>N] 或 ['ok'=>false,'err'=>給使用者看的說明,'rc'=>代碼]
function addr_run(string $job, string $tool = ADDR_TOOL, int $timeout = 300): array {
    $env = ['ADDR_DATA' => "$job/data", 'ADDR_REPORT' => "$job/report", 'PATH' => '/usr/bin:/bin',
            'PYTHONIOENCODING' => 'utf-8', 'PYTHONDONTWRITEBYTECODE' => '1', 'HOME' => $job];
    $run = function (array $cmd, string $cwd) use ($env) {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (!is_resource($p)) return 127;
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        return proc_close($p);
    };
    $rc = $run(['timeout', (string)$timeout, 'python3', $tool], $job);
    $reports = glob("$job/report/*.xlsx") ?: [];
    // 只有摘要、沒有任何「_地址整理.xlsx」＝沒有工作表被處理（多半是缺收據欄），當失敗回報，不讓人下載一份空結果
    $done = array_filter($reports, fn($f) => !str_starts_with(basename($f), '00_'));
    if ($rc === 124) return ['ok' => false, 'rc' => $rc, 'err' => '處理超過 5 分鐘被中止，請分批上傳。'];
    if ($rc !== 0 || !$done)
        return ['ok' => false, 'rc' => $rc, 'err' => '整理失敗，請確認檔案裡有「收據號碼」或「收據編號」欄位；仍不行請找資訊部（分機 181）。'];
    $zip = "$job/out.zip";
    $zrc = $run(array_merge(['python3', '-m', 'zipfile', '-c', $zip], array_map('basename', $reports)), "$job/report");
    if ($zrc !== 0 || !is_file($zip)) return ['ok' => false, 'rc' => $zrc, 'err' => '打包結果失敗，請再試一次。'];
    return ['ok' => true, 'zip' => $zip, 'reports' => count($reports)];
}
