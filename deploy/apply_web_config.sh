#!/bin/sh
# 套用 PHP-FPM 池與 nginx 設定（由 deploy.sh 呼叫）。
# 任何一步失敗：把這次換上的設定檔還原成原本的、以非零結束，後面的步驟（nginx、印 deployed）都不執行。
# （小鹿 10/21 第四次互審：原本 `php-fpm8.5 -t && systemctl reload` 失敗時 set -e 不會中止，
#   會接著換 nginx 到還沒可用的新 socket，最後還印 deployed。）
# 路徑與指令都可用環境變數替換，tests/deploy_test.sh 用假指令驗證失敗路徑。
set -eu
ETC=${WEB_ETC:-/etc}
STATE=${WEB_STATE:-/var/lib/it-ccra}
SRC=${WEB_SRC:-deploy}
OWNER=${WEB_OWNER:-www-data}
PAUSE=${WEB_SLEEP:-2}

fail() { logger -t it-ccra-deploy "FAILED: $*" 2>/dev/null || true; echo "FAILED: $*" >&2; exit 1; }
# 內容不同才換：舊檔先留成 .prev。回傳 0＝有換、1＝沒變。
swap() {
    cmp -s "$1" "$2" && return 1
    if [ -e "$2" ]; then cp -p "$2" "$2.prev"; else rm -f "$2.prev"; fi
    install -m 644 "$1" "$2"
}
restore() { if [ -e "$1.prev" ]; then mv -f "$1.prev" "$1"; else rm -f "$1"; fi; }
done_ok() { rm -f "$1.prev"; }

POOLD=$ETC/php/8.5/fpm/pool.d
CHANGED=""
for pair in it-ccra-fpm.conf:it-ccra.conf it-ccra-addr-fpm.conf:it-ccra-addr.conf; do
    dst=$POOLD/${pair#*:}
    if swap "$SRC/${pair%%:*}" "$dst"; then CHANGED="$CHANGED $dst"; fi
done

# /addr/ 專用暫存目錄（0700，www-data）：要先於 PHP 池與 nginx 生效，否則上傳無處可寫
install -d -o "$OWNER" -g "$OWNER" -m 0700 "$STATE/addr-tmp" "$STATE/addr-tmp/.upload" "$STATE/addr-tmp/.nginx-body"

if [ -n "$CHANGED" ]; then
    if ! php-fpm8.5 -t; then
        for f in $CHANGED; do restore "$f"; done
        fail "php-fpm8.5 -t（池設定已還原）"
    fi
    if ! systemctl reload php8.5-fpm; then
        for f in $CHANGED; do restore "$f"; done
        systemctl reload php8.5-fpm || true          # 盡量回到舊設定
        fail "systemctl reload php8.5-fpm（池設定已還原）"
    fi
    for f in $CHANGED; do done_ok "$f"; done
    sleep "$PAUSE"
fi

NGX=$ETC/nginx/sites-available/it.ccra.tw
if swap "$SRC/nginx-it.ccra.tw.conf" "$NGX"; then
    if ! nginx -t; then restore "$NGX"; fail "nginx -t（站台設定已還原）"; fi
    if ! systemctl reload nginx; then restore "$NGX"; systemctl reload nginx || true; fail "systemctl reload nginx（站台設定已還原）"; fi
    done_ok "$NGX"
fi
