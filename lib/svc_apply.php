<?php
// 資訊服務申請（v2.0 單一入口；規格 GitHub #7，施工票 #8～#15）。
// 類型設定、資料表、狀態轉移集中在這裡：申請頁、戰情室、測試都呼叫同一組函式，新增類型只改 svc_types()。
// 這台不寄信：確認信、Planner 工單、完成通知由資訊部本機維運腳本讀取後處理（先記後寄）。
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/prereview.php';   // 申請單預審（#19）

const SVC_DIRECTOR = 'black@ccra.org.tw';   // 需主任核准的類型只有這個帳號能核准
// 狀態：review 待主任核准 → intake 待受理 → doing 處理中 → done 已完成；review 也可能 → rejected 未核准
const SVC_STATUS = ['review' => '待主任核准', 'intake' => '待受理', 'doing' => '處理中', 'done' => '已完成', 'rejected' => '未核准'];
const SVC_OPEN = ['review', 'intake', 'doing'];

// 類型設定。director＝是否需主任核准（#2 決定）；fields＝類型專屬欄位（#6 決定），共同欄位不在這裡。
function svc_types(): array {
    $until = ['key' => 'until', 'label' => '要用到哪一天？', 'err' => '使用期限', 'type' => 'date', 'required' => false, 'hint' => '不填就是一直要用，資訊部之後會再問你還需不需要'];
    return [
        // AI 工具（#12）：題目沿用原 /ai-apply/（2026-08-26 定案），核准後由資訊部受理開通；ChatGPT 協會申請中，開放後加進選項
        'ai' => ['name' => 'AI 工具', 'director' => true, 'auto_accept' => 'ituncle@ccra.org.tw', 'fields' => [
            ['key' => 'tool', 'label' => '想用哪一個？', 'err' => '申請工具', 'type' => 'select', 'required' => true, 'options' => ['Claude'], 'hint' => 'ChatGPT 協會還在申請，可以用了會通知大家'],
            ['key' => 'plan', 'label' => '你想拿它做什麼？', 'err' => '使用計畫', 'type' => 'textarea', 'required' => true, 'max' => 2000, 'hint' => '例：每週開完會，請它把會議紀錄整理成重點和待辦事項'],
            ['key' => 'why', 'label' => '協會現在就能用的 Copilot（微軟的 AI），哪裡不夠用？', 'err' => 'Copilot 不夠用的原因', 'type' => 'textarea', 'required' => true, 'max' => 2000, 'hint' => '沒用過也沒關係，寫「沒用過」就好'],
            ['key' => 'pii', 'label' => '會不會用到別人的個人資料？（例如姓名、電話、身分證、病況）', 'err' => '是否處理個資', 'type' => 'select', 'required' => true, 'options' => ['不會', '會']],
        ]],
        // 縮址服務（#13）：資訊部直接受理（#2）。後台帳號受理時寫入 shortlink.users，由本機 shortlink_sync 同步到 Cloudflare Access 與 YOURLS
        'shortlink' => ['name' => '縮址服務（ccra.tw 短網址）', 'director' => false, 'fields' => [
            ['key' => 'need', 'label' => '你要哪一種？', 'err' => '需要的服務', 'type' => 'select', 'required' => true, 'options' => ['後台帳號（自己建短網址）', '請資訊部代建一個短網址']],
            ['key' => 'url', 'label' => '原本很長的網址', 'err' => '目標網址', 'type' => 'text', 'required' => false, 'max' => 500, 'pattern' => '#^https?://\S+$#', 'hint' => '請資訊部代建時一定要填。把網頁上面那一長串網址整個複製貼上'],
            ['key' => 'slug', 'label' => '想要的短網址', 'err' => '希望的短網址', 'type' => 'text', 'required' => false, 'max' => 50, 'pattern' => '/^[A-Za-z0-9_-]+$/', 'hint' => '例：填 1919run，網址就是 ccra.tw/1919run（只能用英文字母、數字、- 和 _）'],
        ]],
        // 熊哥 2026-10-16：「開信箱不是為了新同事，新同事是人事部門通知，而是需要其他用途的信箱申請」
        'email' => ['name' => '其他用途信箱', 'director' => true, 'fields' => [
            ['key' => 'use', 'label' => '這個信箱要做什麼用？', 'err' => '信箱用途', 'type' => 'text', 'required' => true, 'max' => 200, 'hint' => '例：年底募款活動收報名信、某個專案對外聯絡、部門共用'],
            ['key' => 'want', 'label' => '想要的信箱名稱（可以不填）', 'err' => '想要的信箱名稱', 'type' => 'text', 'required' => false, 'max' => 80, 'hint' => '例：run2026@ccra.org.tw；不填就由資訊部建議'],
            ['key' => 'users', 'label' => '誰會使用這個信箱？', 'err' => '使用者', 'type' => 'text', 'required' => true, 'max' => 200, 'hint' => '寫同工姓名，可以多人'],
            ['key' => 'until', 'label' => '用到什麼時候？（長期使用就不用填）', 'err' => '使用期限', 'type' => 'date', 'required' => false],
        ]],
        'google' => ['name' => 'Google Workspace 與服務權限', 'director' => true, 'fields' => [
            ['key' => 'service', 'label' => '要用哪一個？', 'err' => '服務', 'type' => 'select', 'required' => true, 'options' => ['Google Workspace 帳號', 'Google Ads', 'Search Console', 'Google Analytics', 'YouTube', '其他']],
            ['key' => 'level', 'label' => '要做到什麼程度？', 'err' => '權限', 'type' => 'select', 'required' => true, 'options' => ['檢視', '編輯', '管理'], 'hint' => '只看＝檢視；要改內容＝編輯；要加人、改設定＝管理'],
            ['key' => 'account', 'label' => '用哪個信箱登入？', 'err' => '要加入的帳號', 'type' => 'text', 'required' => false, 'max' => 120, 'hint' => '不填就用協會信箱'],
        ]],
        'folder' => ['name' => '共用資料夾', 'director' => false, 'fields' => [
            ['key' => 'path', 'label' => '是哪一個資料夾？', 'err' => '資料夾位置', 'type' => 'text', 'required' => true, 'max' => 200, 'hint' => '寫你知道的就好，例如「財務部的報表資料夾」；能截圖更好，之後回信附給資訊部'],
            ['key' => 'perm', 'label' => '要做到什麼程度？', 'err' => '權限', 'type' => 'select', 'required' => true, 'options' => ['讀取', '讀寫'], 'hint' => '只看＝讀取；要改或放檔案進去＝讀寫'],
            $until,
        ]],
        'vpn'   => ['name' => 'VPN', 'director' => true, 'fields' => [
            ['key' => 'location', 'label' => '會在哪裡用？', 'err' => '使用地點', 'type' => 'text', 'required' => true, 'max' => 100, 'hint' => '例：家裡、出差的地方'],
            ['key' => 'device', 'label' => '用哪一台？', 'err' => '使用裝置', 'type' => 'text', 'required' => true, 'max' => 100, 'hint' => '例：協會給的筆電、自己的手機'],
            $until,
        ]],
        'sysperm' => ['name' => '系統權限', 'director' => true, 'fields' => [
            ['key' => 'system', 'label' => '是哪一個系統？', 'err' => '系統名稱', 'type' => 'text', 'required' => true, 'max' => 100, 'hint' => '例：1919 系統、收據系統；不知道名字就寫你平常怎麼叫它'],
            ['key' => 'role', 'label' => '你要在裡面做什麼？', 'err' => '需要的角色或功能', 'type' => 'text', 'required' => true, 'max' => 200, 'hint' => '例：要能新增個案、要能看報表'],
            $until,
        ]],
        'github' => ['name' => 'GitHub 權限', 'director' => true, 'fields' => [
            ['key' => 'account', 'label' => 'GitHub 帳號', 'type' => 'text', 'required' => true, 'max' => 39, 'pattern' => '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', 'hint' => '帳號名稱，不是 email；還沒有帳號請先到 github.com 註冊'],
            ['key' => 'org', 'label' => '組織', 'type' => 'select', 'required' => true, 'options' => ['ccra-tw', 'ccea-tw']],
            ['key' => 'repos', 'label' => '要用的 repo', 'type' => 'text', 'required' => true, 'max' => 300, 'hint' => '可填多個，用逗號分開；不確定可寫用途'],
            ['key' => 'perm', 'label' => '權限', 'type' => 'select', 'required' => true, 'options' => ['讀取', '寫入', '管理']],
            $until,
        ]],
        'dns' => ['name' => '網域指向（DNS）變更', 'director' => false, 'fields' => [
            ['key' => 'domain', 'label' => '是哪一個網址？', 'err' => '網域', 'type' => 'text', 'required' => true, 'max' => 120, 'hint' => '例：event.ccra.tw'],
            ['key' => 'rtype', 'label' => '廠商說的類型', 'err' => '紀錄類型', 'type' => 'select', 'required' => true, 'options' => ['A', 'AAAA', 'CNAME', 'TXT', 'MX', '不確定'], 'hint' => '看不懂就選「不確定」'],
            ['key' => 'target', 'label' => '廠商給你的內容', 'err' => '指向目標', 'type' => 'text', 'required' => true, 'max' => 300, 'hint' => '把廠商信裡那一串數字或文字整段貼過來就好'],
            ['key' => 'when', 'label' => '希望哪一天好？', 'err' => '希望生效日', 'type' => 'date', 'required' => false],
        ]],
        'firewall' => ['name' => '防火牆異動', 'director' => true, 'fields' => [
            ['key' => 'src', 'label' => '來源', 'type' => 'text', 'required' => true, 'max' => 200, 'hint' => '例：外部任何地方、某辦公室、某台電腦'],
            ['key' => 'dst', 'label' => '目的', 'type' => 'text', 'required' => true, 'max' => 200, 'hint' => '要連到哪台主機或服務'],
            ['key' => 'port', 'label' => '埠或服務', 'type' => 'text', 'required' => true, 'max' => 100, 'hint' => '例：443、遠端桌面；不確定可寫「不確定」'],
            $until,
        ]],
        'other' => ['name' => '其他', 'director' => false, 'fields' => []],
    ];
}

