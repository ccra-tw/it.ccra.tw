#!/bin/sh
# GitHub 發布：main 分支有新提交就部署（由 it-ccra-deploy.timer 每 2 分鐘觸發）。
# 採「主機自己拉」而不是 GitHub Actions 推：主機在內網、沒有對外入口；
# 而公開程式庫不宜掛 self-hosted runner（別人的 PR 可能在主機上跑程式）。
set -eu
REPO=/opt/it.ccra.tw
cd "$REPO"
git fetch -q origin main
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] && [ "${1:-}" != "--force" ] && exit 0
git reset -q --hard origin/main
rsync -a --delete --exclude=/receipt-test site/ /var/www/it.ccra.tw/
# 收據系統網頁測試環境：同一套程式，nginx 在這個路徑改用 receipt_test 資料（見 nginx 設定與 lib/db.php）
# 2026-11-19：receipt-test 若變成真的資料夾（11/18 起被一份舊程式佔住），ln -sfn 只會在資料夾裡面再建一個連結、測試頁停在舊版而沒有任何錯誤
# → 先把真資料夾移到網站目錄外留存，再建連結。
if [ -d /var/www/it.ccra.tw/receipt-test ] && [ ! -L /var/www/it.ccra.tw/receipt-test ]; then
    mv /var/www/it.ccra.tw/receipt-test /var/www/receipt-test.stale-$(date +%Y%m%d-%H%M%S)
    logger -t it-ccra-deploy "receipt-test 是真資料夾，已移開改回連結"
fi
ln -sfn receipt /var/www/it.ccra.tw/receipt-test
# 靜態檔帶版本號（?v=提交雜湊），避免 Cloudflare／瀏覽器拿舊的 CSS（10-03 熊哥看到舊樣式）
V=$(git rev-parse --short HEAD)
grep -rlE '(site|form|whoami|edgetip|live|footer|push|receipt)\.(css|js)\?v=' /var/www/it.ccra.tw | xargs -r sed -i -E "s/(site|form|whoami|edgetip|live|footer|push|receipt)\.(css|js)\?v=[A-Za-z0-9]*/\1.\2?v=$V/g"
install -m 644 lib/*.php /var/www/it-lib/
install -m 644 lib/*.py /var/www/it-lib/   # receipt_read.py 等由 PHP 呼叫的 Python（#21）
# systemd 單元有變才重新載入
for u in it-ccra-deploy.service it-ccra-deploy.timer it-ccra-ccnda.service it-ccra-ccnda.timer it-ccra-push.service it-ccra-push.timer it-ccra-addr-sweep.service it-ccra-addr-sweep.timer; do
    if ! cmp -s deploy/$u /etc/systemd/system/$u; then cp deploy/$u /etc/systemd/system/$u; RELOAD_UNITS=1; fi
done
if [ "${RELOAD_UNITS:-}" ]; then systemctl daemon-reload; systemctl enable --now it-ccra-ccnda.timer it-ccra-deploy.timer it-ccra-push.timer it-ccra-addr-sweep.timer; fi
# PHP 程序池、/addr/ 暫存目錄、nginx：任何一步失敗就還原並以非零結束（不會走到下面印 deployed）
sh deploy/apply_web_config.sh || exit 1
logger -t it-ccra-deploy "deployed $(git rev-parse --short HEAD)"
echo "deployed $(git rev-parse --short HEAD)"
