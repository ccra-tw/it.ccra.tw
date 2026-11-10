// 離線用：把網頁本身與地圖程式庫存在手機裡，沒訊號也能打開；路線檔存在 localStorage。
// 地圖圖磚只快取「看過的」那些（不預先大量下載，符合 OpenStreetMap 圖磚使用政策）。
const APP = 'tour-app-v1', TILES = 'tour-tiles-v1';
const SHELL = ['./', './index.html',
  'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css',
  'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'];
self.addEventListener('install', e => e.waitUntil(caches.open(APP).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())));
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => {
  const url = e.request.url;
  if (url.includes('tile.openstreetmap.org')) {
    // 圖磚：有網路就抓新的並順手存一份；沒網路就拿存過的
    e.respondWith(fetch(e.request).then(r => { const copy = r.clone(); caches.open(TILES).then(c => c.put(e.request, copy)); return r; })
      .catch(() => caches.match(e.request)));
    return;
  }
  // 網頁與程式庫：先用手機裡存的，沒有才上網
  e.respondWith(caches.match(e.request).then(hit => hit || fetch(e.request)));
});
