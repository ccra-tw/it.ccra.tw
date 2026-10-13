<?php
// 戰情室推播的命令列入口：IT大蘇本機的探測器、資安事件經 SSH 呼叫。
//   sudo -u www-data php /opt/it.ccra.tw/deploy/push_send.php --kind probe --title "…" --body "…" [--url /ops/] [--tag xxx] [--email 某人]
// 印出「ok=N fail=M」；沒有任何訂閱時 ok=0 fail=0，呼叫端不要把它當成失敗。
if (PHP_SAPI !== 'cli') exit;
require '/var/www/it-lib/webpush.php';
$o = getopt('', ['kind:', 'title:', 'body:', 'url:', 'tag:', 'email:']);
if (empty($o['title'])) { fwrite(STDERR, "need --title\n"); exit(2); }
[$ok, $fail] = push_send(['title' => $o['title'], 'body' => $o['body'] ?? '', 'url' => $o['url'] ?? '/ops/', 'tag' => $o['tag'] ?? null],
    $o['kind'] ?? 'cli', $o['email'] ?? null);
echo "ok=$ok fail=$fail\n";
