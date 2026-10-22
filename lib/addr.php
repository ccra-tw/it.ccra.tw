<?php
// 捐款名單地址整理：呼叫繁英姊的 Python 工具（主機 /opt/addr-tool，不在本 repo；
// 規則與郵遞區號對照表由她維護）。網頁 /addr/、tests/addr_test.php、deploy/addr_sweep.php 共用這裡。
// 🔴 名單含捐款人個資，三道清除：
//   ①網頁結束時 addr_finish()（register_shutdown_function）
//   ②php-fpm 被殺、OOM、主機重開時①不會跑 → it-ccra-addr-sweep.timer 每 10 分鐘補清：
//     addr-tmp 下滿 30 分鐘且工單鎖拿得到的工單，以及 /addr/ 專用上傳暫存（.upload／.nginx-body）裡滿 30 分鐘、沒有程序開著的檔；
//     掃不到、鎖不開、刪不掉都算失敗 → 紀錄＋推戰情室＋非零結束
//   ③每次工單都有總期限，整理＋打包超過就對整個程序群組 TERM、再 KILL，確認回收後才返回
// （小鹿 10/21 兩次互審 32d7733、c8f1602 的各項，本版皆補，見 tests/addr_test.php。）
const ADDR_TOOL = '/opt/addr-tool/tools/build.py';
const ADDR_ROOT = '/var/lib/it-ccra/addr-tmp';
const ADDR_MAX_FILES = 10;
const ADDR_DEADLINE = 300;     // 整理＋打包共用的總秒數
const ADDR_MAX_AGE = 1800;     // 補清門檻：滿 30 分鐘（排程每 10 分鐘，故中斷後最晚約 40 分鐘清除）
const ADDR_REPORT_SUFFIX = '_地址整理.xlsx';
// /addr/ 專用的 PHP 上傳暫存與 nginx 請求本文暫存（見 deploy/it-ccra-addr-fpm.conf、nginx 設定）。
// 只有 /addr/ 會寫進這兩處，所以補清只清這裡、不碰其他功能的 /tmp 或 nginx 共用暫存。
const ADDR_SPOOL_DIRS = ['.upload', '.nginx-body'];
const ADDR_POOL = 'it-ccra-addr';   // /addr/ 專用 PHP-FPM 池名稱（deploy/it-ccra-addr-fpm.conf）

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

