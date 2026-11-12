<?php
// 物資捐贈收據：列印頁（GitHub #21）。瀏覽器列印：A4、邊界「無」、縮放 100%、不印頁首頁尾。
// 物字軌：套印到預印紙，只印變動資料；座標（mm，原點紙張左上角）量自業務單位的套印範本（建置說明「收據列印規格」）。
// 食字軌：沒有預印紙（業務單位 11/12 更正），整張印在白紙上，版面照「(食)捐贈收據 單頁範本」，大章、理事長章、秘書長章與承辦人章一起印（stamp.php）。
// 排序：zip → city → address → 收件人（方便郵寄）；收據編號不因排序改變。每印一次寫 print_log（誰、何時、幾張）。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/receipt.php';
$email = strtolower(access_identity()['email']);
$d = rcpt_db();
$role = rcpt_role($d, $email);
if (!$role) { http_response_code(403); exit('物資捐贈收據系統只開放給開收據的同仁、客服與助理會計。'); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$id = (int)($_GET['id'] ?? 0);
$s = $d->prepare('SELECT * FROM batch WHERE id = ?'); $s->execute([$id]);
$b = $s->fetch(PDO::FETCH_ASSOC);
if (!$b || $b['status'] !== 'numbered') { http_response_code(404); exit('找不到已配號的批次。'); }
$one = (int)($_GET['one'] ?? 0);
$scope = $_GET['scope'] ?? 'send';           // send＝只印要寄的；all＝全部
$from = (int)($_GET['from'] ?? 0); $to = (int)($_GET['to'] ?? 0);
$dx = max(-15, min(15, (float)($_GET['dx'] ?? 0))); $dy = max(-15, min(15, (float)($_GET['dy'] ?? 0)));
$go = isset($_GET['go']);

$sql = 'SELECT * FROM donation WHERE batch_id = ? AND NOT void';
$args = [$id];
if ($one) { $sql .= ' AND id = ?'; $args[] = $one; }
else {
    if ($scope === 'send') $sql .= ' AND send';
    if ($from) { $sql .= ' AND seq >= ?'; $args[] = $from; }
    if ($to) { $sql .= ' AND seq <= ?'; $args[] = $to; }
}
$q = $d->prepare($sql); $q->execute($args);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
$coll = class_exists('Collator') ? new Collator('zh_TW@collation=stroke') : null;   // 中文依筆畫
usort($rows, function ($a, $c) use ($coll) {
    foreach (['zip', 'city', 'address', 'name'] as $k) {
        $x = $coll ? $coll->compare($a[$k], $c[$k]) : strcmp($a[$k], $c[$k]);
        if ($x) return $x;
    }
    return $a['seq'] <=> $c['seq'];
});
// 業務單位 11/12 選（甲）：依「同一地址＋收件人」分兩份——單張（該組只有 1 張）與多張（2 張以上，每組連在一起），郵資不同
$grpKey = fn($r) => $r['zip'] . '|' . $r['city'] . '|' . $r['address'] . '|' . $r['name'];
$grpN = array_count_values(array_map($grpKey, $rows));
$nSingle = count(array_filter($rows, fn($r) => $grpN[$grpKey($r)] === 1));
$nMulti = count($rows) - $nSingle;
$part = $one ? 'all' : ($_GET['part'] ?? 'single');
if ($part === 'single') $rows = array_values(array_filter($rows, fn($r) => $grpN[$grpKey($r)] === 1));
elseif ($part === 'multi') $rows = array_values(array_filter($rows, fn($r) => $grpN[$grpKey($r)] > 1));
if ($go && $rows && $role === 'edit') {
    try {
        $d->beginTransaction();   // 列印紀錄與稽核同一交易；失敗就整筆回滾、不觸發列印
        $d->prepare('INSERT INTO print_log (batch_id, donation_id, kind, count, by_email) VALUES (?,?,?,?,?)')
          ->execute([$id, $one ?: null, $one ? 'reprint' : 'print', count($rows), $email]);
        rcpt_audit($d, $email, $one ? 'reprint' : 'print', $id, count($rows) . ' 張');
        $d->commit();
    } catch (Throwable $e) {
        if ($d->inTransaction()) $d->rollBack();
        $ref = rcpt_log_db_error($e, 'print');
        http_response_code(500);
        exit("列印紀錄寫入失敗，這次沒有列印，請重新整理再試；仍不行請通知資訊部（代碼 $ref）。");
    }
}
$date = rcpt_date_to_roc($b['in_date']);
$units = [];
foreach ($d->query('SELECT name, unit FROM batch_item WHERE batch_id = ' . $id)->fetchAll(PDO::FETCH_ASSOC) as $it) $units[$it['name']] = $it['unit'];
[$ry, $rm, $rd] = explode('/', $date);
$handlerStamp = is_file(STATE_DIR . '/receipt-stamps/handler-' . $b['handler'] . '.png') ? 'handler-' . $b['handler'] : '';
$contents = trim($b['contents']);
if ($contents !== '' && !str_ends_with($contents, '）')) $contents .= '）';
// 座標（mm）：左、上
$P = ['no' => [164.4, 24.2], 'date' => [165.9, 30.3], 'name' => [65.3, 37.0], 'contents' => [11.9, 58.8], 'qty' => [53.6, 67.6],
      'addr' => [18.0, 116.9], 'to' => [18.1, 124.1], 'letter' => [8.6, 132.9]];
$pos = fn($k) => sprintf('left:%.1fmm;top:%.1fmm', $P[$k][0] + $dx, $P[$k][1] + $dy);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title>列印收據｜<?= h($b['po_no'] ?: $date) ?></title>
<style>
@page{size:A4;margin:0}
body{margin:0;font-family:"Microsoft JhengHei","微軟正黑體","Noto Sans TC",sans-serif}
.panel{padding:12px 16px;background:#f3f4f6;font-size:14px}
.panel input{width:5em}
.page{position:relative;width:210mm;height:297mm;page-break-after:always;overflow:hidden}
.f{position:absolute;font-weight:700;font-size:12pt;white-space:nowrap}
.contents{font-size:10pt;line-height:18pt;width:186mm;white-space:normal}
.letter{font-family:"標楷體","DFKai-SB","BiauKai",serif;font-weight:400;font-size:10pt;line-height:5.1mm;width:171mm;white-space:pre-wrap;text-align:justify}
.letter p{margin:0 0 4.9mm}
.warn{color:#9b1c1c;font-weight:700}
.food{padding:14mm 16mm;font-size:11pt;line-height:1.6;font-family:"Microsoft JhengHei","微軟正黑體","Noto Sans TC",sans-serif}
.food .hd{position:relative;border-bottom:1px solid #000;padding-bottom:3mm}
.food .hd .org{font-size:18pt;font-family:"標楷體","DFKai-SB",serif}
.food .hd small{display:block;font-size:8.5pt;line-height:1.35}
.food .hd .seal{position:absolute;right:0;top:0;width:30mm}
.food .no{text-align:right;margin:3mm 0;font-weight:700}
.food h2{text-align:center;font-size:16pt;margin:2mm 0 5mm}
.food table{width:100%;border-collapse:collapse;margin:2mm 0 6mm}
.food th,.food td{border:1px solid #000;padding:1.5mm 2mm}
.food .mail{margin:8mm 0 8mm 4mm;font-size:12pt}
.food .sign{display:flex;align-items:center;gap:6mm;margin-top:6mm}
.food .sign img{height:16mm}
.food .note{font-size:9.5pt;margin-top:6mm}
@media print{.panel{display:none}}
</style>
</head>
<body>
<div class="panel">
<b><?= h($b['source']) ?>（<?= h($b['track']) ?>）收貨 <?= h($date) ?></b>　本次 <?= count($rows) ?> 張（<?= ['single' => '單張份', 'multi' => '多張份', 'all' => '不分份'][$part] ?? '' ?>）（已依郵遞區號、地址、收件人排序）
<?php if ($b['track'] === '食'): ?><div>食字軌：整張印在白紙上（含印章），不需預印紙。</div><?php endif; ?>
<form method="get" style="margin-top:8px">
<input type="hidden" name="id" value="<?= $id ?>"><?php if ($one): ?><input type="hidden" name="one" value="<?= $one ?>"><?php endif; ?>
<?php if (!$one): ?>
份別 <select name="part"><option value="single" <?= $part === 'single' ? 'selected' : '' ?>>單張份（<?= $nSingle ?> 張）</option><option value="multi" <?= $part === 'multi' ? 'selected' : '' ?>>多張份（<?= $nMulti ?> 張，同地址收件人連在一起）</option><option value="all" <?= $part === 'all' ? 'selected' : '' ?>>不分份</option></select>
範圍 <select name="scope"><option value="send" <?= $scope === 'send' ? 'selected' : '' ?>>只印要寄的</option><option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>全部</option></select>
流水號 <input name="from" value="<?= $from ?: '' ?>" placeholder="從">～<input name="to" value="<?= $to ?: '' ?>" placeholder="到">
<?php endif; ?>
位置微調（mm）右移 <input name="dx" value="<?= $dx ?>"> 下移 <input name="dy" value="<?= $dy ?>">
<button>更新預覽</button>
<?php if ($role === 'edit'): ?><button name="go" value="1">記錄並列印</button><?php endif; ?>
</form>
<?php if ($role === 'edit' && $rows): $csrf = hash_hmac('sha256', 'receipt' . $email . date('Y-m-d'), trim((string)@file_get_contents(STATE_DIR . '/form_secret'))); ?>
<form method="post" action="./?a=batch&id=<?= $id ?>" style="margin:6px 0">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="mailed"><input type="hidden" name="ids" value="<?= h(implode(',', array_column($rows, 'id'))) ?>">
寄出後：這 <?= count($rows) ?> 張標記寄出日期 <input name="mailed_on" value="<?= h(rcpt_date_to_roc(date('Y-m-d'))) ?>" style="width:7em"> <button>標記已寄出</button></form>
<?php endif; ?>
<small>列印設定：A4、邊界「無」、縮放 100%、不要印頁首頁尾。第一次請用白紙試印，疊在預印紙上對光檢查。<a href="./?a=batch&id=<?= $id ?>">回批次</a></small>
</div>
<?php if ($go && $rows): ?><script>addEventListener('load', () => print());</script><?php endif; ?>
<?php foreach ($rows as $r): if ($b['track'] === '食'): ?>
<div class="page food">
<div class="hd"><div class="org">社團法人中華基督教救助協會</div><div>Chinese Christian Relief Association</div>
<small>234新北市永和區保生路1號20樓　Tel: (02) 86609995　Fax: (02) 29259995<br>統一編號：83867936　電子郵箱：support@ccra.org.tw<br>劃撥帳號：19281908　戶名：社團法人中華基督教救助協會</small>
<img class="seal" src="./stamp.php?k=seal" alt=""></div>
<div class="no">編號：<?= h($r['receipt_no']) ?></div>
<h2>物資捐贈收據</h2>
<div>茲收到 <b><?= h($r['name']) ?></b><?php if ($r['tax_id'] !== ''): ?>　統編 <?= h($r['tax_id']) ?><?php endif; ?></div>
<div style="text-align:right">捐贈物資，明細如下：</div>
<table><tr><th>品名</th><th style="width:22mm">數量</th><th style="width:18mm">單位</th></tr>
<tr><td><?= h($r['product']) ?></td><td style="text-align:right"><?= (int)$r['qty'] ?></td><td><?= h($units[$r['product']] ?? '') ?></td></tr></table>
<div class="mail"><?= h($r['zip'] . $r['city'] . '　' . $r['address']) ?><br><?= h($r['name']) ?>　收</div>
<div>勸 募 字 號：衛部救字第1141364671號<br>法人登記證書字號：登記簿第29冊第24頁第855號<br>國稅局統一編號：83867936<br>地址：234新北市永和區保生路1號20樓<br>電話：(02)8660-9995</div>
<div style="margin-top:4mm">民國 <?= (int)$ry ?> 年 <?= (int)$rm ?> 月 <?= (int)$rd ?> 日</div>
<div class="sign"><span>社團法人<br>中華基督教救助協會</span><span>理事長</span><img src="./stamp.php?k=chair" alt="夏忠堅"><span>秘書長</span><img src="./stamp.php?k=sg" alt="鄭夙珺"><span>承辦人</span>
<?php if ($handlerStamp): ?><img src="./stamp.php?k=<?= rawurlencode($handlerStamp) ?>" alt="<?= h($b['handler']) ?>"><?php else: ?><span><?= h($b['handler']) ?></span><?php endif; ?></div>
<div class="note">『物資捐贈收據內容若有錯誤,請於收到收據後一星期內更正,以利本會作業』<br>※ 本收據可供您做綜合所得稅扣繳之用，需檢附發票</div>
</div>
<?php else: ?>
<div class="page">
<div class="f" style="<?= $pos('no') ?>"><?= h($r['receipt_no']) ?></div>
<div class="f" style="<?= $pos('date') ?>"><?= h($date) ?></div>
<div class="f" style="<?= $pos('name') ?>"><?= h($r['name']) ?></div>
<?php if ($contents !== ''): ?><div class="f contents" style="<?= $pos('contents') ?>"><?= h($contents) ?></div><?php endif; ?>
<div class="f" style="<?= $pos('qty') ?>"><?= (int)$r['qty'] ?></div>
<div class="f" style="<?= $pos('addr') ?>"><?= h($r['zip'] . $r['city'] . '　' . $r['address']) ?></div>
<div class="f" style="<?= $pos('to') ?>"><?= h($r['name']) ?>　收</div>
<?php if (trim($b['letter']) !== ''): ?><div class="f letter" style="<?= $pos('letter') ?>"><?php foreach (preg_split('/\R{2,}/u', trim($b['letter'])) as $para) echo '<p>' . h($para) . '</p>'; ?></div><?php endif; ?>
</div>
<?php endif; endforeach; ?>
</body>
</html>
