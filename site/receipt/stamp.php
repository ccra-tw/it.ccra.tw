<?php
// 物資捐贈收據：印章圖檔（食字軌白紙收據用）。圖檔放主機 /var/lib/it-ccra/receipt-stamps/，不進公開 repo；
// 只給收據系統名單內的人讀。檔名：seal（協會大章）、chair（理事長）、sg（秘書長）、handler-<經手人姓名>。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/receipt.php';
$email = strtolower(access_identity()['email']);
if (!rcpt_role(rcpt_db(), $email)) { http_response_code(403); exit; }
$k = (string)($_GET['k'] ?? '');
if (!preg_match('/^(seal|chair|sg|handler-[\p{Han}A-Za-z]{1,10})$/u', $k)) { http_response_code(404); exit; }
$f = STATE_DIR . '/receipt-stamps/' . $k . '.png';
if (!is_file($f)) { http_response_code(404); exit; }
header('Content-Type: image/png');
header('Cache-Control: private, max-age=3600');
readfile($f);
