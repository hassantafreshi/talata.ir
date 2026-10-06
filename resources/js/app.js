import '../css/app.css';
import { toast } from './lib/ui.js';
import { toLatin } from './lib/digits.js';

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

function digitNormalizer() {
  // Inputs marked data-digits accept Persian/Arabic digits; normalize on blur for the server.
  document.addEventListener('blur', (e) => {
    const t = e.target;
    if (t instanceof HTMLInputElement && t.hasAttribute('data-digits')) t.value = toLatin(t.value) ? t.value : t.value;
  }, true);
}

function confirmLinks() {
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) e.preventDefault();
  });
}

function flash() {
  document.querySelectorAll('[data-flash]').forEach((el) => toast(el.dataset.flash, { kind: el.dataset.kind || 'info' }));
}

document.addEventListener('DOMContentLoaded', async () => {
  offlineBanner();
  digitNormalizer();
  confirmLinks();
  // Date picker code loads only on pages that have a date field.
  if (document.querySelector('[data-jdp]')) import('./lib/datepicker.js').then((m) => m.initDatePickers());
  flash();
  const page = document.body.dataset.page;
  const loader = page && pages[`./pages/${page}.js`];
  if (loader) {
    try {
      const mod = await loader();
      mod.default?.(document.body);
    } catch (e) {
      toast('بخشی از برنامه دریافت نشد. اینترنت را بررسی و صفحه را دوباره باز کنید.', { kind: 'error', action: { label: 'تلاش دوباره', onClick: () => location.reload() } });
    }
  }
});