// 驗證並整理類型專屬欄位，回傳 [錯誤陣列, 整理後的值]
function svc_fields(string $type, array $in): array {
    $err = []; $out = [];
    foreach (svc_types()[$type]['fields'] ?? [] as $f) {
        $v = trim((string)($in['f'][$f['key']] ?? ''));
        if ($v === '') { if (!empty($f['required'])) $err[] = ($f['type'] === 'select' ? '請選擇' : '請填寫') . ($f['err'] ?? $f['label']) . '。'; continue; }
        if ($f['type'] === 'date') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || strtotime($v) === false) { $err[] = ($f['err'] ?? $f['label']) . '格式不正確。'; continue; }
            if (empty($f['past_ok']) && $v < date('Y-m-d')) { $err[] = ($f['err'] ?? $f['label']) . '不能早於今天。'; continue; }
        } elseif ($f['type'] === 'select') {
            if (!in_array($v, $f['options'], true)) { $err[] = '請選擇' . ($f['err'] ?? $f['label']) . '。'; continue; }
        } elseif (mb_strlen($v) > ($f['max'] ?? 2000)) { $err[] = ($f['err'] ?? $f['label']) . '太長（' . ($f['max'] ?? 2000) . ' 字內）。'; continue; }
        if (!empty($f['pattern']) && !preg_match($f['pattern'], $v)) { $err[] = ($f['err'] ?? $f['label']) . '格式不正確。'; continue; }
        $out[$f['key']] = $v;
    }
    return [$err, $out];
}

