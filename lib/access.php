<?php
// it.ccra.tw：驗證 Cloudflare Access 登入憑證（Cf-Access-Jwt-Assertion）。
// 2026-10-03 IT大蘇建立。主機已只放行 Cloudflare IP，但別人的 Cloudflare 區域也能指向本機 IP
// 並自帶標頭，所以 Cloudflare 官方要求在來源端驗 JWT 簽章與 AUD，不能只信 email 標頭。
date_default_timezone_set('Asia/Taipei');  // 頁面與申請紀錄的時間一律台北時間
const ACCESS_TEAM = 'https://maryonacross.cloudflareaccess.com';
const ACCESS_AUD  = '8fb099ab6feed72ee36eeaf4f5bdc58a6fc6b7d5dd1f26219198fdfb8eacdf92';
const STATE_DIR   = '/var/lib/it-ccra';

function b64url($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function access_certs($force = false) {
    $f = STATE_DIR . '/access_certs.json';
    if (!$force && is_file($f) && time() - filemtime($f) < 3600) return json_decode(file_get_contents($f), true);
    $j = @file_get_contents(ACCESS_TEAM . '/cdn-cgi/access/certs');
    if ($j === false) return is_file($f) ? json_decode(file_get_contents($f), true) : null;
    file_put_contents($f, $j);
    return json_decode($j, true);
}

function access_deny($why) {
    http_response_code(403);
    error_log("it.ccra.tw access denied: $why");
    exit('需要以協會 M365 帳號登入。請重新整理頁面。');
}

// 回傳已驗證的登入資訊（含 email）；驗不過直接 403 結束。
function access_identity() {
    // 測試接縫（GitHub #8）：只在命令列執行時可用替身身分，網頁（php-fpm）永遠走下面的 JWT 驗證
    if (PHP_SAPI === 'cli' && ($t = getenv('ITCCRA_TEST_EMAIL'))) return ['email' => $t];
    $jwt = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? '';
    $p = explode('.', $jwt);
    if (count($p) !== 3) access_deny('no jwt');
    $h = json_decode(b64url($p[0]), true);
    $c = json_decode(b64url($p[1]), true);
    if (!$h || !$c || ($h['alg'] ?? '') !== 'RS256') access_deny('bad header');
    $pem = null;
    foreach ([false, true] as $force) {          // kid 對不上時強制重抓一次（金鑰輪替）
        foreach ((access_certs($force)['public_certs'] ?? []) as $k)
            if (($k['kid'] ?? '') === ($h['kid'] ?? '')) $pem = $k['cert'];
        if ($pem) break;
    }
    if (!$pem) access_deny('kid not found');
    if (openssl_verify("$p[0].$p[1]", b64url($p[2]), $pem, OPENSSL_ALGO_SHA256) !== 1) access_deny('bad signature');
    if (!in_array(ACCESS_AUD, (array)($c['aud'] ?? []), true)) access_deny('aud');
    if (($c['iss'] ?? '') !== ACCESS_TEAM) access_deny('iss');
    if (($c['exp'] ?? 0) < time()) access_deny('expired');
    if (empty($c['email'])) access_deny('no email');
    return $c;
}

require_once __DIR__ . '/db.php';   // itdb()：PostgreSQL 連線（2026-10-06 由 SQLite 改）
