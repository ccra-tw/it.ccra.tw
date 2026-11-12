# -*- coding: utf-8 -*-
"""物資捐贈收據系統：讀「地址整理」後的名單 xlsx，輸出 JSON 給 PHP 匯入（GitHub #21）。

用法：python3 receipt_read.py <檔案.xlsx> [工作表名]
輸出（stdout，UTF-8 JSON）：
  {"sheets":[...], "sheet":"全部訂單", "header_row":1, "columns":[原欄名...],
   "mapping":{"name":"收件人", ...}, "missing":[對不到的必要欄], "rows":[{...}, ...]}
規則（業務單位 11/11 建置說明「匯入規格」）：
  ・標題列不一定在第一列：前 30 列裡找對到最多欄名的一列
  ・依欄名對應、不依順序；姓名依「收件人姓名→收件人→訂購人」優先
  ・姓名與地址都空白的列略過（清單最後的合計列因此不會進來）
  ・有「全部訂單」工作表就用它（ibon 檔另有「補印」「收據」兩張是子集合）
🔴 名單含捐物人個資：本程式只讀檔、輸出到 stdout，不寫任何檔案或紀錄。
"""
import json, re, sys
from openpyxl import load_workbook

FIELDS = [  # (系統欄位, 認得的欄名依優先順序)
    ('receipt_no', ['收據編號', '收據號碼']),
    ('order_no', ['訂單編號', '平台訂單編號', '系統訂單編號']),
    ('name', ['收件人姓名', '收件人', '訂購人']),
    ('mobile', ['收件人聯絡手機', '收件人行動電話', '手機', '電話']),
    ('phone', ['收件人聯絡電話', '收件人電話']),
    ('raw_zip', ['收件人郵遞區號', '送貨郵遞區號']),
    ('raw_addr', ['收件人地址', '地址', '送貨地點']),
    ('product', ['商品名稱', '產品名稱', '商品', '品名']),
    ('qty', ['數量', '訂單數量']),
    ('zip', ['zip']), ('city', ['city']), ('address', ['address']),
    ('send', ['收據寄發', '收據索取', '收據']),
    ('check_note', ['檢核說明']),
    ('dup_note', ['重複字']),
]
REQUIRED = ['name', 'raw_addr', 'qty']
FALLBACK = {'name': '姓名', 'raw_addr': '地址', 'qty': '數量'}  # 欄名對不到時的關鍵字


def norm(v):
    if v is None:
        return ''
    if isinstance(v, float) and v.is_integer():
        v = int(v)
    return str(v).strip()


def map_header(cells):
    cols = [norm(c) for c in cells]
    low = [c.lower() for c in cols]
    mapping = {}
    for key, names in FIELDS:
        for n in names:
            if n.lower() in low:
                mapping[key] = cols[low.index(n.lower())]
                break
    for key, kw in FALLBACK.items():
        if key not in mapping:
            hit = [c for c in cols if kw in c]
            if hit:
                mapping[key] = hit[0]
    return cols, mapping


def pick_header(ws):
    best = (0, None, None, None)
    for i, row in enumerate(ws.iter_rows(min_row=1, max_row=30, values_only=True), start=1):
        cols, mapping = map_header(row)
        if len(mapping) > best[0]:
            best = (len(mapping), i, cols, mapping)
    return best[1], best[2], best[3]


def to_send(v):
    v = norm(v).upper()
    if v in ('Y', 'YES', '是', '要', 'V', '1'):
        return True
    if v in ('N', 'NO', '否', '不要', '0'):
        return False
    return None  # 檔案沒寫：由匯入端決定（預設寄發，交檢核畫面確認）


def to_int(v):
    s = re.sub(r'[,\s]', '', norm(v))
    try:
        return int(float(s)) if s else 0
    except ValueError:
        return None


def main():
    path = sys.argv[1]
    want = sys.argv[2] if len(sys.argv) > 2 else ''
    wb = load_workbook(path, read_only=True, data_only=True)
    sheets = wb.sheetnames
    order = [want] if want in sheets else (['全部訂單'] if '全部訂單' in sheets else []) + [s for s in sheets if s != '全部訂單']
    chosen = None
    for s in order:
        hr, cols, mapping = pick_header(wb[s])
        if hr and all(k in mapping for k in ('name', 'raw_addr')):
            chosen = (s, hr, cols, mapping)
            break
    if not chosen:
        s = order[0] if order else ''
        hr, cols, mapping = pick_header(wb[s]) if s else (None, [], {})
        print(json.dumps({'sheets': sheets, 'sheet': s, 'header_row': hr, 'columns': cols or [], 'mapping': mapping or {},
                          'missing': [k for k in REQUIRED if k not in (mapping or {})], 'rows': []}, ensure_ascii=False))
        return
    s, hr, cols, mapping = chosen
    idx = {k: cols.index(v) for k, v in mapping.items()}
    rows = []
    for n, r in enumerate(wb[s].iter_rows(min_row=hr + 1, values_only=True), start=1):
        get = lambda k: norm(r[idx[k]]) if k in idx and idx[k] < len(r) else ''
        if not get('name') and not get('raw_addr'):
            continue
        rows.append({
            'src_row': hr + n, 'receipt_no': get('receipt_no'), 'order_no': get('order_no'), 'name': get('name'),
            'mobile': get('mobile'), 'phone': get('phone'), 'raw_zip': get('raw_zip'), 'raw_addr': get('raw_addr'),
            'product': get('product'), 'qty': to_int(r[idx['qty']]) if 'qty' in idx and idx['qty'] < len(r) else None,
            'zip': get('zip'), 'city': get('city'), 'address': get('address'),
            'send': to_send(r[idx['send']]) if 'send' in idx and idx['send'] < len(r) else None,
            'check_note': get('check_note'), 'dup_note': get('dup_note'),
        })
    print(json.dumps({'sheets': sheets, 'sheet': s, 'header_row': hr, 'columns': cols, 'mapping': mapping,
                      'missing': [k for k in REQUIRED if k not in mapping], 'rows': rows}, ensure_ascii=False))


if __name__ == '__main__':
    main()
