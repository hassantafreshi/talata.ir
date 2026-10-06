import { post, put, del } from '../lib/http.js';
import { busy, toast, fieldErrors } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';

export default function () {
  document.querySelector('[data-members]').addEventListener('change', async (e) => {
    if (!e.target.matches('[data-perm]')) return;
    const card = e.target.closest('[data-member]');
    const permissions = [...card.querySelectorAll('[data-perm]:checked')].map((c) => c.value);
    const res = await put(`/api/users/${card.dataset.member}`, { permissions });
    if (res.ok) toast('دسترسی ذخیره شد.'); else { e.target.checked = !e.target.checked; toast(res.message, { kind: 'error' }); }
  });
  document.querySelector('[data-members]').addEventListener('click', async (e) => {
    if (!e.target.matches('[data-remove]')) return;
    if (!confirm('این کاربر حذف شود؟ فوراً از حساب فروشگاه خارج می‌شود.')) return;
    const card = e.target.closest('[data-member]');
    const res = await del(`/api/users/${card.dataset.member}`);
    if (res.ok) { card.remove(); toast('کاربر حذف شد.'); } else toast(res.message, { kind: 'error' });
  });
  const form = document.querySelector('[data-invite]');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type=submit]');
    busy(btn);
    const res = await post('/api/users/invite', { mobile: toLatin(form.mobile.value), ...(form.querySelector('[name="permissions[]"]') ? { permissions: [...form.querySelectorAll('[name="permissions[]"]:checked')].map((c) => c.value) } : {}) });
    busy(btn, false);
    if (res.ok) { toast('همکار اضافه شد.'); setTimeout(() => location.reload(), 600); return; }
    if (res.errors) fieldErrors(form, res.errors); else toast(res.message, { kind: 'error' });
  });
}
