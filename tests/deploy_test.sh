#!/bin/sh
# deploy/apply_web_config.sh 的失敗路徑測試：用假的 php-fpm8.5／systemctl／nginx／logger，
# 在暫存目錄裡模擬 /etc 與 /var/lib，確認任一步失敗都會還原設定、非零結束、不碰後續步驟。
# 用法：sh tests/deploy_test.sh（在 repo 根目錄；不需要 root）
set -u
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
pass=0; fail=0
ok() { if [ "$1" = 0 ]; then pass=$((pass+1)); echo "  ✅ $2"; else fail=$((fail+1)); echo "  ❌ $2"; fi; }

mkdir -p "$T/bin"
# 假指令：把呼叫記進 calls.log；FAIL 環境變數指定哪一個要失敗（例 FAIL="fpm-t"）
for c in php-fpm8.5 systemctl nginx logger; do
cat > "$T/bin/$c" <<EOF
#!/bin/sh
echo "$c \$*" >> "$T/calls.log"
case "$c \$*" in
  "php-fpm8.5 -t") case " \$FAIL " in *" fpm-t "*) exit 1;; esac;;
  "systemctl reload php8.5-fpm") case " \$FAIL " in *" fpm-reload "*) exit 1;; esac;;
  "nginx -t") case " \$FAIL " in *" nginx-t "*) exit 1;; esac;;
  "systemctl reload nginx") case " \$FAIL " in *" nginx-reload "*) exit 1;; esac;;
esac
exit 0
EOF
chmod +x "$T/bin/$c"
done

setup() {   # 每個情境重建假的 /etc（舊設定）與新設定來源
    rm -rf "$T/etc" "$T/state" "$T/src" "$T/calls.log"
    mkdir -p "$T/etc/php/8.5/fpm/pool.d" "$T/etc/nginx/sites-available" "$T/state" "$T/src"
    echo old-main > "$T/etc/php/8.5/fpm/pool.d/it-ccra.conf"
    echo old-ngx  > "$T/etc/nginx/sites-available/it.ccra.tw"
    echo old-main > "$T/src/it-ccra-fpm.conf"              # 主池沒變
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

echo "全部成功"
setup; rc=$(run "")
ok $([ "$rc" = 0 ] && echo 0 || echo 1) "結束碼 0"
ok $(has "systemctl reload nginx" && echo 0 || echo 1) "nginx 有 reload"
ok $([ "$(cat "$T/etc/php/8.5/fpm/pool.d/it-ccra-addr.conf")" = new-addr ] && echo 0 || echo 1) "新池設定已裝"
ok $([ -d "$T/state/addr-tmp/.upload" ] && [ -d "$T/state/addr-tmp/.nginx-body" ] && echo 0 || echo 1) "暫存目錄建立"
ok $([ ! -e "$T/etc/nginx/sites-available/it.ccra.tw.prev" ] && echo 0 || echo 1) "成功後不留 .prev"

echo "php-fpm8.5 -t 失敗"
setup; rc=$(run "fpm-t")
ok $([ "$rc" != 0 ] && echo 0 || echo 1) "結束碼非 0"
ok $(has "systemctl reload php8.5-fpm" && echo 1 || echo 0) "沒有 reload php-fpm"
ok $(has "nginx" && echo 1 || echo 0) "沒有碰 nginx（-t 與 reload 都沒跑）"
ok $([ ! -e "$T/etc/php/8.5/fpm/pool.d/it-ccra-addr.conf" ] && echo 0 || echo 1) "新池設定已移除（還原成原本沒有）"
ok $([ "$(cat "$T/etc/nginx/sites-available/it.ccra.tw")" = old-ngx ] && echo 0 || echo 1) "nginx 設定仍是舊的"

echo "reload php8.5-fpm 失敗"
setup; rc=$(run "fpm-reload")
ok $([ "$rc" != 0 ] && echo 0 || echo 1) "結束碼非 0"
ok $(has "nginx" && echo 1 || echo 0) "沒有碰 nginx"
ok $([ ! -e "$T/etc/php/8.5/fpm/pool.d/it-ccra-addr.conf" ] && echo 0 || echo 1) "新池設定已還原"

echo "nginx -t 失敗"
setup; rc=$(run "nginx-t")
ok $([ "$rc" != 0 ] && echo 0 || echo 1) "結束碼非 0"
ok $(has "systemctl reload nginx" && echo 1 || echo 0) "沒有 reload nginx"
ok $([ "$(cat "$T/etc/nginx/sites-available/it.ccra.tw")" = old-ngx ] && echo 0 || echo 1) "nginx 設定還原成舊的"

echo "deploy.sh 串接"
ok $(grep -q '^sh deploy/apply_web_config.sh || exit 1$' "$REPO/deploy/deploy.sh" && echo 0 || echo 1) "deploy.sh 失敗即 exit 1（在印 deployed 之前）"
ln=$(grep -n 'apply_web_config.sh' "$REPO/deploy/deploy.sh" | cut -d: -f1); ld=$(grep -n '^echo "deployed' "$REPO/deploy/deploy.sh" | cut -d: -f1)
ok $([ "$ln" -lt "$ld" ] && echo 0 || echo 1) "套用步驟在 deployed 訊息之前"

echo "$((pass+fail)) 項，失敗 $fail"
[ "$fail" = 0 ]
