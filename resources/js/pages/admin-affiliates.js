import { request, post } from '../lib/http.js';
import { busy, toast, fieldErrors } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';

export default function () {
  document.querySelectorAll('[data-affiliate-form]').forEach((form) => form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type=submit]');
    const data = Object.fromEntries(new FormData(form));
    for (const k of ['mobile', 'commission_percent', 'discount_percent']) if (data[k] !== undefined) data[k] = toLatin(data[k]);
    data.include_sms_credit = form.include_sms_credit.checked;
    busy(btn);
    const res = await request(form.dataset.method, form.dataset.url, data);
    busy(btn, false);
    if (res.ok) { if (res.data.next) location.href = res.data.next; else { toast('ذخیره شد.'); setTimeout(() => location.reload(), 500); } return; }
    if (res.errors) fieldErrors(form, res.errors); else toast(res.message, { kind: 'error' });
  }));

  const payout = document.querySelector('[data-payout-form]');
  payout?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!confirm('واریز همه کمیسیون‌های قابل پرداخت ثبت شود؟')) return;
    const btn = payout.querySelector('[type=submit]');
    busy(btn);
    const res = await post(payout.dataset.url, { reference: payout.reference.value.trim(), note: payout.note.value.trim() });
    busy(btn, false);
    if (res.ok) location.reload(); else if (res.errors) fieldErrors(payout, res.errors); else toast(res.message, { kind: 'error' });
  });

  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-void]');
    if (!b) return;
    const reason = prompt('دلیل لغو این کمیسیون:');
    if (!reason || reason.trim().length < 3) return;
    const res = await post(b.dataset.void, { reason: reason.trim() });
    if (res.ok) location.reload(); else toast(res.message, { kind: 'error' });
  });
}
