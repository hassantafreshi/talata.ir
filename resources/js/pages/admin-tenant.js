import { post } from '../lib/http.js';
import { busy, toast } from '../lib/ui.js';
import { wireAdminActions } from '../lib/admin-forms.js';
import { parseTomanToIrr, toman } from '../lib/digits.js';

// Admin restores a shop's settings backup (on the owner's request); audited server-side as staff.
// Manual activation preview: what the plan costs now (with VAT) next to what was received.
function activationPreview() {
  const form = document.querySelector('[data-activation]');
  if (!form) return;
  const prices = JSON.parse(form.dataset.prices);
  const vat = Number(form.dataset.vat);
  const out = form.querySelector('[data-preview]');
  const render = () => {
    const toman0 = Number(prices[form.plan.value]?.[form.period.value] || 0);
    const withVat = Math.round(toman0 * (1 + vat / 100));
    const got = parseTomanToIrr(form.received_toman.value, true);
    const gotToman = got === null ? null : Number(got) / 10;
    let note = `قیمت فعلی: ${toman(String(toman0 * 10))} + مالیات = ${toman(String(withVat * 10))} تومان.`;
    if (gotToman !== null && gotToman !== withVat) note += gotToman === 0 ? ' مبلغ دریافتی صفر است (رایگان).' : ` دریافتی ${toman(String(gotToman * 10))} تومان (با قیمت فعلی فرق دارد).`;
    out.textContent = note;
  };
  form.addEventListener('input', render);
  form.addEventListener('change', render);
  render();
}

export default function () {
  wireAdminActions();
  activationPreview();
  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-admin-restore]');
    if (!form) return;
    e.preventDefault();
    const sections = [...form.querySelectorAll('[name="sections[]"]:checked')].map((c) => c.value);
    if (!sections.length) { toast('دست‌کم یک بخش را انتخاب کنید.', { kind: 'error' }); return; }
    if (!confirm('تنظیمات این فروشگاه برگردانده شود؟ این کار با نام شما ثبت می‌شود.')) return;
    const btn = form.querySelector('[type=submit]');
    busy(btn);
    const res = await post(form.dataset.url, { sections });
    busy(btn, false);
    if (res.ok) { toast('برگردانده شد.'); setTimeout(() => location.reload(), 600); } else toast(res.message, { kind: 'error' });
  });
}