function svc_db(): PDO {
    $d = itdb('svc_apply');
    $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $d->exec('CREATE TABLE IF NOT EXISTS req (
        id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
        created_at TEXT NOT NULL, type TEXT NOT NULL, status TEXT NOT NULL,
        applicant_email TEXT NOT NULL, applicant_name TEXT, applicant_dept TEXT,
        for_self BOOLEAN NOT NULL DEFAULT TRUE, target_name TEXT, target_dept TEXT, target_start TEXT,
        purpose TEXT NOT NULL, wanted_by TEXT, fields JSONB NOT NULL DEFAULT \'{}\'::jsonb,
        decision TEXT, decision_note TEXT, decided_by TEXT, decided_at TEXT,
        accepted_by TEXT, accepted_at TEXT, planner_task_id TEXT,
        done_by TEXT, done_note TEXT, done_at TEXT,
        ack_notified_at TEXT, decision_notified_at TEXT, done_notified_at TEXT,
        first_response_at TEXT, is_test BOOLEAN NOT NULL DEFAULT FALSE)');
    $d->exec('ALTER TABLE req ADD COLUMN IF NOT EXISTS head_note TEXT');   // 主管知會結果（#11）：已知會誰／查無主管
    $d->exec('ALTER TABLE req ADD COLUMN IF NOT EXISTS pre_verdict TEXT');   // 送出當下的預審建議（#19）：approve／director／return／skip，規則日後改了也留得住當時判斷
    return $d;
}

