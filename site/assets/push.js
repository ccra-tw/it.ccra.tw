// 戰情室「開啟通知」按鈕。頁面上要有 #push-box（裡面放 #push-btn、#push-test、#push-msg）。
(function () {
  const box = document.getElementById('push-box');
  if (!box) return;
  const btn = document.getElementById('push-btn'), test = document.getElementById('push-test'), msg = document.getElementById('push-msg');
  const say = t => { msg.textContent = t; };
  const ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  box.hidden = false;
  // 個人通知設定（熊哥 10-15）：要收哪幾類＋勿擾時段，存在帳號上，所有裝置一起套用
  const set = document.getElementById('push-set');
  if (set) (async () => {
    const cats = document.getElementById('push-cats'), qf = document.getElementById('push-qf'), qt = document.getElementById('push-qt');
    const save = document.getElementById('push-save'), smsg = document.getElementById('push-set-msg');
    const p = await fetch('/ops/push.php', { credentials: 'same-origin' }).then(r => r.json());
    cats.textContent = '';
    for (const [k, name] of Object.entries(p.cats || {})) {
      const lab = document.createElement('label'), cb = document.createElement('input');
      cb.type = 'checkbox'; cb.value = k; cb.checked = !(p.off || []).includes(k);
      if ((p.always || []).includes(k)) { cb.checked = true; cb.disabled = true; }
      lab.append(cb, name + ((p.always || []).includes(k) ? '（一定會收）' : ''));
      cats.append(lab);
    }
    qf.value = p.quiet_from || ''; qt.value = p.quiet_to || '';
    set.hidden = false;
    save.addEventListener('click', async () => {
      save.disabled = true;
      try {
        const off = [...cats.querySelectorAll('input:not(:checked):not(:disabled)')].map(c => c.value);
        const r = await fetch('/ops/push.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ op: 'prefs', off, quiet_from: qf.value, quiet_to: qt.value }) }).then(r => r.json());
        smsg.textContent = r.ok ? '已儲存，你所有裝置都會照這個設定。' : (r.error || '沒有存成功');
      } catch (e) { smsg.textContent = '沒有存成功：' + e.message; }
      finally { save.disabled = false; }
    });
  })().catch(() => {});
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    btn.hidden = true;
    say(ios && !standalone ? 'iPhone／iPad 要先用 Safari 的「分享 → 加入主畫面」，再從主畫面開戰情室，才能開啟通知。'
                           : '這個瀏覽器不支援網頁通知。');
    return;
  }
  const b64 = s => { const p = '='.repeat((4 - s.length % 4) % 4); const r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(r, c => c.charCodeAt(0)); };
  const api = (body) => fetch('/ops/push.php', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(r => r.json());

  let reg;
  async function refresh() {
    reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    const sub = await reg.pushManager.getSubscription();
    if (Notification.permission === 'denied') { btn.hidden = true; test.hidden = true; say('通知被瀏覽器封鎖了，要到網站設定改成「允許」。'); return; }
    btn.textContent = sub ? '關閉這台裝置的通知' : '開啟通知';
    btn.dataset.on = sub ? '1' : '';
    test.hidden = !sub;
    say(sub ? '這台裝置會依下面的通知設定，收到斷線、資安事件與新申請單的即時通知。' : '開啟後，有異常或新申請單時這台裝置會跳通知。');
  }
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      const cur = await reg.pushManager.getSubscription();
      if (cur) {
        await api({ op: 'unsub', endpoint: cur.endpoint });
        await cur.unsubscribe();
      } else {
        if (await Notification.requestPermission() !== 'granted') { say('沒有允許通知，所以沒開。'); return; }
        const { key } = await fetch('/ops/push.php', { credentials: 'same-origin' }).then(r => r.json());
        const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(key) });
        const r = await api({ op: 'sub', sub: sub.toJSON() });
        if (!r.ok) { await sub.unsubscribe(); say('登記失敗，請稍後再試。'); return; }
      }
      await refresh();
    } catch (e) { say('沒有成功：' + e.message); }
    finally { btn.disabled = false; }
  });
  test.addEventListener('click', async () => {
    test.disabled = true;
    try { const r = await api({ op: 'test' }); say(r.ok ? '已送出測試通知（' + r.ok + ' 台裝置），幾秒內會跳出來。' : '測試沒送出去，請關閉再開啟一次通知。'); }
    catch (e) { say('沒有成功：' + e.message); }
    finally { test.disabled = false; }
  });
  refresh().catch(e => say('通知功能載入失敗：' + e.message));
})();
