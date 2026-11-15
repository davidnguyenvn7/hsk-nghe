/*
 * HSK Nghe — service worker (được phục vụ qua /?hskn=sw, PHP chèn HSKN_SW ở dòng đầu).
 *
 * Chỉ can thiệp:
 *   - trang app (/?hskn=app)            mạng trước, mất mạng thì dùng bản đã lưu
 *   - JS/CSS/icon của plugin             dùng bản đã lưu, cập nhật ngầm
 *   - REST công khai hskn/v1 (GET)       mạng trước, mất mạng thì dùng bản đã lưu (danh sách bài, playlist đã nghe)
 * Không đụng: wp-admin, đăng nhập, sổ tay (me/*), phân tích câu, file MP3 (trình duyệt tự xử lý Range).
 */
/* global HSKN_SW */
const CACHE = 'hskn-' + HSKN_SW.VERSION;

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll([HSKN_SW.APP]).catch(() => {})).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => k.startsWith('hskn-') && k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

function isPublicRest(url) {
  const route = url.searchParams.get('rest_route');
  const path = HSKN_SW.REST && url.pathname.startsWith(HSKN_SW.REST) ? url.pathname.slice(HSKN_SW.REST.length)
    : (route && route.startsWith('/hskn/v1/') ? route.slice(9) : null);
  return path !== null && /^(tracks|topics)/.test(path);
}

async function networkFirst(req, key) {
  const cache = await caches.open(CACHE);
  try {
    const res = await fetch(req);
    if (res.ok) cache.put(key || req, res.clone());
    return res;
  } catch (err) {
    const hit = await cache.match(key || req);
    if (hit) return hit;
    throw err;
  }
}

async function staleWhileRevalidate(req) {
  const cache = await caches.open(CACHE);
  const hit = await cache.match(req);
  const net = fetch(req).then((res) => { if (res.ok) cache.put(req, res.clone()); return res; }).catch(() => hit);
  return hit || net;
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (/\/wp-(admin|login)/.test(url.pathname)) return;

  if (req.mode === 'navigate') {
    if (url.searchParams.get('hskn') === 'app') {
      // ?view=… vẫn dùng chung một bản lưu của trang app
      e.respondWith(networkFirst(req, HSKN_SW.APP).catch(() => new Response(
        '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>HSK Nghe</title>'
        + '<p style="font:16px system-ui;padding:24px">Bạn đang offline. Kết nối mạng rồi mở lại app nhé.</p>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
    }
    return;
  }
  if (url.pathname.startsWith(HSKN_SW.ASSETS) && !/\.mp3$/.test(url.pathname)) {
    e.respondWith(staleWhileRevalidate(req));
    return;
  }
  if (isPublicRest(url)) e.respondWith(networkFirst(req));
});
