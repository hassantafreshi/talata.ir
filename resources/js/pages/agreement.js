import { post } from '../lib/http.js';
import { busy, toast, fieldErrors, escapeHtml } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';
import { showQuota } from '../lib/quota.js';

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const form = document.querySelector('[data-agreement-form]');
  const box = document.querySelector('[data-preview]');
  const empty = document.querySelector('[data-preview-empty]');
  let timer = null, seq = 0;

  function payload(preview) {
    const src = form.source.value;
    return {
      invoice_id: src === 'invoice' ? form.invoice_id.value : null,
      principal_toman: src === 'manual' ? toLatin(form.principal_toman.value) : null,
      down_payment_toman: toLatin(form.down_payment_toman.value) || '0',
      count: Number(toLatin(form.count.value)) || 0,
      frequency: form.frequency.value, first_due: form.first_due.value,
      reminders: form.reminders.checked, preview,
    };
  }

  function syncSource() {
    const src = form.source.value;
    form.querySelectorAll('[data-src]').forEach((el) => el.classList.toggle('hidden', el.dataset.src !== src));
  }

  async function preview() {
    if (!form.first_due.value) return; // nothing to schedule yet
    const mine = ++seq;
    const res = await post(boot.url, payload(true));
    if (mine !== seq || !res.ok) return;
    if (!res.data.ok) { box.classList.add('hidden'); empty.classList.remove('hidden'); return; }
    box.querySelector('tbody').innerHTML = res.data.lines.map((l) => `<tr><td class="num">${escapeHtml(l.n.toLocaleString('fa-IR'))}</td><td class="num">${escapeHtml(l.due_fa)}</td><td class="num">${escapeHtml(l.amount_fa)}</td></tr>`).join('');
    box.querySelector('[data-principal]').textContent = res.data.principal_fa;
    box.classList.remove('hidden'); empty.classList.add('hidden');
  }

  form.addEventListener('input', () => { syncSource(); clearTimeout(timer); timer = setTimeout(preview, 400); });
  form.addEventListener('change', () => { syncSource(); clearTimeout(timer); timer = setTimeout(preview, 100); });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!form.first_due.value) { fieldErrors(form, { first_due: 'تاریخ اولین قسط را انتخاب کنید.' }); return; }
    const btn = form.querySelector('[type=submit]');
    busy(btn);
    const res = await post(boot.url, payload(false));
    busy(btn, false);
    if (res.ok) { location.href = res.data.next; return; }
    if (res.code?.startsWith('CAPABILITY_')) { showQuota(res); return; }
    if (res.errors) fieldErrors(form, res.errors); else toast(res.message, { kind: 'error', timeout: 8000 });
  });
  syncSource();
}
