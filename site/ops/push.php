<?php
// 戰情室網頁推播：訂閱／取消／測試。只開放資訊部帳號（同戰情室）。
// GET → {"key": VAPID 公鑰, "mine": 這個帳號的訂閱數, cats/always/off/quiet_from/quiet_to: 個人設定}
// POST JSON {op: sub|unsub|test|prefs, sub?, endpoint?, off?, quiet_from?, quiet_to?}
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/itstaff.php';
require '/var/www/it-lib/webpush.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$id = access_identity();
$email = strtolower($id['email']);
if (!is_it_staff($email)) { http_response_code(403); exit('{"error":"forbidden"}'); }

$d = push_db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $n = $d->prepare('SELECT count(*) FROM subs WHERE email = ?'); $n->execute([$email]);
    $p = push_prefs_get($d, $email);
    exit(json_encode(['key' => vapid_keys()['public'], 'mine' => (int)$n->fetchColumn(), 'cats' => PUSH_CATS, 'always' => PUSH_ALWAYS,
        'off' => array_values(array_filter(explode(',', $p['off']))), 'quiet_from' => $p['quiet_from'], 'quiet_to' => $p['quiet_to']], JSON_UNESCAPED_UNICODE));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{}'); }
$in = json_decode($GLOBALS['ITCCRA_RAW_BODY'] ?? file_get_contents('php://input'), true) ?: [];   // 前者只有命令列測試會設
$op = $in['op'] ?? '';

if ($op === 'sub') {
    $s = $in['sub'] ?? [];
    $ep = (string)($s['endpoint'] ?? ''); $p = (string)($s['keys']['p256dh'] ?? ''); $a = (string)($s['keys']['auth'] ?? '');
    // 只收各瀏覽器官方推播服務的網址，不讓人把主機當成對任意網址發 POST 的跳板
    $host = parse_url($ep, PHP_URL_HOST) ?: '';
    if (!str_starts_with($ep, 'https://') || !preg_match('/(^|\.)(googleapis\.com|mozilla\.com|push\.apple\.com|notify\.windows\.com)$/', $host)
        || strlen(wp_unb64u($p)) !== 65 || strlen(wp_unb64u($a)) !== 16) {
        http_response_code(400); exit('{"error":"bad subscription"}');
    }
    $d->prepare('INSERT INTO subs (endpoint, p256dh, auth, email, ua, created_at, fails) VALUES (?,?,?,?,?,?,0)
                 ON CONFLICT (endpoint) DO UPDATE SET p256dh = EXCLUDED.p256dh, auth = EXCLUDED.auth, email = EXCLUDED.email, ua = EXCLUDED.ua, fails = 0')
      ->execute([$ep, $p, $a, $email, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300), date('c')]);
    exit('{"ok":true}');
}
if ($op === 'unsub') {
    $d->prepare('DELETE FROM subs WHERE endpoint = ? AND email = ?')->execute([(string)($in['endpoint'] ?? ''), $email]);
    exit('{"ok":true}');
}
if ($op === 'prefs') {
    $e = push_prefs_set($d, $email, array_map('strval', (array)($in['off'] ?? [])), (string)($in['quiet_from'] ?? ''), (string)($in['quiet_to'] ?? ''));
    exit(json_encode($e === '' ? ['ok' => true] : ['error' => $e], JSON_UNESCAPED_UNICODE));
}
if ($op === 'test') {
    [$ok, $fail] = push_send(['title' => '戰情室測試通知', 'body' => IT_STAFF[$email] . '，看到這則就表示這台裝置收得到。',
        'url' => '/ops/', 'tag' => 'test'], 'test', $email);
    exit(json_encode(['ok' => $ok, 'fail' => $fail]));
}
http_response_code(400);
echo '{"error":"bad op"}';
