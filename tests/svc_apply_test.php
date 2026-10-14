<?php
// 資訊服務申請（GitHub #8 骨架）伺服器端測試：以替身身分打申請頁與戰情室，再讀測試資料表。
// 用法：php tests/svc_apply_test.php <site 目錄> <lib 目錄>（由 tests/run_svc_test.py 準備好暫存副本後呼叫）
$SITE = $argv[1]; $LIB = $argv[2];
putenv('ITCCRA_TEST_DB=1');
require $LIB . '/svc_apply.php';
require $LIB . '/itstaff.php';
$secret = trim((string)file_get_contents('/var/lib/it-ccra/form_secret'));
$STAFF = 'ituncle@ccra.org.tw'; $STAFF2 = 'black@ccra.org.tw'; $OUT = 'nobody@example.org';
$fail = 0; $n = 0;
function ok($cond, $msg) { global $fail, $n; $n++; echo ($cond ? "  ✅ " : "  ❌ ") . $msg . "\n"; if (!$cond) $fail++; }
function req($email, $page, $method = 'GET', $post = [], $get = []) {
    global $SITE;
    $env = 'ITCCRA_TEST_DB=1 ITCCRA_TEST_EMAIL=' . escapeshellarg($email) . ' ITCCRA_REQ=' . escapeshellarg(json_encode(['page' => "$SITE/$page", 'method' => $method, 'post' => $post, 'get' => $get]));
    return (string)shell_exec("env $env php " . escapeshellarg(__DIR__ . '/req.php') . ' 2>&1');
}
$d = svc_db();
$d->exec('TRUNCATE req RESTART IDENTITY');
$cnt = fn() => (int)$d->query('SELECT count(*) FROM req')->fetchColumn();
$row = fn($id) => $d->query('SELECT * FROM req WHERE id = ' . (int)$id)->fetch(PDO::FETCH_ASSOC);
$tok = fn($pfx, $em) => hash_hmac('sha256', $pfx . $em . date('Y-m-d'), $secret);

echo "申請頁\n";
$o = req($STAFF, 'apply/index.php');
ok(str_contains($o, '資訊服務申請') && str_contains($o, 'name="purpose"'), 'GET 顯示表單');
$good = ['csrf' => $tok('apply', $STAFF), 'type' => 'other', 'name' => '測試甲', 'purpose' => '測試用途', 'wanted_by' => ''];
req($STAFF, 'apply/index.php', 'POST', array_merge($good, ['csrf' => 'bad']));
ok($cnt() === 0, 'CSRF 錯誤不新增');
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($good, ['purpose' => '']));
ok($cnt() === 0 && str_contains($o, '請說明用途'), '用途空白被擋並提示');
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($good, ['wanted_by' => '2000-01-01']));
ok($cnt() === 0 && str_contains($o, '不能早於今天'), '希望完成日早於今天被擋');
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($good, ['type' => 'nope']));
ok($cnt() === 0 && str_contains($o, '請選擇申請類型'), '不存在的類型被擋');
req($STAFF, 'apply/index.php', 'POST', $good);
ok($cnt() === 1, '有效申請新增一筆');
$r = $row(1);
ok($r && $r['status'] === 'intake' && $r['type'] === 'other' && $r['applicant_email'] === $STAFF, '「其他」類直接進待受理，申請人正確');
req($STAFF, 'apply/index.php', 'POST', $good);
ok($cnt() === 1, '同人同類同對象重送不重複');
$o = req($STAFF, 'apply/index.php', 'GET', [], ['sent' => '1']);
ok(str_contains($o, 'S' . date('ymd') . '-001') && str_contains($o, '待受理'), '送出後顯示申請編號與狀態');
$o = req($STAFF2, 'apply/index.php', 'GET', [], ['sent' => '1']);
ok(!str_contains($o, 'S' . date('ymd') . '-001'), '別人看不到我的申請編號');

