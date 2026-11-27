<?php
// 許願池（/wish/）測試。用法：php tests/wish_test.php <site 目錄> <lib 目錄>
$SITE = $argv[1]; $LIB = $argv[2];
putenv('ITCCRA_TEST_DB=1');
require $LIB . '/wish.php';
$fail = 0; $n = 0;
function ok($cond, $msg) { global $fail, $n; $n++; echo ($cond ? "  ✅ " : "  ❌ ") . $msg . "\n"; if (!$cond) $fail++; }
function req($email, $method = 'GET', $get = [], $post = []) {
    global $SITE;
    $r = ['page' => "$SITE/wish/index.php", 'method' => $method, 'get' => $get, 'post' => $post];
    $env = 'ITCCRA_TEST_DB=1 ITCCRA_TEST_EMAIL=' . escapeshellarg($email) . ' ITCCRA_REQ=' . escapeshellarg(json_encode($r));
    return (string)shell_exec("env $env php " . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
}
$secret = trim((string)file_get_contents('/var/lib/it-ccra/form_secret'));
$csrf = fn($e) => hash_hmac('sha256', 'wish' . strtolower($e) . date('Y-m-d'), $secret);
$d = wish_db();
$d->exec('DELETE FROM log'); $d->exec('DELETE FROM vote'); $d->exec('DELETE FROM wish');
$A = 'tester.a@example.org'; $B = 'tester.b@example.org'; $IT = 'ituncle@ccra.org.tw';

echo "許願\n";
$id = wish_add($d, 'Tester.A@example.org', '999-測試甲', '測試部', "  希望報修有進度通知  ", "現在要打電話問\r\n希望收到信");
$w = $d->query("SELECT * FROM wish WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
ok($w['email'] === $A && $w['title'] === '希望報修有進度通知' && $w['status'] === 'new', '新增：信箱小寫、標題去頭尾空白、狀態新許願');
ok($w['body'] === "現在要打電話問\n希望收到信", '說明換行統一成 \\n');
ok((int)$d->query("SELECT count(*) FROM vote WHERE wish_id = $id")->fetchColumn() === 1, '許願的人自己算一票');
foreach ([['', '空標題'], [str_repeat('字', WISH_TITLE_MAX + 1), '標題太長']] as [$t, $why]) {
    try { wish_add($d, $A, '', '', $t, ''); ok(false, "$why 應被擋"); } catch (RuntimeException $e) { ok(true, "$why 被擋：" . $e->getMessage()); }
}
try { wish_add($d, $A, '', '', '標題', str_repeat('字', WISH_BODY_MAX + 1)); ok(false, '說明太長應被擋'); } catch (RuntimeException $e) { ok(true, '說明太長被擋'); }

echo "我也要\n";
ok(wish_toggle_vote($d, $id, 'Tester.B@example.org') === true, 'B 按我也要 → 有投');
ok((int)$d->query("SELECT count(*) FROM vote WHERE wish_id = $id")->fetchColumn() === 2, '票數 2');
ok(wish_toggle_vote($d, $id, $B) === false, 'B 再按一次 → 取消');
ok((int)$d->query("SELECT count(*) FROM vote WHERE wish_id = $id")->fetchColumn() === 1, '票數回到 1');
try { wish_toggle_vote($d, 999999, $B); ok(false, '不存在的願望應被擋'); } catch (RuntimeException $e) { ok(true, '不存在的願望被擋'); }

echo "資訊部回覆\n";
wish_reply($d, $id, $IT, 'planned', '下個月做', 'asQEZH9c');
$w = $d->query("SELECT * FROM wish WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
ok($w['status'] === 'planned' && $w['reply'] === '下個月做' && $w['planner'] === 'asQEZH9c' && $w['replied_by'] === $IT, '狀態、回覆、票號、回覆人都存到');
try { wish_reply($d, $id, $IT, 'declined', '', ''); ok(false, '暫不處理沒寫原因應被擋'); } catch (RuntimeException $e) { ok(true, '暫不處理沒寫原因被擋'); }
try { wish_reply($d, $id, $IT, 'xxx', '', ''); ok(false, '亂寫狀態應被擋'); } catch (RuntimeException $e) { ok(true, '亂寫狀態被擋'); }
try { wish_reply($d, $id, $IT, 'review', '', "a'; DROP"); ok(false, '票號格式不對應被擋'); } catch (RuntimeException $e) { ok(true, '票號格式不對被擋'); }
ok(str_contains((string)$d->query("SELECT detail FROM log WHERE action = 'reply' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'new → planned'), '回覆留紀錄（改前→改後）');

echo "清單排序\n";
$id2 = wish_add($d, $B, '999-測試乙', '', '第二個願望', '');
wish_toggle_vote($d, $id2, $A); wish_toggle_vote($d, $id2, 'c@example.org');
$id3 = wish_add($d, $B, '999-測試乙', '', '已完成的願望', ''); wish_reply($d, $id3, $IT, 'done', '好了', '');
$l = wish_list($d, $A);
ok(array_column($l, 'id') === [$id2, $id, $id3], '未結案依票數在前，已完成排最後');
ok($l[0]['mine'] === true && $l[1]['mine'] === true, 'mine 反映目前使用者有沒有投');
ok(count(wish_list($d, $A, 'done')) === 1, '依狀態篩選');

echo "頁面\n";
$p = req($A);
ok(str_contains($p, '許一個願') && str_contains($p, '第二個願望') && str_contains($p, '我也要 3'), '列表與票數顯示');
ok(!str_contains($p, 'asQEZH9c') && !str_contains($p, '資訊部回覆狀態'), '一般同工看不到 Planner 票號與回覆表單');
$p = req($IT);
ok(str_contains($p, '資訊部回覆狀態') && str_contains($p, 'asQEZH9c'), '資訊部看得到回覆表單與票號');
req($A, 'POST', [], ['csrf' => $csrf($A), 'do' => 'add', 'title' => '<script>x</script>', 'body' => '']);
ok(str_contains(req($B), '&lt;script&gt;x&lt;/script&gt;'), '標題輸出有跳脫');
$before = (int)$d->query('SELECT count(*) FROM wish')->fetchColumn();
req($A, 'POST', [], ['csrf' => 'bad', 'do' => 'add', 'title' => '偽造', 'body' => '']);
ok((int)$d->query('SELECT count(*) FROM wish')->fetchColumn() === $before, 'csrf 不對不會新增');
req($A, 'POST', [], ['csrf' => $csrf($A), 'do' => 'reply', 'id' => $id, 'status' => 'done', 'reply' => '偷改', 'planner' => '']);
ok($d->query("SELECT status FROM wish WHERE id = $id")->fetchColumn() === 'planned', '非資訊部送回覆會被擋');
req($IT, 'POST', [], ['csrf' => $csrf($IT), 'do' => 'reply', 'id' => $id, 'status' => 'review', 'reply' => '再評估', 'planner' => '']);
ok($d->query("SELECT status FROM wish WHERE id = $id")->fetchColumn() === 'review', '資訊部從頁面回覆成功');
req($B, 'POST', [], ['csrf' => $csrf($B), 'do' => 'vote', 'id' => $id]);
ok((int)$d->query("SELECT count(*) FROM vote WHERE wish_id = $id AND email = '$B'")->fetchColumn() === 1, '從頁面按我也要');

$d->exec('DELETE FROM log'); $d->exec('DELETE FROM vote'); $d->exec('DELETE FROM wish');
echo ($fail ? "❌ 失敗 $fail" : "✅ 全部通過") . " $n 項\n";
exit($fail ? 1 : 0);
