<?php
// 物資捐贈收據：列印頁（GitHub #21）。瀏覽器列印：A4、邊界「無」、縮放 100%、不印頁首頁尾。
// 物字軌：套印到預印紙，只印變動資料；座標（mm，原點紙張左上角）量自業務單位的套印範本（建置說明「收據列印規格」）。
// 食字軌：沒有預印紙（業務單位 11/12 更正），整張印在白紙上，版面照「(食)捐贈收據 單頁範本」，大章、理事長章、秘書長章與承辦人章一起印（stamp.php）。
// 排序：zip → city → address → 收件人（方便郵寄）；收據編號不因排序改變。每印一次寫 print_log（誰、何時、幾張）。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/receipt.php';
$email = strtolower(access_identity()['email']);
$d = rcpt_db();
$letter = trim(rcpt_letter($d));   // 信件統一由「設定」維護，列印一律套用（繁英姊 11/25）
$role = rcpt_role($d, $email);
if (!$role) { http_response_code(403); exit('物資捐贈收據系統只開放給開收據的同仁、客服與助理會計。'); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$id = (int)($_GET['id'] ?? 0);
$s = $d->prepare('SELECT * FROM batch WHERE id = ?'); $s->execute([$id]);
$b = $s->fetch(PDO::FETCH_ASSOC);
if (!$b || $b['status'] !== 'numbered') { http_response_code(404); exit('找不到已配號的批次。'); }
// 食字軌：品名或單位缺的收據不印（配號時已擋；這裡再擋一次，避免配號前就存在的舊批次或日後資料被改）
if ($b['track'] === '食' && ($miss = rcpt_food_missing($d, $id))) { http_response_code(409);    // 已配號的批次不能重匯，所以只講可行的處置（大沈 b8e2623 複驗建議）
    $parts = array_map(fn($m) => "第 {$m[0]} 列{$m[1]}", array_slice($miss, 0, 5));
    exit(h('這個批次已配號，但有 ' . count($miss) . ' 列品名／單位不完整（' . implode('；', $parts) . (count($miss) > 5 ? '…' : '') . '），收據不能列印。請通知資訊部協助補正。')); }
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
// 分次列印（繁英姊 11/19：「影印機紙夾一次只能放 500 張，列印時可以分次列印嗎？1-500」）：排好序之後依「第幾張」切段，每段 500 張
const RCPT_CHUNK = 500;
$total = count($rows);
$chunks = $one ? 1 : max(1, (int)ceil($total / RCPT_CHUNK));
$chunk = $one ? 1 : max(1, min($chunks, (int)($_GET['chunk'] ?? 1)));
if (!$one && $total > RCPT_CHUNK) $rows = array_slice($rows, ($chunk - 1) * RCPT_CHUNK, RCPT_CHUNK);
$chunkText = $chunks > 1 ? '（第 ' . $chunk . ' 段／共 ' . $chunks . ' 段：第 ' . (($chunk - 1) * RCPT_CHUNK + 1) . '～' . (($chunk - 1) * RCPT_CHUNK + count($rows)) . ' 張，全份 ' . $total . ' 張）' : '';
if ($go && $rows && $role === 'edit') {
    try {
        $d->beginTransaction();   // 列印紀錄與稽核同一交易；失敗就整筆回滾、不觸發列印
        $d->prepare('INSERT INTO print_log (batch_id, donation_id, kind, count, by_email) VALUES (?,?,?,?,?)')
          ->execute([$id, $one ?: null, $one ? 'reprint' : 'print', count($rows), $email]);
        rcpt_audit($d, $email, $one ? 'reprint' : 'print', $id, count($rows) . ' 張' . $chunkText);
        $d->commit();
    } catch (Throwable $e) {
        if ($d->inTransaction()) $d->rollBack();
        $ref = rcpt_log_db_error($e, 'print');
        http_response_code(500);
        exit("列印紀錄寫入失敗，這次沒有列印，請重新整理再試；仍不行請通知資訊部（代碼 {$ref}）。");
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
// 繁英姊 11/20 實測：姓名、套數各往下 3 mm
$P = ['no' => [164.4, 27.2], 'date' => [165.9, 33.3], 'name' => [65.3, 40.0], 'contents' => [11.9, 61.8], 'qty' => [53.6, 70.6],
      'addr' => [18.0, 126.9], 'to' => [18.1, 134.1], 'letter' => [8.6, 145.0]];   // 繁英姊 11/20：地址＋收件人先往下 18 mm、14:1x 實印後再上調 10 mm；信維持 149.0；每套內容下調 0.5 mm
$pos = fn($k) => sprintf('left:%.1fmm;top:%.1fmm', $P[$k][0] + $dx, $P[$k][1] + $dy);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>列印收據｜<?= h($b['po_no'] ?: $date) ?>｜CCRA 資訊服務</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Noto+Sans+TC:wght@400;700;800;900&display=swap" rel="stylesheet">
<!-- 協會版型只給螢幕（CCRA CI，熊哥 11/18）；列印時不載，收據版面不受影響 -->
<link rel="stylesheet" href="/assets/site.css?v=dev" media="screen">
<link rel="stylesheet" href="/assets/receipt.css?v=dev" media="screen">
<script src="/assets/whoami.js?v=dev" defer></script>
<style>
@page{size:A4;margin:0}
body{margin:0;font-family:"Microsoft JhengHei","微軟正黑體","Noto Sans TC",sans-serif}
.ctl .row{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px 16px}
.ctl .row>div{min-width:0}
.ctl label{margin:0 0 4px}
.ctl input.mm{width:5.5em}
.ctl input.no{width:7em}
.ctl select{width:auto}
.page{position:relative;width:210mm;height:297mm;page-break-after:always;overflow:hidden;background:#fff;color:#000}
/* 繁英 11/20：單筆列印多出一張空白頁。原因：.food 有 14mm 上下內距，site.css 只在螢幕套 border-box，列印時高度變 297+28mm 溢到第二頁；另最後一頁不再強制換頁 */
@media print{.page{box-sizing:border-box;height:296mm;break-after:page}.page:last-child{page-break-after:auto;break-after:auto}html,body{height:auto}}
@media screen{.page{margin:0 auto 18px;box-shadow:0 18px 40px -22px rgba(13,26,51,.45);border-radius:4px}.pages{padding:0 16px 40px;overflow-x:auto}}
.f{position:absolute;font-weight:700;font-size:12pt;white-space:nowrap}
.contents{font-size:10pt;line-height:18pt;width:186mm;white-space:normal}
.letter{font-family:"標楷體","DFKai-SB","BiauKai",serif;font-weight:400;font-size:10pt;line-height:5.1mm;width:171mm;white-space:pre-wrap;text-align:justify}
.letter p{margin:0;text-indent:2em}.letter p:first-child{text-indent:0}   /* 繁英姊 11/20：每段開頭空兩字、段落間不空行（稱謂那行不縮排） */
.warn{color:#9b1c1c;font-weight:700}
.food{padding:14mm 16mm;font-size:11pt;line-height:1.6;font-family:"Microsoft JhengHei","微軟正黑體","Noto Sans TC",sans-serif}
.food .hd{position:relative;border-bottom:1px solid #000;padding-bottom:3mm;padding-left:22mm;min-height:19mm}
.food .hd .logo{position:absolute;left:0;top:0;width:18mm;height:18mm}
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
@media print{.screen-only{display:none!important}}
</style>
</head>
<body class="rcpage">
<header class="bar screen-only"><div class="wrap">
  <a href="/" style="display:flex;align-items:center;gap:12px;text-decoration:none"><img class="mark" src="/img/logo.png" alt="CCRA 資訊服務首頁">
  <img class="word word-light" src="/img/textlogo_black.png" alt="中華基督教救助協會">
  <img class="word word-dark" src="/img/textlogo_white.png" alt="中華基督教救助協會"></a>
  <span class="sep" aria-hidden="true"></span>
  <div class="title">物資捐贈收據<small>Donation Receipts</small></div>
</div></header>
<main class="rc screen-only">
<div class="rc-head">
  <div><h1>列印收據</h1><p><?= h($b['source']) ?>（<?= h($b['track']) ?>）收貨 <?= h($date) ?>　本次 <b><?= count($rows) ?></b> 張<?= h($chunkText) ?><?= $one ? '' : '（' . (['single' => '單張份', 'multi' => '多張份', 'all' => '不分份'][$part] ?? '') . '）' ?>，已依郵遞區號、地址、收件人排序</p></div>
  <div class="chips"><a class="btn ghost sm" href="./?a=batch&id=<?= $id ?>">← 回批次</a></div>
</div>
<?php if ($b['track'] === '食'): ?><div class="alert ok">食字軌：整張印在白紙上（含協會 Logo 與印章），不需預印紙。</div><?php endif; ?>
<section class="panel ctl"><h2>列印設定</h2>
<form method="get" onsubmit="rcPrintBusy(event)" oninput="if (['part','scope','from','to'].indexOf(event.target.name) >= 0) { var c = this.querySelector('[name=chunk]'); if (c) c.value = 1; }">
<input type="hidden" name="id" value="<?= $id ?>"><?php if ($chunks > 1): /* 份別、範圍、流水號一改就回第 1 段（大沈 d836044） */ ?><input type="hidden" name="chunk" value="<?= $chunk ?>"><?php endif; ?><?php if ($one): ?><input type="hidden" name="one" value="<?= $one ?>"><?php endif; ?>
<div class="row">
<?php if (!$one): ?>
<div><label for="p_part">份別</label><select id="p_part" name="part"><option value="single" <?= $part === 'single' ? 'selected' : '' ?>>單張份（<?= $nSingle ?> 張）</option><option value="multi" <?= $part === 'multi' ? 'selected' : '' ?>>多張份（<?= $nMulti ?> 張，同地址收件人連在一起）</option><option value="all" <?= $part === 'all' ? 'selected' : '' ?>>不分份</option></select></div>
<div><label for="p_scope">範圍</label><select id="p_scope" name="scope"><option value="send" <?= $scope === 'send' ? 'selected' : '' ?>>只印要寄的</option><option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>全部</option></select></div>
<div><label for="p_from">流水號</label><input type="text" class="no" id="p_from" name="from" aria-label="流水號起" value="<?= $from ?: '' ?>" placeholder="從"> ～ <input type="text" class="no" id="p_to" name="to" aria-label="流水號訖" value="<?= $to ?: '' ?>" placeholder="到"></div>
<?php endif; ?>
<div><label for="p_dx">位置微調（mm）</label><label for="p_dx" style="display:inline;margin:0;font-weight:400">右移</label> <input type="text" class="mm" id="p_dx" name="dx" aria-label="右移（mm）" value="<?= $dx ?>"> <label for="p_dy" style="display:inline;margin:0;font-weight:400">下移</label> <input type="text" class="mm" id="p_dy" name="dy" aria-label="下移（mm）" value="<?= $dy ?>"></div>
</div>
<?php if ($chunks > 1): $qs = $_GET; unset($qs['go']); ?>
<div class="chunks" role="group" aria-label="分次列印"><span class="hint">紙夾一次 500 張，分次印：</span><?php for ($k = 1; $k <= $chunks; $k++): $qs['chunk'] = $k; ?><a class="btn sm<?= $k === $chunk ? '' : ' ghost' ?>" href="?<?= h(http_build_query($qs)) ?>"<?= $k === $chunk ? ' aria-current="true"' : '' ?>>第 <?= ($k - 1) * RCPT_CHUNK + 1 ?>～<?= min($k * RCPT_CHUNK, $total) ?> 張</a><?php endfor; ?></div>
<?php endif; ?>
<div class="actions"><button class="btn ghost">更新預覽</button><?php if ($role === 'edit'): ?><button name="go" value="1">記錄並列印</button><?php endif; ?></div>
</form>
<p class="hint">列印設定：A4、邊界「無」、縮放 100%、不要印頁首頁尾。<?php if ($b['track'] !== '食'): ?>第一次請用白紙試印，疊在預印紙上對光檢查。<?php endif; ?></p>
</section>
<?php if ($role === 'edit' && $rows): $csrf = hash_hmac('sha256', 'receipt' . $email . date('Y-m-d'), trim((string)@file_get_contents(STATE_DIR . '/form_secret'))); ?>
<section class="panel ctl"><h2>寄出後 <small>這 <?= count($rows) ?> 張一起標記寄出日期</small></h2>
<form method="post" action="./?a=batch&id=<?= $id ?>">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="mailed"><input type="hidden" name="ids" value="<?= h(implode(',', array_column($rows, 'id'))) ?>">
<div class="row"><div><label for="p_mailed_on">寄出日期（民國）</label><input type="text" class="no" id="p_mailed_on" name="mailed_on" value="<?= h(rcpt_date_to_roc(date('Y-m-d'))) ?>"></div><div><button>標記已寄出</button></div></div></form>
</section>
<?php endif; ?>
<h2 style="margin:22px 0 10px;font-size:17px">預覽</h2>
</main>
<div class="pages">
<?php if ($go && $rows): ?><script>addEventListener('load', () => print());</script><?php endif; ?>
<dialog id="rcBusy" class="rc-dlg" aria-live="polite"><p class="rc-dlg-t"><span class="spin" aria-hidden="true"></span> <span id="rcBusyMsg">處理中…</span></p><p class="hint">張數多時要一點時間，好了會自動跳出印表機視窗。</p></dialog>
<script>
// 送出列印設定時顯示進行中（繁英姊 11/19：「系統有在運作，但是螢幕沒有顯示『列印中』，所以我會以為沒有反應」）
function rcPrintBusy(e) {
  var go = e.submitter && e.submitter.name === 'go', d = document.getElementById('rcBusy');
  document.getElementById('rcBusyMsg').textContent = go ? '準備列印中，請稍候…' : '產生預覽中…';
  d.addEventListener('cancel', function (x) { x.preventDefault(); });
  if (d.showModal) d.showModal();
}
// 繁英姊 11/20：其他按鈕（例如標記寄出日期）也要顯示處理中
document.addEventListener('submit', function (e) {
  if (e.defaultPrevented) return;
  if ((e.target.getAttribute('method') || '').toLowerCase() === 'dialog' || (e.submitter && (e.submitter.getAttribute('formmethod') || '').toLowerCase() === 'dialog')) return;   // 11/23：只關視窗的 dialog 表單不顯示處理中
  var d = document.getElementById('rcBusy'); if (!d || d.open) return;
  var b = e.submitter, label = b ? (b.textContent || '').trim() : '';
  document.getElementById('rcBusyMsg').textContent = label ? '「' + label.slice(0, 20) + '」處理中，請稍候…' : '處理中，請稍候…';
  d.addEventListener('cancel', function (x) { x.preventDefault(); });
  if (d.showModal) d.showModal();
});
addEventListener('pageshow', function () { var d = document.getElementById('rcBusy'); if (d && d.open) d.close(); });
</script>
<?php foreach ($rows as $r): if ($b['track'] === '食'): ?>
<div class="page food">
<div class="hd"><img class="logo" src="/img/logo.png" alt="CCRA"><div class="org">社團法人中華基督教救助協會</div><div>Chinese Christian Relief Association</div>
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
<?php if ($letter !== ''): ?><div class="f letter" style="<?= $pos('letter') ?>"><?php foreach (preg_split('/\R{2,}/u', $letter) as $para) echo '<p>' . h($para) . '</p>'; ?></div><?php endif; ?>
</div>
<?php endif; endforeach; ?>
</div>
</body>
</html>