echo "戰情室\n";
$o = req($OUT, 'ops/index.php');
ok(str_contains($o, '戰情室只開放給資訊部帳號'), '非資訊部帳號被擋');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => 'bad', 'act' => 'svc', 'do' => 'accept', 'id' => 1]);
ok($row(1)['status'] === 'intake', 'CSRF 錯誤不受理');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF), 'act' => 'svc', 'do' => 'accept', 'id' => 1]);
$r = $row(1);
ok($r['status'] === 'doing' && $r['accepted_by'] === $STAFF && $r['first_response_at'], '資訊部受理 → 處理中、受理人即負責人、記第一次回應');
req($STAFF2, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF2), 'act' => 'svc', 'do' => 'accept', 'id' => 1]);
ok($row(1)['accepted_by'] === $STAFF, '已受理的不會被別人再受理');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF), 'act' => 'svc', 'do' => 'done', 'id' => 1, 'note' => '已處理']);
$r = $row(1);
ok($r['status'] === 'done' && $r['done_by'] === $STAFF && $r['done_note'] === '已處理' && $r['done_at'], '完成 → 已完成、記完成人與說明');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF), 'act' => 'svc', 'do' => 'done', 'id' => 1, 'note' => '再按一次']);
ok($row(1)['done_note'] === '已處理', '已完成的再按完成不改內容');

echo "需主任核准（VPN，#9）\n";
$DIR = SVC_DIRECTOR;
$vpn = ['csrf' => $tok('apply', $STAFF), 'type' => 'vpn', 'name' => '測試乙', 'purpose' => '在家連線', 'wanted_by' => '',
        'f' => ['location' => '住家', 'device' => '協會筆電', 'until' => '']];
$before = $cnt();
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($vpn, ['f' => ['location' => '', 'device' => '筆電']]));
ok($cnt() === $before && str_contains($o, '請填寫使用地點'), 'VPN 使用地點必填');
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($vpn, ['f' => ['location' => '住家', 'device' => '筆電', 'until' => '2000-01-01']]));
ok($cnt() === $before && str_contains($o, '使用期限不能早於今天'), 'VPN 期限不能早於今天');
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($vpn, ['f' => ['location' => str_repeat('長', 101), 'device' => '筆電']]));
ok($cnt() === $before && str_contains($o, '使用地點太長'), 'VPN 欄位長度上限');
req($STAFF, 'apply/index.php', 'POST', $vpn);
$vid = (int)$d->query("SELECT max(id) FROM req WHERE type = 'vpn'")->fetchColumn();
$r = $row($vid);
ok($r && $r['status'] === 'review' && json_decode($r['fields'], true) == ['location' => '住家', 'device' => '協會筆電'], 'VPN 送出 → 待主任核准，欄位存成 JSON（空的選填不存）');
$o = req($STAFF, 'apply/index.php', 'GET');
ok(str_contains($o, 'data-type="vpn"') && str_contains($o, 'name="f[location]"'), '申請頁有 VPN 專屬欄位');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF), 'act' => 'svc', 'do' => 'approve', 'id' => $vid]);
ok($row($vid)['status'] === 'review', '非主任按核准被拒');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF), 'act' => 'svc', 'do' => 'accept', 'id' => $vid]);
ok($row($vid)['status'] === 'review', '待核准的不能被資訊部直接受理');
req($DIR, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $DIR), 'act' => 'svc', 'do' => 'reject', 'id' => $vid, 'note' => '']);
ok($row($vid)['status'] === 'review', '不核准沒寫理由被擋');
req($DIR, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $DIR), 'act' => 'svc', 'do' => 'approve', 'id' => $vid, 'note' => '同意']);
$r = $row($vid);
ok($r['status'] === 'intake' && $r['decision'] === 'approve' && $r['decided_by'] === $DIR && $r['decision_note'] === '同意', '主任核准 → 待受理（交資訊部），記核准人與意見');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF), 'act' => 'svc', 'do' => 'accept', 'id' => $vid]);
ok($row($vid)['status'] === 'doing', '核准後資訊部受理 → 處理中（之後開工單）');
req($DIR, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $DIR), 'act' => 'svc', 'do' => 'reject', 'id' => $vid, 'note' => '改變心意']);
ok($row($vid)['status'] === 'doing', '已核准的不能再改成不核准');
req($STAFF, 'apply/index.php', 'POST', array_merge($vpn, ['name' => '測試丙']));
$v2 = (int)$d->query("SELECT max(id) FROM req WHERE type = 'vpn'")->fetchColumn();
req($DIR, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $DIR), 'act' => 'svc', 'do' => 'reject', 'id' => $v2, 'note' => '請改用遠端桌面']);
$r = $row($v2);
ok($r['status'] === 'rejected' && $r['decision_note'] === '請改用遠端桌面' && $r['done_at'], '主任不核准 → 未核准、記理由、結案時間');

