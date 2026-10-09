import { post, idempotencyKey } from '../lib/http.js';
import { busy, toast, sheet } from '../lib/ui.js';
import { toman } from '../lib/digits.js';

// Display only: the server prices every order from its own pricing version.
function vatOf(subIrr, ratePercent) {
  const [w, f = ''] = String(ratePercent).split('.');
  const scale = 10n ** BigInt(f.length);
  const num = BigInt(subIrr) * BigInt(w + f);
  const den = 100n * scale;
  return (2n * num + den) / (2n * den); // HALF_UP, non-negative
}

// Some PSPs take a GET redirect, others a POST form with fields (e.g. a token).
function goToGateway({ url, method, fields }) {
  if ((method || 'GET').toUpperCase() === 'GET') { location.href = url; return; }
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = url;
  for (const [name, value] of Object.entries(fields || {})) {
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = name; input.value = String(value);
    form.appendChild(input);
  }
  document.body.appendChild(form);
  form.submit();
}

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  // One key per distinct choice on this page view: a double tap on «پرداخت» creates one order.
  const keys = new Map();

  async function order(payload, btn) {
    busy(btn);
    const sig = `${payload.product}|${payload.plan || payload.pack_amount_toman}|${payload.period || ''}|${payload.discount_code || ''}`;
    if (!keys.has(sig)) keys.set(sig, idempotencyKey('ord'));
    const res = await post('/api/billing/orders', { ...payload, idempotency_key: keys.get(sig) });
    if (res.ok) { goToGateway(res.data.redirect); return; }
    busy(btn, false);
    // Next to the button that was tapped, so it is still there after the toast fades (gateway down, limits…).
    const host = btn.closest('form, .card, .band, section') || btn.parentElement;
    let note = host.querySelector('[data-order-error]');
    if (!note) { note = document.createElement('p'); note.className = 'notice err'; note.dataset.orderError = ''; note.setAttribute('role', 'alert'); btn.insertAdjacentElement('afterend', note); }
    note.textContent = res.message;
    toast(res.message, { kind: 'error', timeout: 9000 });
  }

  // Plans page
  document.querySelectorAll('[name="period"]').forEach((r) => r.addEventListener('change', () => {
    document.querySelectorAll('[data-period]').forEach((el) => { el.hidden = el.dataset.period !== r.value; });
  }));
  // Discount / referral code: live repricing of every plan card (server computes; display only).
  let appliedCode = '';
  const dForm = document.querySelector('[data-discount-form]');
  async function applyCode(code, quiet = false) {
    const msg = dForm.querySelector('[data-discount-msg]');
    dForm.querySelector('.field').classList.remove('invalid');
    const blocks = [...document.querySelectorAll('[data-plan][data-period]')];
    for (const el of blocks) {
      const res = await post('/api/billing/discount', { code, plan: el.dataset.plan, period: el.dataset.period });
      if (!res.ok) {
        appliedCode = '';
        if (!quiet) { dForm.querySelector('.field').classList.add('invalid'); dForm.querySelector('.err').textContent = res.message; }
        msg.textContent = '';
        return;
      }
      const d = res.data;
      el.querySelector('[data-f="discount"]').textContent = `− ${d.discount_fa}`;
      el.querySelector('[data-discount-row]').classList.toggle('hidden', !d.applied);
      el.querySelector('[data-f="vat"]').textContent = d.vat_fa;
      el.querySelector('[data-f="total"]').textContent = d.total_fa;
      appliedCode = d.code || '';
      msg.textContent = d.message_fa || '';
    }
  }
  dForm?.addEventListener('submit', (e) => { e.preventDefault(); applyCode(dForm.discount_code.value.trim()); });
  if (dForm && dForm.discount_code.value.trim()) applyCode(dForm.discount_code.value.trim(), true);

  // One calm confirmation before leaving for the bank: which plan, which period, the exact payable amount.
  function confirmPlan(btn) {
    const card = btn.closest('.plan-card');
    const block = btn.closest('[data-period]');
    const { sheet: el } = sheet(`
      <h2 class="h3" data-t></h2>
      <dl class="kv"><div><dt>دوره</dt><dd data-p></dd></div><div><dt><strong>قابل پرداخت</strong></dt><dd class="num"><strong data-a></strong> تومان</dd></div></dl>
      <p class="small muted">با «رفتن به درگاه» صفحه بانک باز می‌شود. پس از پرداخت، خودکار به زرلیو برمی‌گردید و نتیجه را می‌بینید.</p>
      <div class="stack-sm"><button type="button" class="btn btn-gold block" data-go data-busy-text="انتقال به درگاه…">رفتن به درگاه پرداخت</button>
      <button type="button" class="btn btn-line block" data-close>انصراف</button></div>`, { label: 'تأیید خرید پلن' });
    el.querySelector('[data-t]').textContent = `خرید پلن ${card.querySelector('h2').textContent.trim()}`;
    el.querySelector('[data-p]').textContent = btn.dataset.periodBtn === 'yearly' ? 'سالانه (۱۲ ماه)' : 'ماهانه';
    el.querySelector('[data-a]').textContent = block.querySelector('[data-f="total"]').textContent.trim();
    const go = el.querySelector('[data-go]');
    if (/^[۰0]$/.test(el.querySelector('[data-a]').textContent)) go.textContent = 'فعال‌سازی بدون پرداخت'; // 100% discount: no bank
    // On failure the sheet stays open with the reason under the button (order() adds it).
    go.addEventListener('click', () => order({ product: 'PLAN', plan: btn.dataset.buyPlan, period: btn.dataset.periodBtn, discount_code: appliedCode || null }, go));
    go.focus();
  }
  document.querySelectorAll('[data-buy-plan]').forEach((btn) => btn.addEventListener('click', () => confirmPlan(btn)));

  // SMS credit page
  const form = document.querySelector('[data-sms-form]');
  if (!form) return;
  const draw = () => {
    const toma = BigInt(form.pack.value);
    const sub = toma * 10n;
    const vat = vatOf(sub, boot.vat);
    form.querySelector('[data-sub]').textContent = `${toman(sub.toString())} تومان`;
    form.querySelector('[data-vat]').textContent = `${toman(vat.toString())} تومان`;
    form.querySelector('[data-total]').textContent = `${toman((sub + vat).toString())} تومان`;
    form.querySelector('[data-count]').textContent = `${(toma / BigInt(boot.per_segment_toman)).toLocaleString('fa-IR')} پیامک یک‌بخشی`;
    // The button says exactly what will be charged, VAT included.
    const pay = form.querySelector('[data-pay-label]');
    if (pay && !pay.disabled) pay.textContent = `پرداخت ${toman((sub + vat).toString())} تومان`;
  };
  form.addEventListener('change', draw);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    order({ product: 'SMS_CREDIT', pack_amount_toman: form.pack.value, discount_code: form.discount_code?.value.trim() || null, return_to: boot.return ? { route: 'invoice', id: boot.return } : null }, form.querySelector('[type=submit]'));
  });
  draw();
}
