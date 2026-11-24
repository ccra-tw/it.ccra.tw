<?php
// Zabbix 主機監控摘要（戰情室用）。走 ITSrv01 本機的 Zabbix API，用唯讀帳號 it-ccra-ops 的 API token。
// token 放 STATE_DIR/zabbix-ops.token（www-data 600），不進 GitHub；結果快取 60 秒。
// 回傳 [主機陣列或 null, 錯誤訊息]；每台：name, host, hostid, group, avail(1 正常/2 連不到/0 未知), cpu, mem, disk, problems[]。
// Zabbix 介面填 127.0.0.1（本機代理）的主機，實際 IP 補在這裡
const ZBX_LOCAL_IP = ['10084' => '192.168.100.7', '10680' => '20.2.139.210'];
// 大蘇能協助管理或查看的主機（熊哥 11-20：「應該要標註上去哪幾台設備你可以協助監控或管理」）。
// 依 2026-11-20 08:0x 逐台實測填寫（SSH／WinRM／SQL 實際登入成功才列）；其餘主機只有 Zabbix 監控，不標。
// level：manage＝可管理（SSH＋sudo），read＝唯讀查看；via 顯示在標籤提示。權限有異動要回來改這張表。
const ZBX_ITSU_ACCESS = [
    '10084' => ['level' => 'manage', 'via' => 'SSH＋sudo'],
    '10680' => ['level' => 'manage', 'via' => 'SSH＋sudo'],
    '10681' => ['level' => 'manage', 'via' => '大蘇工作機本機'],
    '10689' => ['level' => 'read', 'via' => 'WinRM itsu-ro、SQL ro_itsu'],
    '10693' => ['level' => 'read', 'via' => 'WinRM itsu-ro、SQL ro_itsu'],
    '10688' => ['level' => 'read', 'via' => 'WinRM itsu'],
];
function zbx_access_tag($hostid) {
    $a = ZBX_ITSU_ACCESS[(string)$hostid] ?? null;
    if (!$a) return null;
    return ['text' => $a['level'] === 'manage' ? '大蘇可管理' : '大蘇可查看', 'level' => $a['level'], 'via' => $a['via']];
}

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
// 主機列要顯示的位址（大沈 11-20 審查：要依 useip／dns／main 取，不是第一筆 ip）。
// 順序：主要介面（main=1）優先，同等時代理介面（type=1）優先；介面 useip=1 顯示 ip，useip=0 顯示 dns。
// 迴路位址（127.0.0.0/8、::1）代表走本機代理，略過，交給呼叫端用 ZBX_LOCAL_IP 補。全都沒有就回空字串。
// 只有 IP 字面值才判迴路（大沈 11-20 二審：DNS 名稱 127.monitor.example 不能被當成 127/8；0:0:0:0:0:0:0:1 要認得是 ::1）。
function zbx_is_loopback($a) {
    $a = strtolower(trim((string)$a));
    if ($a === '' || $a === 'localhost') return true;
    if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return str_starts_with($a, '127.');
    if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return inet_pton($a) === inet_pton('::1');
    return false;   // DNS 名稱交給 DNS 分支，不在這裡判
}
function zbx_pick_ip(array $interfaces) {
    $rank = fn($i) => [((int)($i['main'] ?? 0)) === 1 ? 0 : 1, ((int)($i['type'] ?? 0)) === 1 ? 0 : 1];
    usort($interfaces, fn($a, $b) => $rank($a) <=> $rank($b));
    foreach ($interfaces as $i) {
        $useip = !isset($i['useip']) || (int)$i['useip'] === 1;
        $addr = $useip ? ($i['ip'] ?? '') : ($i['dns'] ?? '');
        if (!zbx_is_loopback($addr)) return (string)$addr;
    }
    return '';
}

function zbx_sites($file = null) {
    $file = $file ?? STATE_DIR . '/host_sites.json';
    $j = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    if (!is_array($j) || !is_array($j['hosts'] ?? null)) return [[], ''];
    $out = [];
    foreach ($j['hosts'] as $hid => $list) if (is_array($list)) $out[(string)$hid] = array_values(array_filter($list, 'is_string'));
    return [$out, (string)($j['updated_at'] ?? '')];
}

// 作業系統字串縮短：Zabbix 原值很長（Linux 帶編譯器資訊、Windows 帶 fe_release 等內部版號）
function zbx_os_short($s) {
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    if (preg_match('/^Linux version (\S+)/', $s, $m)) return 'Linux ' . $m[1];
    if (preg_match('/^(Windows .+?) \d+\.\d+\.\S+ Build (\S+)/', $s, $m)) {
        // Windows 11 回報的產品名仍是「Windows 10」，用組建號 22000 以上判斷
        if (str_starts_with($m[1], 'Windows 10') && (int)$m[2] >= 22000) $m[1] = 'Windows 11' . substr($m[1], 10);
        return $m[1] . '（Build ' . $m[2] . '）';
    }
    if (preg_match('/^Linux \S+ (\S+)/', $s, $m)) return 'Linux ' . $m[1];   // SNMP sysDescr（NAS）
    return mb_strimwidth($s, 0, 60, '…', 'UTF-8');
}

