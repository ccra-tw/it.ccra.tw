<?php
// 使用調查名單登記（命令列，由資訊部本機寄信腳本經 SSH 呼叫）。
// 用法：php survey_enroll.php enroll   < [{"email","name","dept"},…]   → 印出 [{"email","name","dept","token","sent_at"}]
//       php survey_enroll.php sent <email>                             → 記下已寄信
// 識別碼只在主機產生、只隨個人信寄出；本程式庫與 GitHub 不存任何識別碼。
require '/var/www/it-lib/survey.php';
$cmd = $argv[1] ?? '';
if ($cmd === 'enroll') {
    $in = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($in)) { fwrite(STDERR, "stdin 不是 JSON 陣列\n"); exit(2); }
    $out = [];
    foreach ($in as $p) {
        if (!filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) continue;
        $t = survey_enroll($p['email'], (string)($p['name'] ?? ''), (string)($p['dept'] ?? ''));
        $out[] = survey_person($t);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
} elseif ($cmd === 'sent' && isset($argv[2])) {
    survey_mark_sent($argv[2]);
    echo "ok\n";
} else { fwrite(STDERR, "用法：enroll < list.json ｜ sent <email>\n"); exit(2); }
