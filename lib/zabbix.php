<?php
// Zabbix 主機監控摘要（戰情室用）。走 ITSrv01 本機的 Zabbix API，用唯讀帳號 it-ccra-ops 的 API token。
// token 放 STATE_DIR/zabbix-ops.token（www-data 600），不進 GitHub；結果快取 60 秒。
// 回傳 [主機陣列或 null, 錯誤訊息]；每台：name, host, hostid, group, avail(1 正常/2 連不到/0 未知), cpu, mem, disk, problems[]。
function zbx_call($method, $params, $token) {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
        'header' => "Content-Type: application/json-rpc\r\nHost: mon.ccra.tw\r\nAuthorization: Bearer $token\r\n",
        'content' => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1])]]);
    $r = @file_get_contents('http://127.0.0.1/api_jsonrpc.php', false, $ctx);
    $j = $r ? json_decode($r, true) : null;
    if (!$j || isset($j['error'])) throw new RuntimeException($method . ': ' . ($j['error']['data'] ?? 'no response'));
    return $j['result'];
}

// 主機可用狀態：有代理／SNMP 介面就看介面；只有 ICMP（沒裝代理的 NAS、交換器）介面永遠是 0 未知，改看 icmpping（1 通／0 不通）。
// 熊哥 2026-11-02「設備監控有一堆狀況」：11/01 新加的 13 台 ping-only 主機被畫成「狀態未知」。
function zbx_avail(array $interfaces, $ping) {
    $av = 0;
    foreach ($interfaces as $i) $av = max($av, (int)$i['available']);
    foreach ($interfaces as $i) if ((int)$i['available'] === 2) $av = 2;
    if ($av === 0 && $ping !== null) $av = ((int)$ping === 1) ? 1 : 2;
    return $av;
}

// 主機上有哪些網站（熊哥 2026-11-02：「哪一個網站在哪一台主機，在主機展開的時候可以看到」）。
// STATE_DIR/host_sites.json 由大蘇本機每天從 IIS 綁定／nginx server_name 抓好推上來：{"updated_at":..., "hosts":{"<Zabbix hostid>":["網址",...]}}
function zbx_sites($file = null) {
    $file = $file ?? STATE_DIR . '/host_sites.json';
    $j = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    if (!is_array($j) || !is_array($j['hosts'] ?? null)) return [[], ''];
    $out = [];
    foreach ($j['hosts'] as $hid => $list) if (is_array($list)) $out[(string)$hid] = array_values(array_filter($list, 'is_string'));
    return [$out, (string)($j['updated_at'] ?? '')];
}

function zabbix_hosts() {
    $cache = STATE_DIR . '/zabbix_cache.json';
    if (is_file($cache) && time() - filemtime($cache) < 60) return [json_decode(file_get_contents($cache), true), ''];
    $token = trim((string)@file_get_contents(STATE_DIR . '/zabbix-ops.token'));
    try {
        $hosts = zbx_call('host.get', ['output' => ['hostid', 'host', 'name'], 'filter' => ['status' => 0],
            'selectInterfaces' => ['type', 'available', 'error'], 'selectHostGroups' => ['name']], $token);
        $ids = array_column($hosts, 'hostid');
        $keys = ['system.cpu.util', 'vm.memory.utilization', 'vm.memory.util', 'vfs.fs.dependent.size[/,pused]', 'vfs.fs.dependent.size[C:,pused]', 'icmppingsec', 'icmpping'];
        $items = zbx_call('item.get', ['hostids' => $ids, 'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
            'filter' => ['key_' => $keys]], $token);
        $probs = zbx_call('problem.get', ['hostids' => $ids, 'output' => ['eventid', 'name', 'severity', 'clock', 'objectid'],
            'recent' => false, 'suppressed' => false, 'sortfield' => ['eventid'], 'sortorder' => 'DESC'], $token);
        $trig = $probs ? zbx_call('trigger.get', ['triggerids' => array_column($probs, 'objectid'), 'output' => ['triggerid'],
            'selectHosts' => ['hostid'], 'monitored' => true], $token) : [];   // 只算啟用中的觸發器；手動停用的（例如誤報）不列
        $t2h = [];
        foreach ($trig as $t) $t2h[$t['triggerid']] = $t['hosts'][0]['hostid'] ?? null;
        $out = [];
        foreach ($hosts as $h) {
            $ping = null;
            foreach ($items as $it) if ($it['hostid'] === $h['hostid'] && $it['key_'] === 'icmpping' && $it['lastclock']) $ping = $it['lastvalue'];
            $row = ['hostid' => $h['hostid'], 'name' => $h['name'], 'group' => $h['hostgroups'][0]['name'] ?? '',
                'avail' => zbx_avail($h['interfaces'], $ping), 'ping_only' => $ping !== null && !array_filter($h['interfaces'], fn($i) => (int)$i['available'] !== 0),
                'cpu' => null, 'mem' => null, 'disk' => null, 'problems' => []];
            foreach ($items as $it) {
                if ($it['hostid'] !== $h['hostid'] || !$it['lastclock']) continue;
                $v = round((float)$it['lastvalue'], 1);
                if ($it['key_'] === 'system.cpu.util') $row['cpu'] = $v;
                elseif ($it['key_'] === 'vm.memory.utilization' || $it['key_'] === 'vm.memory.util') $row['mem'] = $v;   // Linux／Windows 範本鍵名不同
                elseif (str_starts_with($it['key_'], 'vfs.fs.dependent.size')) $row['disk'] = $v;
            }
            foreach ($probs as $p) if (($t2h[$p['objectid']] ?? null) === $h['hostid'])
                $row['problems'][] = ['name' => $p['name'], 'severity' => (int)$p['severity'], 'clock' => (int)$p['clock']];
            $out[] = $row;
        }
        usort($out, fn($a, $b) => [count($b['problems']) > 0, $a['avail'] !== 1, $a['group'], $a['name']] <=> [count($a['problems']) > 0, $b['avail'] !== 1, $b['group'], $b['name']]);
        $data = ['at' => time(), 'hosts' => $out];
        file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE));
        return [$data, ''];
    } catch (Throwable $e) {
        error_log('zabbix: ' . $e->getMessage());
        if (is_file($cache)) return [json_decode(file_get_contents($cache), true), '暫時讀不到 Zabbix，以下為上次取得的資料。'];
        return [null, '讀不到 Zabbix，請稍後再試。'];
    }
}