echo "全部類型（#10）\n";
$fill = function ($t) {
    $f = [];
    foreach ($t['fields'] as $x) $f[$x['key']] = $x['type'] === 'select' ? $x['options'][0] : ($x['type'] === 'date' ? date('Y-m-d') : (!empty($x['pattern']) ? ($x['key'] === 'url' ? 'https://example.org/x' : 'test-user1') : '測試值'));
    return $f;
};
foreach (svc_types() as $k => $t) {
    $base = ['csrf' => $tok('apply', $STAFF), 'type' => $k, 'name' => '型別' . $k, 'purpose' => '測試', 'wanted_by' => '', 'f' => $fill($t)];
    foreach ($t['fields'] as $x) {
        if (empty($x['required'])) continue;
        $b = $cnt(); $p = $base; $p['f'][$x['key']] = '';
        $o = req($STAFF, 'apply/index.php', 'POST', $p);
        ok($cnt() === $b && str_contains($o, $x['type'] === 'select' ? '請選擇' . $x['label'] : '請填寫' . $x['label']), "{$t['name']}：{$x['label']}必填");
        if ($x['type'] === 'select') {
            $p['f'][$x['key']] = '不在選項內';
            req($STAFF, 'apply/index.php', 'POST', $p);
            ok($cnt() === $b, "{$t['name']}：{$x['label']}只接受選項內的值");
        }
    }
    $b = $cnt();
    req($STAFF, 'apply/index.php', 'POST', $base);
    $r = $d->query("SELECT * FROM req WHERE type = " . $d->quote($k) . " ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok($cnt() === $b + 1 && $r['status'] === ($t['director'] ? 'review' : 'intake'), "{$t['name']}：送出 → " . ($t['director'] ? '待主任核准' : '待受理'));
}
$e = ['csrf' => $tok('apply', $STAFF), 'type' => 'email', 'name' => '新同工', 'purpose' => '到職', 'wanted_by' => '',
      'f' => ['name_zh' => '新同工', 'name_en' => 'New Staff', 'start' => '2020-01-01']];
$b = $cnt(); req($STAFF, 'apply/index.php', 'POST', $e);
ok($cnt() === $b + 1, 'Email 到職日可以是過去（補辦）');
$want = ['ai' => true, 'shortlink' => false, 'email' => true, 'google' => true, 'vpn' => true, 'sysperm' => true, 'github' => true, 'firewall' => true, 'folder' => false, 'dns' => false, 'other' => false];
$got = array_map(fn($t) => $t['director'], svc_types());
ok(array_diff_key($want, $got) === [] && array_intersect_key($got, $want) == $want, '主任核准分流符合 #2 決定');
$gh = ['csrf' => $tok('apply', $STAFF), 'type' => 'github', 'name' => '開發者', 'purpose' => '開發', 'wanted_by' => '',
       'f' => ['account' => 'me@example.org', 'org' => 'ccra-tw', 'repos' => 'it.ccra.tw', 'perm' => '寫入']];
$b = $cnt(); $o = req($STAFF, 'apply/index.php', 'POST', $gh);
ok($cnt() === $b && str_contains($o, 'GitHub 帳號格式不正確'), 'GitHub 帳號填 email 被擋');
$gh['f']['account'] = 'dev-user'; req($STAFF, 'apply/index.php', 'POST', $gh);
ok($cnt() === $b + 1, 'GitHub 帳號格式正確可送出');
$o = req($STAFF, 'apply/index.php', 'GET');
ok(substr_count($o, 'class="tf"') === count(array_filter(svc_types(), fn($t) => $t['fields'])) && str_contains($o, '<select id="f_google_service"'), '申請頁為每個類型產生欄位區，下拉選單正確');

echo "代他人申請（#11）\n";
$ob = ['csrf' => $tok('apply', $STAFF), 'type' => 'other', 'name' => '主管甲', 'purpose' => '替新人申請', 'wanted_by' => '',
       'for' => 'other', 'target_name' => '新人乙', 'target_dept' => '資訊部', 'target_start' => '2026-10-01'];
$b = $cnt();
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($ob, ['target_name' => '']));
ok($cnt() === $b && str_contains($o, '請填寫申請對象的姓名'), '代他人：對象姓名必填');
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($ob, ['target_dept' => '不存在的部門']));
ok($cnt() === $b && str_contains($o, '請選擇申請對象的部門'), '代他人：部門須在清單內');
$o = req($STAFF, 'apply/index.php', 'POST', array_merge($ob, ['target_start' => '10/01']));
ok($cnt() === $b && str_contains($o, '到職日格式不正確'), '代他人：到職日格式檢查');
req($STAFF, 'apply/index.php', 'POST', $ob);
$r = $d->query('SELECT * FROM req ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok($cnt() === $b + 1 && $r['for_self'] === false && $r['target_name'] === '新人乙' && $r['target_dept'] === '資訊部' && $r['target_start'] === '2026-10-01' && $r['applicant_name'] === '主管甲',
   '代他人：申請人與對象分開記錄（含過去的到職日）');
