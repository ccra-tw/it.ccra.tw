<?php
// 捐款名單地址整理：呼叫繁英姊的 Python 工具（主機 /opt/addr-tool，不在本 repo；
// 規則與郵遞區號對照表由她維護）。網頁 /addr/、tests/addr_test.php、deploy/addr-sweep.php 共用這裡。
// 🔴 名單含捐款人個資，三道清除：
//   ①網頁結束時 addr_cleanup()（register_shutdown_function）
//   ②php-fpm 被殺、OOM、主機重開時①不會跑 → it-ccra-addr-sweep.timer 每 10 分鐘掃 addr-tmp，
//     超過 ADDR_MAX_AGE 且工單鎖拿得到（沒有程式在用）的一律刪，刪不掉寫紀錄並以非零結束
//   ③每次工單都有總期限，整理＋打包超過就強制終止（TERM 後 10 秒 KILL）
// （小鹿 10/21 互審 32d7733 指出①不足以保證刪除、逾時未涵蓋打包、搬檔失敗仍繼續，本版三項皆補。）
const ADDR_TOOL = '/opt/addr-tool/tools/build.py';
const ADDR_ROOT = '/var/lib/it-ccra/addr-tmp';
const ADDR_MAX_FILES = 10;
const ADDR_DEADLINE = 300;     // 整理＋打包共用的總秒數
const ADDR_MAX_AGE = 1800;     // 補清：工單資料夾最多存在 30 分鐘
const ADDR_OUT_CAP = 65536;    // 子程式輸出最多讀這麼多位元組（只為了排空 pipe，內容不記錄）

// 建工單：0700 資料夾＋工單鎖（處理期間持有，補清程序拿不到鎖就不刪）。失敗回 null。
function addr_new_job(string $root = ADDR_ROOT): ?array {
    if (!is_dir($root) && !@mkdir($root, 0700, true)) return null;
    $job = $root . '/' . bin2hex(random_bytes(12));
    if (!@mkdir("$job/data", 0700, true) || !@mkdir("$job/report", 0700)) { addr_cleanup($job); return null; }
    @chmod($job, 0700);
    $lock = @fopen("$job/.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { addr_cleanup($job); return null; }
    return ['dir' => $job, 'lock' => $lock];
}

function addr_safe_name(int $i, string $name): string {
    return sprintf('%02d_', $i + 1) . preg_replace('/[\/\\\\:*?"<>|\x00-\x1f]/u', '_', basename($name));
}

// 把上傳檔搬進工單；任一個失敗就回 false（呼叫端整批中止）。$move 可替換，測試用。
function addr_stage(string $job, array $tmpNames, array $names, ?callable $move = null): bool {
    $move ??= 'move_uploaded_file';
    foreach ($names as $i => $n) {
        $dst = "$job/data/" . addr_safe_name($i, $n);
        if (!$move($tmpNames[$i], $dst) || !is_file($dst)) return false;
    }
    return true;
}

// 刪資料夾；回傳刪不掉的路徑清單（空陣列＝全刪掉）。
function addr_cleanup(string $dir): array {
    $failed = [];
    if (!is_dir($dir)) return $failed;
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
        $p = "$dir/$f";
        if (is_dir($p) && !is_link($p)) $failed = array_merge($failed, addr_cleanup($p));
        elseif (!@unlink($p)) $failed[] = $p;
    }
    if (!@rmdir($dir)) $failed[] = $dir;
    return $failed;
}

function addr_finish(array $job): array {
    if (is_resource($job['lock'])) { flock($job['lock'], LOCK_UN); fclose($job['lock']); }
    $failed = addr_cleanup($job['dir']);
    if ($failed) error_log('it.ccra.tw addr: cleanup failed ' . count($failed) . ' path(s) under ' . basename($job['dir']));
    return $failed;
}