// 送出申請。$who：['email','name','dept']（登入身分）；$in：表單欄位。回傳 [錯誤陣列, 新 id 或既有 id 或 null]
function svc_submit(array $who, array $in): array {
    $types = svc_types();
    $type = (string)($in['type'] ?? '');
    $err = [];
    if (!isset($types[$type])) return [['請選擇申請類型。'], null];
    $name = trim((string)($in['name'] ?? ''));
    $purpose = trim((string)($in['purpose'] ?? ''));
    $wanted = trim((string)($in['wanted_by'] ?? ''));
    if ($name === '' || mb_strlen($name) > 50) $err[] = '請填寫姓名。';
    // 為誰申請（#11）：本人，或代他人（例如替還沒有帳號的新同工申請）
    $forSelf = ($in['for'] ?? 'self') !== 'other';
    $tName = $name; $tDept = (string)($who['dept'] ?? ''); $tStart = null;
    if (!$forSelf) {
        $tName = trim((string)($in['target_name'] ?? ''));
        $tDept = (string)($in['target_dept'] ?? '');
        $tStart = trim((string)($in['target_start'] ?? ''));
        if ($tName === '' || mb_strlen($tName) > 50) $err[] = '請填寫申請對象的姓名。';
        if (!in_array($tDept, require __DIR__ . '/depts.php', true)) $err[] = '請選擇申請對象的部門。';
        if ($tStart !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tStart) || strtotime($tStart) === false)) $err[] = '申請對象的到職日格式不正確。';
        if ($tStart === '') $tStart = null;
    }
    if ($purpose === '' || mb_strlen($purpose) > 2000) $err[] = '請說明用途（2000 字內）。';
    if ($wanted !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wanted) || strtotime($wanted) === false)) $err[] = '希望完成日格式不正確。';
    elseif ($wanted !== '' && $wanted < date('Y-m-d')) $err[] = '希望完成日不能早於今天。';
    [$ferr, $fields] = svc_fields($type, $in);
    $err = array_merge($err, $ferr);
    // 縮址：代建要填目標網址；後台帳號只能本人申請（帳號要對應本人的 M365 登入）
    if ($type === 'shortlink' && !$ferr) {
        if (($fields['need'] ?? '') === '請資訊部代建一個短網址' && ($fields['url'] ?? '') === '') $err[] = '請填寫目標網址。';
        if (($fields['need'] ?? '') === '後台帳號（自己建短網址）' && !$forSelf) $err[] = '縮址後台帳號請本人申請。';
    }
    if ($err) return [$err, null];

    $d = svc_db();
    // 防重複：同一人、同類型、同對象、還沒結束的申請已存在就不新增（重新整理或連按兩下）
    $dup = $d->prepare('SELECT id FROM req WHERE applicant_email = ? AND type = ? AND target_name = ? AND status = ANY(?::text[]) ORDER BY id DESC LIMIT 1');
    $dup->execute([strtolower($who['email']), $type, $tName, '{' . implode(',', SVC_OPEN) . '}']);
    if ($old = $dup->fetchColumn()) return [[], (int)$old];

    $status = $types[$type]['director'] ? 'review' : 'intake';
    $st = $d->prepare('INSERT INTO req (created_at, type, status, applicant_email, applicant_name, applicant_dept, for_self, target_name, target_dept, target_start, purpose, wanted_by, fields, is_test)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?::jsonb,?) RETURNING id');
    $st->execute([date('c'), $type, $status, strtolower($who['email']), $name, (string)($who['dept'] ?? ''),
                  $forSelf ? 'true' : 'false', $tName, $tDept, $tStart, $purpose, $wanted !== '' ? $wanted : null, json_encode((object)$fields, JSON_UNESCAPED_UNICODE), !empty($in['is_test']) ? 'true' : 'false']);
    $id = (int)$st->fetchColumn();
    $new = $d->query('SELECT * FROM req WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    $d->prepare('UPDATE req SET pre_verdict = ? WHERE id = ?')->execute([svc_prereview($new)['verdict'], $id]);
    return [[], $id];
}