req($STAFF, 'apply/index.php', 'POST', array_merge($ob, ['target_name' => '新人丙']));
ok($cnt() === $b + 2, '代不同對象申請同類型各自成立');
req($STAFF, 'apply/index.php', 'POST', $ob);
ok($cnt() === $b + 2, '同一對象重送不重複');
req($STAFF, 'apply/index.php', 'POST', array_merge($ob, ['for' => 'self', 'target_name' => '應被忽略', 'name' => '本人丁']));
$r = $d->query('SELECT * FROM req ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok($r['for_self'] === true && $r['target_name'] === '本人丁', '選本人時忽略對象欄位，對象＝本人');
$o = req($STAFF, 'apply/index.php', 'GET');
ok(str_contains($o, 'name="for" value="other"') && str_contains($o, 'id="target_dept"'), '申請頁有「為誰申請」與對象欄位');

echo "AI 工具併入（#12）
";
$ai = ['csrf' => $tok('apply', $STAFF), 'type' => 'ai', 'name' => '同工戊', 'purpose' => '整理會議紀錄', 'wanted_by' => '',
       'f' => ['tool' => 'Claude', 'plan' => "每週整理逐字稿
比對上週", 'why' => 'Copilot 不能處理長檔', 'pii' => '不會']];
$b = $cnt(); $o = req($STAFF, 'apply/index.php', 'POST', array_merge($ai, ['f' => array_merge($ai['f'], ['tool' => 'ChatGPT'])]));
ok($cnt() === $b, 'AI：還沒開放的工具不能選');
req($STAFF, 'apply/index.php', 'POST', $ai);
$aid = (int)$d->query("SELECT max(id) FROM req WHERE type = 'ai'")->fetchColumn();
ok($row($aid)['status'] === 'review' && (json_decode($row($aid)['fields'], true)['plan'] ?? '') === "每週整理逐字稿
比對上週", 'AI：送出待主任核准，多行使用計畫原樣保存');
req($DIR, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $DIR), 'act' => 'svc', 'do' => 'approve', 'id' => $aid, 'note' => '']);
$r = $row($aid);
ok($r['status'] === 'doing' && $r['accepted_by'] === 'ituncle@ccra.org.tw', 'AI：主任核准後自動由大蘇受理（沿用開通 Claude 流程）');
$o = req($STAFF, 'ai-apply/index.php');
ok(!str_contains($o, 'name="plan"'), '舊 AI 申請網址不再顯示舊表單（轉到新入口）');
$o = req($STAFF, 'apply/index.php', 'GET', [], ['type' => 'ai']);
ok(str_contains($o, '<option value="ai" selected') && str_contains($o, '<textarea id="f_ai_plan"'), '新入口帶 type=ai 會預選 AI 工具並顯示使用計畫');

echo "縮址併入（#13）
";
$sl = itdb('shortlink');
$sl->exec('CREATE TABLE IF NOT EXISTS users (email TEXT PRIMARY KEY, name TEXT, added_at TEXT, added_by TEXT, synced_at TEXT)');
$sl->exec('TRUNCATE users');
$base = ['csrf' => $tok('apply', $STAFF), 'type' => 'shortlink', 'name' => '公關己', 'purpose' => '活動頁', 'wanted_by' => ''];
$b = $cnt();
$o = req($STAFF, 'apply/index.php', 'POST', $base + ['f' => ['need' => '請資訊部代建一個短網址']]);
ok($cnt() === $b && str_contains($o, '請填寫目標網址'), '縮址代建：目標網址必填');
$o = req($STAFF, 'apply/index.php', 'POST', $base + ['f' => ['need' => '請資訊部代建一個短網址', 'url' => 'www.ccra.org.tw']]);
ok($cnt() === $b && str_contains($o, '目標網址格式不正確'), '縮址代建：網址要有 http(s)://');
$o = req($STAFF, 'apply/index.php', 'POST', $base + ['f' => ['need' => '請資訊部代建一個短網址', 'url' => 'https://www.ccra.org.tw/a', 'slug' => '中文 名']]);
ok($cnt() === $b && str_contains($o, '希望的短網址格式不正確'), '縮址代建：短網址只能英數 - _');
$o = req($STAFF, 'apply/index.php', 'POST', $base + ['for' => 'other', 'target_name' => '別人', 'target_dept' => '資訊部', 'f' => ['need' => '後台帳號（自己建短網址）']]);
ok($cnt() === $b && str_contains($o, '縮址後台帳號請本人申請'), '縮址後台帳號不能代他人申請');
req($STAFF, 'apply/index.php', 'POST', $base + ['f' => ['need' => '後台帳號（自己建短網址）']]);
$sid = (int)$d->query("SELECT max(id) FROM req WHERE type = 'shortlink'")->fetchColumn();
ok($row($sid)['status'] === 'intake', '縮址：資訊部直接受理（不經主任）');
req($STAFF, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF), 'act' => 'svc', 'do' => 'accept', 'id' => $sid]);
$u = $sl->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC);
ok($row($sid)['status'] === 'doing' && count($u) === 1 && $u[0]['email'] === $STAFF && $u[0]['added_by'] === $STAFF && $u[0]['synced_at'] === null,
   '縮址後台帳號受理 → 寫入名單（待同步）');
