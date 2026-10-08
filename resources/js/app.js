import navFeedback from './lib/nav-feedback.js';
import '../css/app.css';
import { toast } from './lib/ui.js';
// Tiny helpers almost every page uses (~2.5 KiB gzip together) ride in the entry chunk: on an 800 ms RTT
// link each separate chunk costs a round trip (docs/PERFORMANCE_BUDGET.md: login <= 8 requests).
import './lib/http.js';
import './lib/digits.js';
import './lib/otp.js';

// Lazy page modules: each page loads only its own code (weak-network budget).
const pages = import.meta.glob('./pages/*.js');

// Capture the Android/Chromium install prompt as early as possible: it can fire before the (lazily loaded)
// install-prompt banner attaches its own listener, so stash it for the banner to use.
window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); window.__bip = e; });

function offlineBanner() {
  const el = document.querySelector('[data-offline-banner]');
  if (!el) return;
  const sync = () => { el.hidden = navigator.onLine; };
  window.addEventListener('online', () => { sync(); toast('اتصال برقرار شد.'); });
  window.addEventListener('offline', sync);
  sync();
}

function confirmLinks() {
  document.addEventListener('submit', (e) => {
    const form = e.target;
    // AJAX admin action forms (data-action) ask themselves, with the placeholders filled in.
    if (form.dataset.confirm && !form.dataset.action && !window.confirm(form.dataset.confirm)) e.preventDefault();
  });
}

// Leaving the account: remove draft copies kept on this device (shared phones in a shop).
function clearDeviceCopiesOnLogout() {
  document.addEventListener('submit', (e) => {
    if (!/\/logout(\?|$)/.test(e.target.getAttribute('action') || '')) return;
    try {
      Object.keys(localStorage).filter((k) => k.startsWith('draft:')).forEach((k) => localStorage.removeItem(k));
      Object.keys(sessionStorage).filter((k) => k.startsWith('review-buyer:')).forEach((k) => sessionStorage.removeItem(k));
    } catch {}
  });
}

// Installable app + offline page. The worker caches only hashed assets, fonts, icons and the static
// offline page — never pages, API answers or invoice links (public/sw.js).
function registerWorker() {
  if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
  window.addEventListener('load', () => { navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {}); });
}

function flash() {
  document.querySelectorAll('[data-flash]').forEach((el) => toast(el.dataset.flash, { kind: el.dataset.kind || 'info' }));
}

document.addEventListener('DOMContentLoaded', async () => {
  navFeedback(toast);
  offlineBanner();
  confirmLinks();
  clearDeviceCopiesOnLogout();
  registerWorker();
  // Date picker code loads only on pages that have a date field.
  if (document.querySelector('[data-jdp]')) import('./lib/datepicker.js').then((m) => m.initDatePickers());
  // The post-login fingerprint offer loads only on the page that actually shows the card.
  if (document.querySelector('[data-passkey-offer]')) import('./lib/passkey-offer.js').then((m) => m.default());
  // «Add to home screen» hint, once the merchant is inside the app (logged-in shell only).
  if (document.querySelector('[data-install-prompt]')) import('./lib/install-prompt.js').then((m) => m.default());
  flash();
  const page = document.body.dataset.page;
  const loader = page && pages[`./pages/${page}.js`];
  if (loader) {
    try {
      const mod = await loader();
      mod.default?.(document.body);
    } catch (e) {
      // Stays until used: without its module the page's buttons do nothing.
      toast('بخشی از برنامه دریافت نشد. اینترنت را بررسی و صفحه را دوباره باز کنید.', { kind: 'error', timeout: 3600000, action: { label: 'تلاش دوباره', onClick: () => location.reload() } });
    }
  }
});
