// 資訊服務 Service Worker：只處理戰情室推播（不做離線快取，頁面一律走網路）。
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  let m = {};
  try { m = e.data ? e.data.json() : {}; } catch (_) { m = { body: e.data ? e.data.text() : '' }; }
  const opt = {
    body: m.body || '',
    icon: '/img/icon-192.png',
    badge: '/img/favicon-32.png',
    data: { url: m.url || '/ops/' },
  };
  if (m.tag) { opt.tag = m.tag; opt.renotify = true; }
  e.waitUntil(self.registration.showNotification(m.title || '戰情室', opt));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = new URL(e.notification.data && e.notification.data.url || '/ops/', self.location.origin).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(ws => {
    for (const w of ws) if (w.url.startsWith(url) && 'focus' in w) return w.focus();
    return self.clients.openWindow(url);
  }));
});