req($STAFF, 'apply/index.php', 'POST', $base + ['name' => '公關庚', 'f' => ['need' => '請資訊部代建一個短網址', 'url' => 'https://www.ccra.org.tw/a', 'slug' => 'run2026']]);
$s2 = (int)$d->query("SELECT max(id) FROM req WHERE type = 'shortlink'")->fetchColumn();
req($STAFF, 'ops/index.php', 'POST', ['csrf' => $tok('ops', $STAFF), 'act' => 'svc', 'do' => 'accept', 'id' => $s2]);
ok((int)$sl->query('SELECT count(*) FROM users')->fetchColumn() === 1, '縮址代建受理不會開後台帳號');
$o = req($OUT, 'shortlink/index.php');
ok(!str_contains($o, 'name="purpose"'), '舊縮址申請頁不再顯示舊表單（沒帳號的轉到新入口）');
$sl->exec('TRUNCATE users');

echo "篩選、同工歷年、月報（#14）\n";
$d->exec('TRUNCATE req RESTART IDENTITY');
$ins = $d->prepare("INSERT INTO req (created_at,type,status,applicant_email,applicant_name,applicant_dept,for_self,target_name,purpose,fields,first_response_at,done_at,decided_by,is_test) VALUES (?,?,?,?,?,?,?,?,?,'{}'::jsonb,?,?,?,?)");
$T = fn($s) => date('c', strtotime($s));
$ins->execute([$T('2026-09-10 09:00'), 'vpn', 'done', 'hist@ccra.org.tw', '歷史甲', '資訊部', 'true', '歷史甲', 'x', $T('2026-09-10 11:00'), $T('2026-09-11 09:00'), 'black@ccra.org.tw', 'false']);
$ins->execute([$T('2026-09-12 09:00'), 'vpn', 'rejected', 'hist@ccra.org.tw', '歷史甲', '資訊部', 'true', '歷史甲', 'x', $T('2026-09-12 13:00'), null, 'black@ccra.org.tw', 'false']);
$ins->execute([$T('2026-09-15 09:00'), 'folder', 'doing', 'other@ccra.org.tw', '別人乙', '公關部', 'false', '歷史甲', 'x', null, null, null, 'false']);
$ins->execute([$T('2026-09-20 09:00'), 'vpn', 'done', 'hist@ccra.org.tw', '歷史甲', '資訊部', 'true', '歷史甲', 'x', $T('2026-09-20 10:00'), $T('2026-09-20 12:00'), null, 'true']);   // 測試單
$ai = itdb('ai_apply');
$ai->exec("CREATE TABLE IF NOT EXISTS ai_apply (id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, created_at TEXT, email TEXT, name TEXT, dept TEXT,
    decision TEXT, decided_by TEXT, decided_at TEXT, first_response_at TEXT, done_at TEXT, is_test BOOLEAN DEFAULT FALSE)");
