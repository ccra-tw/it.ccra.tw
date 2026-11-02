// 全站統一頁尾（熊哥 2026-10-06：「內頁的底也都要加上統一呈現」）。
// 首頁與每個內頁都只引用這一支，頁尾內容只在這裡改；版本號讀 /changelog/versions.json（唯一來源）。
(function () {
  function build() {
    if (document.querySelector('footer.site-foot')) return;
    var f = document.createElement('footer');
    f.className = 'site-foot';
    f.innerHTML =
      '<p class="motto"><span>當你感覺不到資訊部的存在，</span><span>才是資訊部<em>真正存在的價值</em>。</span><br>' +
      '<span>但別忘了，</span><span>每一個理所當然，</span><span>都是有人默默付出代價。</span></p>' +
      '<p class="sign">中華基督教救助協會　資訊部</p>' +
      '<p class="ver"><a href="/changelog/">資訊站 <span class="site-ver"></span>・版本紀錄</a></p>';
    (document.querySelector('main') || document.body).appendChild(f);
    fetch('/changelog/versions.json', {cache: 'no-cache'}).then(function (r) { return r.json(); }).then(function (v) {
      if (v && v[0]) f.querySelector('.site-ver').textContent = 'v' + v[0].version;
    }).catch(function () {});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build); else build();
})();