// 刪資料夾；回傳刪不掉的路徑清單（空陣列＝全刪掉）。讀不到目錄內容也算失敗。
function addr_cleanup(string $dir): array {
    $failed = [];
    if (!is_dir($dir)) return $failed;
    $list = @scandir($dir);
    if ($list === false) return [$dir];
    foreach (array_diff($list, ['.', '..']) as $f) {
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

// 對整個程序群組送訊號，直到群組沒有程序或逾時。回傳群組是否已清空。
// $p 是 proc_open 的程序：每輪呼叫 proc_get_status 收掉已結束的群組首程序，否則它留成殭屍，群組看起來永遠還在。
// 訊號用數字常數：SIGTERM／SIGKILL 由 pcntl 提供，命令列 PHP 有、php-fpm 沒有（10/22 繁英姊第一次實際上傳就因此 500，
// 測試全在命令列跑所以沒抓到）。Linux 上 TERM=15、KILL=9。
const ADDR_SIG_TERM = 15;
const ADDR_SIG_KILL = 9;

function addr_kill_group(int $pgid, $p): bool {
    foreach ([ADDR_SIG_TERM, ADDR_SIG_KILL] as $sig) {
        @posix_kill(-$pgid, $sig);
        for ($i = 0; $i < 50; $i++) {                       // 每種訊號最多等 5 秒
            if (is_resource($p)) proc_get_status($p);
            if (!@posix_kill(-$pgid, 0)) return true;          // 群組裡已沒有程序
            usleep(100000);
        }
    }
    return !@posix_kill(-$pgid, 0);
}

// 跑一個子程式。setsid 讓它（含 timeout 底下的 python）自成一個程序群組；
// 「輸出讀完」與「程序結束」分開判斷，期限一到或提前收尾時，對整個群組 TERM→KILL 並確認回收。
// 回傳結束碼；逾時 124；群組殺不掉 125（呼叫端當失敗處理）。
function addr_exec(array $cmd, string $cwd, array $env, int $seconds): int {
    if ($seconds <= 0) return 124;
    $p = proc_open(array_merge(['setsid', 'timeout', '--kill-after=5', (string)$seconds], $cmd),
                   [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($p)) return 127;
    $pgid = proc_get_status($p)['pid'];                    // setsid 不 fork，pid 即新群組 id
    foreach ([1, 2] as $k) stream_set_blocking($pipes[$k], false);
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $until = microtime(true) + $seconds;
    $exit = null;
    while (microtime(true) < $until) {
        if ($open) {                                         // 有 pipe 就排空（內容丟棄、不記錄）
            $r = array_values($open); $w = $e = null;
            if (@stream_select($r, $w, $e, 0, 200000) !== false)
                foreach ($open as $k => $fh) { while (($c = fread($fh, 65536)) !== false && $c !== ''); if (feof($fh)) { fclose($fh); unset($open[$k]); } }
        } else usleep(200000);                               // pipe 都關了，程序可能還在跑：繼續等，不提前收尾
        $st = proc_get_status($p);
        if (!$st['running']) { $exit = $st['exitcode']; break; }
    }
    foreach ($open as $fh) fclose($fh);
    $clean = addr_kill_group($pgid, $p);                         // 不論怎麼結束，都確認整個群組沒有殘留
    $rc = proc_close($p);
    if (!$clean) { error_log("it.ccra.tw addr: process group $pgid survived KILL"); return 125; }
    if ($exit === null) return 124;                          // 期限到仍在跑
    return $exit >= 0 ? $exit : $rc;
}

// 跑工具並打包。回傳 ['ok'=>true,'zip'=>路徑,'reports'=>N] 或 ['ok'=>false,'err'=>給使用者看的說明,'rc'=>代碼]
function addr_run(string $job, string $tool = ADDR_TOOL, int $deadline = ADDR_DEADLINE, ?string $zipper = null): array {
    $env = ['ADDR_DATA' => "$job/data", 'ADDR_REPORT' => "$job/report", 'PATH' => '/usr/bin:/bin',
            'PYTHONIOENCODING' => 'utf-8', 'PYTHONDONTWRITEBYTECODE' => '1', 'HOME' => $job];
    $timeout = ['ok' => false, 'err' => '處理超過 5 分鐘被中止，請分批上傳。'];
    $end = time() + $deadline;
    $rc = addr_exec(['python3', $tool], $job, $env, $end - time());
    if ($rc === 124 || $rc === 137) return $timeout + ['rc' => $rc];
    $reports = glob("$job/report/*.xlsx") ?: [];
    // 一定要有「_地址整理.xlsx」：只有摘要＝沒有工作表被處理（多半缺收據欄），不讓人下載空結果
    $done = array_filter($reports, fn($f) => str_ends_with(basename($f), ADDR_REPORT_SUFFIX));
    if ($rc !== 0 || !$done)
        return ['ok' => false, 'rc' => $rc, 'err' => '整理失敗，請確認檔案裡有「收據號碼」或「收據編號」欄位；仍不行請找資訊部（分機 181）。'];
    $zip = "$job/out.zip";
    $zcmd = $zipper ? ['python3', $zipper, $zip] : ['python3', '-m', 'zipfile', '-c', $zip];
    $zrc = addr_exec(array_merge($zcmd, array_map('basename', $reports)), "$job/report", $env, $end - time());
    if ($zrc === 124 || $zrc === 137) return $timeout + ['rc' => $zrc];
    if ($zrc !== 0 || !is_file($zip)) return ['ok' => false, 'rc' => $zrc, 'err' => '打包結果失敗，請再試一次。'];
    return ['ok' => true, 'zip' => $zip, 'reports' => count($reports)];
}

// 這個檔是否有程序開著。回傳 'busy'（有人開著）、'free'（確定沒人開著）、'unknown'（無法確認）。
// 小鹿 10/21 第四次互審：「看不到」不可當「沒人用」。逐一掃 /proc/<pid>/fd：
//   ・程序在掃描中結束（目錄消失）→ 略過
//   ・fd 目錄讀不到：程序擁有者是我們（www-data）→ 可能就是持有暫存檔的 worker，回 unknown；
//     擁有者是別人（root 等）→ 不可能持有 www-data 0700 目錄裡的檔（打不開），略過，免得永遠擋住補清
//   ・/proc 本身讀不到 → unknown
// $procRoot、$uidOf 可替換，測試用。PHP-FPM worker 預設不可讀 fd（process.dumpable=no），
// 所以 /addr/ 專用池設 process.dumpable = yes（deploy/it-ccra-addr-fpm.conf），補清才看得到它開著的上傳暫存。
function addr_in_use(string $path, string $procRoot = '/proc', ?callable $uidOf = null, ?callable $cmdOf = null): string {
    $real = realpath($path);
    if ($real === false) return 'free';                                       // 檔已不在
    $me = posix_geteuid();
    $uidOf ??= fn(string $pidDir) => @fileowner($pidDir);
    $cmdOf ??= fn(string $pidDir) => @file_get_contents("$pidDir/cmdline");
    $pids = @scandir($procRoot);
    if ($pids === false) return 'unknown';
    $unknown = false;
    foreach ($pids as $pid) {
        if (!ctype_digit($pid)) continue;
        $pidDir = "$procRoot/$pid";
        $fdDir = "$pidDir/fd";
        $fds = @scandir($fdDir);
        if ($fds === false) {
            if (!file_exists($pidDir)) continue;                                // 程序已結束
            $uid = $uidOf($pidDir);
            if ($uid === false) { if (!file_exists($pidDir)) continue; $unknown = true; continue; }
            if ($uid === $me) {
                // 我們的程序但看不到 fd。其他 PHP-FPM 池的 worker（預設不可讀 fd）用自己的 upload_tmp_dir，
                // 不會寫進 /addr/ 專用暫存，以命令列「php-fpm: pool <名稱>」辨認後略過；本池或認不出來的一律當無法確認。
                $cmd = $cmdOf($pidDir);
                if ($cmd !== false && preg_match('/\Aphp-fpm: pool ([\w.-]+)/', str_replace("\0", ' ', $cmd), $m) && $m[1] !== ADDR_POOL) continue;
                $unknown = true;
            }
            continue;                                                           // 別人的程序：打不開我們的檔，略過
        }
        foreach ($fds as $fd) {
            if ($fd === '.' || $fd === '..') continue;
            $t = @readlink("$fdDir/$fd");
            if ($t === false) { if (file_exists("$fdDir/$fd")) $unknown = true; continue; }   // 消失＝已關閉；還在卻讀不到＝不確定
            if ($t === $real) return 'busy';
        }
    }
    return $unknown ? 'unknown' : 'free';
}

// 補清。回傳 [刪除數, 失敗說明清單]。失敗包括：目錄讀不到、工單鎖開不了、刪不掉。
function addr_sweep(string $root = ADDR_ROOT, int $maxAge = ADDR_MAX_AGE, ?int $now = null, array $spool = ADDR_SPOOL_DIRS): array {
    $now ??= time(); $deleted = 0; $failed = [];
    if (!is_dir($root)) return [0, []];
    $list = @scandir($root);
    if ($list === false) return [0, ["scan $root"]];
    foreach (array_diff($list, ['.', '..']) as $name) {
        $dir = "$root/$name";
        if (!is_dir($dir) || is_link($dir) || !preg_match('/\A[0-9a-f]{24}\z/', $name)) continue;   // 只動工單資料夾
        if ($now - filemtime($dir) < $maxAge) continue;
        $lock = @fopen("$dir/.lock", 'c');
        if (!$lock) { $failed[] = "lock $dir"; continue; }                     // 鎖開不了：不刪、當失敗
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); continue; }    // 還有程式在用
        $f = addr_cleanup($dir);
        fclose($lock);
        $f ? $failed = array_merge($failed, $f) : $deleted++;
    }
    foreach ($spool as $sub) {                                                // 專用上傳暫存：單層、只有檔案
        $dir = "$root/$sub";
        if (!is_dir($dir)) continue;                                          // 還沒建立（部署時才建）
        $items = @scandir($dir);
        if ($items === false) { $failed[] = "scan $dir"; continue; }
        foreach (array_diff($items, ['.', '..']) as $f) {
            $p = "$dir/$f";
            if (is_link($p)) { $failed[] = "link $p"; continue; }
            if (is_dir($p)) { $f2 = addr_cleanup_if_stale($p, $now, $maxAge, $deleted); $failed = array_merge($failed, $f2); continue; }
            if ($now - filemtime($p) < $maxAge) continue;
            $use = addr_in_use($p);
            if ($use === 'busy') continue;                                      // 還有程序開著：不刪
            if ($use === 'unknown') { $failed[] = "inuse-unknown $p"; continue; }   // 無法確認：保留並回報
            @unlink($p) ? $deleted++ : $failed[] = $p;
        }
    }
    return [$deleted, $failed];
}

// nginx 的 client_body_temp_path 若設了分層會有子目錄；只清裡面滿期且沒人開著的檔。
function addr_cleanup_if_stale(string $dir, int $now, int $maxAge, int &$deleted): array {
    $failed = [];
    $items = @scandir($dir);
    if ($items === false) return ["scan $dir"];
    foreach (array_diff($items, ['.', '..']) as $f) {
        $p = "$dir/$f";
        if (is_link($p)) { $failed[] = "link $p"; continue; }
        if (is_dir($p)) { $failed = array_merge($failed, addr_cleanup_if_stale($p, $now, $maxAge, $deleted)); continue; }
        if ($now - filemtime($p) < $maxAge) continue;
        $use = addr_in_use($p);
        if ($use === 'busy') continue;
        if ($use === 'unknown') { $failed[] = "inuse-unknown $p"; continue; }
        @unlink($p) ? $deleted++ : $failed[] = $p;
    }
    return $failed;
}
