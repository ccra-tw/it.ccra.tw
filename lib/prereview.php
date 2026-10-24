<?php
// 申請單預審（GitHub #19；規則＝申請單預審標準 v0.2，2026-10-24 主任定案）。
// 只給建議，最後仍由主任在戰情室按；測試單不預審。規則全部集中在這裡，戰情室、月報、測試共用。
// 純函式：只看申請紀錄本身（svc_apply 的 req 列），不查資料庫、不寄信，方便單元測試。

const PRE_APPROVE = 'approve';    // 建議核准
const PRE_DIRECTOR = 'director';  // 需主任看
const PRE_RETURN = 'return';      // 退回補件
const PRE_SKIP = 'skip';          // 不預審（測試單）
const PRE_LABEL = [PRE_APPROVE => '建議核准', PRE_DIRECTOR => '需主任看', PRE_RETURN => '退回補件', PRE_SKIP => '不預審'];
const PRE_RANK = [PRE_APPROVE => 0, PRE_DIRECTOR => 1, PRE_RETURN => 2];

// 協會自有網域（縮址代建判斷「協會網域或協會活動頁」用）；子網域一併算
const PRE_OWN_DOMAINS = ['ccra.org.tw', 'ccra.tw', '1919.org.tw', 'ccea.org.tw', 'ccea.tw', 'letsrun.tw', 'focus.org.tw',
    'leaderfocus.org.tw', 'leadership.org.tw', 'p4t.org.tw', 'pastor.org.tw', 'nymphas.org.tw'];

function pre_own_domain(string $host): bool {
    $host = strtolower(rtrim($host, '.'));
    foreach (PRE_OWN_DOMAINS as $d) if ($host === $d || str_ends_with($host, '.' . $d)) return true;
    return false;
}

