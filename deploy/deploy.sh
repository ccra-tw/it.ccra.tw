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
rsync -a --delete site/ /var/www/it.ccra.tw/
# 靜態檔帶版本號（?v=提交雜湊），避免 Cloudflare／瀏覽器拿舊的 CSS（10-03 熊哥看到舊樣式）
V=$(git rev-parse --short HEAD)
grep -rlE '(site|form|whoami|edgetip|live|footer|push)\.(css|js)\?v=' /var/www/it.ccra.tw | xargs -r sed -i -E "s/(site|form|whoami|edgetip|live|footer|push)\.(css|js)\?v=[A-Za-z0-9]*/\1.\2?v=$V/g"
install -m 644 lib/*.php /var/www/it-lib/
# systemd 單元有變才重新載入
for u in it-ccra-deploy.service it-ccra-deploy.timer it-ccra-ccnda.service it-ccra-ccnda.timer it-ccra-push.service it-ccra-push.timer; do
    if ! cmp -s deploy/$u /etc/systemd/system/$u; then cp deploy/$u /etc/systemd/system/$u; RELOAD_UNITS=1; fi
done
if [ "${RELOAD_UNITS:-}" ]; then systemctl daemon-reload; systemctl enable --now it-ccra-ccnda.timer it-ccra-deploy.timer it-ccra-push.timer; fi
if ! cmp -s deploy/nginx-it.ccra.tw.conf /etc/nginx/sites-available/it.ccra.tw; then
    cp deploy/nginx-it.ccra.tw.conf /etc/nginx/sites-available/it.ccra.tw
    nginx -t && systemctl reload nginx
fi
logger -t it-ccra-deploy "deployed $(git rev-parse --short HEAD)"
echo "deployed $(git rev-parse --short HEAD)"