// 主任核准／不核准（需主任核准類 review → intake 交資訊部受理，或 → rejected）。只有 SVC_DIRECTOR 能做。
function svc_decide(int $id, string $by, string $decision, string $note = ''): bool {
    if (strtolower($by) !== SVC_DIRECTOR || !in_array($decision, ['approve', 'reject'], true)) return false;
    $note = mb_substr(trim($note), 0, 500);
    if ($decision === 'reject' && $note === '') return false;   // 不核准要附理由，申請人才知道怎麼辦
    $now = date('c');
    $d = svc_db();
    $s = $d->prepare("UPDATE req SET status = ?, decision = ?, decision_note = ?, decided_by = ?, decided_at = ?, first_response_at = COALESCE(first_response_at, ?), done_at = ? WHERE id = ? AND status = 'review'");
    $s->execute([$decision === 'approve' ? 'intake' : 'rejected', $decision, $note, strtolower($by), $now, $now, $decision === 'reject' ? $now : null, $id]);
    if ($s->rowCount() !== 1) return false;
    // 核准後固定由某人執行的類型（AI 工具＝大蘇開通帳號，沿用 v1 的「開通 Claude」任務）：直接受理，省掉一次手動受理
    if ($decision === 'approve') {
        $t = $d->prepare('SELECT type FROM req WHERE id = ?'); $t->execute([$id]);
        if ($who = svc_types()[$t->fetchColumn()]['auto_accept'] ?? null) svc_accept($id, $who);
    }
    return true;
}

// 資訊部受理（直接辦理類 intake → doing）。受理人就是負責人；Planner 工單由本機腳本開。
function svc_accept(int $id, string $by): bool {
    $d = svc_db();
    $s = $d->prepare("UPDATE req SET status = 'doing', accepted_by = ?, accepted_at = ?, first_response_at = COALESCE(first_response_at, ?) WHERE id = ? AND status = 'intake'");
    $now = date('c');
    $s->execute([strtolower($by), $now, $now, $id]);
    if ($s->rowCount() !== 1) return false;
    $r = $d->query('SELECT * FROM req WHERE id = ' . (int)$id)->fetch(PDO::FETCH_ASSOC);
    $f = json_decode((string)$r['fields'], true) ?: [];
    // 縮址後台帳號：寫入名單（唯一來源 shortlink.users），一小時內由本機 shortlink_sync 同步到 Cloudflare Access 與 YOURLS
    // 測試單不寫入真實名單（#15），否則會真的開出 Cloudflare Access 與 YOURLS 權限
    if ($r['type'] === 'shortlink' && ($f['need'] ?? '') === '後台帳號（自己建短網址）' && !$r['is_test']) {
        $sl = itdb('shortlink');
        $sl->exec('CREATE TABLE IF NOT EXISTS users (email TEXT PRIMARY KEY, name TEXT, added_at TEXT, added_by TEXT, synced_at TEXT)');
        $sl->prepare('INSERT INTO users (email, name, added_at, added_by) VALUES (?,?,?,?) ON CONFLICT (email) DO NOTHING')
           ->execute([$r['applicant_email'], $r['applicant_name'], $now, strtolower($by)]);
    }
    return true;
}

// 完成（doing → done）。完成通知由本機腳本寄、Planner 工單由本機腳本結案。
function svc_complete(int $id, string $by, string $note = ''): bool {
    $s = svc_db()->prepare("UPDATE req SET status = 'done', done_by = ?, done_note = ?, done_at = ? WHERE id = ? AND status = 'doing'");
    $s->execute([strtolower($by), mb_substr(trim($note), 0, 500), date('c'), $id]);
    return $s->rowCount() === 1;
}

function svc_no(array $r): string { return 'S' . date('ymd', strtotime($r['created_at'])) . '-' . str_pad((string)$r['id'], 3, '0', STR_PAD_LEFT); }

