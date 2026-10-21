#!/bin/sh
# deploy/apply_web_config.sh 的失敗路徑測試：用假的 php-fpm8.5／systemctl／nginx／logger／cp／install，
# 在暫存目錄裡模擬 /etc 與 /var/lib，確認任一步失敗都會：非零結束、不執行後續套用、
# 把這次換上的「池與 nginx」一起還原、已 reload 過的服務再 reload 回舊設定。
# 用法：sh tests/deploy_test.sh（在 repo 根目錄；不需要 root）
set -u
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
pass=0; fail=0
ok() { if [ "$1" = 0 ]; then pass=$((pass+1)); echo "  ✅ $2"; else fail=$((fail+1)); echo "  ❌ $2"; fi; }
REAL_CP=$(command -v cp); REAL_INSTALL=$(command -v install)

mkdir -p "$T/bin"
# 服務類假指令：把呼叫記進 calls.log；FAIL 指定哪一個要失敗（例 FAIL="fpm-t"）
for c in php-fpm8.5 systemctl nginx logger; do
cat > "$T/bin/$c" <<EOF
#!/bin/sh
echo "$c \$*" >> "$T/calls.log"
case "$c \$*" in
  "php-fpm8.5 -t") case " \$FAIL " in *" fpm-t "*) exit 1;; *" rollback-block "*) chmod 0555 "$T/etc/php/8.5/fpm/pool.d"; exit 1;; esac;;
  "systemctl reload php8.5-fpm") case " \$FAIL " in *" fpm-reload "*) exit 1;; esac;;
  "nginx -t") case " \$FAIL " in *" nginx-t "*) exit 1;; esac;;
  "systemctl reload nginx") case " \$FAIL " in *" nginx-reload "*) [ -e "$T/nginx-reloaded-once" ] || { : > "$T/nginx-reloaded-once"; exit 1; };; esac;;