// 回傳 ['verdict' => PRE_*, 'reasons' => [['level' => info|warn|red, 'text' => ...], ...]]。
// 多條規則同時成立時取最嚴的（退回補件 > 需主任看 > 建議核准），理由全部列出。
function svc_prereview(array $r): array {
    if (!empty($r['is_test']) && $r['is_test'] !== 'f') return ['verdict' => PRE_SKIP, 'reasons' => [['level' => 'info', 'text' => '測試單不預審']]];
    $f = is_array($r['fields'] ?? null) ? $r['fields'] : (json_decode((string)($r['fields'] ?? '{}'), true) ?: []);
    $v = fn(string $k) => trim((string)($f[$k] ?? ''));
    $verdict = PRE_APPROVE; $reasons = [];
    $add = function (string $to, string $level, string $text) use (&$verdict, &$reasons) {
        if (PRE_RANK[$to] > PRE_RANK[$verdict]) $verdict = $to;
        $reasons[] = ['level' => $level, 'text' => $text];
    };
    $has = fn(string $s, string $re) => (bool)preg_match('/' . $re . '/u', $s);

    // 一、共同檢查
    if (trim((string)($r['applicant_dept'] ?? '')) === '') $add(PRE_RETURN, 'warn', '申請人不在部門對照表，請確認是協會 M365 帳號');
    $forSelf = !isset($r['for_self']) || $r['for_self'] === true || $r['for_self'] === 't' || $r['for_self'] === 1 || $r['for_self'] === '1';
    if (!$forSelf && (string)($r['target_dept'] ?? '') !== (string)($r['applicant_dept'] ?? ''))
        $add(PRE_DIRECTOR, 'warn', '代其他部門的人申請：系統無法確認申請人是否為對方主管');

    // 二、各類型
    switch ((string)($r['type'] ?? '')) {
        case 'ai':
            $plan = $v('plan');
            if (mb_strlen($plan) < 15 || $has($plan, '^(試試看?|學習|看看|好奇|想試試|了解一下)[。！!\s]*$'))
                $add(PRE_RETURN, 'warn', '使用計畫沒有寫出具體要做的工作');
            if ($v('pii') === '會') $add(PRE_DIRECTOR, 'warn', '會處理個資：建議改用協會帳號登入的 Copilot Chat（有企業資料保護），或說明如何去識別化');
            if ($verdict === PRE_APPROVE) $reasons[] = ['level' => 'info', 'text' => '用途具體、不處理個資'];
            break;
        case 'shortlink':
            if ($v('need') === '請資訊部代建一個短網址') {
                $host = (string)parse_url($v('url'), PHP_URL_HOST);
                if ($host !== '' && pre_own_domain($host)) $reasons[] = ['level' => 'info', 'text' => '目標是協會網域，資訊部可直接代建'];
                else $add(PRE_DIRECTOR, 'warn', '目標不是協會網域（' . ($host ?: '未填') . '），請確認不是外部商業網站');
            } else $reasons[] = ['level' => 'info', 'text' => '後台帳號：部門主管已知會即可開'];
            break;
        case 'email':
            $use = $v('use');
            if ($has($use, '募款|捐款|奉獻|個資|身分證')) $add(PRE_DIRECTOR, 'warn', '以協會名義募款或收個資的信箱');
            if ($has($use, '活動|報名|專案') && $v('until') === '') $add(PRE_RETURN, 'warn', '活動或專案用信箱請填使用期限');
            if ($v('users') === '') $add(PRE_RETURN, 'warn', '沒有指定使用者');
            break;
        case 'google':
            $svc = $v('service'); $lvl = $v('level');
            if ($lvl === '管理') $add(PRE_DIRECTOR, 'warn', '要求「管理」權限');
            if (in_array($svc, ['Google Ads', 'Google Analytics', 'Search Console'], true)) $add(PRE_DIRECTOR, 'warn', $svc . ' 牽涉廣告預算或捐款頁數據');
            if ($verdict === PRE_APPROVE) $reasons[] = ['level' => 'info', 'text' => $lvl === '檢視' ? '只要檢視' : '部門內工作需要的編輯權限'];
            break;
        case 'folder':
            if ($v('perm') === '讀寫' && $has($v('path') . ' ' . (string)($r['purpose'] ?? ''), '個案|捐款|收據|名單|個資'))
                $add(PRE_DIRECTOR, 'warn', '含個資的資料夾要讀寫');
            if ($v('until') === '') $reasons[] = ['level' => 'info', 'text' => '沒填期限：半年後資訊部回頭確認是否還需要'];
            break;
        case 'vpn':
            $dev = $v('device');
            if ($has($dev, '協會|公司|公務|配發')) $reasons[] = ['level' => 'info', 'text' => '協會配發的電腦'];
            else $add(PRE_DIRECTOR, 'warn', '個人裝置或裝置不明（協會無法管理其安全）');
            if ($v('until') === '') $reasons[] = ['level' => 'info', 'text' => '沒填期限：建議給 3 個月'];
            break;
        case 'sysperm':
            if ($has($v('system'), '個案|捐款|收據|1919|急難') && $has($v('role'), '匯出|下載|管理|全部'))
                $add(PRE_DIRECTOR, 'warn', '含個資系統要匯出或管理');
            else $reasons[] = ['level' => 'info', 'text' => '請確認該系統負責單位同意'];
            break;
        case 'github':
            $perm = $v('perm'); $org = $v('org');
            if ($perm === '管理') $add(PRE_DIRECTOR, 'warn', '要求「管理」權限');
            if ($org === 'ccea-tw') $add(PRE_DIRECTOR, 'warn', 'ccea-tw 為私有 repo');
            elseif ($perm === '讀取') $add(PRE_RETURN, 'info', 'ccra-tw 為公開 repo，只要讀取不用申請，直接看即可');
            if ($verdict === PRE_APPROVE) $reasons[] = ['level' => 'info', 'text' => '指定 repo 的寫入權限'];
            break;
        case 'dns':
            $dom = strtolower($v('domain')); $rt = $v('rtype');
            if (in_array($dom, PRE_OWN_DOMAINS, true)) $add(PRE_DIRECTOR, 'warn', '根網域（' . $dom . '）變更');
            if (in_array($rt, ['MX', 'TXT'], true)) $reasons[] = ['level' => 'warn', 'text' => $rt . ' 會影響寄信與 SPF／DMARC：資訊部複核、先確認不影響現有郵件'];
            elseif ($rt === '不確定') $reasons[] = ['level' => 'info', 'text' => '紀錄類型不確定：資訊部向廠商確認'];
            if ($verdict === PRE_APPROVE && !in_array($rt, ['MX', 'TXT'], true)) $reasons[] = ['level' => 'info', 'text' => '子網域指向，資訊部可直接做'];
            break;
        case 'firewall':
            $add(PRE_DIRECTOR, 'info', '防火牆異動一律主任看');
            if ($has(mb_strtolower($v('src')), '外部|任何|any|0\.0\.0\.0|全部|所有')) $reasons[] = ['level' => 'red', 'text' => '來源為外部任何地方：對網際網路開放'];
            if ($v('until') === '') $reasons[] = ['level' => 'warn', 'text' => '沒填期限：建議設期限'];
            break;
        default:
            $reasons[] = ['level' => 'info', 'text' => '資訊部受理後判斷屬於哪一類，再照該類處理'];
    }
    return ['verdict' => $verdict, 'reasons' => $reasons];
}
