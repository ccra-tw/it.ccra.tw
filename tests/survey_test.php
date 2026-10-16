<?php
// 使用調查（/survey/ 專屬連結免登入、/ops/survey.php 統計）伺服器端測試。
// 用法：php tests/survey_test.php <site 目錄> <lib 目錄>（由 tests/run_svc_test.py 準備好暫存副本後呼叫）
$SITE = $argv[1]; $LIB = $argv[2];
putenv('ITCCRA_TEST_DB=1');
require $LIB . '/survey.php';
$fail = 0; $n = 0;
function ok($cond, $msg) { global $fail, $n; $n++; echo ($cond ? "  ✅ " : "  ❌ ") . $msg . "\n"; if (!$cond) $fail++; }
function req($email, $page, $method = 'GET', $get = [], $post = []) {
    global $SITE;
    $r = ['page' => "$SITE/$page", 'method' => $method, 'get' => $get, 'post' => $post];
    $env = 'ITCCRA_TEST_DB=1 ITCCRA_TEST_EMAIL=' . escapeshellarg($email) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($r));
    return (string)shell_exec("env $env php " . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
}

$d = survey_db();
$d->exec('DELETE FROM person'); $d->exec('DELETE FROM answer');

echo "名單登記\n";
$t1 = survey_enroll('Tester.One@example.org', '999-測試甲', '測試部');
$t1b = survey_enroll('tester.one@example.org', '999-測試甲', '測試二部');
ok($t1 === $t1b, '同一人重登記沿用原識別碼（重寄不會讓舊連結失效）');
ok(strlen($t1) >= 32 && preg_match('/^[A-Za-z0-9_-]+$/', $t1), '識別碼夠長且只含網址安全字元');
ok(survey_person($t1)['dept'] === '測試二部', '重登記會更新部門');
ok(survey_person($t1)['email'] === 'tester.one@example.org', '信箱存小寫');
$t2 = survey_enroll('tester.two@example.org', '999-測試乙', '測試部');
ok($t1 !== $t2, '不同人不同識別碼');
ok(survey_person('') === null && survey_person('abc') === null && survey_person("x' OR '1'='1aaaaaaaaaaaaaaaaaaa") === null, '空的、太短、含怪字元的識別碼一律不認');
ok(survey_person(str_repeat('A', 32)) === null, '格式對但沒登記的識別碼不認');

echo "問卷頁（免登入）\n";
$o = req('', 'survey/index.php', 'GET', ['t' => 'nope']);
ok(strpos($o, '找不到這份問卷') !== false && strpos($o, 'name="items[]"') === false, '沒有識別碼看不到題目');
$o = req('', 'survey/index.php', 'GET', ['t' => $t1]);
ok(strpos($o, '測試甲，平安') !== false, '專屬連結帶出姓名（去掉部門代碼）');
ok(substr_count($o, 'name="items[]"') === count(survey_items()) && count(survey_items()) === 25, '25 個勾選項都在');
ok(strpos($o, '知道自己電腦有裝防毒軟體就勾選') !== false, '防毒軟體說明照熊哥 10-16');
preg_match('/name="csrf" value="([0-9a-f]+)"/', $o, $m);
$csrf = $m[1] ?? '';
ok($csrf !== '', '表單帶 csrf');

$o = req('', 'survey/index.php', 'POST', ['t' => $t1], ['t' => $t1, 'csrf' => 'bad', 'items' => ['Claude']]);
ok(strpos($o, '頁面已過期') !== false && survey_answer('tester.one@example.org') === null, 'csrf 不對不收');
req('', 'survey/index.php', 'POST', ['t' => $t1], ['t' => $t1, 'csrf' => $csrf, 'items' => ['Claude', 'VPN', '駭客工具'], 'other' => " Excel 巨集\n某後台 ", 'note' => 'NAS 要 180']);
$a = survey_answer('tester.one@example.org');
ok($a && $a['items'] === ['Claude', 'VPN'], '只收題目裡的項目，清單外的丟掉');
ok($a['other'] === "Excel 巨集\n某後台" && $a['note'] === 'NAS 要 180', '其他與備註照存（去頭尾空白）');
$o = req('', 'survey/index.php', 'GET', ['t' => $t1]);
ok(strpos($o, '你已經填過了') !== false && preg_match('/value="Claude" checked/', $o), '再進來看到已填內容，可修改');
req('', 'survey/index.php', 'POST', ['t' => $t1], ['t' => $t1, 'csrf' => $csrf, 'items' => ['Zoom']]);
ok(survey_answer('tester.one@example.org')['items'] === ['Zoom'], '重填以最後一次為準');
$csrf2 = hash_hmac('sha256', 'survey' . $t2, trim((string)@file_get_contents('/var/lib/it-ccra/form_secret')));
req('', 'survey/index.php', 'POST', ['t' => $t2], ['t' => $t2, 'csrf' => $csrf, 'items' => ['IG']]);
ok(survey_answer('tester.two@example.org') === null, '拿別人的 csrf 不能替第二個人送出');

echo "統計（戰情室）\n";
$o = req('someone@example.org', 'ops/survey.php');
ok(strpos($o, '只開放給資訊部') !== false && strpos($o, '測試甲') === false, '非資訊部看不到統計');
$o = req('ituncle@ccra.org.tw', 'ops/survey.php');
ok(strpos($o, '已填 <b>1</b> 人／名單 <b>2</b> 人') !== false, '已填／名單人數');
ok(strpos($o, '測試乙（未寄信）') !== false, '未填名單標出還沒寄信的人');
survey_mark_sent('tester.two@example.org');
$st = survey_stats();
ok(count($st['count']['Zoom']) === 1 && count($st['count']['Claude']) === 0, '勾選人數照最後一次回覆');
ok($st['todo'][0]['sent_at'] !== null, '記下已寄信');

$d->exec('DELETE FROM person'); $d->exec('DELETE FROM answer');
echo "\n$n 項，失敗 $fail\n";
exit($fail ? 1 : 0);
