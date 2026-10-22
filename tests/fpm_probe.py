# -*- coding: utf-8 -*-
"""在真正的 php-fpm 池裡跑 /addr/ 的程序控制（2026-10-22 繁英姊實際上傳 500 之後補）。

為什麼要有這支：其他測試都用命令列 PHP 跑，而命令列有 pcntl、php-fpm 沒有——
lib/addr.php 用了 SIGTERM（pcntl 常數），測試全過，第一次真的上傳就 Fatal。
這支用最小的 FastCGI 用戶端直接打 /addr/ 專用池的 socket，在 fpm 環境裡：
  1. 回報 pcntl／posix 是否載入（確認環境真的不同）
  2. 跑 addr_exec 一個會逾時的指令，逼它走 addr_kill_group，確認不 Fatal、回 124、程序群組清空
用法（主機上、以 www-data 執行）：python3 tests/fpm_probe.py <lib 目錄>
"""
import json, os, shutil, socket, struct, sys, tempfile

SOCK = "/run/php/it-ccra-addr.sock"
LIB = sys.argv[1]
WORK = "/var/lib/it-ccra/fpmprobe"   # php-fpm 有 PrivateTmp，不能放 /tmp

PROBE = r"""<?php
require %s;
$out = ['pcntl' => extension_loaded('pcntl'), 'posix' => extension_loaded('posix'), 'sapi' => PHP_SAPI];
$t = microtime(true);
$rc = addr_exec(['sleep', '30'], sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin'], 1);
$out['rc'] = $rc; $out['secs'] = round(microtime(true) - $t, 1);
// 小鹿 10/22：sleep 收到 TERM 就結束，走不到 KILL。這支忽略 TERM（exec 後仍維持忽略），逼 addr_kill_group 送 KILL
$t = microtime(true);
$out['rc2'] = addr_exec(['sh', '-c', 'trap "" TERM; exec sleep 3017'], sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin'], 1);
$out['secs2'] = round(microtime(true) - $t, 1);
$left = 0;
foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) { $c = @file_get_contents($f); if ($c !== false && str_contains($c, "sleep\0" . "3017")) $left++; }
$out['left2'] = $left;
echo json_encode($out);
"""


def fcgi(sock_path, params, body=b""):
    def rec(t, content, rid=1):
        return struct.pack("!BBHHBx", 1, t, rid, len(content), 0) + content
    def nv(k, v):
        k, v = k.encode(), v.encode()
        enc = lambda n: bytes([n]) if n < 128 else struct.pack("!I", n | 0x80000000)
        return enc(len(k)) + enc(len(v)) + k + v
    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    s.settimeout(60)
    s.connect(sock_path)
    s.sendall(rec(1, struct.pack("!HB5x", 1, 0)))                       # BEGIN_REQUEST, responder
    s.sendall(rec(4, b"".join(nv(k, v) for k, v in params.items())) + rec(4, b""))
    s.sendall(rec(5, body) + rec(5, b""))
    out, err = b"", b""
    buf = b""
    while True:
        chunk = s.recv(65536)
        if not chunk:
            break
        buf += chunk
    s.close()
    i = 0
    while i + 8 <= len(buf):
        _, t, _, ln, pad = struct.unpack("!BBHHB", buf[i:i + 7])
        content = buf[i + 8:i + 8 + ln]
        if t == 6: out += content
        if t == 7: err += content
        i += 8 + ln + pad
    return out, err


def main():
    os.makedirs(WORK, exist_ok=True)
    d = tempfile.mkdtemp(dir=WORK)
    try:
        shutil.copy(os.path.join(LIB, "addr.php"), os.path.join(d, "addr.php"))
        probe = os.path.join(d, "probe.php")
        with open(probe, "w", encoding="utf-8") as f:
            f.write(PROBE % json.dumps(os.path.join(d, "addr.php")))
        os.chmod(d, 0o755)
        out, err = fcgi(SOCK, {"SCRIPT_FILENAME": probe, "REQUEST_METHOD": "GET", "SERVER_PROTOCOL": "HTTP/1.1",
                               "GATEWAY_INTERFACE": "CGI/1.1", "REQUEST_URI": "/probe", "SCRIPT_NAME": "/probe"})
        body = out.split(b"\r\n\r\n", 1)[-1].decode("utf-8", "replace")
        fail, n = 0, 0
        def ok(cond, msg):
            nonlocal fail, n
            n += 1
            print(("  ✅ " if cond else "  ❌ ") + msg)
            if not cond: fail += 1
        try:
            r = json.loads(body)
        except ValueError:
            r = {}
        ok(bool(r), "php-fpm 有回 JSON（沒有 Fatal）" + ("" if r else "：" + (body + err.decode("utf-8", "replace"))[:300]))
        ok(r.get("sapi") == "fpm-fcgi", "真的在 php-fpm 裡執行（sapi=%s）" % r.get("sapi"))
        ok(r.get("posix") is True, "fpm 有 posix")
        # 環境前提：池裡沒有 pcntl 才測得到 10/22 那個差異；哪天池裝了 pcntl，這項會紅，提醒改留一個停用 pcntl 的回歸案例
        ok(r.get("pcntl") is False, "fpm 沒有 pcntl（本測試的前提；變了要改測法）")
        ok(r.get("rc") == 124, "逾時回 124（走過 addr_kill_group）")
        ok(r.get("secs", 99) < 15, "1 秒逾時＋收尾在 15 秒內結束（實際 %s 秒）" % r.get("secs"))
        ok(r.get("rc2") == 124, "忽略 TERM 的程序：逾時仍回 124（KILL 分支收得掉，不是 125）")
        ok(r.get("secs2", 0) >= 4, "…有等滿 TERM 的 5 秒＝真的走到 KILL（實際 %s 秒）" % r.get("secs2"))
        ok(r.get("secs2", 99) < 15,"…TERM 等 5 秒後 KILL，15 秒內結束（實際 %s 秒）" % r.get("secs2"))
        ok(r.get("left2") == 0, "…群組沒有殘留程序（剩 %s 個）" % r.get("left2"))
        print("%d 項，失敗 %d" % (n, fail))
        sys.exit(1 if fail else 0)
    finally:
        shutil.rmtree(d, ignore_errors=True)


main()
