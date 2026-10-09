import { post } from '../lib/http.js';
import { busy, sheet, toast, fieldErrors } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';
import { showQuota } from '../lib/quota.js';

export function customerSheet({ url, method = post, title = 'مشتری جدید', values = {}, onSaved }) {
  const tpl = document.querySelector('[data-customer-tpl]');
  const { sheet: el, close } = sheet(tpl.innerHTML, { label: title });
  el.querySelector('[data-title]').textContent = title;
  const form = el.querySelector('[data-customer-form]');
  for (const [k, v] of Object.entries(values)) if (form.elements[k]) form.elements[k].value = v ?? '';
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type=submit]');
    busy(btn);
    const res = await method(url, { name: form.name.value.trim(), mobile: toLatin(form.mobile.value), national_id: toLatin(form.national_id?.value || ''), note: form.note.value.trim() });
    busy(btn, false);
    if (res.ok) { close(); onSaved(res.data); return; }
    if (res.code === 'DUPLICATE_CUSTOMER') {
      const u = res.data?.customer?.url;
      fieldErrors(form, { mobile: res.message }); // stays next to the field
      toast(res.message, { kind: 'error', action: u ? { label: 'باز کردن', onClick: () => { location.href = u; } } : null, timeout: 9000 });
      return;
    }
    if (res.code?.startsWith('QUOTA_')) { close(); showQuota(res); return; }
    if (res.errors) fieldErrors(form, res.errors); else toast(res.message, { kind: 'error' });
  });
}

export default function () {
  let t = null;
  const search = document.querySelector('input[type=search]');
  search?.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => search.form.requestSubmit(), 500); });
  document.querySelector('[data-add]')?.addEventListener('click', () => customerSheet({ url: '/api/customers', onSaved: (d) => { location.href = d.next; } }));
}
