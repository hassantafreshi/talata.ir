import { get } from '../lib/http.js';
import { busy, toast } from '../lib/ui.js';

const BADGE = { FRESH: ['ok', 'به‌روز'], STALE: ['warn', 'قدیمی'], ERROR: ['err', 'خطا'] };

export default function () {
  const badge = document.querySelector('[data-freshness-badge]');
  function render(b) {
    for (const [asset, row] of Object.entries(b.rows)) {
      const v = document.querySelector(`[data-asset="${asset}"]`);
      if (v) v.textContent = row.display_fa ?? '—';
      const c = document.querySelector(`[data-chg="${asset}"]`);
      if (c) c.textContent = row.change_fa ? `${row.direction > 0 ? '+ ' : row.direction < 0 ? '− ' : ''}${row.change_fa} از دریافت قبلی` : 'بدون تغییر';
    }
    document.querySelector('[data-spread]').textContent = b.spread_fa ?? '—';
    document.querySelector('[data-time]').textContent = b.fetched_at_fa ?? '—';
    const [kind, label] = navigator.onLine ? (BADGE[b.freshness] || ['off', '—']) : ['off', 'آفلاین'];
    badge.className = `badge ${kind}`; badge.textContent = label;
  }
  async function refresh(btn) {
    if (!navigator.onLine) { badge.className = 'badge off'; badge.textContent = 'آفلاین'; return; }
    if (btn) busy(btn);
    const res = await get('/api/quotes/board');
    if (btn) busy(btn, false);
    if (res.ok) render(res.data); else if (btn) toast(res.message, { kind: 'error' });
  }
  setInterval(() => { if (!document.hidden) refresh(); }, 180000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  window.addEventListener('offline', () => { badge.className = 'badge off'; badge.textContent = 'آفلاین'; });
  document.querySelector('[data-refresh]').addEventListener('click', (e) => refresh(e.currentTarget));
}
