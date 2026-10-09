import { get, post } from '../lib/http.js';
import { busy, sheet, toast, escapeHtml } from '../lib/ui.js';
import { parseTomanToIrr, toman } from '../lib/digits.js';
import { showQuota } from '../lib/quota.js';

export default function () {
  const hero = document.querySelector('[data-quote]');
  let quote = JSON.parse(hero.dataset.quote);
  const priceEl = hero.querySelector('[data-price]');
  const timeEl = hero.querySelector('[data-time]');
  const badge = hero.querySelector('[data-freshness-badge]');
  const errNote = hero.querySelector('[data-error-note]');
  const startBtn = hero.querySelector('[data-start]');
  let pollMs = Number(hero.dataset.poll || 180) * 1000;

  const renderQuote = (q) => {
    quote = q;
    if (q.poll_seconds) pollMs = q.poll_seconds * 1000;
    priceEl.textContent = q.value_toman_fa ?? '—';
    const startRate = hero.querySelector('[data-start-rate]');
    if (startRate) startRate.textContent = q.value_toman_fa ?? '—';
    timeEl.textContent = q.fetched_at_fa ?? '—';
    const em = hero.querySelector('[data-emergency]');
    if (em) em.hidden = !q.is_emergency;
    const src = hero.querySelector('[data-source]');
    if (src && q.source_fa) src.textContent = q.source_fa;
    const map = { FRESH: ['ok', 'به‌روز'], STALE: ['warn', 'قدیمی'], ERROR: ['err', 'خطا'] };
    const [kind, label] = navigator.onLine ? (map[q.freshness] || ['off', '—']) : ['off', 'آفلاین'];
    badge.className = `badge ${kind}`; badge.textContent = label;
    errNote.classList.toggle('hidden', q.freshness !== 'ERROR');
    if (startBtn) startBtn.disabled = !q.value_irr || !navigator.onLine;
    const hint = hero.querySelector('[data-start-hint]');
    if (hint) {
      hint.hidden = !!q.value_irr && navigator.onLine;
      hint.textContent = navigator.onLine ? 'نرخ بازار هنوز در دسترس نیست؛ پایین همین صفحه «ثبت نرخ دستی» یا «فاکتور فقط متفرقه» را بزنید.' : 'اینترنت قطع است؛ پس از اتصال، «ثبت فاکتور جدید» دوباره فعال می‌شود.';
    }
  };

  const refresh = async (btn) => {
    if (document.hidden || !navigator.onLine) return;
    if (btn) busy(btn);
    const res = await get('/api/quotes/latest');
    if (btn) busy(btn, false);
    if (res.ok) renderQuote(res.data); else if (btn) toast(res.message, { kind: 'error' });
  };
  hero.querySelector('[data-retry-quote]')?.addEventListener('click', (e) => refresh(e.currentTarget));
  const loop = () => setTimeout(async () => { await refresh(); loop(); }, pollMs);
  loop();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  // Back online: re-enable with the last known rate at once, then fetch (a failed fetch keeps it usable).
  window.addEventListener('online', () => { renderQuote(quote); refresh(); });
  window.addEventListener('offline', () => renderQuote(quote));

  async function start(payload, btn) {
    busy(btn);
    const res = await post('/api/invoices/drafts', payload);
    busy(btn, false);
    if (res.ok) { location.href = res.data.next; return; }
    if (res.code === 'RATE_CHANGED') { changedSheet(res.data.latest); return; }
    if (res.code?.startsWith('QUOTA_') || res.code?.startsWith('CAPABILITY_')) { showQuota(res); return; }
    toast(res.message, { kind: 'error' });
  }

  function changedSheet(latest) {
    const { sheet: el, close } = sheet(`
      <h2>نرخ تازه رسید؛ با کدام عدد ادامه دهیم؟</h2>
      <div class="grid-2">
        <div class="band"><span class="small muted">عددی که دیدید · ${escapeHtml(quote.fetched_at_fa)}</span><strong class="num">${escapeHtml(quote.value_toman_fa)}</strong></div>
        <div class="band em"><span class="small muted">نرخ تازه · ${escapeHtml(latest.fetched_at_fa)}</span><strong class="num">${escapeHtml(latest.value_toman_fa)}</strong></div>
      </div>
      <p class="hint">نرخ پذیرفته‌شده برای این معامله ثابت می‌ماند تا خودتان «استفاده از نرخ جدید» را بزنید.</p>
      <button class="btn btn-gold block" type="button" data-accept>ادامه با نرخ تازه (${escapeHtml(latest.value_toman_fa)})</button>
      <button class="btn btn-line block" type="button" data-close>برگشت و دوباره نگاه کنم</button>`, { label: 'نرخ تازه' });
    el.querySelector('[data-accept]').addEventListener('click', (e) => {
      renderQuote({ ...quote, value_irr: latest.value_irr, value_toman_fa: latest.value_toman_fa, fetched_at_fa: latest.fetched_at_fa });
      start({ mode: 'MARKET', value_irr: latest.value_irr }, e.currentTarget).then(close);
    });
  }

  renderQuote(quote);
  startBtn?.addEventListener('click', () => start({ mode: 'MARKET', value_irr: quote.value_irr }, startBtn));
  document.querySelector('[data-misc-only]')?.addEventListener('click', (e) => start({ mode: 'NONE' }, e.currentTarget));
  document.querySelector('[data-manual]')?.addEventListener('click', () => {
    const tpl = document.querySelector('[data-manual-tpl]');
    const { sheet: el } = sheet(tpl.innerHTML, { label: 'نرخ دستی' });
    const form = el.querySelector('[data-manual-form]');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const irr = parseTomanToIrr(form.value_toman.value);
      if (!irr) { form.value_toman.closest('.field').classList.add('invalid'); form.querySelector('.err').textContent = 'نرخ را درست وارد کنید.'; return; }
      start({ mode: 'MANUAL', value_toman: form.value_toman.value, reason: form.reason.value }, form.querySelector('[type=submit]'));
    });
  });
}