// 一行系統資訊：作業系統｜N 核｜記憶體 N GB｜開機 N 天；缺的欄位略過
function zbx_sysline(array $x) {
    $p = [];
    if (($x['os'] ?? '') !== '') $p[] = $x['os'];
    if (!empty($x['cores'])) $p[] = (int)$x['cores'] . ' 核';
    if (!empty($x['memtotal'])) $p[] = '記憶體 ' . round($x['memtotal'] / 1073741824) . ' GB';
    if (isset($x['uptime']) && $x['uptime'] !== null) {
        $d = intdiv((int)$x['uptime'], 86400);
        $p[] = $d >= 1 ? '開機 ' . $d . ' 天' : '開機 ' . max(1, intdiv((int)$x['uptime'], 3600)) . ' 小時';
    }
    return implode('｜', $p);
}

// 快取格式版本（2026-11-25 大沈審查：主機列加了技術名稱 host 之後，舊版快取沒有這欄，
// 設備監控會拿顯示名稱去查操作紀錄而查錯主機）。改格式就加一，舊版快取一律不當成新鮮資料。
const ZBX_CACHE_VER = 2;

function zbx_cache_read(string $cache): ?array {
    $d = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    return is_array($d) && isset($d['hosts']) && is_array($d['hosts']) ? $d : null;
}

function zabbix_hosts() {
    $cache = STATE_DIR . '/zabbix_cache.json';
    $c = zbx_cache_read($cache);
    if ($c && ($c['v'] ?? 0) === ZBX_CACHE_VER && time() - filemtime($cache) < 60) return [$c, ''];
    $token = trim((string)@file_get_contents(STATE_DIR . '/zabbix-ops.token'));
    try {
        $hosts = zbx_call('host.get', ['output' => ['hostid', 'host', 'name', 'description'], 'filter' => ['status' => 0],
            'selectInterfaces' => ['type', 'available', 'error', 'ip', 'dns', 'useip', 'main'], 'selectHostGroups' => ['name']], $token);
        $ids = array_column($hosts, 'hostid');
        $keys = ['system.cpu.util', 'vm.memory.utilization', 'vm.memory.util', 'vfs.fs.dependent.size[/,pused]', 'vfs.fs.dependent.size[C:,pused]', 'icmppingsec', 'icmpping',
            // 熊哥 11-20：設備監控的說明要有系統資訊（作業系統、核心數、記憶體、開機時間）
            'system.sw.os', 'system.descr[sysDescr.0]', 'system.cpu.num', 'vm.memory.size[total]', 'system.uptime', 'system.hw.uptime[hrSystemUptime.0]'];
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
            $ip = zbx_pick_ip($h['interfaces']);   // 熊哥 11-20：主機要搭配 IP
            if ($ip === '') $ip = ZBX_LOCAL_IP[$h['hostid']] ?? '';   // 監控主機本身與 Azure 走本機代理，另補
            $row = ['hostid' => $h['hostid'], 'host' => $h['host'], 'name' => $h['name'], 'ip' => $ip, 'group' => $h['hostgroups'][0]['name'] ?? '',
                'avail' => zbx_avail($h['interfaces'], $ping), 'ping_only' => $ping !== null && !array_filter($h['interfaces'], fn($i) => (int)$i['available'] !== 0),
                'cpu' => null, 'mem' => null, 'disk' => null, 'problems' => [],
                'os' => '', 'cores' => null, 'memtotal' => null, 'uptime' => null, 'desc' => trim((string)($h['description'] ?? ''))];
            foreach ($items as $it) {
                if ($it['hostid'] !== $h['hostid'] || !$it['lastclock']) continue;
                $k = $it['key_'];
                if ($k === 'system.sw.os' || $k === 'system.descr[sysDescr.0]') { if ($it['lastvalue'] !== '') $row['os'] = zbx_os_short($it['lastvalue']); continue; }
                if ($k === 'system.cpu.num') { $row['cores'] = (int)$it['lastvalue']; continue; }
                if ($k === 'vm.memory.size[total]') { $row['memtotal'] = (float)$it['lastvalue']; continue; }
                if ($k === 'system.uptime' || $k === 'system.hw.uptime[hrSystemUptime.0]') { $row['uptime'] = (int)$it['lastvalue']; continue; }
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
        $data = ['v' => ZBX_CACHE_VER, 'at' => time(), 'hosts' => $out];
        file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE));
        return [$data, ''];
    } catch (Throwable $e) {
        error_log('zabbix: ' . $e->getMessage());
        // 刷新失敗時仍用上次的資料顯示（可能是舊版格式，主機列沒有 host；設備監控那邊會標「暫無法對應操作紀錄」）
        if ($c = zbx_cache_read($cache)) return [$c, '暫時讀不到 Zabbix，以下為上次取得的資料。'];
        return [null, '讀不到 Zabbix，請稍後再試。'];
    }
}
