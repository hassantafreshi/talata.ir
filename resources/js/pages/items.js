import { put, del } from '../lib/http.js';
import { toast } from '../lib/ui.js';
import { toLatin, toman, toPersian, parseTomanToIrr } from '../lib/digits.js';
import { priceGold, priceManual, PricingError } from '../lib/pricing.js';

const ERR = {
  REQUIRED: 'این مورد را وارد کنید.', INVALID_NUMBER: 'عدد درست نیست.', MUST_BE_POSITIVE: 'باید بیشتر از صفر باشد.',
  OUT_OF_RANGE: 'عدد بیش از حد مجاز است.', DISCOUNT_EXCEEDS_ELIGIBLE: 'برای ادامه، مبلغ تخفیف را کمتر از مجموع اجرت و سود وارد کنید.',
};
const FIELD = { net_weight_g: 'net_weight_g', purity_ppt: 'purity_ppt', wage_percent: 'wage_percent', profit_percent: 'profit_percent', discount: 'discount_toman', manual_total_irr: 'manual_total_toman', price18_irr_per_g: 'net_weight_g' };
const uid = () => (Date.now().toString(36) + Math.random().toString(36).slice(2, 12)).slice(0, 20);

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  let version = boot.version;
  let rate = boot.rate_irr;
  const host = document.querySelector('[data-rows]');
  const tpl = document.querySelector('[data-row-tpl]');
  const saveState = document.querySelector('[data-save-state]');
  const payableEl = document.querySelector('[data-payable]');
  const countEl = document.querySelector('[data-row-count]');
  const reviewBtn = document.querySelector('[data-review]');
  let rows = boot.rows.length ? boot.rows : [newRow(boot.rate_mode === 'NONE' ? 'MISC' : 'GOLD')];
  let timer = null, saving = false, dirty = false, useLatest = false;

  function newRow(type) {
    return { row_uid: uid(), item_type: type, name: type === 'GOLD' ? 'طلای ۱۸ عیار' : '', description: '', net_weight_g: '', purity_ppt: '750', wage_percent: '0', profit_percent: '0', discount_toman: '', discount_scope: 'TAXABLE_COMPONENTS', manual_total_toman: '' };
  }

  function render() {
    host.innerHTML = '';
    rows.forEach((row, i) => host.appendChild(renderRow(row, i)));
    countEl.textContent = toPersian(rows.length);
    preview();
  }

  function renderRow(row, i) {
    const el = tpl.content.firstElementChild.cloneNode(true);
    el.dataset.uid = row.row_uid;
    el.querySelector('[data-row-no]').textContent = toPersian(i + 1);
    const radios = el.querySelectorAll('[data-f="item_type"]');
    radios.forEach((r) => { r.name = `type-${row.row_uid}`; r.checked = r.value === row.item_type; if (boot.rate_mode === 'NONE' && r.value === 'GOLD') r.closest('label').hidden = true; });
    el.querySelector('[data-gold]').hidden = row.item_type !== 'GOLD';
    el.querySelector('[data-misc]').hidden = row.item_type !== 'MISC';
    const scope = row.item_type === 'GOLD' ? el.querySelector('[data-gold]') : el.querySelector('[data-misc]');
    el.querySelectorAll('input[data-f]').forEach((inp) => {
      const f = inp.dataset.f;
      if (f === 'item_type') return;
      if (f === 'name' && !scope.contains(inp)) { inp.disabled = true; return; }
      inp.value = row[f] ?? '';
      inp.id = `${f}-${row.row_uid}`;
      const label = inp.closest('.field')?.querySelector('label');
      if (label) label.htmlFor = inp.id;
    });
    const preset = ['750', '875', '1000'].includes(String(row.purity_ppt));
    el.querySelectorAll('[data-p]').forEach((c) => c.setAttribute('aria-pressed', String(preset ? c.dataset.p === String(row.purity_ppt) : c.dataset.p === 'custom')));
    el.querySelector('[data-purity-custom]').classList.toggle('hidden', preset);
    return el;
  }

  function rowOf(el) { return rows.find((r) => r.row_uid === el.closest('[data-row]').dataset.uid); }

  host.addEventListener('input', (e) => {
    const f = e.target.dataset.f;
    if (!f || f === 'item_type') return;
    rowOf(e.target)[f] = e.target.value;
    e.target.closest('.field')?.classList.remove('invalid');
    changed();
  });
  host.addEventListener('change', (e) => {
    if (e.target.dataset.f !== 'item_type') return;
    const row = rowOf(e.target);
    row.item_type = e.target.value;
    if (row.item_type === 'MISC' && row.name === 'طلای ۱۸ عیار') row.name = '';
    if (row.item_type === 'GOLD' && !row.name) row.name = 'طلای ۱۸ عیار';
    render(); changed();
  });
  host.addEventListener('click', (e) => {
    const chip = e.target.closest('[data-p]');
    if (chip) {
      const row = rowOf(chip);
      const card = chip.closest('[data-row]');
      if (chip.dataset.p === 'custom') { card.querySelector('[data-purity-custom]').classList.remove('hidden'); card.querySelector('[data-f="purity_ppt"]').focus(); }
      else { row.purity_ppt = chip.dataset.p; card.querySelector('[data-f="purity_ppt"]').value = chip.dataset.p; card.querySelector('[data-purity-custom]').classList.add('hidden'); }
      card.querySelectorAll('[data-p]').forEach((c) => c.setAttribute('aria-pressed', String(c === chip)));
      changed();
      return;
    }
    const rm = e.target.closest('[data-remove]');
    if (rm) {
      const idx = rows.findIndex((r) => r.row_uid === rm.closest('[data-row]').dataset.uid);
      const [removed] = rows.splice(idx, 1);
      render(); changed();
      toast('ردیف حذف شد.', { action: { label: 'برگرداندن', onClick: () => { rows.splice(idx, 0, removed); render(); changed(); } }, timeout: 7000 });
    }
  });

  document.querySelector('[data-add-row]').addEventListener('click', () => {
    rows.push(newRow(boot.rate_mode === 'NONE' ? 'MISC' : 'GOLD'));
    render(); changed();
    const last = host.lastElementChild;
    last.scrollIntoView({ behavior: 'smooth', block: 'center' });
    last.querySelector(rows.at(-1).item_type === 'GOLD' ? '[data-f="net_weight_g"]' : '[data-misc] [data-f="name"]')?.focus();
  });

  function localPrice(row) {
    const w = toLatin(row.net_weight_g);
    if (row.item_type === 'GOLD') {
      const discountIrr = row.discount_toman ? parseTomanToIrr(row.discount_toman, true) : null;
      return priceGold({ net_weight_g: w, purity_ppt: toLatin(row.purity_ppt), price18_irr_per_g: rate, wage_percent: toLatin(row.wage_percent) || '0', profit_percent: toLatin(row.profit_percent) || '0', discount: discountIrr && discountIrr !== '0' ? { scope: row.discount_scope || 'TAXABLE_COMPONENTS', amount_irr: discountIrr } : null, vat_rate_percent: boot.vat }, boot.limits);
    }
    if (!row.name?.trim()) throw new PricingError('REQUIRED', 'name');
    return priceManual({ manual_total_irr: parseTomanToIrr(row.manual_total_toman) ?? 'x' }, boot.limits);
  }

  function preview(serverRows = null) {
    let total = 0n, valid = rows.length > 0;
    rows.forEach((row) => {
      const card = host.querySelector(`[data-row][data-uid="${row.row_uid}"]`);
      if (!card) return;
      const totalEl = card.querySelector('[data-row-total]');
      const bd = card.querySelector('[data-breakdown]');
      const eff = card.querySelector('[data-eff]');
      card.querySelectorAll('.field.invalid').forEach((f) => f.classList.remove('invalid'));
      try {
        const r = localPrice(row);
        total += BigInt(r.T);
        totalEl.textContent = `${toman(r.T)} تومان`;
        if (row.item_type === 'GOLD') {
          bd.textContent = `ارزش طلا ${toman(r.M)} · اجرت ${toman(r.W)} · سود ${toman(r.P)} · مالیات ${toman(r.V)}`;
          eff.textContent = `نرخ این عیار: ${toman(String(BigInt(r.effective_rate_irr_per_g.split('.')[0])))} تومان/گرم`;
        } else { bd.textContent = ''; }
      } catch (e) {
        valid = false;
        totalEl.textContent = '—';
        bd.textContent = '';
        const shown = (row.item_type === 'GOLD' ? (row.net_weight_g !== '') : (row.manual_total_toman !== '' || row.name !== '')) || serverRows;
        if (e instanceof PricingError && shown) {
          const field = FIELD[e.field] || e.field;
          const scope = row.item_type === 'GOLD' ? card.querySelector('[data-gold]') : card.querySelector('[data-misc]');
          const inp = scope.querySelector(`[data-f="${field}"]`) || card.querySelector(`[data-f="${field}"]`);
          const fieldEl = inp?.closest('.field');
          if (fieldEl) { fieldEl.classList.add('invalid'); fieldEl.querySelector('.err').textContent = ERR[e.code] || 'مقدار درست نیست.'; }
        }
      }
    });
    payableEl.textContent = valid ? `${toman(total.toString())} تومان` : '—';
    reviewBtn.setAttribute('aria-disabled', String(!valid));
  }

  function changed() {
    preview();
    dirty = true;
    saveState.textContent = 'در حال ذخیره…';
    clearTimeout(timer);
    timer = setTimeout(save, 800);
  }

  async function save() {
    if (saving) { timer = setTimeout(save, 400); return; }
    saving = true; dirty = false;
    const payload = { version, rows: rows.map((r) => ({ ...r })), buyer: boot.buyer, use_latest_rate: useLatest };
    const res = await put(`/api/invoices/drafts/${boot.id}`, payload);
    saving = false;
    if (res.ok) {
      version = res.data.version;
      if (useLatest) { useLatest = false; }
      saveState.textContent = `پیش‌نویس ذخیره شد · ${new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit' }).format(new Date())}`;
      try { localStorage.removeItem(`draft:${boot.id}`); } catch {}
      if (dirty) changed();
      return;
    }
    if (res.code === 'DRAFT_CONFLICT') { saveState.textContent = 'این پیش‌نویس در جای دیگری تغییر کرد.'; toast(res.message, { kind: 'error', action: { label: 'بارگذاری دوباره', onClick: () => location.reload() } }); return; }
    try { localStorage.setItem(`draft:${boot.id}`, JSON.stringify(payload)); } catch {}
    saveState.textContent = res.code === 'OFFLINE' ? 'آفلاین؛ روی همین دستگاه نگه داشته شد' : 'ذخیره نشد؛ دوباره تلاش می‌کنیم';
    timer = setTimeout(save, 5000);
  }

  reviewBtn.addEventListener('click', async (e) => {
    e.preventDefault();
    if (reviewBtn.getAttribute('aria-disabled') === 'true') { preview(true); toast('بعضی ردیف‌ها کامل یا درست نیستند.', { kind: 'error' }); host.querySelector('.field.invalid input')?.focus(); return; }
    clearTimeout(timer);
    if (dirty || saving) await save();
    location.href = boot.review_url;
  });

  document.querySelector('[data-delete-draft]').addEventListener('click', async () => {
    if (!confirm('این پیش‌نویس حذف شود؟')) return;
    const res = await del(`/api/invoices/drafts/${boot.id}`);
    if (res.ok) location.href = res.data.next; else toast(res.message, { kind: 'error' });
  });

  // New market rate notice (never applied silently).
  const newRate = document.querySelector('[data-new-rate]');
  if (boot.rate_mode === 'MARKET' && boot.latest_irr && boot.latest_irr !== rate) {
    newRate.classList.remove('hidden');
    newRate.querySelector('[data-new-rate-value]').textContent = boot.latest_fa;
  }
  document.querySelector('[data-use-new-rate]')?.addEventListener('click', () => {
    rate = boot.latest_irr; useLatest = true;
    document.querySelector('[data-rate]').textContent = boot.latest_fa;
    newRate.classList.add('hidden');
    changed();
  });

  window.addEventListener('beforeunload', (e) => { if (dirty || saving) { e.preventDefault(); } });
  render();
}
