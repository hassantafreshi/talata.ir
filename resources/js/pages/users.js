import { post, put, del } from '../lib/http.js';
import { busy, toast, fieldErrors } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';

// Team access picker: presets fill the checklist; dependencies are kept (e.g. voiding needs seeing invoices).
function wirePicker(picker) {
  const boxes = () => [...picker.querySelectorAll('[data-perm]')];
  const summary = picker.querySelector('[data-perm-summary]');
  const hint = picker.querySelector('[data-preset-hint]');
  const sync = () => {
    const on = boxes().filter((b) => b.checked);
    picker.querySelectorAll('[data-preset]').forEach((p) => {
      const want = JSON.parse(p.dataset.perms);
      p.setAttribute('aria-pressed', String(want.length === on.length && on.every((b) => want.includes(b.value))));
    });
    summary.textContent = on.length ? `${on.length.toLocaleString('fa-IR')} دسترسی انتخاب شده.` : 'دست‌کم یک دسترسی را انتخاب کنید.';
  };
  picker.addEventListener('click', (e) => {
    const p = e.target.closest('[data-preset]');
    if (!p) return;
    const want = JSON.parse(p.dataset.perms);
    boxes().forEach((b) => { b.checked = want.includes(b.value); });
    hint.textContent = p.title;
    sync();
  });
  picker.addEventListener('change', (e) => {
    const b = e.target.closest('[data-perm]');
    if (!b) return;
    if (b.checked && b.dataset.needs) b.dataset.needs.split(',').forEach((k) => { const n = picker.querySelector(`[data-perm][value="${k}"]`); if (n) n.checked = true; });
    if (!b.checked) boxes().filter((x) => x.dataset.needs?.split(',').includes(b.value)).forEach((x) => { x.checked = false; });
    hint.textContent = '';
    sync();
  });
  sync();
}
const chosen = (root) => [...root.querySelectorAll('[data-perm]:checked')].map((c) => c.value);

export default function () {
  document.querySelectorAll('[data-perm-picker]').forEach(wirePicker);
  const members = document.querySelector('[data-members]');
  members.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-perm-form]');
    if (!form) return;
    e.preventDefault();
    const permissions = chosen(form);
    if (!permissions.length) { toast('دست‌کم یک دسترسی را انتخاب کنید.', { kind: 'error' }); return; }
    const btn = form.querySelector('[type=submit]');
    busy(btn);
    const res = await put(`/api/users/${form.closest('[data-member]').dataset.member}`, { permissions });
    busy(btn, false);
    if (res.ok) { toast('دسترسی ذخیره شد.'); setTimeout(() => location.reload(), 500); } else toast(res.message, { kind: 'error' });
  });
  members.addEventListener('click', async (e) => {
    if (!e.target.matches('[data-remove]')) return;
    if (!confirm('این کاربر حذف شود؟ فوراً از حساب فروشگاه خارج می‌شود.')) return;
    const card = e.target.closest('[data-member]');
    const res = await del(`/api/users/${card.dataset.member}`);
    if (res.ok) { card.remove(); toast('کاربر حذف شد.'); } else toast(res.message, { kind: 'error' });
  });
  const form = document.querySelector('[data-invite]');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const picker = form.querySelector('[data-perm-picker]');
    if (picker && !chosen(picker).length) { toast('دست‌کم یک دسترسی را انتخاب کنید.', { kind: 'error' }); return; }
    const btn = form.querySelector('[type=submit]');
    busy(btn);
    const res = await post('/api/users/invite', { mobile: toLatin(form.mobile.value), ...(picker ? { permissions: chosen(picker) } : {}) });
    busy(btn, false);
    if (res.ok) { toast('همکار اضافه شد.'); setTimeout(() => location.reload(), 600); return; }
    if (res.errors) fieldErrors(form, res.errors); else toast(res.message, { kind: 'error' });
  });
}