esac
exit 0
EOF
chmod +x "$T/bin/$c"
done
# cp：備份（目的地是 .prev）在 FAIL 含 cp-backup 時先寫半截再失敗
cat > "$T/bin/cp" <<EOF
#!/bin/sh
for a in "\$@"; do last=\$a; done
case "\$last" in *.prev) case " \$FAIL " in *" cp-backup "*) echo partial > "\$last"; exit 1;; esac;; esac
exec "$REAL_CP" "\$@"
EOF
# install：裝池設定／nginx 設定時可指定失敗（先把目的地寫成半截，模擬裝到一半）；install -d 可指定失敗
cat > "$T/bin/install" <<EOF
#!/bin/sh
for a in "\$@"; do last=\$a; done
case " \$* " in *" -d "*) case " \$FAIL " in *" mkdir "*) exit 1;; esac;; esac
case "\$last" in
  */pool.d/*) case " \$FAIL " in *" install-pool "*) echo partial > "\$last"; exit 1;; esac;;
  */sites-available/*) case " \$FAIL " in *" install-nginx "*) echo partial > "\$last"; exit 1;; esac;;
esac
exec "$REAL_INSTALL" "\$@"
EOF
chmod +x "$T/bin/cp" "$T/bin/install"

POOL="$T/etc/php/8.5/fpm/pool.d"; NGXF="$T/etc/nginx/sites-available/it.ccra.tw"
setup() {   # 每個情境重建假的 /etc（舊設定）與新設定來源；$1=main 時主池也有變
    rm -rf "$T/etc" "$T/state" "$T/src" "$T/calls.log" "$T/nginx-reloaded-once"
    mkdir -p "$POOL" "$T/etc/nginx/sites-available" "$T/state" "$T/src"
    echo old-main > "$POOL/it-ccra.conf"
    echo old-ngx  > "$NGXF"
    if [ "${1:-}" = main ]; then echo new-main > "$T/src/it-ccra-fpm.conf"; else echo old-main > "$T/src/it-ccra-fpm.conf"; fi
    echo new-addr > "$T/src/it-ccra-addr-fpm.conf"         # 新增 /addr/ 池
    echo new-ngx  > "$T/src/nginx-it.ccra.tw.conf"         # nginx 有變
    : > "$T/calls.log"
}
run() {
    FAIL="$1" PATH="$T/bin:$PATH" WEB_ETC="$T/etc" WEB_STATE="$T/state" WEB_SRC="$T/src" WEB_OWNER="$(id -un)" WEB_SLEEP=0 \
        sh "$REPO/deploy/apply_web_config.sh" > "$T/out.log" 2>&1
    echo $?
}
has() { grep -qF "$1" "$T/calls.log"; }
cnt() { grep -cxF "$1" "$T/calls.log"; }
y() { if "$@"; then echo 0; else echo 1; fi; }
n() { if "$@"; then echo 1; else echo 0; fi; }   # 反向：條件不成立才算過
no_addr() { [ ! -e "$POOL/it-ccra-addr.conf" ]; }
main_old() { [ "$(cat "$POOL/it-ccra.conf")" = old-main ]; }
ngx_old() { [ "$(cat "$NGXF")" = old-ngx ]; }
no_prev() { [ -z "$(find "$T/etc" -name '*.prev')" ]; }
restored_msg() { grep -qF "rollback: 已還原" "$T/out.log"; }

echo "全部成功"
setup; rc=$(run "")
ok $(y [ "$rc" = 0 ]) "結束碼 0"
ok $(y has "systemctl reload nginx") "nginx 有 reload"
ok $(y [ "$(cat "$POOL/it-ccra-addr.conf")" = new-addr ]) "新池設定已裝"
ok $(y [ -d "$T/state/addr-tmp/.upload" ]) "暫存目錄建立"
ok $(y no_prev) "成功後不留 .prev"

echo "php-fpm8.5 -t 失敗（主池也有變）"
setup main; rc=$(run "fpm-t")
ok $(y [ "$rc" != 0 ]) "結束碼非 0"
ok $(n has "systemctl reload php8.5-fpm") "沒有 reload php-fpm"
ok $(n has "nginx") "沒有碰 nginx"
ok $(y no_addr) "新池設定已移除"
ok $(y main_old) "主池設定還原成舊的"
ok $(y ngx_old) "nginx 設定仍是舊的"
ok $(y no_prev) "不留 .prev"
ok $(y restored_msg) "記錄還原成功"

echo "reload php8.5-fpm 失敗"
setup main; rc=$(run "fpm-reload")
ok $(y [ "$rc" != 0 ]) "結束碼非 0"
ok $(n has "nginx") "沒有碰 nginx"
ok $(y no_addr) "新池設定已還原"
ok $(y main_old) "主池設定還原"
ok $(y [ "$(cnt 'systemctl reload php8.5-fpm')" = 2 ]) "還原後再 reload php-fpm 一次"

echo "nginx -t 失敗（PHP 已 reload 成功）"
setup main; rc=$(run "nginx-t")
ok $(y [ "$rc" != 0 ]) "結束碼非 0"
ok $(n has "systemctl reload nginx") "沒有 reload nginx"
ok $(y ngx_old) "nginx 設定還原成舊的"
ok $(y no_addr) "PHP 新池設定也還原"
ok $(y main_old) "PHP 主池設定也還原"
ok $(y [ "$(cnt 'systemctl reload php8.5-fpm')" = 2 ]) "PHP 再 reload 回舊設定"
ok $(y no_prev) "不留 .prev"

echo "reload nginx 失敗"
setup main; rc=$(run "nginx-reload")
ok $(y [ "$rc" != 0 ]) "結束碼非 0"
ok $(y ngx_old) "nginx 設定還原"
ok $(y no_addr) "PHP 新池設定還原"
ok $(y main_old) "PHP 主池設定還原"
ok $(y [ "$(cnt 'systemctl reload php8.5-fpm')" = 2 ]) "PHP 再 reload 一次"
ok $(y [ "$(cnt 'systemctl reload nginx')" = 2 ]) "nginx 還原後再 reload 一次"
ok $(y restored_msg) "記錄還原成功"

echo "備份 cp 失敗（主池有變，第一個要備份的就是它）"
setup main; rc=$(run "cp-backup")
ok $(y [ "$rc" != 0 ]) "結束碼非 0"
ok $(y main_old) "主池設定沒被動"
ok $(y no_addr) "沒有裝上新池"
ok $(n has "php-fpm8.5") "沒有跑 php-fpm -t"
ok $(n has "nginx") "沒有碰 nginx"
ok $(y no_prev) "半截的 .prev 已清掉"

echo "備份 cp 失敗（nginx 那一步；PHP 已 reload）"
setup; rc=$(run "cp-backup")
ok $(y [ "$rc" != 0 ]) "結束碼非 0"
ok $(y ngx_old) "nginx 設定沒被動"
ok $(y no_addr) "PHP 新池設定還原"
ok $(y [ "$(cnt 'systemctl reload php8.5-fpm')" = 2 ]) "PHP 再 reload 回舊設定"
ok $(n has "nginx -t") "沒有跑 nginx -t"

echo "池設定 install 失敗"
setup main; rc=$(run "install-pool")
ok $(y [ "$rc" != 0 ]) "結束碼非 0"
ok $(y main_old) "裝到一半的主池設定還原"
ok $(y no_addr) "沒有新池設定殘留"
ok $(n has "php-fpm8.5") "沒有跑 php-fpm -t"
ok $(n has "nginx") "沒有碰 nginx"
ok $(y no_prev) "不留 .prev"

echo "nginx install 失敗"
setup main; rc=$(run "install-nginx")
ok $(y [ "$rc" != 0 ]) "結束碼非 0"
ok $(y ngx_old) "裝到一半的 nginx 設定還原"
ok $(y main_old) "PHP 主池設定還原"
ok $(y no_addr) "PHP 新池設定還原"
ok $(y [ "$(cnt 'systemctl reload php8.5-fpm')" = 2 ]) "PHP 再 reload 回舊設定"
ok $(n has "nginx -t") "沒有跑 nginx -t"

echo "暫存目錄建立失敗"
setup main; rc=$(run "mkdir")
ok $(y [ "$rc" != 0 ]) "結束碼非 0"
ok $(y main_old) "主池設定還原"
ok $(y no_addr) "新池設定還原"
ok $(n has "php-fpm8.5") "沒有跑 php-fpm -t"

echo "還原本身失敗時要記下來"
setup main; rc=$(run "rollback-block")
ok $(y [ "$rc" != 0 ]) "結束碼非 0"
ok $(y grep -qF "rollback: 還原不完整" "$T/out.log") "記錄「還原不完整，需人工處理」"
ok $(n grep -qF "rollback: 已還原" "$T/out.log") "不會誤報還原成功"
chmod 0755 "$POOL"

echo "deploy.sh 串接"
ok $(y grep -q '^sh deploy/apply_web_config.sh || exit 1$' "$REPO/deploy/deploy.sh") "deploy.sh 失敗即 exit 1（在印 deployed 之前）"
ln=$(grep -n 'apply_web_config.sh' "$REPO/deploy/deploy.sh" | cut -d: -f1); ld=$(grep -n '^echo "deployed' "$REPO/deploy/deploy.sh" | cut -d: -f1)
ok $(y [ "$ln" -lt "$ld" ]) "套用步驟在 deployed 訊息之前"

echo "$((pass+fail)) 項，失敗 $fail"
[ "$fail" = 0 ]