// 跑一個子程式，期限內同時排空 stdout／stderr；回傳結束碼（逾時 124）。
function addr_exec(array $cmd, string $cwd, array $env, int $seconds): int {
    if ($seconds <= 0) return 124;
    $p = proc_open(array_merge(['timeout', '--kill-after=10', (string)$seconds], $cmd),
                   [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($p)) return 127;
    foreach ([1, 2] as $k) stream_set_blocking($pipes[$k], false);
    $open = [1 => $pipes[1], 2 => $pipes[2]]; $read = 0;
    $until = microtime(true) + $seconds + 15;          // timeout 本身＋KILL 後限之外再留一點餘裕
    while ($open && microtime(true) < $until) {
        $r = array_values($open); $w = $e = null;
        if (@stream_select($r, $w, $e, 1) === false) break;
        foreach ($open as $k => $fh) {
            $chunk = fread($fh, 8192);
            if ($chunk !== false && $chunk !== '') $read += strlen($chunk);   // 只計量不保存
            if (feof($fh)) { fclose($fh); unset($open[$k]); }
        }
        if ($read > ADDR_OUT_CAP * 64) break;           // 輸出異常大量：不再讀，交給期限收尾
    }
    foreach ($open as $fh) fclose($fh);
    $st = proc_get_status($p);
    if ($st['running']) { proc_terminate($p, 9); }
    return proc_close($p);
}

// 跑工具並打包。回傳 ['ok'=>true,'zip'=>路徑,'reports'=>N] 或 ['ok'=>false,'err'=>給使用者看的說明,'rc'=>代碼]
function addr_run(string $job, string $tool = ADDR_TOOL, int $deadline = ADDR_DEADLINE): array {
    $env = ['ADDR_DATA' => "$job/data", 'ADDR_REPORT' => "$job/report", 'PATH' => '/usr/bin:/bin',
            'PYTHONIOENCODING' => 'utf-8', 'PYTHONDONTWRITEBYTECODE' => '1', 'HOME' => $job];
    $end = time() + $deadline;
    $rc = addr_exec(['python3', $tool], $job, $env, $end - time());
    $reports = glob("$job/report/*.xlsx") ?: [];
    // 只有摘要、沒有任何「_地址整理.xlsx」＝沒有工作表被處理（多半是缺收據欄），當失敗回報，不讓人下載一份空結果
    $done = array_filter($reports, fn($f) => !str_starts_with(basename($f), '00_'));
    if ($rc === 124 || $rc === 137) return ['ok' => false, 'rc' => $rc, 'err' => '處理超過 5 分鐘被中止，請分批上傳。'];
    if ($rc !== 0 || !$done)
        return ['ok' => false, 'rc' => $rc, 'err' => '整理失敗，請確認檔案裡有「收據號碼」或「收據編號」欄位；仍不行請找資訊部（分機 181）。'];
    $zip = "$job/out.zip";
    $zrc = addr_exec(array_merge(['python3', '-m', 'zipfile', '-c', $zip], array_map('basename', $reports)), "$job/report", $env, $end - time());
    if ($zrc === 124 || $zrc === 137) return ['ok' => false, 'rc' => $zrc, 'err' => '處理超過 5 分鐘被中止，請分批上傳。'];
    if ($zrc !== 0 || !is_file($zip)) return ['ok' => false, 'rc' => $zrc, 'err' => '打包結果失敗，請再試一次。'];
    return ['ok' => true, 'zip' => $zip, 'reports' => count($reports)];
}

// 補清：刪掉 $root 底下超過 $maxAge 秒、且工單鎖拿得到的資料夾。回傳 [刪除數, 刪不掉的路徑清單]。
function addr_sweep(string $root = ADDR_ROOT, int $maxAge = ADDR_MAX_AGE, ?int $now = null): array {
    $now ??= time(); $deleted = 0; $failed = [];
    if (!is_dir($root)) return [0, []];
    foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $name) {
        $dir = "$root/$name";
        if (!is_dir($dir) || is_link($dir) || !preg_match('/\A[0-9a-f]{24}\z/', $name)) continue;   // 只動工單資料夾
        if ($now - filemtime($dir) < $maxAge) continue;
        $lock = @fopen("$dir/.lock", 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); continue; }   // 還有程式在用
        $f = addr_cleanup($dir);
        if ($lock) fclose($lock);
        $f ? $failed = array_merge($failed, $f) : $deleted++;
    }
    return [$deleted, $failed];
}
