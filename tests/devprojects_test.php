<?php
// 戰情室「開發案狀態」卡片（lib/devprojects.php，GitHub #18）的讀檔與呈現測試。
// 用法：php tests/devprojects_test.php <site 目錄> <lib 目錄>（由 tests/run_svc_test.py 準備好暫存副本後呼叫）
$SITE = $argv[1]; $LIB = $argv[2];
require $LIB . '/devprojects.php';
$fail = 0; $n = 0;
function ok($cond, $msg) { global $fail, $n; $n++; echo ($cond ? "  ✅ " : "  ❌ ") . $msg . "\n"; if (!$cond) $fail++; }
$dir = sys_get_temp_dir() . '/devproj-test-' . getmypid(); @mkdir($dir, 0700, true);
$f = "$dir/dev_projects.json";

echo "讀檔：缺檔、壞檔、過期都要講出來\n";
@unlink($f);
[$d, $w] = devprojects_data($f);
ok($d === null && strpos($w, '還沒有') !== false, '檔案不在 → 無資料並說明，不當成「沒有開發案」');
file_put_contents($f, '{not json');
[$d, $w] = devprojects_data($f);
ok($d === null && strpos($w, '格式不對') !== false, '壞掉的 JSON → 說格式不對');
file_put_contents($f, json_encode(['updated_at' => 'x']));
[$d, $w] = devprojects_data($f);
ok($d === null && strpos($w, '格式不對') !== false, '少了 projects → 說格式不對');
$good = ['updated_at' => '2026-10-21T19:00+08:00', 'projects' => [
    ['name' => '資訊站', 'url' => 'https://example.org', 'repo_url' => 'https://github.com/example/a', 'version' => '2.2.0', 'deployed' => '10/21 18:47',
     'items' => [
        ['title' => '項目甲', 'stage' => 'dev', 'src' => 'planner', 'link' => 'https://example.org/t/1', 'who' => '測試者', 'note' => '10/21 進度一句'],
        ['title' => '<script>alert(1)</script>', 'stage' => 'confirm', 'src' => 'github', 'link' => 'javascript:alert(1)'],
        ['title' => '怪階段', 'stage' => '亂寫的', 'src' => 'planner', 'link' => 'https://example.org/t/3'],
     ]],
    ['name' => '空專案', 'url' => 'ftp://example.org', 'items' => []],
    'not-an-array',
]];
file_put_contents($f, json_encode($good, JSON_UNESCAPED_UNICODE));
[$d, $w] = devprojects_data($f, filemtime($f) + 60);
ok(is_array($d) && $w === '', '新的資料 → 有資料、沒有警示');
[$d2, $w2] = devprojects_data($f, filemtime($f) + DEVPROJ_STALE + 1);
ok(is_array($d2) && strpos($w2, '超過 2 小時') !== false, '超過兩小時沒更新 → 仍顯示資料但加警示');

echo "呈現\n";
$html = devprojects_render($d, '');
ok(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, '標題裡的 HTML 會跳脫');
ok(strpos($html, 'javascript:') === false, 'javascript: 連結不輸出成連結');
ok(strpos($html, 'href="ftp:') === false && strpos($html, '空專案') !== false, '非 http(s) 的專案網址只留文字');
ok(strpos($html, '未分類') !== false, '認不得的階段顯示「未分類」，不猜');
ok(strpos($html, 'dp-dev') !== false && strpos($html, '開發中') !== false, '已知階段顯示對應文字與樣式');
ok(strpos($html, '等主任確認') !== false, 'confirm → 等主任確認');
ok(strpos($html, 'v2.2.0') !== false && strpos($html, '部署 10/21 18:47') !== false, '版本與部署時間有顯示');
ok(strpos($html, '目前沒有進行中的項目') !== false, '沒有項目的專案明講「沒有進行中的項目」');
ok(strpos($html, '10/21 進度一句') !== false && strpos($html, '測試者') !== false, '最新進度與負責人有顯示');
ok(substr_count($html, 'class="dp-proj"') === 2, '不是陣列的專案略過，不讓整張卡壞掉');
$h2 = devprojects_render($d2, $w2);
ok(strpos($h2, 'dp-warn') !== false && strpos($h2, '項目甲') !== false, '過期時警示與資料同時出現');
$h3 = devprojects_render(null, '還沒有開發案資料');
ok(strpos($h3, '還沒有開發案資料') !== false && strpos($h3, 'dp-proj') === false, '無資料時只顯示說明');

echo "戰情室頁面有接上\n";
$ops = file_get_contents("$SITE/ops/index.php");
ok(strpos($ops, "devprojects.php'") !== false, '戰情室有載入 devprojects.php');
ok(strpos($ops, 'devprojects_render($dpData, $dpWarn)') !== false, '戰情室有輸出卡片');

@unlink($f); @rmdir($dir);
echo "$n 項，失敗 $fail\n";
exit($fail ? 1 : 0);
