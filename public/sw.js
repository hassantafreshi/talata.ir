/* Zarlio service worker — deliberately small (docs/PERFORMANCE_BUDGET.md, CLAUDE.md privacy rules).
 * Caches ONLY: hashed build assets (/build/assets/*), self-hosted fonts (/fonts/*), app icons and the static
 * offline page. Every page, API call, public invoice/verification link, payment, login and admin request
 * goes to the network and is never stored: shops share phones, and those responses carry private data. */
const VERSION = 'zarlio-v1';
const OFFLINE_URL = '/offline.html';

/* Pure routing decision, unit-tested in tests/js/sw.test.mjs. */
function routeFor(url, method, mode) {
  if (method !== 'GET' || url.origin !== self.location.origin) return 'network';
  const p = url.pathname;
  if (/^\/(api|admin|i|v|pay|login|logout)(\/|$)/.test(p)) return mode === 'navigate' ? 'network-offline' : 'network';
  if (/^\/build\/assets\/[^/]+$/.test(p) || /^\/fonts\/[^/]+\.woff2$/.test(p) || /^\/icons\/[^/]+\.png$/.test(p)) return 'cache-first';
  if (mode === 'navigate') return 'network-offline';
  return 'network';
}

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((c) => c.add(new Request(OFFLINE_URL, { cache: 'reload' }))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  // Old versions are dropped; assets are content-hashed, so a new deploy never serves a stale bundle.
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const route = routeFor(new URL(req.url), req.method, req.mode);
  if (route === 'cache-first') {
    event.respondWith(caches.open(VERSION).then(async (c) => {
      const hit = await c.match(req);
      if (hit) return hit;
      const res = await fetch(req);
      if (res.ok && res.type === 'basic') c.put(req, res.clone());
      return res;
    }));
  } else if (route === 'network-offline') {
    // Pages are never cached; only when the network is gone do we show the static offline page.
    event.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
  }
  // 'network': let the browser handle it normally (nothing stored).
});
