import { post } from '../lib/http.js';
import { busy, toast, fieldErrors } from '../lib/ui.js';

export default function () {
  const manual = document.querySelector('[data-manual]');
  manual?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = manual.querySelector('[type=submit]');
    busy(btn);
    const res = await post('/api/settings/backups', { label: manual.label.value.trim() || null });
    busy(btn, false);
    if (res.ok) { toast('پشتیبان ذخیره شد.'); setTimeout(() => location.reload(), 500); } else if (res.errors) fieldErrors(manual, res.errors); else toast(res.message, { kind: 'error' });
  });
  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-restore-form]');
    if (!form) return;
    e.preventDefault();
    const sections = [...form.querySelectorAll('[name="sections[]"]:checked')].map((c) => c.value);
    if (!sections.length) { toast('دست‌کم یک بخش را انتخاب کنید.', { kind: 'error' }); return; }
    if (!confirm('بخش‌های انتخاب‌شده به این نسخه برگردند؟ تنظیمات فعلی هم پشتیبان گرفته می‌شود.')) return;
    const btn = form.querySelector('[type=submit]');
    busy(btn);
    const res = await post(`/api/settings/backups/${form.closest('[data-backup]').dataset.backup}/restore`, { sections });
    busy(btn, false);
    if (res.ok) { toast('تنظیمات برگردانده شد.'); setTimeout(() => location.reload(), 700); } else toast(res.message, { kind: 'error' });
  });
}
