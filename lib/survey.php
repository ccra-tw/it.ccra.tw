<?php
// 應用軟體與專案系統使用調查（線上版）。
// 熊哥 2026-10-16：問卷「做成問卷然後直接秀出統計」；「不要填帳號，發信的時候每一封信直接帶同工的電郵資訊，
// 讓他填寫的時候就已經是他的身分，因為很多人還不會登入M365」；統計「只有資訊部看（戰情室）」。
// 所以問卷頁 /survey/ 不經 M365 登入（Cloudflare Access 對該路徑放行），身分只認信裡那條專屬連結的識別碼。
// 題目照資訊部 10/15 的「應用軟體與專案系統問卷調查表」（Excel 版）；系統清單不另外加，讓同工在「其他」自己寫（熊哥：搞不好會有新發現）。
require_once __DIR__ . '/db.php';

const SURVEY_ID = '2026-apps';
const SURVEY_GROUPS = [
    '一、基本工具' => ['Claude', 'VPN', 'Google Workspace（ccra.tw）'],
    '二、Google 行銷工具權限' => ['Google 廣告', 'GA（Google Analytics）', 'Search Console'],
    '三、業務系統' => ['1919 急難救助', '食物銀行／新北惜食', '陪讀 CR', '個案管理系統', '收據系統', '請款系統', '感謝禮系統', '諾亞專案', '環台勇士募款系統'],
    '四、NAS 與付費軟體' => ['NAS 資料夾權限', 'Adobe', 'Canva', 'Zoom', '防毒軟體'],
    '五、共用帳號' => ['FB 粉專', 'IG', 'LINE 官方帳號', 'YouTube', '官網後台'],
];
const SURVEY_HINTS = [
    'Google Workspace（ccra.tw）' => 'ccra.tw 帳號',
    'NAS 資料夾權限' => '需要特定資料夾，請寫在下方備註',
    '防毒軟體' => '知道自己電腦有裝防毒軟體就勾選（不必寫哪一套）',
];

function survey_items(): array {
    $all = [];
    foreach (SURVEY_GROUPS as $items) foreach ($items as $i) $all[] = $i;
    return $all;
}

function survey_db(): PDO {
    $d = itdb('survey');
    $d->exec('CREATE TABLE IF NOT EXISTS person (token TEXT PRIMARY KEY, survey TEXT NOT NULL, email TEXT NOT NULL, name TEXT, dept TEXT,
              created_at TEXT, sent_at TEXT, UNIQUE (survey, email))');
    $d->exec('CREATE TABLE IF NOT EXISTS answer (survey TEXT NOT NULL, email TEXT NOT NULL, items TEXT NOT NULL, other TEXT, note TEXT,
              submitted_at TEXT, updated_at TEXT, PRIMARY KEY (survey, email))');
    return $d;
}

// 識別碼只認格式正確且登記過的；格式不對直接回 null，不查資料庫
function survey_person(string $token): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) return null;
    $s = survey_db()->prepare('SELECT * FROM person WHERE token = ? AND survey = ?');
    $s->execute([$token, SURVEY_ID]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// 名單登記：已有的人沿用原識別碼（重寄不會讓舊連結失效），新的人才產生
function survey_enroll(string $email, string $name, string $dept): string {
    $d = survey_db();
    $email = strtolower(trim($email));
    $s = $d->prepare('SELECT token FROM person WHERE survey = ? AND email = ?');
    $s->execute([SURVEY_ID, $email]);
    if ($t = $s->fetchColumn()) {
        $d->prepare('UPDATE person SET name = ?, dept = ? WHERE token = ?')->execute([$name, $dept, $t]);
        return $t;
    }
    $t = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $d->prepare('INSERT INTO person (token, survey, email, name, dept, created_at) VALUES (?,?,?,?,?,?)')
      ->execute([$t, SURVEY_ID, $email, $name, $dept, date('c')]);
    return $t;
}

function survey_answer(string $email): ?array {
    $s = survey_db()->prepare('SELECT * FROM answer WHERE survey = ? AND email = ?');
    $s->execute([SURVEY_ID, $email]);
    $a = $s->fetch(PDO::FETCH_ASSOC);
    if (!$a) return null;
    $a['items'] = json_decode($a['items'], true) ?: [];
    return $a;
}

// 只收題目清單裡的項目（送來的其他值一律丟掉）；可以重填，以最後一次為準
function survey_save(string $email, array $items, string $other, string $note): void {
    $valid = array_values(array_intersect(survey_items(), $items));
    $other = mb_substr(trim($other), 0, 1000);
    $note = mb_substr(trim($note), 0, 1000);
    $now = date('c');
    survey_db()->prepare('INSERT INTO answer (survey, email, items, other, note, submitted_at, updated_at) VALUES (?,?,?,?,?,?,?)
        ON CONFLICT (survey, email) DO UPDATE SET items = EXCLUDED.items, other = EXCLUDED.other, note = EXCLUDED.note, updated_at = EXCLUDED.updated_at')
      ->execute([SURVEY_ID, $email, json_encode($valid, JSON_UNESCAPED_UNICODE), $other, $note, $now, $now]);
}

// 統計：每一項勾選人數與名單、「其他」與備註原文、已填／未填名單
function survey_stats(): array {
    $d = survey_db();
    $people = $d->prepare('SELECT email, name, dept, sent_at FROM person WHERE survey = ? ORDER BY dept, name');
    $people->execute([SURVEY_ID]);
    $people = $people->fetchAll(PDO::FETCH_ASSOC);
    $ans = $d->prepare('SELECT * FROM answer WHERE survey = ?');
    $ans->execute([SURVEY_ID]);
    $byEmail = [];
    foreach ($ans->fetchAll(PDO::FETCH_ASSOC) as $a) { $a['items'] = json_decode($a['items'], true) ?: []; $byEmail[$a['email']] = $a; }
    $count = array_fill_keys(survey_items(), []);
    $done = []; $todo = []; $texts = [];
    foreach ($people as $p) {
        $a = $byEmail[$p['email']] ?? null;
        if (!$a) { $todo[] = $p; continue; }
        $done[] = $p + ['updated_at' => $a['updated_at']];
        foreach ($a['items'] as $i) if (isset($count[$i])) $count[$i][] = $p['name'];
        if ($a['other'] !== '' || $a['note'] !== '') $texts[] = ['name' => $p['name'], 'dept' => $p['dept'], 'other' => $a['other'], 'note' => $a['note']];
    }
    return ['count' => $count, 'done' => $done, 'todo' => $todo, 'texts' => $texts, 'total' => count($people)];
}

function survey_mark_sent(string $email): void {
    survey_db()->prepare('UPDATE person SET sent_at = ? WHERE survey = ? AND email = ?')->execute([date('c'), SURVEY_ID, strtolower($email)]);
}
