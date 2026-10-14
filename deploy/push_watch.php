<?php
// 戰情室推播：每分鐘看一次 Zabbix 與各申請單，有新的就推給已開啟通知的資訊部裝置（it-ccra-push.timer，www-data 身分）。
// 熊哥 2026-10-13 選定範圍：斷線（Zabbix 嚴重以上）、新的報修／AI 申請／縮址申請／咖啡廳申請。
// 本機探測器與資安事件由 IT大蘇那台經 SSH 呼叫 push_send.php，不在這裡。
// 第一次跑（或新增來源）只記下目前的位置，不推舊資料，免得一開就跳幾十則。
// Zabbix 問題解除時推一則「已解除」：沒有解除的告警會把一次異常變成長期錯誤資訊。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/zabbix.php';
require '/var/www/it-lib/webpush.php';

$d = push_db();
$d->exec('CREATE TABLE IF NOT EXISTS cursor (src TEXT PRIMARY KEY, last_id BIGINT)');
$d->exec('CREATE TABLE IF NOT EXISTS zbx (eventid TEXT PRIMARY KEY, host TEXT, name TEXT, severity INTEGER, at TEXT, resolved_at TEXT)');

// ---- 新申請單 ----
const SRC = [
    'repair'   => ['repair', 'repair', '新的資訊報修', '/ops/#repair'],
    'ai_apply' => ['ai_apply', 'ai_apply', '新的 AI 工具申請', '/ops/#ai'],
    'shortlink'=> ['shortlink', 'apply', '新的縮址後台申請', '/ops/#shortlink'],
    'cafe_join'=> ['cafe_join', 'cafe_join', '新的咖啡廳加入申請', '/ops/#cafe'],
    'svc_apply'=> ['svc_apply', 'req', '新的資訊服務申請', '/ops/#svc'],   // v2.0 單一入口（GitHub #12）
];
foreach (SRC as $src => [$schema, $table, $title, $url]) {
    try {
        $s = itdb($schema);
        if (!itdb_cols($s, $table)) continue;
        $cols = itdb_cols($s, $table);
        $max = (int)$s->query("SELECT coalesce(max(id),0) FROM $table")->fetchColumn();
        $q = $d->prepare('SELECT last_id FROM cursor WHERE src = ?'); $q->execute([$src]);
        $last = $q->fetchColumn();
        if ($last === false) { $d->prepare('INSERT INTO cursor VALUES (?,?)')->execute([$src, $max]); continue; }
        if ($max <= (int)$last) continue;
        $extra = in_array('is_test', $cols, true) ? ', is_test' : '';
        // 資訊服務申請的姓名／部門欄名不同，另帶類型
        $nd = $table === 'req' ? "applicant_name AS name, applicant_dept AS dept, type" : 'name, dept';
        $rows = $s->prepare("SELECT id, $nd$extra FROM $table WHERE id > ? ORDER BY id");
        $rows->execute([(int)$last]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $who = trim(($r['dept'] ?? '') . ' ' . ($r['name'] ?? ''));
            if (isset($r['type'])) { require_once '/var/www/it-lib/svc_apply.php'; $who = (svc_types()[$r['type']]['name'] ?? $r['type']) . '｜' . $who; }
            $test = !empty($r['is_test']) ? '（測試）' : '';
            push_send(['title' => $title . $test, 'body' => "#{$r['id']} {$who}", 'url' => $url, 'tag' => "$src-{$r['id']}"], $src);
        }
        $d->prepare('UPDATE cursor SET last_id = ? WHERE src = ?')->execute([$max, $src]);
    } catch (Throwable $e) { error_log("push_watch $src: " . $e->getMessage()); }
}

// ---- Zabbix 嚴重（4）以上 ----
try {
    $token = trim((string)@file_get_contents(STATE_DIR . '/zabbix-ops.token'));
    $probs = zbx_call('problem.get', ['output' => ['eventid', 'name', 'severity', 'clock', 'objectid'], 'severities' => [4, 5],
        'recent' => false, 'suppressed' => false], $token);
    $hostOf = [];
    if ($probs) foreach (zbx_call('trigger.get', ['triggerids' => array_column($probs, 'objectid'), 'output' => ['triggerid'],
        'selectHosts' => ['name'], 'monitored' => true], $token) as $t) $hostOf[$t['triggerid']] = $t['hosts'][0]['name'] ?? '';
    $first = !(int)$d->query("SELECT count(*) FROM cursor WHERE src = 'zabbix'")->fetchColumn();
    $now = [];
    foreach ($probs as $p) {
        if (!isset($hostOf[$p['objectid']])) continue;          // 觸發器已停用（例如誤報）就不推
        $now[$p['eventid']] = true;
        $ins = $d->prepare('INSERT INTO zbx (eventid, host, name, severity, at) VALUES (?,?,?,?,?) ON CONFLICT DO NOTHING');
        $ins->execute([$p['eventid'], $hostOf[$p['objectid']], $p['name'], (int)$p['severity'], date('c')]);
        if ($ins->rowCount() && !$first)
            push_send(['title' => ((int)$p['severity'] === 5 ? '災難' : '嚴重') . '：' . $hostOf[$p['objectid']], 'body' => $p['name'],
                'url' => '/ops/#hosts', 'tag' => 'zbx-' . $p['eventid']], 'zabbix');
    }
    if ($first) $d->exec("INSERT INTO cursor VALUES ('zabbix', 0)");
    foreach ($d->query('SELECT * FROM zbx WHERE resolved_at IS NULL')->fetchAll(PDO::FETCH_ASSOC) as $z) {
        if (isset($now[$z['eventid']])) continue;
        $d->prepare('UPDATE zbx SET resolved_at = ? WHERE eventid = ?')->execute([date('c'), $z['eventid']]);
        push_send(['title' => '已解除：' . $z['host'], 'body' => $z['name'], 'url' => '/ops/#hosts', 'tag' => 'zbx-' . $z['eventid']], 'zabbix');
    }
} catch (Throwable $e) { error_log('push_watch zabbix: ' . $e->getMessage()); }
