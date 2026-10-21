<?php
// /addr/ 暫存補清（it-ccra-addr-sweep.timer 每 10 分鐘，www-data 身分）。
// 網頁結束時本來就會刪；這支處理 php-fpm 被殺、OOM、主機重開等「結束時程式沒跑到」的情況。
// 只刪 addr-tmp 底下、超過 30 分鐘、工單鎖拿得到的工單資料夾。刪不掉：寫系統紀錄、推戰情室、以非零結束（systemd 會標失敗）。
// （小鹿 10/21 互審 32d7733 第 1 項。）
require '/var/www/it-lib/addr.php';
[$deleted, $failed] = addr_sweep();
if ($deleted) error_log("it.ccra.tw addr-sweep: removed $deleted stale job(s)");
if ($failed) {
    error_log('it.ccra.tw addr-sweep: FAILED to remove ' . count($failed) . ' path(s)');
    try {
        require '/var/www/it-lib/webpush.php';
        push_send(['title' => '地址整理暫存刪不掉', 'body' => count($failed) . ' 個檔案或資料夾殘留在 addr-tmp（可能含捐款人名單），請資訊部處理',
                   'url' => '/ops/', 'tag' => 'addr-sweep'], 'security');
    } catch (Throwable $e) { error_log('it.ccra.tw addr-sweep: push failed ' . $e->getMessage()); }
    exit(1);
}