// ---- 篩選、同工歷年、月報統計（#14） ----
// 戰情室清單篩選。$f：type、status（空＝全部）、test（true＝含測試單；預設只含未結案的測試單，方便測完收尾）。
function svc_list(array $f = [], int $limit = 60): array {
    $w = []; $p = [];
    if (($f['type'] ?? '') !== '' && isset(svc_types()[$f['type']])) { $w[] = 'type = ?'; $p[] = $f['type']; }
    if (($f['status'] ?? '') !== '' && isset(SVC_STATUS[$f['status']])) { $w[] = 'status = ?'; $p[] = $f['status']; }
    if (empty($f['test'])) $w[] = "(is_test = FALSE OR status IN ('review','intake','doing'))";
    $s = svc_db()->prepare('SELECT * FROM req' . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
        . " ORDER BY CASE status WHEN 'review' THEN 0 WHEN 'intake' THEN 1 WHEN 'doing' THEN 2 ELSE 3 END, id DESC LIMIT " . max(1, $limit));
    $s->execute($p);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// 舊紀錄（v2.0 前的 AI 申請、縮址申請）轉成與 req 相同的欄位，供歷年查詢與統計共用。
// 舊單只有主任裁示：未裁示＝待主任核准、核准＝已完成（舊流程核准即結案，開通另走 Planner）、不核准＝未核准。
function svc_legacy(): array {
    $out = [];
    foreach ([['ai_apply', 'ai_apply', 'ai', 'A'], ['shortlink', 'apply', 'shortlink', 'L']] as [$schema, $table, $type, $pre]) {
        try {
            $d = itdb($schema);
            if (!itdb_cols($d, $table)) continue;
            foreach ($d->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $st = $r['decision'] === null || $r['decision'] === '' ? 'review' : ($r['decision'] === 'approve' ? 'done' : 'rejected');
                $out[] = ['src' => 'legacy', 'no' => $pre . date('ymd', strtotime($r['created_at'])) . '-' . str_pad((string)$r['id'], 3, '0', STR_PAD_LEFT),
                    'type' => $type, 'status' => $st, 'created_at' => $r['created_at'],
                    'applicant_email' => strtolower((string)$r['email']), 'applicant_name' => $r['name'], 'applicant_dept' => $r['dept'],
                    'target_name' => $r['name'], 'decided_by' => $r['decided_by'] ?? null, 'decided_at' => $r['decided_at'] ?? null,
                    'accepted_by' => null, 'first_response_at' => $r['first_response_at'] ?? null, 'done_at' => $r['done_at'] ?? null,
                    'is_test' => !empty($r['is_test'])];
            }
        } catch (Throwable $e) { error_log("svc_legacy $schema: " . $e->getMessage()); }
    }
    return $out;
}

// 新舊紀錄合併成同一種列（新紀錄 src='new'）。
function svc_all(): array {
    $rows = [];
    foreach (svc_db()->query('SELECT * FROM req')->fetchAll(PDO::FETCH_ASSOC) as $r)
        $rows[] = ['src' => 'new', 'no' => svc_no($r), 'is_test' => (bool)$r['is_test']] + $r;
    return array_merge($rows, svc_legacy());
}

// 同工歷年申請：以 email（完整）或姓名（部分）比對申請人與申請對象，新到舊。含測試單但標示，由畫面決定怎麼顯示。
function svc_history(string $who): array {
    $who = trim(mb_strtolower($who));
    if ($who === '') return [];
    $hit = fn($r) => str_contains($who, '@') ? strtolower((string)$r['applicant_email']) === $who
        : (str_contains(mb_strtolower((string)$r['applicant_name']), $who) || str_contains(mb_strtolower((string)($r['target_name'] ?? '')), $who));
    $rows = array_values(array_filter(svc_all(), $hit));
    usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $rows;
}

// 月報統計（$ym 如 2026-10）：依送出月份，測試單一律排除。時數取平均、四捨五入到小數一位，無資料為 null。
function svc_stats(string $ym): array {
    $hours = fn($a, $b) => ($a && $b) ? (strtotime($b) - strtotime($a)) / 3600 : null;
    $avg = fn(array $v) => $v ? round(array_sum($v) / count($v), 1) : null;
    $by = [];
    foreach (svc_all() as $r) {
        if ($r['is_test'] || !str_starts_with(date('Y-m', strtotime($r['created_at'])), $ym)) continue;
        $t = &$by[$r['type']];
        $t ??= ['total' => 0, 'done' => 0, 'rejected' => 0, 'open' => 0, 'pre_mismatch' => 0, 'resp' => [], 'fin' => []];
        $t['total']++;
        if ($r['status'] === 'done') $t['done']++; elseif ($r['status'] === 'rejected') $t['rejected']++; else $t['open']++;
        // 預審與最後結果不一致（#19）：建議核准卻未核准、建議退回補件卻完成了；舊紀錄沒有 pre_verdict 不計
        $pv = $r['pre_verdict'] ?? null;
        if (($pv === PRE_APPROVE && $r['status'] === 'rejected') || ($pv === PRE_RETURN && $r['status'] === 'done')) $t['pre_mismatch']++;
        if (($h = $hours($r['created_at'], $r['first_response_at'])) !== null) $t['resp'][] = $h;
        if ($r['status'] === 'done' && ($h = $hours($r['created_at'], $r['done_at'])) !== null) $t['fin'][] = $h;
        unset($t);
    }
    foreach ($by as &$t) { $t['resp_h'] = $avg($t['resp']); $t['fin_h'] = $avg($t['fin']); unset($t['resp'], $t['fin']); }
    unset($t);
    ksort($by);
    return $by;
}
