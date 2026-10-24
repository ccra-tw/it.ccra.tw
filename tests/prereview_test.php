<?php
// 申請單預審（#19）單元測試：純函式，不碰資料庫。用法：php tests/prereview_test.php <site 目錄> <lib 目錄>
$LIB = $argv[2] ?? __DIR__ . '/../lib';
require $LIB . '/prereview.php';
$fail = 0; $n = 0;
function ok($cond, $msg) { global $fail, $n; $n++; echo ($cond ? "  ✅ " : "  ❌ ") . $msg . "\n"; if (!$cond) $fail++; }
function pr(string $type, array $fields = [], array $extra = []): array {
    return svc_prereview(array_merge(['type' => $type, 'applicant_dept' => '180', 'for_self' => 't', 'target_dept' => '180',
        'is_test' => 'f', 'purpose' => '測試', 'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE)], $extra));
}
$vd = fn(array $p) => $p['verdict'];
$txt = fn(array $p) => implode('｜', array_column($p['reasons'], 'text'));
$hasRed = fn(array $p) => in_array('red', array_column($p['reasons'], 'level'), true);
$aiOk = ['tool' => 'Claude', 'plan' => '每週開完會把會議紀錄整理成重點與待辦事項', 'why' => '沒用過', 'pii' => '不會'];

echo "共同檢查\n";
ok($vd(pr('ai', $aiOk, ['is_test' => 't'])) === PRE_SKIP, '測試單不預審（字串 t）');
ok($vd(pr('ai', $aiOk, ['is_test' => true])) === PRE_SKIP, '測試單不預審（布林）');
ok($vd(pr('ai', $aiOk)) === PRE_APPROVE, '正常 AI 申請建議核准');
ok($vd(pr('ai', $aiOk, ['applicant_dept' => ''])) === PRE_RETURN, '申請人不在部門對照表 → 退回補件');
ok($vd(pr('ai', $aiOk, ['for_self' => 'f', 'target_dept' => '180'])) === PRE_APPROVE, '代同部門申請不加碼');
ok($vd(pr('ai', $aiOk, ['for_self' => 'f', 'target_dept' => '610'])) === PRE_DIRECTOR, '代他部門申請 → 需主任看');
ok($vd(pr('ai', $aiOk, ['fields' => $aiOk])) === PRE_APPROVE, 'fields 給陣列也能讀');

echo "AI 工具\n";
ok($vd(pr('ai', ['plan' => '試試看', 'pii' => '不會'])) === PRE_RETURN, '用途只寫「試試看」→ 退回補件');
ok($vd(pr('ai', ['plan' => '學習', 'pii' => '不會'])) === PRE_RETURN, '用途只寫「學習」→ 退回補件');
ok($vd(pr('ai', ['plan' => $aiOk['plan'], 'pii' => '會'])) === PRE_DIRECTOR && str_contains($txt(pr('ai', ['plan' => $aiOk['plan'], 'pii' => '會'])), 'Copilot Chat'), '處理個資 → 需主任看並建議 Copilot Chat');
ok($vd(pr('ai', ['plan' => '學習', 'pii' => '會'])) === PRE_RETURN && count(pr('ai', ['plan' => '學習', 'pii' => '會'])['reasons']) === 2, '同時成立取最嚴（退回）且兩條理由都列');

echo "縮址\n";
ok($vd(pr('shortlink', ['need' => '後台帳號（自己建短網址）'])) === PRE_APPROVE, '後台帳號建議核准');
ok($vd(pr('shortlink', ['need' => '請資訊部代建一個短網址', 'url' => 'https://www.1919.org.tw/event/2026'])) === PRE_APPROVE, '代建協會網域 → 建議核准');
ok($vd(pr('shortlink', ['need' => '請資訊部代建一個短網址', 'url' => 'https://evil1919.org.tw/x'])) === PRE_DIRECTOR, '相似但非協會網域不被當成協會網域');
ok($vd(pr('shortlink', ['need' => '請資訊部代建一個短網址', 'url' => 'https://shop.example.com/a'])) === PRE_DIRECTOR, '代建外部網站 → 需主任看');

echo "其他用途信箱\n";
ok($vd(pr('email', ['use' => '部門共用', 'users' => '甲、乙'])) === PRE_APPROVE, '有用途有使用者 → 建議核准');
ok($vd(pr('email', ['use' => '年底募款活動收報名信', 'users' => '甲', 'until' => '2026-12-31'])) === PRE_DIRECTOR, '募款信箱 → 需主任看');
ok($vd(pr('email', ['use' => '路跑活動報名', 'users' => '甲'])) === PRE_RETURN, '活動信箱沒填期限 → 退回補件');

echo "Google\n";
ok($vd(pr('google', ['service' => 'Google Workspace 帳號', 'level' => '檢視'])) === PRE_APPROVE, '檢視 → 建議核准');
ok($vd(pr('google', ['service' => 'YouTube', 'level' => '編輯'])) === PRE_APPROVE, '編輯 → 建議核准');
ok($vd(pr('google', ['service' => 'YouTube', 'level' => '管理'])) === PRE_DIRECTOR, '管理 → 需主任看');
ok($vd(pr('google', ['service' => 'Google Ads', 'level' => '檢視'])) === PRE_DIRECTOR, 'Ads 即使檢視也需主任看');

echo "共用資料夾\n";
ok($vd(pr('folder', ['path' => '財務部報表', 'perm' => '讀取'])) === PRE_APPROVE, '一般資料夾 → 建議核准');
ok($vd(pr('folder', ['path' => '個案資料', 'perm' => '讀寫'])) === PRE_DIRECTOR, '個資資料夾讀寫 → 需主任看');
ok($vd(pr('folder', ['path' => '個案資料', 'perm' => '讀取'])) === PRE_APPROVE, '個資資料夾只讀不加碼');
ok(str_contains($txt(pr('folder', ['path' => 'x', 'perm' => '讀取'])), '半年'), '沒填期限註明半年後回頭問');

echo "VPN\n";
ok($vd(pr('vpn', ['location' => '家裡', 'device' => '協會給的筆電'])) === PRE_APPROVE, '協會電腦 → 建議核准');
ok($vd(pr('vpn', ['location' => '家裡', 'device' => '自己的手機'])) === PRE_DIRECTOR, '個人手機 → 需主任看');
ok(str_contains($txt(pr('vpn', ['location' => '家裡', 'device' => '協會給的筆電'])), '3 個月'), '沒填期限建議 3 個月');

echo "系統權限\n";
ok($vd(pr('sysperm', ['system' => '收據系統', 'role' => '要能看報表'])) === PRE_APPROVE, '一般角色 → 建議核准');
ok($vd(pr('sysperm', ['system' => '1919 系統', 'role' => '要能匯出名單'])) === PRE_DIRECTOR, '個資系統匯出 → 需主任看');

echo "GitHub\n";
ok($vd(pr('github', ['account' => 'a', 'org' => 'ccra-tw', 'repos' => 'x', 'perm' => '讀取'])) === PRE_RETURN, '公開 repo 只讀 → 退回並說明');
ok($vd(pr('github', ['account' => 'a', 'org' => 'ccra-tw', 'repos' => 'x', 'perm' => '寫入'])) === PRE_APPROVE, '寫入 → 建議核准');
ok($vd(pr('github', ['account' => 'a', 'org' => 'ccea-tw', 'repos' => 'x', 'perm' => '讀取'])) === PRE_DIRECTOR, 'ccea-tw 私有 → 需主任看（不是退回）');
ok($vd(pr('github', ['account' => 'a', 'org' => 'ccra-tw', 'repos' => 'x', 'perm' => '管理'])) === PRE_DIRECTOR, '管理 → 需主任看');

echo "DNS\n";
ok($vd(pr('dns', ['domain' => 'event.ccra.tw', 'rtype' => 'CNAME', 'target' => 'x.example.com'])) === PRE_APPROVE, '子網域 CNAME → 建議核准');
ok($vd(pr('dns', ['domain' => 'ccra.org.tw', 'rtype' => 'A', 'target' => '1.2.3.4'])) === PRE_DIRECTOR, '根網域 → 需主任看');
$mx = pr('dns', ['domain' => 'mail.ccra.tw', 'rtype' => 'MX', 'target' => 'x']);
ok($vd($mx) === PRE_APPROVE && str_contains($txt($mx), 'SPF'), 'MX 不必主任但註明資訊部複核');

echo "防火牆\n";
$fw = pr('firewall', ['src' => '外部任何地方', 'dst' => 'web', 'port' => '443']);
ok($vd($fw) === PRE_DIRECTOR && $hasRed($fw), '一律需主任看；來源外部任何地方標紅');
ok(!$hasRed(pr('firewall', ['src' => '某辦公室', 'dst' => 'web', 'port' => '443', 'until' => '2026-12-31'])), '來源限定不標紅');
ok(str_contains($txt($fw), '期限'), '沒填期限建議設期限');

echo "其他\n";
ok($vd(pr('other')) === PRE_APPROVE && str_contains($txt(pr('other')), '判斷屬於哪一類'), '其他 → 資訊部判斷類別');
ok($vd(pr('nope')) === PRE_APPROVE, '未知類型不出錯');

echo "\n預審：{$n} 項，失敗 {$fail}\n";
exit($fail ? 1 : 0);
