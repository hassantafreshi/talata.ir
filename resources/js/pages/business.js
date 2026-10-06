import { post, del } from '../lib/http.js';
import { busy, toast, fieldErrors } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';
import { showQuota } from '../lib/quota.js';

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const form = document.querySelector('[data-business]');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type=submit]');
    const data = Object.fromEntries(new FormData(form));
    data.business_mobile = toLatin(data.business_mobile);
    data.landline = toLatin(data.landline);
    data.socials = [...form.querySelectorAll('[data-social]')].filter((i) => i.value.trim()).map((i) => ({ network: i.dataset.social, handle: i.value.trim() }));
    data.return = boot.return;
    busy(btn);
    const res = await post('/api/settings/business', data);
    busy(btn, false);
    if (res.ok) { if (res.data.next) { location.href = res.data.next; return; } toast('ذخیره شد.'); return; }
    if (res.errors) fieldErrors(form, res.errors); else toast(res.message, { kind: 'error' });
  });

  const file = document.querySelector('[data-logo-file]');
  const img = document.querySelector('[data-logo-img]');
  const delBtn = document.querySelector('[data-logo-delete]');
  file?.addEventListener('change', async () => {
    const f = file.files[0];
    if (!f) return;
    if (f.size > 1024 * 1024) { toast('حجم فایل بیش از ۱ مگابایت است.', { kind: 'error' }); file.value = ''; return; }
    const fd = new FormData(); fd.append('logo', f);
    const t = toast('در حال بارگذاری لوگو…', { timeout: 30000 });
    const res = await post('/api/settings/logo', fd, { timeout: 60000 });
    t.remove(); file.value = '';
    if (res.ok) { document.querySelector('[data-logo-error]')?.remove(); img.src = res.data.url; img.classList.remove('hidden'); delBtn.classList.remove('hidden'); toast('لوگو ذخیره شد.'); return; }
    if (res.code?.startsWith('CAPABILITY_')) { showQuota(res); return; }
    const text = res.errors?.logo?.[0] ? `${res.message} ${res.errors.logo[0]}` : res.message;
    let note = document.querySelector('[data-logo-error]');
    if (!note) { note = document.createElement('p'); note.className = 'notice err small'; note.dataset.logoError = ''; note.setAttribute('role', 'alert'); file.insertAdjacentElement('afterend', note); }
    note.textContent = text;
    toast(text, { kind: 'error', timeout: 8000 });
  });
  delBtn?.addEventListener('click', async () => {
    if (!confirm('لوگو حذف شود؟ فاکتورهای صادرشده تغییر نمی‌کنند.')) return;
    const res = await del('/api/settings/logo');
    if (res.ok) { img.classList.add('hidden'); delBtn.classList.add('hidden'); toast('لوگو حذف شد.'); } else toast(res.message, { kind: 'error' });
  });
}
