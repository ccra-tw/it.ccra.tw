# -*- coding: utf-8 -*-
"""lib/receipt_read.py 測試（全部虛構資料）。用法：python3 tests/receipt_read_test.py <lib 目錄>"""
import json, os, subprocess, sys, tempfile
from openpyxl import Workbook
LIB = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), '..', 'lib')
fail = 0; n = 0
def ok(c, m):
    global fail, n
    n += 1; print(('  ✅ ' if c else '  ❌ ') + m)
    if not c: fail += 1
def run(path, *a):
    return json.loads(subprocess.run([sys.executable, os.path.join(LIB, 'receipt_read.py'), path, *a], capture_output=True, text=True, check=True).stdout)

d = tempfile.mkdtemp()
# ① ibon 整理後格式：三個工作表，標題列在第 3 列，有合計列
wb = Workbook(); ws = wb.active; ws.title = '收據'
ws.append(['只有Y']); a = wb.create_sheet('全部訂單'); wb.create_sheet('補印')
a.append(['ibon 物資捐贈 7-8 月']); a.append([])
a.append(['收據號碼', '訂單編號', '訂購人', '收件人', '收件人行動電話', '送貨郵遞區號', '送貨地點', '產品名稱', '訂單數量', 'zip', 'city', 'address', '收據', '檢核說明', '重複字'])
a.append(['', 'A001', '甲先生', '王測試', '0900000001', '100', '臺北市中正區測試路1號', '救助套餐', 2, '100', '臺北市中正區', '測試路1號', 'Y', '', ''])
a.append(['', 'A002', '乙小姐', '愛心捐贈', '', '', '愛心捐贈', '救助套餐', 1, '', '', '', 'N', '愛心捐贈', ''])
a.append(['', '', '', '', '', '', '', '合計', 3])
p1 = os.path.join(d, 'ibon.xlsx'); wb.save(p1)
r = run(p1)
ok(r['sheet'] == '全部訂單', '有「全部訂單」就用它（不用第一張「收據」）')
ok(r['header_row'] == 3, '標題列不在第一列也找得到（第 3 列）')
ok(r['mapping']['name'] == '收件人', '姓名優先用「收件人」，不是「訂購人」')
ok(len(r['rows']) == 2, '合計列（姓名、地址都空）不匯入')
ok(r['rows'][0]['qty'] == 2 and r['rows'][0]['send'] is True and r['rows'][1]['send'] is False, '數量轉整數、收據 Y/N 轉布林')
ok(r['rows'][0]['zip'] == '100' and r['rows'][0]['address'] == '測試路1號', '沿用檔案裡已切好的 zip／address')
ok(r['missing'] == [], '必要欄位都對到')
# ② 家庭雲端原始格式：欄名不同、沒有收據欄
wb = Workbook(); ws = wb.active; ws.title = '工作表1'
ws.append(['項次', '平台訂單編號', '收件人', '電話', '地址', '商品', '數量'])
ws.append([1, 'F-1', '林測試', '0911', '新北市永和區測試街2號', '生活用品', '1'])
p2 = os.path.join(d, 'family.xlsx'); wb.save(p2)
r = run(p2)
ok(r['mapping'].get('order_no') == '平台訂單編號' and r['mapping'].get('raw_addr') == '地址', '家庭雲端欄名對得到')
ok(r['rows'][0]['send'] is None, '沒有收據欄時回 None（交檢核畫面決定）')
# ③ 對不到必要欄位
wb = Workbook(); ws = wb.active; ws.append(['甲', '乙']); ws.append(['1', '2'])
p3 = os.path.join(d, 'bad.xlsx'); wb.save(p3)
r = run(p3)
ok(r['rows'] == [] and 'name' in r['missing'], '對不到姓名／地址時不輸出資料、列出缺的欄位')
print(('❌ 失敗 %d／%d' % (fail, n)) if fail else ('✅ 全部通過 %d 項' % n))
sys.exit(1 if fail else 0)
