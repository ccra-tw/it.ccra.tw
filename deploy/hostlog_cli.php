<?php
// 主機操作紀錄（命令列，由資訊部本機自動化經 SSH 呼叫；熊哥 2026-11-03 交辦）。
// 用法：sudo -n -u www-data php /opt/it.ccra.tw/deploy/hostlog_cli.php add < rows.json
//         rows.json＝[{"host","time","type","what","why","change","verify","undo","ticket","who","who_email"},…]
//         time 寫 'YYYY-MM-DD HH:MM'（台北時間）；不知道確切時間就寫說明文字，存在「時間說明」欄。
//         who 預設 IT大蘇、who_email 預設 ituncle@ccra.org.tw。全部驗過才寫入，有一筆不合格就一筆都不寫。
//       sudo -n -u www-data php /opt/it.ccra.tw/deploy/hostlog_cli.php export --month YYYY-MM > out.csv
//         印出該月 CSV（UTF-8 含 BOM）。
require '/var/www/it-lib/hostlog.php';
$cmd = $argv[1] ?? '';
if ($cmd === 'add') {
    $in = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($in) || !array_is_list($in)) { fwrite(STDERR, "stdin 不是 JSON 陣列\n"); exit(2); }
    [$n, $errs] = hostlog_import($in);
    if ($errs) { fwrite(STDERR, implode("\n", $errs) . "\n一筆都沒有寫入。\n"); exit(1); }
    echo "已新增 $n 筆\n";
} elseif ($cmd === 'export' && ($argv[2] ?? '') === '--month' && isset($argv[3])) {
    try { hostlog_export_csv($argv[3], STDOUT); }
    catch (InvalidArgumentException $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(2); }
} else { fwrite(STDERR, "用法：add < rows.json ｜ export --month YYYY-MM\n"); exit(2); }
