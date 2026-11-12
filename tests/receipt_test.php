<?php
// 物資捐贈收據系統（lib/receipt.php）測試。純函式在任何機器可跑；配號與資料表要 ITCCRA_TEST_DB=1（寫 receipt_test schema）。
// 用法：php tests/receipt_test.php <lib 目錄> [db]
$LIB = $argv[1] ?? __DIR__ . '/../lib';
require $LIB . '/receipt.php';
$fail = 0; $n = 0;
function ok($cond, $msg) { global $fail, $n; $n++; echo ($cond ? "  ✅ " : "  ❌ ") . $msg . "\n"; if (!$cond) $fail++; }
function throws(callable $f): bool { try { $f(); return false; } catch (Throwable $e) { return true; } }

echo "收據編號格式\n";
ok(rcpt_no('物', '2026-09-14', 1) === '物(115)09140001', '物(115)09140001');
ok(rcpt_no('物', '2026-07-31', 5375) === '物(115)07315375', '業務單位實例 物(115)07315375');
ok(rcpt_no('食', '2027-01-05', 12) === '食(116)01050012', '跨年：116 年');
ok(throws(fn() => rcpt_no('物', '2026-09-14', 0)), '流水號 0 擋下');
ok(throws(fn() => rcpt_no('物', '2026-09-14', 10000)), '流水號 10000 擋下');
ok(throws(fn() => rcpt_no('X', '2026-09-14', 1)), '未知字軌擋下');
ok(rcpt_range_text('物(115)09140001', '物(115)09145089') === '物(115)09140001~5089', '起訖字串與文中報關單號相同寫法');
ok(rcpt_range_text('物(115)09140001', '物(115)09140001') === '物(115)09140001', '只有一張時不加 ~');

echo "民國日期\n";
ok(rcpt_roc_to_date('115/09/14') === '2026-09-14', '115/09/14');
ok(rcpt_roc_to_date('115/9/4') === '2026-09-04', '115/9/4');
ok(rcpt_roc_to_date('1150914') === '2026-09-14', '1150914');
ok(rcpt_roc_to_date('115/02/30') === null, '不存在的日期回 null');
ok(rcpt_date_to_roc('2026-09-14') === '115/09/14', '西元轉民國');

if (($argv[2] ?? '') === 'db') {
    putenv('ITCCRA_TEST_DB=1');
    echo "配號（receipt_test schema）\n";
    $d = rcpt_db();
    $d->exec('TRUNCATE batch, batch_item, donation, print_log, audit RESTART IDENTITY CASCADE');
    $mk = function (string $wms, string $date, int $rows) use ($d) {
        $d->prepare("INSERT INTO batch (source, track, wms_no, in_date, created_by) VALUES ('ibon','物',?,?,'t@x')")->execute([$wms, $date]);
        $id = (int)$d->lastInsertId();
        $s = $d->prepare('INSERT INTO donation (batch_id, row_no, name) VALUES (?,?,?)');
        for ($i = 1; $i <= $rows; $i++) $s->execute([$id, $i, "測試$i"]);
        return $id;
    };
    $a = $mk('T001', '2026-09-14', 3);
    $r = rcpt_assign($d, $a, 't@x');
    ok($r['first'] === '物(115)09140001' && $r['last'] === '物(115)09140003', '第一批 0001～0003');
    $b = $mk('T002', '2026-09-14', 2);
    $r = rcpt_assign($d, $b, 't@x');
    ok($r['first'] === '物(115)09140004' && $r['range'] === '物(115)09140004~0005', '同日第二批接續 0004～0005');
    ok(throws(fn() => rcpt_assign($d, $b, 't@x')), '已配號批次不能重配');
    $c = $mk('T003', '2026-09-15', 1);
    ok(rcpt_assign($d, $c, 't@x')['first'] === '物(115)09150001', '換日從 0001 起');
    $d->exec("UPDATE donation SET void = true WHERE receipt_no = '物(115)09140005'");
    $e = $mk('T004', '2026-09-14', 1);
    ok(rcpt_assign($d, $e, 't@x')['first'] === '物(115)09140006', '作廢號碼不重用');
    $f = $mk('T005', '2026-09-16', 0);
    ok(throws(fn() => rcpt_assign($d, $f, 't@x')), '沒有明細不能配號');
    ok($d->query("SELECT status FROM batch WHERE wms_no = 'T005'")->fetchColumn() === 'draft', '配號失敗時批次保持草稿（交易回復）');
}
echo $fail ? "❌ 失敗 $fail／$n\n" : "✅ 全部通過 $n 項\n";
exit($fail ? 1 : 0);
