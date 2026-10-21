#!/bin/sh
# 套用 PHP-FPM 池與 nginx 設定（由 deploy.sh 呼叫）。
# 任何一步失敗：把這次換上的所有設定檔（池與 nginx）一起還原、已 reload 過的服務再 reload 回舊設定，
# 記錄還原是否成功，以非零結束；後面的步驟（nginx、deploy.sh 印 deployed）都不執行。
# （小鹿 10/21 第四、五次互審：
#   ①原本 `php-fpm8.5 -t && systemctl reload` 失敗時 set -e 不會中止；
#   ②swap 用回傳值 1 表示「沒變」，install／cp 失敗也是非零，在 if 條件裡被當成「沒變」而繼續；
#   ③PHP reload 成功後就刪掉 .prev，之後 nginx 失敗只還原 nginx，PHP 留在新版。）
# 所以：檔案替換的成敗不經回傳值傳遞，失敗一律當場 fail；備份留到全部成功才刪；還原集中在 rollback。
# 路徑與指令都可用環境變數替換，tests/deploy_test.sh 用假指令驗證失敗路徑。
set -eu
ETC=${WEB_ETC:-/etc}
STATE=${WEB_STATE:-/var/lib/it-ccra}
SRC=${WEB_SRC:-deploy}
OWNER=${WEB_OWNER:-www-data}
PAUSE=${WEB_SLEEP:-2}

BACKED=""      # 這次換掉、原本就存在的檔（舊版在 <檔>.prev）
CREATED=""     # 這次新建、原本不存在的檔
PHP_RELOAD=0   # 已對 php8.5-fpm 下過 reload（還原後要再 reload 一次）
NGX_RELOAD=0   # 已對 nginx 下過 reload

log() { logger -t it-ccra-deploy "$*" 2>/dev/null || true; echo "$*" >&2; }

rollback() {
    ok=1
    for f in $BACKED; do mv -f "$f.prev" "$f" || { ok=0; log "rollback: 還原 $f 失敗"; }; done
    for f in $CREATED; do rm -f "$f" || { ok=0; log "rollback: 移除 $f 失敗"; }; done
    if [ "$PHP_RELOAD" = 1 ]; then systemctl reload php8.5-fpm || { ok=0; log "rollback: reload php8.5-fpm 失敗"; }; fi
    if [ "$NGX_RELOAD" = 1 ]; then systemctl reload nginx || { ok=0; log "rollback: reload nginx 失敗"; }; fi
    if [ "$ok" = 1 ]; then log "rollback: 已還原這次換上的設定"; else log "rollback: 還原不完整，需人工處理"; fi
}
fail() { log "FAILED: $*"; rollback; exit 1; }

# 內容不同才換。結果放在 SWAPPED（1＝有換、0＝相同沒換）；任何失敗直接 fail，不靠回傳值。
stage() {
    src=$1; dst=$2; SWAPPED=0
    [ -r "$src" ] || fail "讀不到 $src"
    if [ -e "$dst" ] && cmp -s "$src" "$dst"; then return 0; fi
    if [ -e "$dst" ]; then
        cp -p "$dst" "$dst.prev" || { rm -f "$dst.prev"; fail "備份 $dst 失敗"; }
        BACKED="$BACKED $dst"
    else
        rm -f "$dst.prev"
        CREATED="$CREATED $dst"
    fi
    install -m 644 "$src" "$dst" || fail "安裝 $dst 失敗"
    SWAPPED=1
}

POOLD=$ETC/php/8.5/fpm/pool.d
POOL_CHANGED=0
for pair in it-ccra-fpm.conf:it-ccra.conf it-ccra-addr-fpm.conf:it-ccra-addr.conf; do
    stage "$SRC/${pair%%:*}" "$POOLD/${pair#*:}"
    if [ "$SWAPPED" = 1 ]; then POOL_CHANGED=1; fi
done

# /addr/ 專用暫存目錄（0700，www-data）：要先於 PHP 池與 nginx 生效，否則上傳無處可寫
install -d -o "$OWNER" -g "$OWNER" -m 0700 "$STATE/addr-tmp" "$STATE/addr-tmp/.upload" "$STATE/addr-tmp/.nginx-body" \
    || fail "建立 $STATE/addr-tmp 暫存目錄"

if [ "$POOL_CHANGED" = 1 ]; then
    php-fpm8.5 -t || fail "php-fpm8.5 -t"
    PHP_RELOAD=1
    systemctl reload php8.5-fpm || fail "systemctl reload php8.5-fpm"
    sleep "$PAUSE"
fi

NGX=$ETC/nginx/sites-available/it.ccra.tw
stage "$SRC/nginx-it.ccra.tw.conf" "$NGX"
if [ "$SWAPPED" = 1 ]; then
    nginx -t || fail "nginx -t"
    NGX_RELOAD=1
    systemctl reload nginx || fail "systemctl reload nginx"
fi

# 全部成功才丟掉備份
for f in $BACKED; do rm -f "$f.prev"; done
