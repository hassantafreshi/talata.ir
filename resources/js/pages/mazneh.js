import { get } from '../lib/http.js';
import { busy, toast } from '../lib/ui.js';
import { startClock } from '../lib/quote-clock.js';

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
    const em = document.querySelector('[data-emergency]');
    if (em) em.hidden = !b.rows.GOLD_18_SELL?.is_emergency;
    const spread = document.querySelector('[data-spread]');
    if (spread) spread.textContent = b.spread_fa ?? '—';
    const buyRow = document.querySelector('[data-buy-row]');
    if (buyRow) buyRow.hidden = !b.rows.GOLD_18_BUY?.display_fa;
    document.querySelector('[data-time]').textContent = b.fetched_at_fa ?? '—';
    const [kind, label] = navigator.onLine ? (BADGE[b.freshness] || ['off', '—']) : ['off', 'آفلاین'];
    badge.className = `badge ${kind}`; badge.textContent = label;
  }
  const clock = startClock();
  async function refresh(btn) {
    if (!navigator.onLine) { badge.className = 'badge off'; badge.textContent = 'آفلاین'; return; }
    if (btn) busy(btn);
    const res = await get('/api/quotes/board');
    if (btn) busy(btn, false);
    if (res.ok) { clock.set(res.data.clock); render(res.data); } else if (btn) toast(res.message, { kind: 'error' });
  }
  const loop = () => setTimeout(async () => { if (!document.hidden) await refresh(); loop(); }, clock.delayMs());
  loop();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  window.addEventListener('offline', () => { badge.className = 'badge off'; badge.textContent = 'آفلاین'; });
  window.addEventListener('online', () => refresh());
  document.querySelector('[data-refresh]').addEventListener('click', (e) => refresh(e.currentTarget));
}
