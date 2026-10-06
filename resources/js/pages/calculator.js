import { post, put } from '../lib/http.js';
import { busy, toast, markInvalid, clearInvalid } from '../lib/ui.js';
import { toLatin, toman, parseTomanToIrr } from '../lib/digits.js';
import { priceGold, PricingError } from '../lib/pricing.js';
import { showQuota } from '../lib/quota.js';

const ERR = { REQUIRED: 'این مورد را وارد کنید.', INVALID_NUMBER: 'عدد درست نیست.', MUST_BE_POSITIVE: 'باید بیشتر از صفر باشد.', OUT_OF_RANGE: 'عدد بیش از حد مجاز است.', DISCOUNT_EXCEEDS_ELIGIBLE: 'برای ادامه، مبلغ تخفیف را کمتر از مجموع اجرت و سود وارد کنید.' };
const FIELD = { net_weight_g: 'weight', purity_ppt: 'purity', price18_irr_per_g: 'rate', wage_percent: 'wage', profit_percent: 'profit', discount: 'discount' };

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const form = document.querySelector('[data-calc]');
  const total = document.querySelector('[data-total]');
  const state = document.querySelector('[data-state]');
  const toInvoice = document.querySelector('[data-to-invoice]');
  let marketIrr = boot.quote.value_irr;
  let last = null;

  function input() {
    const rateIrr = parseTomanToIrr(form.rate.value);
    const discountIrr = form.discount.value ? parseTomanToIrr(form.discount.value, true) : null;
    return {
      net_weight_g: toLatin(form.weight.value), purity_ppt: toLatin(form.purity.value), price18_irr_per_g: rateIrr ?? (form.rate.value ? 'x' : ''),
      wage_percent: toLatin(form.wage.value) || '0', profit_percent: toLatin(form.profit.value) || '0',
      discount: discountIrr && discountIrr !== '0' ? { scope: 'TAXABLE_COMPONENTS', amount_irr: discountIrr } : (form.discount.value && !discountIrr ? { scope: 'TAXABLE_COMPONENTS', amount_irr: 'x' } : null),
      vat_rate_percent: boot.vat,
    };
  }

  function compute() {
    clearInvalid(form);
    const inp = input();
    try {
      const r = priceGold(inp, boot.limits);
      last = { inp, r };
      total.textContent = toman(r.T);
      state.textContent = inp.price18_irr_per_g === marketIrr ? 'با نرخ مظنه' : 'با نرخ واردشده (دستی)';
      document.querySelector('[data-out="eff"]').textContent = toman(r.effective_rate_irr_per_g.split('.')[0]);
      for (const k of ['M', 'W', 'P', 'V']) document.querySelector(`[data-out="${k}"]`).textContent = toman(r[k]);
      if (toInvoice) toInvoice.disabled = false;
    } catch (e) {
      last = null;
      total.textContent = '—';
      for (const k of ['eff', 'M', 'W', 'P', 'V']) document.querySelector(`[data-out="${k}"]`).textContent = '—';
      if (toInvoice) toInvoice.disabled = true;
      if (!(e instanceof PricingError)) throw e;
      if (e.field === 'net_weight_g' && e.code === 'REQUIRED') { state.textContent = 'وزن را وارد کنید.'; return; }
      state.textContent = 'ورودی ناقص یا نادرست است.';
      const name = FIELD[e.field];
      const field = name && form.elements[name]?.closest('.field');
      if (field) markInvalid(field, ERR[e.code] || 'مقدار درست نیست.');
    }
  }

  form.addEventListener('input', compute);
  form.addEventListener('submit', (e) => e.preventDefault());
  // «پاک‌کردن»: back to an empty piece at today's market rate (nothing is stored anywhere).
  document.querySelector('[data-clear]')?.addEventListener('click', () => {
    form.reset();
    form.querySelectorAll('[data-p]').forEach((c) => c.setAttribute('aria-pressed', String(c.dataset.p === '750')));
    form.querySelector('[data-purity-custom]').classList.add('hidden');
    form.purity.value = '750';
    compute();
    form.weight.focus();
  });
  form.querySelector('[data-purity]').addEventListener('click', (e) => {
    const chip = e.target.closest('[data-p]');
    if (!chip) return;
    form.querySelectorAll('[data-p]').forEach((c) => c.setAttribute('aria-pressed', String(c === chip)));
    const custom = form.querySelector('[data-purity-custom]');
    if (chip.dataset.p === 'custom') { custom.classList.remove('hidden'); form.purity.focus(); }
    else { custom.classList.add('hidden'); form.purity.value = chip.dataset.p; }
    compute();
  });

  toInvoice?.addEventListener('click', async () => {
    if (!last) return;
    const { inp } = last;
    const manual = inp.price18_irr_per_g !== marketIrr;
    busy(toInvoice);
    const created = await post('/api/invoices/drafts', manual
      ? { mode: 'MANUAL', value_toman: toLatin(form.rate.value), reason: 'CUSTOMER_AGREEMENT' }
      : { mode: 'MARKET', value_irr: marketIrr });
    if (!created.ok) {
      busy(toInvoice, false);
      if (created.code === 'RATE_CHANGED') {
        marketIrr = created.data.latest.value_irr;
        form.rate.value = created.data.latest.value_toman_fa;
        document.querySelector('[data-rate-hint]').textContent = `از مظنه فروش · دریافت ${created.data.latest.fetched_at_fa}`;
        compute();
        toast('نرخ مظنه تازه شد و در ماشین‌حساب قرار گرفت. مبلغ را بررسی و دوباره بزنید.', { timeout: 8000 });
        return;
      }
      if (created.code?.startsWith('QUOTA_') || created.code?.startsWith('CAPABILITY_')) { showQuota(created); return; }
      toast(created.message, { kind: 'error' }); return;
    }
    const row = {
      row_uid: `c${Date.now().toString(36)}`, item_type: 'GOLD', name: 'طلای ۱۸ عیار', description: '',
      net_weight_g: inp.net_weight_g, purity_ppt: inp.purity_ppt, wage_percent: inp.wage_percent, profit_percent: inp.profit_percent,
      discount_toman: inp.discount ? toLatin(form.discount.value) : '', discount_scope: 'TAXABLE_COMPONENTS', manual_total_toman: '',
    };
    await put(`/api/invoices/drafts/${created.data.draft_id}`, { version: created.data.version, rows: [row], buyer: {}, use_latest_rate: false });
    location.href = created.data.next;
  });

  compute();
}
