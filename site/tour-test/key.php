<?php
// 環台前導路線頁的 Google 地圖金鑰：不寫進公開程式庫，由主機上的設定檔提供（熊哥 11/11 底圖改 Google 地圖）。
// 金鑰本身只認 it.ccra.tw（之後 tour.ccra.tw）來源，瀏覽器載入 Google 地圖時本來就會帶出，這裡只是不讓它進 git。
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$k = @file_get_contents('/etc/it-ccra/google-maps-key.txt');
$k = $k === false ? '' : trim($k);
if (!preg_match('/^[A-Za-z0-9_-]{30,60}$/', $k)) { http_response_code(404); exit; }
echo $k;
