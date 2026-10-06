import '../css/app.css';
import { toast } from './lib/ui.js';

// Lazy page modules: each page loads only its own code (weak-network budget).
const pages = import.meta.glob('./pages/*.js');

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

function flash() {
  document.querySelectorAll('[data-flash]').forEach((el) => toast(el.dataset.flash, { kind: el.dataset.kind || 'info' }));
}

document.addEventListener('DOMContentLoaded', async () => {
  offlineBanner();
  confirmLinks();
  // Date picker code loads only on pages that have a date field.
  if (document.querySelector('[data-jdp]')) import('./lib/datepicker.js').then((m) => m.initDatePickers());
  // The post-login fingerprint offer loads only on the page that actually shows the card.
  if (document.querySelector('[data-passkey-offer]')) import('./lib/passkey-offer.js').then((m) => m.default());
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
