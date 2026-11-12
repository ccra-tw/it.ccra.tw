<?php
// 物資捐贈收據：列印頁（GitHub #21）。瀏覽器列印：A4、邊界「無」、縮放 100%、不印頁首頁尾。
// 物字軌：套印到預印紙，只印變動資料；座標（mm，原點紙張左上角）量自業務單位的套印範本（建置說明「收據列印規格」）。
// 食字軌：預印紙版面待業務單位提供量測後補（11/12 確認食也有預印紙）；目前先用同一套座標並在頁面提示。
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
if ($go && $rows && $role === 'edit') {
    $d->prepare('INSERT INTO print_log (batch_id, donation_id, kind, count, by_email) VALUES (?,?,?,?,?)')
      ->execute([$id, $one ?: null, $one ? 'reprint' : 'print', count($rows), $email]);
    rcpt_audit($d, $email, $one ? 'reprint' : 'print', $id, count($rows) . ' 張');
}
$date = rcpt_date_to_roc($b['in_date']);
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
@media print{.panel{display:none}}
</style>
</head>
<body>
<div class="panel">
<b><?= h($b['source']) ?>（<?= h($b['track']) ?>）收貨 <?= h($date) ?></b>　本次 <?= count($rows) ?> 張（已依郵遞區號、地址、收件人排序）
<?php if ($b['track'] === '食'): ?><div class="warn">食字軌預印紙的位置還在量測，請先用白紙試印、疊在預印紙上對光確認。</div><?php endif; ?>
<form method="get" style="margin-top:8px">
<input type="hidden" name="id" value="<?= $id ?>"><?php if ($one): ?><input type="hidden" name="one" value="<?= $one ?>"><?php endif; ?>
<?php if (!$one): ?>
範圍 <select name="scope"><option value="send" <?= $scope === 'send' ? 'selected' : '' ?>>只印要寄的</option><option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>全部</option></select>
流水號 <input name="from" value="<?= $from ?: '' ?>" placeholder="從">～<input name="to" value="<?= $to ?: '' ?>" placeholder="到">
<?php endif; ?>
位置微調（mm）右移 <input name="dx" value="<?= $dx ?>"> 下移 <input name="dy" value="<?= $dy ?>">
<button>更新預覽</button>
<?php if ($role === 'edit'): ?><button name="go" value="1">記錄並列印</button><?php endif; ?>
</form>
<small>列印設定：A4、邊界「無」、縮放 100%、不要印頁首頁尾。第一次請用白紙試印，疊在預印紙上對光檢查。<a href="./?a=batch&id=<?= $id ?>">回批次</a></small>
</div>
<?php if ($go && $rows): ?><script>addEventListener('load', () => print());</script><?php endif; ?>
<?php foreach ($rows as $r): ?>
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
<?php endforeach; ?>
</body>
</html>
