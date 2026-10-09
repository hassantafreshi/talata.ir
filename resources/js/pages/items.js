import { put, del } from '../lib/http.js';
import { toast, markInvalid, clearInvalid } from '../lib/ui.js';
import { toLatin, toman, toPersian, parseTomanToIrr } from '../lib/digits.js';
import productSuggest from '../lib/product-suggest.js';
import { priceGold, priceGoldIn, priceGoldInWeight, priceManual, weight750, PricingError } from '../lib/pricing.js';

const ERR = {
  REQUIRED: 'این مورد را وارد کنید.', INVALID_NUMBER: 'عدد درست نیست.', MUST_BE_POSITIVE: 'باید بیشتر از صفر باشد.',
  OUT_OF_RANGE: 'عدد بیش از حد مجاز است.', DISCOUNT_EXCEEDS_ELIGIBLE: 'برای ادامه، مبلغ تخفیف را کمتر از مجموع اجرت و سود وارد کنید.',
  NO_BUY_RATE: 'نرخ خرید بازار در دسترس نیست. «نرخ دستی» را انتخاب و وارد کنید.',
};
// Gold received from the customer: per-kind defaults (coins are usually 900).
const KIND_NAME = { OLD_GOLD: 'طلای کهنه', COIN: 'سکه', MELTED: 'طلای آب‌شده', OTHER: 'طلای دریافتی' };
const FIELD = { rate_irr_per_g: 'rate_toman', deduction_percent: 'deduction_percent', net_weight_g: 'net_weight_g', purity_ppt: 'purity_ppt', wage_percent: 'wage_percent', profit_percent: 'profit_percent', discount: 'discount_toman', manual_total_irr: 'manual_total_toman', price18_irr_per_g: 'net_weight_g' };
// «روش تسویه»: only «با طلا» changes the calculation; cheque and instalment are recorded on the invoice.
const SETTLE_HINT = {
  MONEY: 'ارزش طلا به تومان حساب و نقد یا با کارت پرداخت می‌شود.',
  CHEQUE: 'مبلغ به تومان حساب می‌شود و روی فاکتور «روش تسویه: چک» نوشته می‌شود.',
  WEIGHT: 'مشتری به‌جای پولِ طلا، همین وزن (معادل ۷۵۰) طلا بدهکار می‌شود؛ فقط اجرت، سود و مالیات نقدی است.',
  INSTALLMENT: 'مبلغ به تومان حساب می‌شود و روی فاکتور «قسطی» نوشته می‌شود.',
};
// Fields that start at 0: the 0 is cleared on the FIRST focus only, so typing does not give «۰۱۸».
const ZERO_FIELDS = new Set(['wage_percent', 'profit_percent', 'deduction_percent']);
const isZero = (v) => /^\s*[0۰٠]([.,٫][0۰٠]*)?\s*$/.test(v || '');
const uid = () => (Date.now().toString(36) + Math.random().toString(36).slice(2, 12)).slice(0, 20);

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  let version = boot.version;
  let rate = boot.rate_irr;
  let buyRate = boot.buy_rate_irr;
  const acceptedRate = rate, acceptedBuyRate = buyRate;
  const newRate = document.querySelector('[data-new-rate]');
  const acceptedRateFa = document.querySelector('[data-rate]')?.textContent;
  const LOCAL_TTL = 24 * 3600 * 1000;
  const host = document.querySelector('[data-rows]');
  const tpl = document.querySelector('[data-row-tpl]');
  const saveState = document.querySelector('[data-save-state]');
  const payableEl = document.querySelector('[data-payable]');
  const countEl = document.querySelector('[data-row-count]');
  const reviewBtn = document.querySelector('[data-review]');
  let rows = boot.rows.length ? boot.rows : [newRow(boot.rate_mode === 'NONE' ? 'MISC' : 'GOLD')];
  let timer = null, saving = false, dirty = false, useLatest = false;

  function defaultBasis() { return buyRate ? 'BUY' : rate ? 'SELL' : 'MANUAL'; }

  function newRow(type) {
    return {
      row_uid: uid(), item_type: type, name: type === 'GOLD' ? 'طلای ۱۸ عیار' : '', description: '', net_weight_g: '', purity_ppt: '750', wage_percent: '0', profit_percent: '0', discount_toman: '', discount_scope: 'TAXABLE_COMPONENTS', manual_total_toman: '',
      kind: type === 'GOLD' ? 'JEWELRY' : 'OLD_GOLD', rate_basis: defaultBasis(), rate_toman: '', deduction_percent: '0', assay_ref: '', settlement: 'MONEY',
    };
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
    const isIn = row.item_type === 'GOLD_IN';
    el.classList.toggle('is-in', isIn);
    el.querySelectorAll('[data-sec]').forEach((sec) => { sec.hidden = !sec.dataset.sec.split(',').includes(row.item_type); });
    const coin = isIn || (row.item_type === 'GOLD' && row.kind === 'COIN');
    el.querySelectorAll('[data-only]').forEach((c) => { c.hidden = !(c.dataset.only === row.item_type || (coin && c.dataset.p === '900')); });
    if (isIn) {
      el.querySelector('[data-row-total-label]').textContent = row.rate_basis === 'WEIGHT' ? 'به حساب طلای مشتری (بس)' : 'از مبلغ فاکتور کم می‌شود';
      el.querySelector('[data-weight-hint]').textContent = 'وزن ترازو؛ برای سکه وزن خود سکه.';
      el.querySelector('[data-sec="GOLD,GOLD_IN"] [data-f="name"]').placeholder = KIND_NAME[row.kind] || 'طلای دریافتی';
      el.querySelector('[data-basis-opt="BUY"]').hidden = !buyRate && row.rate_basis !== 'BUY';
      el.querySelector('[data-basis-opt="SELL"]').hidden = !rate && row.rate_basis !== 'SELL';
      el.querySelector('[data-buy-rate]').textContent = buyRate ? `${toman(buyRate)} تومان` : '(در دسترس نیست)';
      el.querySelector('[data-sell-rate]').textContent = rate ? `${toman(rate)} تومان` : '';
      el.querySelector('[data-rate-manual]').hidden = row.rate_basis !== 'MANUAL';
      el.querySelector('[data-assay]').hidden = row.kind !== 'MELTED' && !row.assay_ref;
    }
    el.querySelectorAll('input[type="radio"][data-f="kind"], input[type="radio"][data-f="rate_basis"], input[type="radio"][data-f="settlement"]').forEach((r) => { r.name = `${r.dataset.f}-${row.row_uid}`; r.checked = r.value === (row[r.dataset.f] || (r.dataset.f === 'settlement' ? 'MONEY' : '')); });
    if (row.item_type === 'GOLD' && row.settlement === 'WEIGHT') el.querySelector('[data-row-total-label]').textContent = 'مبلغ نقدی (اجرت، سود، مالیات)';
    if (row.item_type === 'GOLD') el.querySelector('[data-assay-sale]').hidden = row.kind !== 'MELTED' && !row.assay_ref;
    if (row.item_type === 'GOLD') {
      el.querySelector('[data-settle-hint]').textContent = (SETTLE_HINT[row.settlement] || SETTLE_HINT.MONEY)
        + (row.settlement === 'INSTALLMENT' ? (boot.can_installments ? ' بعد از صدور، «ثبت اقساط این فاکتور» را بزنید.' : ' زمان‌بندی اقساط و یادآوری پیامکی در پلن حرفه‌ای است.') : '');
    }
    el.querySelectorAll('input[data-f]').forEach((inp) => {
      const f = inp.dataset.f;
      if (f === 'item_type' || inp.type === 'radio') return;
      if (inp.closest('[data-sec]')?.hidden) { inp.disabled = true; return; }
      inp.value = row[f] ?? '';
      inp.id = `${f}-${row.row_uid}`;
      const label = inp.closest('.field')?.querySelector('label');
      if (label) label.htmlFor = inp.id;
    });
    const nameInput = el.querySelector('[data-sec="GOLD,GOLD_IN"] [data-f="name"]');
    if (row.item_type === 'GOLD') nameInput.dataset.defaultName = 'طلای ۱۸ عیار';
    const preset = (coin ? ['750', '900', '875', '1000'] : ['750', '875', '1000']).includes(String(row.purity_ppt));
    el.querySelectorAll('[data-p]').forEach((c) => c.setAttribute('aria-pressed', String(preset ? c.dataset.p === String(row.purity_ppt) : c.dataset.p === 'custom')));
    el.querySelector('[data-purity-custom]').classList.toggle('hidden', preset);
    return el;
  }

  function rowOf(el) { return rows.find((r) => r.row_uid === el.closest('[data-row]').dataset.uid); }

  // Rows are rebuilt when their type changes; keyboard and screen-reader users keep their place.
  function renderKeepingFocus(target) {
    const uid = target.closest('[data-row]')?.dataset.uid;
    const f = target.dataset.f;
    const value = target.value;
    render();
    const again = uid && host.querySelector(`[data-uid="${CSS.escape(uid)}"] [data-f="${CSS.escape(f)}"][value="${CSS.escape(value)}"]`);
    again?.focus();
  }

  // First focus on a field that still holds the default 0: clear it so the number is typed fresh. Later focuses
  // keep whatever is there; leaving it empty puts the 0 back.
  const cleared = new Set();
  host.addEventListener('focusin', (e) => {
    const f = e.target.dataset?.f;
    if (!ZERO_FIELDS.has(f)) return;
    const key = `${e.target.closest('[data-row]').dataset.uid}:${f}`;
    if (cleared.has(key)) return;
    cleared.add(key);
    if (isZero(e.target.value)) { e.target.value = ''; rowOf(e.target)[f] = ''; }
  });
  host.addEventListener('focusout', (e) => {
    const f = e.target.dataset?.f;
    if (!ZERO_FIELDS.has(f) || e.target.value.trim() !== '') return;
    e.target.value = '0'; rowOf(e.target)[f] = '0'; changed();
  });

  host.addEventListener('input', (e) => {
    const f = e.target.dataset.f;
    if (!f || e.target.type === 'radio') return;
    rowOf(e.target)[f] = e.target.value;
    const fieldEl = e.target.closest('.field');
    if (fieldEl) { fieldEl.classList.remove('invalid'); e.target.removeAttribute('aria-invalid'); }
    changed();
  });
  host.addEventListener('change', (e) => {
    const f = e.target.dataset.f;
    if (f === 'kind' || f === 'rate_basis' || f === 'settlement') {
      const row = rowOf(e.target);
      row[f] = e.target.value;
      if (f === 'kind' && e.target.value === 'COIN' && row.purity_ppt === '750') row.purity_ppt = '900';
      if (f === 'kind' && e.target.value !== 'COIN' && row.purity_ppt === '900') row.purity_ppt = '750';
      // Coins and plaques sold by the shop: a coin name instead of the jewelry default.
      if (f === 'kind' && e.target.value === 'COIN' && row.item_type === 'GOLD' && (!row.name || row.name === 'طلای ۱۸ عیار' || row.name === 'طلای آب‌شده')) row.name = 'سکه';
      if (f === 'kind' && e.target.value !== 'COIN' && row.item_type === 'GOLD' && row.name === 'سکه') row.name = 'طلای ۱۸ عیار';
      // Melted gold sold by the shop: usually no wage/profit and an exact assay purity.
      if (f === 'kind' && e.target.value === 'MELTED' && row.item_type === 'GOLD') {
        if (!row.name || row.name === 'طلای ۱۸ عیار') row.name = 'طلای آب‌شده';
      }
      if (f === 'kind' && e.target.value === 'JEWELRY' && row.name === 'طلای آب‌شده') row.name = 'طلای ۱۸ عیار';
      renderKeepingFocus(e.target); changed();
      if (f === 'rate_basis' && row.rate_basis === 'MANUAL') host.querySelector(`[data-uid="${row.row_uid}"] [data-f="rate_toman"]`)?.focus();
      return;
    }
    if (f !== 'item_type') return;
    const row = rowOf(e.target);
    const prev = row.item_type;
    row.item_type = e.target.value;
    Object.entries(newRow(row.item_type)).forEach(([k, v]) => { if (row[k] === undefined) row[k] = v; });
    const kinds = row.item_type === 'GOLD' ? ['JEWELRY', 'COIN', 'MELTED'] : ['OLD_GOLD', 'COIN', 'MELTED', 'OTHER'];
    if (!kinds.includes(row.kind)) row.kind = kinds[0];
    if (row.item_type !== 'GOLD' && row.name === 'طلای ۱۸ عیار') row.name = '';
    if (row.item_type === 'GOLD' && !row.name && prev !== 'GOLD_IN') row.name = 'طلای ۱۸ عیار';
    if (row.item_type !== 'GOLD_IN' && row.kind !== 'COIN' && row.purity_ppt === '900') row.purity_ppt = '750';
    renderKeepingFocus(e.target); changed();
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

  // Product names: the shop's sold products (pre-fill weight, purity, wage, profit) then the shared list.
  productSuggest({
    host,
    namesUrl: boot.names_url,
    typeOf: (input) => {
      const row = rowOf(input);
      const sec = input.closest('[data-sec]')?.dataset.sec;
      if (row.item_type === 'GOLD' && sec === 'GOLD,GOLD_IN') return 'GOLD';
      if (row.item_type === 'MISC' && sec === 'MISC') return 'MISC';
      return null;
    },
    onPick: (input, choice) => {
      const row = rowOf(input);
      const uidOf = row.row_uid;
      let focus = row.item_type === 'MISC' ? 'manual_total_toman' : 'net_weight_g';
      let note = '';
      if (choice.kind === 'base') {
        row.name = choice.term;
      } else {
        const p = choice.item;
        row.name = p.n;
        if (row.item_type === 'GOLD') {
          if (p.w) row.net_weight_g = p.w;
          if (p.p) row.purity_ppt = p.p;
          if (p.wg !== '') row.wage_percent = p.wg;
          if (p.pr !== '') row.profit_percent = p.pr;
          if (['JEWELRY', 'COIN', 'MELTED'].includes(p.k)) row.kind = p.k;
          ['wage_percent', 'profit_percent'].forEach((f) => cleared.add(`${uidOf}:${f}`)); // pre-filled: keep on focus
          note = 'وزن، اجرت و سود از فروش قبلی پر شد. وزن همین قطعه را بررسی کنید.';
        } else if (p.m) {
          row.manual_total_toman = p.m;
          note = 'قیمت از فروش قبلی پر شد؛ در صورت نیاز تغییر دهید.';
        }
      }
      render(); changed();
      const card = host.querySelector(`[data-uid="${CSS.escape(uidOf)}"]`);
      const target = card?.querySelector(`[data-sec="${row.item_type === 'MISC' ? 'MISC' : 'GOLD,GOLD_IN'}"] [data-f="${focus}"]`) || card?.querySelector(`[data-f="${focus}"]`);
      if (!target) return;
      target.focus();
      target.select?.();
      const field = target.closest('.field');
      field?.classList.add('attn');
      setTimeout(() => field?.classList.remove('attn'), 2600);
      if (note) {
        const hint = field?.querySelector('.hint') || field?.appendChild(Object.assign(document.createElement('p'), { className: 'hint' }));
        if (hint) { hint.dataset.prev ??= hint.textContent; hint.textContent = note; hint.classList.add('attn-text'); }
      }
    },
  });

  document.querySelector('[data-add-row]').addEventListener('click', () => {
    rows.push(newRow(boot.rate_mode === 'NONE' ? 'MISC' : 'GOLD'));
    render(); changed();
    const last = host.lastElementChild;
    last.scrollIntoView({ behavior: 'smooth', block: 'center' });
    last.querySelector(rows.at(-1).item_type === 'MISC' ? '[data-sec="MISC"] [data-f="name"]' : '[data-f="net_weight_g"]')?.focus();
  });

  function localPrice(row) {
    const w = toLatin(row.net_weight_g);
    if (row.item_type === 'GOLD_IN') {
      const basis = row.rate_basis || 'BUY';
      if (basis === 'WEIGHT') return priceGoldInWeight({ net_weight_g: w, purity_ppt: toLatin(row.purity_ppt), deduction_percent: toLatin(row.deduction_percent) || '0' }, boot.limits);
      const r = basis === 'BUY' ? buyRate : basis === 'SELL' ? rate : (row.rate_toman ? (parseTomanToIrr(row.rate_toman) ?? 'x') : null);
      if (!r) throw new PricingError(basis === 'BUY' ? 'NO_BUY_RATE' : 'REQUIRED', 'rate_irr_per_g');
      return priceGoldIn({ net_weight_g: w, purity_ppt: toLatin(row.purity_ppt), rate_irr_per_g: r, deduction_percent: toLatin(row.deduction_percent) || '0' }, boot.limits);
    }
    if (row.item_type === 'GOLD') {
      const discountIrr = row.discount_toman ? parseTomanToIrr(row.discount_toman, true) : null;
      const r = priceGold({ net_weight_g: w, purity_ppt: toLatin(row.purity_ppt), price18_irr_per_g: rate, wage_percent: toLatin(row.wage_percent) || '0', profit_percent: toLatin(row.profit_percent) || '0', discount: discountIrr && discountIrr !== '0' ? { scope: row.discount_scope || 'TAXABLE_COMPONENTS', amount_irr: discountIrr } : null, vat_rate_percent: boot.vat }, boot.limits);
      // «تسویه وزنی»: the metal is owed in gold; only wage + profit + VAT are money (as InvoiceCalculator).
      if (row.settlement === 'WEIGHT') return { ...r, settlement: 'WEIGHT', debit_750: weight750(w, toLatin(row.purity_ppt)), T: (BigInt(r.B) + BigInt(r.V)).toString() };
      return r;
    }
    if (!row.name?.trim()) throw new PricingError('REQUIRED', 'name');
    return priceManual({ manual_total_irr: parseTomanToIrr(row.manual_total_toman) ?? 'x' }, boot.limits);
  }

  function preview(serverRows = null) {
    let total = 0n, received = 0n, valid = rows.length > 0, goldBal = 0n; // gold balance in milligrams of 750
    const hasSale = rows.some((r) => r.item_type === 'GOLD' || r.item_type === 'MISC');
    const hasIn = rows.some((r) => r.item_type === 'GOLD_IN');
    rows.forEach((row) => {
      const card = host.querySelector(`[data-row][data-uid="${row.row_uid}"]`);
      if (!card) return;
      const totalEl = card.querySelector('[data-row-total]');
      const bd = card.querySelector('[data-breakdown]');
      const eff = card.querySelector('[data-eff]');
      clearInvalid(card);
      try {
        const r = localPrice(row);
        const mg = (s) => BigInt(s.replace('.', ''));
        if (r.settlement === 'WEIGHT' && row.item_type === 'GOLD_IN') {
          goldBal -= mg(r.credit_750);
          totalEl.textContent = `بستانکار ${toPersian(r.credit_750.replace(/\.?0+$/, ''))} گرم ۷۵۰`;
          bd.textContent = `حساب وزنی: معادل ${toPersian(r.weight_750.replace(/\.?0+$/, ''))} گرم ۷۵۰` + (r.credit_750 !== r.weight_750 ? ` · بعد از کسر ${toPersian(r.credit_750.replace(/\.?0+$/, ''))}` : '');
          eff.textContent = '';
          return;
        }
        if (r.settlement === 'WEIGHT' && row.item_type === 'GOLD') goldBal += mg(r.debit_750);
        if (row.item_type === 'GOLD_IN') {
          received += BigInt(r.T);
          totalEl.textContent = `−${toman(r.T)} تومان`;
          bd.textContent = `معادل ${toPersian(r.weight_750.replace(/\.?0+$/, ''))} گرم طلای ۱۸ عیار (۷۵۰)` + (r.D !== '0' ? ` · کسر ${toman(r.D)} تومان` : '');
          eff.textContent = '';
          return;
        }
        total += BigInt(r.T);
        totalEl.textContent = `${toman(r.T)} تومان`;
        if (row.item_type === 'GOLD' && r.settlement === 'WEIGHT') {
          bd.textContent = `طلا بدهکار ${toPersian(r.debit_750.replace(/\.?0+$/, ''))} گرم ۷۵۰ · اجرت ${toman(r.W)} · سود ${toman(r.P)} · مالیات ${toman(r.V)}`;
          eff.textContent = '';
        } else if (row.item_type === 'GOLD') {
          bd.textContent = `ارزش طلا ${toman(r.M)} · اجرت ${toman(r.W)} · سود ${toman(r.P)} · مالیات ${toman(r.V)}`;
          eff.textContent = `نرخ این عیار: ${toman(String(BigInt(r.effective_rate_irr_per_g.split('.')[0])))} تومان/گرم`;
        } else { bd.textContent = ''; }
      } catch (e) {
        valid = false;
        totalEl.textContent = '—';
        bd.textContent = '';
        const shown = (row.item_type === 'MISC' ? (row.manual_total_toman !== '' || row.name !== '') : (row.net_weight_g !== '')) || serverRows;
        if (e instanceof PricingError && shown) {
          const field = FIELD[e.field] || e.field;
          const inp = [...card.querySelectorAll(`[data-f="${field}"]`)].find((x) => !x.closest('[data-sec]')?.hidden);
          const fieldEl = inp?.closest('.field');
          if (fieldEl) markInvalid(fieldEl, ERR[e.code] || 'مقدار درست نیست.');
        }
      }
    });
    // payable = sales − gold received; negative = balance owed to the customer.
    const payable = total - received;
    const credit = payable < 0n;
    valid = valid && hasSale;
    document.querySelector('[data-split]').classList.toggle('hidden', !hasIn);
    const hasWeight = rows.some((r) => r.settlement === 'WEIGHT' || (r.item_type === 'GOLD_IN' && r.rate_basis === 'WEIGHT'));
    document.querySelector('[data-gold-balance]').classList.toggle('hidden', !hasWeight);
    if (hasWeight) {
      const abs = goldBal < 0n ? -goldBal : goldBal;
      const g = `${abs / 1000n}.${String(abs % 1000n).padStart(3, '0')}`.replace(/\.?0+$/, '');
      document.querySelector('[data-gold-balance-value]').textContent = goldBal === 0n ? 'تسویه' : `${toPersian(g)} ${goldBal > 0n ? 'بدهکار (مشتری)' : 'بستانکار (به نفع مشتری)'}`;
    }
    document.querySelector('[data-sales]').textContent = toman(total.toString());
    document.querySelector('[data-gold-in]').textContent = `−${toman(received.toString())}`;
    document.querySelector('[data-payable-label]').textContent = !hasIn ? 'جمع فاکتور' : credit ? 'مانده به نفع مشتری' : 'قابل پرداخت';
    document.querySelector('[data-sale-required]').classList.toggle('hidden', hasSale || !rows.length);
    payableEl.textContent = valid ? `${toman((credit ? -payable : payable).toString())} تومان` : '—';
    reviewBtn.setAttribute('aria-disabled', String(!valid));
  }

  function changed() {
    preview();
    dirty = true;
    saveState.textContent = 'در حال ذخیره…';
    clearTimeout(timer);
    timer = setTimeout(save, 800);
  }

  // Unsaved edits stay «dirty» until the server has them: a failed save keeps them, keeps a copy on this device
  // (offered back on the next visit) and «مرور فاکتور» never moves on without a successful save.
  const localKey = `draft:${boot.id}`;
  let stopped = false;
  async function save() {
    if (saving) { timer = setTimeout(save, 400); return false; }
    saving = true; dirty = false;
    // «نرخ جدید»: send the exact rate the merchant saw; the server applies it only if it is still the current one.
    const payload = { version, rows: rows.map((r) => ({ ...r })), buyer: boot.buyer, use_latest_rate: useLatest, ...(useLatest ? { latest_rate_irr: rate } : {}) };
    const res = await put(`/api/invoices/drafts/${boot.id}`, payload);
    saving = false;
    if (res.ok) {
      version = res.data.version;
      if (useLatest) { useLatest = false; }
      saveState.textContent = `پیش‌نویس ذخیره شد · ${new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit' }).format(new Date())}`;
      try { localStorage.removeItem(localKey); } catch {}
      if (dirty) changed();
      return true;
    }
    dirty = true;
    if (res.code === 'RATE_CHANGED') {
      // The market moved again since the notice was shown: go back to the accepted rate and show the new value.
      useLatest = false; rate = acceptedRate; buyRate = acceptedBuyRate;
      boot.latest_irr = res.data.latest?.value_irr; boot.latest_fa = res.data.latest?.value_toman_fa;
      document.querySelector('[data-rate]').textContent = acceptedRateFa;
      if (boot.latest_irr) { newRate.classList.remove('hidden'); newRate.querySelector('[data-new-rate-value]').textContent = boot.latest_fa; }
      render();
      toast(res.message, { kind: 'error', timeout: 9000 });
      timer = setTimeout(save, 300);
      return false;
    }
    // Device copy: rows only (no buyer name/mobile), dropped after LOCAL_TTL and at logout.
    try { localStorage.setItem(localKey, JSON.stringify({ base_version: version, rows: payload.rows, at: Date.now() })); } catch {}
    if (res.code === 'NOT_DRAFT') { stopped = true; try { localStorage.removeItem(localKey); } catch {} saveState.textContent = 'این فاکتور صادر شده است و دیگر ویرایش نمی‌شود.'; toast(res.message, { kind: 'error', timeout: 15000, action: { label: 'دیدن فاکتور', onClick: () => location.reload() } }); return false; }
    if (res.code === 'DRAFT_CONFLICT') { stopped = true; saveState.textContent = 'این پیش‌نویس در جای دیگری تغییر کرد.'; toast(res.message, { kind: 'error', timeout: 15000, action: { label: 'بارگذاری دوباره', onClick: () => location.reload() } }); return false; }
    if (res.status === 401 || res.status === 419) {
      // Session ended: retrying cannot help. The edits are on this device and come back after signing in.
      stopped = true;
      saveState.textContent = 'نشست شما تمام شد؛ تغییرات روی همین دستگاه نگه داشته شد.';
      toast('برای ذخیره تغییرات دوباره وارد شوید.', { kind: 'error', timeout: 20000, action: { label: 'ورود دوباره', onClick: () => { location.href = '/login'; } } });
      return false;
    }
    saveState.textContent = res.code === 'OFFLINE' ? 'آفلاین؛ تغییرات روی همین دستگاه نگه داشته شد و با برگشت اینترنت ذخیره می‌شود' : 'ذخیره نشد؛ دوباره تلاش می‌کنیم';
    timer = setTimeout(save, 5000);
    return false;
  }
  window.addEventListener('online', () => { if (dirty && !saving && !stopped) { clearTimeout(timer); save(); } });

  async function saveNow() {
    while (saving) await new Promise((r) => setTimeout(r, 150));
    return dirty ? save() : true;
  }

  reviewBtn.addEventListener('click', async (e) => {
    e.preventDefault();
    if (reviewBtn.getAttribute('aria-disabled') === 'true') { preview(true); toast('بعضی ردیف‌ها کامل یا درست نیستند.', { kind: 'error' }); host.querySelector('.field.invalid input')?.focus(); return; }
    clearTimeout(timer);
    if (!(await saveNow())) {
      toast('تغییرات هنوز ذخیره نشده‌اند؛ اتصال را بررسی کنید و دوباره «مرور فاکتور» را بزنید.', { kind: 'error', timeout: 8000 });
      return;
    }
    location.href = boot.review_url;
  });

  // A copy left on this device by a save that never reached the server (offline, closed tab) is offered back.
  try {
    const local = JSON.parse(localStorage.getItem(localKey) || 'null');
    const fresh = local && Number(local.at) > Date.now() - LOCAL_TTL;
    if (fresh && local.base_version === version && Array.isArray(local.rows)) {
      toast('تغییرات ذخیره‌نشده‌ای روی همین دستگاه پیدا شد.', { timeout: 30000, action: { label: 'بازگرداندن', onClick: () => { rows = local.rows; render(); changed(); } } });
    } else if (local) {
      localStorage.removeItem(localKey);
    }
  } catch {}

  document.querySelector('[data-delete-draft]').addEventListener('click', async () => {
    if (!confirm('این پیش‌نویس حذف شود؟')) return;
    const res = await del(`/api/invoices/drafts/${boot.id}`);
    if (res.ok) location.href = res.data.next; else toast(res.message, { kind: 'error' });
  });

  // New market rate notice (never applied silently).
  if (boot.rate_mode === 'MARKET' && boot.latest_irr && boot.latest_irr !== rate) {
    newRate.classList.remove('hidden');
    newRate.querySelector('[data-new-rate-value]').textContent = boot.latest_fa;
  }
  document.querySelector('[data-use-new-rate]')?.addEventListener('click', () => {
    rate = boot.latest_irr; useLatest = true;
    if (boot.latest_buy_irr) buyRate = boot.latest_buy_irr;
    render();
    document.querySelector('[data-rate]').textContent = boot.latest_fa;
    newRate.classList.add('hidden');
    changed();
  });

  window.addEventListener('beforeunload', (e) => { if (dirty || saving) { e.preventDefault(); } });
  render();
}
