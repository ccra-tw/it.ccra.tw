<?php
// 測試用：在命令列模擬一次網頁請求。環境變數 ITCCRA_REQ＝JSON {page, method, get, post}；
// 替身身分由 ITCCRA_TEST_EMAIL、測試資料表由 ITCCRA_TEST_DB=1 指定（見 lib/access.php、lib/db.php 的測試接縫）。
$r = json_decode((string)getenv('ITCCRA_REQ'), true);
$_SERVER['REQUEST_METHOD'] = $r['method'] ?? 'GET';
$_GET = $r['get'] ?? [];
$_POST = $r['post'] ?? [];
chdir(dirname($r['page']));
include $r['page'];
