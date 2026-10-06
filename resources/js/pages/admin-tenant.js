import { post } from '../lib/http.js';
import { busy, toast } from '../lib/ui.js';

// Admin restores a shop's settings backup (on the owner's request); audited server-side as staff.
export default function () {
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
