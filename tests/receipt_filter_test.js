// 收據設定頁篩選（site/receipt/index.php 的 .setfilter 腳本）行為測試，不需瀏覽器：用最小的假 DOM 跑原始碼裡那段 <script>。
// 用法：node tests/receipt_filter_test.js（ITSrv01 沒有 node，在本機跑）
// 大沈 11/25 審 9845cc6：一般列的停用表單也有 hidden input，舊寫法用 input,select 判斷編輯列 → 一般列永遠不會被藏。
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(path.join(__dirname, '..', 'site', 'receipt', 'index.php'), 'utf8');
const m = src.match(/<script>(\(function\(\)\{document\.querySelectorAll\('\.setfilter'\)[\s\S]*?)<\/script>/);
if (!m) { console.log('❌ 找不到篩選腳本'); process.exit(1); }

function row(text, cls = []) {
  return { textContent: text, hidden: false, classList: { contains: (c) => cls.includes(c) } };
}
const rows = [
  row('編號名稱狀態', ['hd']),
  row('DEMO001假供應商1啟用修改停用'),          // 一般列（裡面也有停用表單的 hidden input，但腳本不該在意）
  row('DEMO002假供應商2啟用修改停用'),
  row('', ['editing']),                           // 編輯中：名稱在 input.value，textContent 幾乎是空的
  row('DEMO004假供應商4已停用修改重新啟用', ['bad']),
];
const table = { querySelectorAll: () => rows };
let handler = null;
const input = { dataset: { for: 'tb-supplier' }, value: '', addEventListener: (ev, fn) => { if (ev === 'input') handler = fn; } };
global.document = {
  querySelectorAll: (sel) => (sel === '.setfilter' ? [input] : []),
  getElementById: (id) => (id === 'tb-supplier' ? table : null),
};
new Function(m[1])();

let fail = 0, n = 0;
const ok = (c, msg) => { n++; console.log((c ? '  ✅ ' : '  ❌ ') + msg); if (!c) fail++; };
const vis = () => rows.slice(1).map((r) => !r.hidden);

input.value = 'SHEN-VERIFY-NO-MATCH'; handler();
ok(JSON.stringify(vis()) === JSON.stringify([false, false, true, false]), '不存在的字串：一般列全藏、只留編輯列');
ok(!rows[0].hidden, '標題列不受篩選影響');
input.value = 'demo002'; handler();
ok(JSON.stringify(vis()) === JSON.stringify([false, true, true, false]), '不分大小寫找到相符列，編輯列仍保留');
input.value = '  '; handler();
ok(vis().every(Boolean), '清空（只有空白）後全部恢復');
console.log(`\n${n} 項，失敗 ${fail}`);
process.exit(fail ? 1 : 0);
