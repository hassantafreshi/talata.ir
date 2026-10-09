import { post } from '../lib/http.js';
import { wireAdminActions } from '../lib/admin-forms.js';
import { busy, toast, fieldErrors } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';

// Admin price editor: live previews (with VAT), a list of old → new changes, typo guard for >50% changes.
const fmt = new Intl.NumberFormat('fa-IR');
const num = (v) => { const s = toLatin(String(v)).replace(/[,٬\s]/g, ''); return /^\d{1,12}$/.test(s) ? Number(s) : null; };

export default function () {
  wireAdminActions(); // discount-code forms
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const vat = Number(boot.vat);
  const form = document.querySelector('[data-pricing-form]');
  const inputs = [...form.querySelectorAll('[data-money]')];
  const list = form.querySelector('[data-changes]');
  const submit = form.querySelector('[type=submit]');

  function refresh() {
    const changes = [];
    inputs.forEach((inp) => {
      const v = num(inp.value);
      const field = inp.closest('.field');
      const vatEl = field.querySelector('[data-vat]');
      const two = field.querySelector('[data-two]');
      if (vatEl) vatEl.textContent = v === null ? '—' : `${fmt.format(Math.round(v * (1 + vat / 100)))} تومان`;
      if (two) two.textContent = v === null ? '—' : `${fmt.format(v * 2)} تومان`;
      if (v !== null && String(v) !== inp.dataset.old) changes.push({ label: field.querySelector('label').textContent.replace(' (تومان، بدون مالیات)', ''), section: inp.closest('section').querySelector('h2').textContent, old: Number(inp.dataset.old), now: v });
    });
    form.querySelectorAll('section').forEach((sec) => {
      const note = sec.querySelector('[data-yearly-note]');
      if (!note) return;
      const [m, y] = [...sec.querySelectorAll('[data-money]')].map((i) => num(i.value));
      note.textContent = m && y ? `سالانه معادل ${fmt.format(Math.round(y / 12))} تومان در ماه (${fmt.format(Math.round(100 - (y / (m * 12)) * 100))}٪ تخفیف نسبت به ۱۲ ماه).` : '';
    });
    if (!list) return;
    list.replaceChildren(...(changes.length ? changes.map((c) => {
      const li = document.createElement('li');
      li.className = 'list-item';
      const pct = c.old ? Math.round(((c.now - c.old) / c.old) * 100) : 0;
      li.textContent = `${c.section} · ${c.label}: ${fmt.format(c.old)} ← ${fmt.format(c.now)} تومان (${pct > 0 ? '+' : ''}${fmt.format(pct)}٪)`;
      if (Math.abs(pct) > 50) li.classList.add('warn-row');
      return li;
    }) : [Object.assign(document.createElement('li'), { className: 'muted small', textContent: 'هنوز چیزی تغییر نکرده است.' })]));
    submit.disabled = !changes.length;
    return changes;
  }

  function payload(extra = {}) {
    const data = { plans: {}, sms: {}, note: form.note?.value.trim() || null, ...extra };
    inputs.forEach((inp) => {
      const [group, code, period] = inp.name.split('.');
      const v = toLatin(inp.value).replace(/[,٬\s]/g, '');
      if (group === 'plans') { data.plans[code] ??= {}; data.plans[code][period] = v; } else data.sms[code] = v;
    });
    return data;
  }

  form.addEventListener('input', refresh);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const changes = refresh();
    if (!changes?.length) return;
    if (!confirm(`${fmt.format(changes.length)} قیمت تغییر می‌کند و از همین حالا برای خریدهای جدید اعمال می‌شود. منتشر شود؟`)) return;
    busy(submit);
    let res = await post('/admin/api/pricing', payload());
    if (!res.ok && res.code === 'CONFIRM_LARGE_CHANGE') {
      const lines = (res.data.changes || []).map((c) => `${c.label_fa}: ${c.old_fa} ← ${c.new_fa} تومان`).join('\n');
      if (confirm(`این تغییرها بیش از ۵۰٪ است:\n${lines}\nمطمئن هستید؟`)) res = await post('/admin/api/pricing', payload({ confirm_large: true }));
    }
    busy(submit, false);
    if (res.ok) { toast(`نسخه قیمت ${fmt.format(res.data.version)} منتشر شد.`); setTimeout(() => location.reload(), 700); return; }
    if (res.errors) fieldErrors(form, res.errors); else if (res.code !== 'CONFIRM_LARGE_CHANGE') toast(res.message, { kind: 'error' });
  });

  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-restore]');
    if (!b) return;
    if (!confirm(`قیمت‌های نسخه ${fmt.format(b.dataset.version)} دوباره به‌عنوان نسخه جدید منتشر شود؟`)) return;
    busy(b);
    const res = await post(`/admin/api/pricing/${b.dataset.restore}/restore`, {});
    busy(b, false);
    if (res.ok) { toast(`نسخه ${fmt.format(res.data.version)} منتشر شد.`); setTimeout(() => location.reload(), 700); } else toast(res.message, { kind: 'error' });
  });
  refresh();
}
