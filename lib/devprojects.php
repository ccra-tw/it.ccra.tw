<?php
// 戰情室「開發案狀態」卡片（GitHub #18）。
// 熊哥 2026-10-21 TG：「我需要比照CCNDA戰情室 有一個目前開放案的呈現」。
// 資料：IT大蘇本機 dev_projects.py 每小時彙整（GitHub issue＋Planner 票＋主機部署版本）推到
//       STATE_DIR/dev_projects.json；這台主機不為此另拿 GitHub／Graph 權杖，只讀檔。
// 階段判斷在本機那支做；這裡只負責呈現，認不得的階段一律顯示「未分類」，不猜。

const DEVPROJ_FILE  = '/var/lib/it-ccra/dev_projects.json';
const DEVPROJ_STALE = 7200;   // 每小時更新，超過兩小時沒動就明講（排程可能停了）

// 階段：鍵 => [顯示文字, 樣式]
const DEVPROJ_STAGES = [
    'plan'    => ['規劃中', 'dp-plan'],
    'queued'  => ['排隊中', 'dp-queued'],
    'dev'     => ['開發中', 'dp-dev'],
    'review'  => ['互審中', 'dp-review'],
    'confirm' => ['等主任確認', 'dp-confirm'],
    'waiting' => ['等同工回覆', 'dp-waiting'],
    'human'   => ['等人工處理', 'dp-waiting'],
    'blocked' => ['卡住', 'dp-blocked'],
    'deploy'  => ['等部署', 'dp-deploy'],
    'done'    => ['已上線', 'dp-done'],
];

function dp_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** 只輸出 http(s) 連結；其他（javascript: 之類）一律只留文字 */
function dp_link($url, $text) {
    if (!is_string($url) || !preg_match('#^https?://#i', $url)) return dp_h($text);
    return '<a href="' . dp_h($url) . '" target="_blank" rel="noopener">' . dp_h($text) . '</a>';
}

function dp_stage($key) {
    return DEVPROJ_STAGES[$key] ?? ['未分類', 'dp-unknown'];
}

/** 讀資料檔：回傳 [資料或 null, 警示文字]。檔案不在、格式不對、太舊都要講出來，不當成「沒有開發案」 */
function devprojects_data($file = DEVPROJ_FILE, $now = null) {
    $now = $now ?? time();
    if (!is_file($file)) return [null, '還沒有開發案資料（本機彙整排程尚未推送）。'];
    $d = json_decode((string)@file_get_contents($file), true);
    if (!is_array($d) || !isset($d['projects']) || !is_array($d['projects'])) return [null, '開發案資料格式不對。'];
    $age = $now - (int)@filemtime($file);
    return [$d, $age > DEVPROJ_STALE ? '開發案資料超過 2 小時沒有更新（本機排程可能停了）。' : ''];
}

/** 卡片內文（不含外框）。$d 是 devprojects_data() 的資料，$warn 是警示文字 */
function devprojects_render($d, $warn) {
    $o = '';
    if ($warn !== '') $o .= '<p class="dp-warn">⚠️ ' . dp_h($warn) . '</p>';
    if (!$d) return $o;
    foreach ($d['projects'] as $p) {
        if (!is_array($p)) continue;
        $items = array_values(array_filter((array)($p['items'] ?? []), 'is_array'));
        $o .= '<div class="dp-proj"><div class="dp-head"><b>' . dp_link($p['url'] ?? null, $p['name'] ?? '（未命名）') . '</b>';
        $ver = [];
        if (!empty($p['version'])) $ver[] = 'v' . $p['version'];
        if (!empty($p['deployed'])) $ver[] = '部署 ' . $p['deployed'];
        if ($ver) $o .= ' <span class="meta">' . dp_h(implode('・', $ver)) . '</span>';
        if (!empty($p['repo_url'])) $o .= ' <span class="meta">' . dp_link($p['repo_url'], 'GitHub') . '</span>';
        $o .= '</div>';
        if (!$items) { $o .= '<p class="empty">目前沒有進行中的項目。</p></div>'; continue; }
        $o .= '<ul class="dp-items">';
        foreach ($items as $it) {
            [$lab, $cls] = dp_stage($it['stage'] ?? '');
            $src = ($it['src'] ?? '') === 'github' ? 'GitHub' : 'Planner';
            $o .= '<li><span class="tag ' . dp_h($cls) . '">' . dp_h($lab) . '</span> '
                . dp_link($it['link'] ?? null, $it['title'] ?? '')
                . ' <span class="meta">' . dp_h($src) . (!empty($it['who']) ? '・' . dp_h($it['who']) : '') . '</span>';
            if (!empty($it['note'])) $o .= '<div class="dp-note">' . dp_h($it['note']) . '</div>';
            $o .= '</li>';
        }
        $o .= '</ul></div>';
    }
    return $o;
}
