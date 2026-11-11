// 離線用：把網頁本身與地圖程式庫存在手機裡，沒訊號也能打開；路線檔存在 localStorage。
// 地圖圖磚只快取「看過的」那些（不預先大量下載，符合 OpenStreetMap 圖磚使用政策）。
// 網頁有改版就把 APP 的版本號加一，手機才會換掉舊的網頁快取（圖磚快取保留）
const APP = 'tourtest-app-v7', TILES = 'tourtest-tiles-v1';
const SHELL = ['./', './index.html',
  'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css',
  'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'];
self.addEventListener('install', e => e.waitUntil(caches.open(APP).then(c => c.addAll(SHELL.map(u => new Request(u, {cache: 'reload'})))).then(() => self.skipWaiting())));
self.addEventListener('activate', e => e.waitUntil(
  // 只刪本程式自己的舊網頁快取（tour-app-*），同網站其他程式的快取不動
  caches.keys().then(ks => Promise.all(ks.filter(k => k.startsWith('tourtest-app-') && k !== APP).map(k => caches.delete(k)))).then(() => self.clients.claim())));
self.addEventListener('fetch', e => {
  const url = e.request.url;
  if (url.includes('tile.openstreetmap.org')) {
    // 圖磚：有網路就抓新的並順手存一份；沒網路就拿存過的
    e.respondWith(fetch(e.request).then(r => { const copy = r.clone(); caches.open(TILES).then(c => c.put(e.request, copy)); return r; })
      .catch(() => caches.match(e.request)));
    return;
  }
  // Google 地圖與金鑰（key.php）一律走網路、不快取：Google 條款不允許快取，金鑰也不該留在手機
  if (url.includes('googleapis.com') || url.includes('gstatic.com') || url.includes('/key.php')) return;
  // 網頁本身（大沈第十三輪）：有網路先抓新的並更新快取，沒網路才用存的——改版後不會被舊快取卡在有問題的舊頁面
  const u = new URL(url);
  if (e.request.mode === 'navigate' || (u.origin === location.origin && /\/(index\.html)?$/.test(u.pathname))) {
    const cached = () => caches.match(e.request).then(hit => hit || caches.match('./')).then(hit => hit || caches.match('./index.html'));
    const net = fetch(e.request, {cache: 'no-store'}).then(r => {
      if (r.ok) { const copy = r.clone(); caches.open(APP).then(c => c.put(e.request.mode === 'navigate' ? './' : e.request, copy)); }
      return r;
    });
    // 訊號弱時不要卡住：4 秒內網路沒回應就先給手機裡存的（新版下次開啟時就會換上）；手機裡沒有才繼續等網路
    const slow = new Promise(ok => setTimeout(ok, 4000)).then(cached).then(hit => hit || net);
    e.respondWith(Promise.race([net.catch(() => cached()), slow]).then(r => r || net));
    return;
  }
  // 程式庫（Leaflet 等，網址帶版本號不會變）：先用手機裡存的，沒有才上網
  e.respondWith(caches.match(e.request).then(hit => hit || fetch(e.request)));
});