$ai->prepare("INSERT INTO ai_apply (created_at,email,name,dept,decision,decided_by,decided_at,first_response_at,done_at,is_test) VALUES (?,?,?,?,?,?,?,?,?,false)")
   ->execute([$T('2026-09-05 09:00'), 'HIST@ccra.org.tw', '歷史甲', '資訊部', 'approve', 'black@ccra.org.tw', $T('2026-09-05 15:00'), $T('2026-09-05 15:00'), $T('2026-09-05 15:00')]);
$types = fn($rows) => array_map(fn($r) => $r['type'] . '/' . $r['status'], $rows);
ok($types(svc_list(['type' => 'vpn'])) === ['vpn/rejected', 'vpn/done'], '類型篩選（預設不含已結案測試單）');
ok(count(svc_list(['type' => 'vpn', 'test' => true])) === 3, '勾「含測試單」才看得到已結案測試單');
ok($types(svc_list(['status' => 'doing'])) === ['folder/doing'], '狀態篩選');
ok(count(svc_list(['type' => 'nope', 'status' => 'nope'])) === 4 - 1, '不認得的篩選值當作沒篩');
$h = svc_history('hist@ccra.org.tw');
ok(count($h) === 4 && $h[3]['src'] === 'legacy' && str_starts_with($h[3]['no'], 'A260905') && $h[3]['status'] === 'done',
   '同工歷年（email）含舊 AI 申請，新到舊');
ok(count(svc_history('歷史')) === 5, '同工歷年（姓名）也找得到「代他申請」的單');
ok(svc_history('') === [] && svc_history('查無此人') === [], '空白與查無回空');
$st = svc_stats('2026-09');
ok(($st['vpn'] ?? null) === ['total' => 2, 'done' => 1, 'rejected' => 1, 'open' => 0, 'resp_h' => 3.0, 'fin_h' => 24.0], '月報：測試單排除、平均回應 3 小時、完成 24 小時');
ok(($st['ai']['total'] ?? 0) === 1 && ($st['ai']['fin_h'] ?? null) === 6.0 && $st['folder']['open'] === 1 && $st['folder']['resp_h'] === null, '月報含舊紀錄；沒回應的不算進平均');
ok(svc_stats('2026-08') === [], '沒申請的月份為空');
// 戰情室畫面（篩選、歷年、月報）在測試資料表下開不起整頁（其他區塊的表不存在），改在上線後以正式資料唯讀 GET 驗證
$ai->exec("DELETE FROM ai_apply WHERE email = 'HIST@ccra.org.tw'");

$d->exec('TRUNCATE req RESTART IDENTITY');
echo ($fail ? "🔴 失敗 {$fail}／{$n}\n" : "✅ 全部通過 {$n} 項\n");
exit($fail ? 1 : 0);
