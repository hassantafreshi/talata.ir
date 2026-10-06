import { post, put, idempotencyKey } from '../lib/http.js';
import { busy, sheet, toast, fieldErrors } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';
import { customerSheet } from './customers.js';

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);

  document.querySelector('[data-edit]')?.addEventListener('click', () => customerSheet({
    url: `/api/customers/${boot.id}`, method: put, title: 'ویرایش مشتری', values: { name: boot.name, mobile: boot.mobile, note: boot.note },
    onSaved: () => location.reload(),
  }));

  document.querySelectorAll('[data-pay]').forEach((btn) => btn.addEventListener('click', async () => {
    const tpl = document.querySelector('[data-pay-tpl]');
    const { sheet: el, close } = sheet(tpl.innerHTML, { label: 'ثبت دریافت قسط' });
    const { initDatePickers } = await import('../lib/datepicker.js');
    initDatePickers(el);
    const form = el.querySelector('[data-pay-form]');
    const key = idempotencyKey('pay'); // a retry of the same sheet never records twice
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const submit = form.querySelector('[type=submit]');
      busy(submit);
      const res = await post(`/api/agreements/${btn.dataset.pay}/payments`, {
        amount_toman: toLatin(form.amount_toman.value), method: form.method.value, paid_on: form.paid_on.value,
        reference: form.reference.value.trim(), idempotency_key: key,
      });
      busy(submit, false);
      if (res.ok) { close(); toast('دریافت ثبت شد.'); setTimeout(() => location.reload(), 600); return; }
      if (res.errors) fieldErrors(form, res.errors); else toast(res.message, { kind: 'error', timeout: 8000 });
    });
  }));

  document.querySelectorAll('[data-reverse]').forEach((btn) => btn.addEventListener('click', async () => {
    const reason = prompt('دلیل برگشت این پرداخت را بنویسید:');
    if (!reason || !reason.trim()) return;
    const res = await post(`/api/payments/${btn.dataset.reverse}/reverse`, { reason: reason.trim() });
    if (res.ok) { toast('پرداخت برگشت خورد.'); setTimeout(() => location.reload(), 600); } else toast(res.message, { kind: 'error' });
  }));

  document.querySelectorAll('[data-reminders]').forEach((box) => box.addEventListener('change', async () => {
    const res = await post(`/api/agreements/${box.dataset.reminders}/reminders`, { enabled: box.checked });
    if (res.ok) toast(res.data.enabled ? 'یادآوری فعال شد.' : 'یادآوری خاموش شد.');
    else { box.checked = !box.checked; toast(res.message, { kind: 'error' }); }
  }));
}
