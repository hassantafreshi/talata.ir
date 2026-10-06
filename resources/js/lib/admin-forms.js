import { request, idempotencyKey } from './http.js';
import { busy, toast, fieldErrors } from './ui.js';
import { toLatin, group, toPersian } from './digits.js';
import { MONTHS, parse as parseJalali } from './jalali.js';

// Admin console actions (docs/handoff/04_SCREENS_ADMIN.md A-00).
//  <form data-action="/admin/api/…" [data-method] [data-confirm="… {field} …"] [data-idem] [data-reload]>
//  <button data-post="/admin/api/…" [data-confirm] [data-reload]>
// Fields go as JSON (checkbox → boolean). {field} in the confirm text is filled from the form so the
// dialog repeats the effect. data-idem keeps one idempotency key per attempt: a retry after a lost
// response is applied once on the server; a success starts a new key.

function collect(form) {
  const data = {};
  for (const el of form.elements) {
    if (!el.name || el.disabled || el.type === 'submit' || el.type === 'button') continue;
    if (el.type === 'checkbox') {
      if (el.name.endsWith('[]')) { const k = el.name.slice(0, -2); data[k] ??= []; if (el.checked) data[k].push(el.value); } else data[el.name] = el.checked;
    } else if (el.type === 'radio') {
      if (el.checked) data[el.name] = el.value;
    } else data[el.name] = el.value.trim();
  }
  return data;
}

function shown(form, name) {
  const el = form.elements[name];
  if (!el) return '';
  if (el instanceof RadioNodeList) {
    const on = [...el].find((r) => r.checked);
    return on ? (on.closest('label')?.textContent.trim() || on.value) : '';
  }
  if (el.tagName === 'SELECT') return el.selectedOptions[0]?.textContent.trim() || '';
  if (el.type === 'checkbox') return el.checked ? 'بله' : 'خیر';
  if (el.type === 'hidden' && el.closest('[data-jdp]')) {
    const d = parseJalali(el.value);
    return d ? `${toPersian(d[2])} ${MONTHS[d[1] - 1]} ${toPersian(d[0])}` : el.value;
  }
  const v = el.value.trim();
  // Amounts in the confirmation are grouped Persian digits («۱۰٬۲۱۸٬۳۶۰»), as on the rest of the page.
  if (el.inputMode === 'numeric' && /^\d+$/.test(toLatin(v).replace(/[,٬\s]/g, ''))) return group(toLatin(v).replace(/[,٬\s]/g, ''));
  if (el.inputMode === 'decimal') return toPersian(toLatin(v));
  return v;
}

const fill = (text, form) => (text || '').replace(/\{([a-z_]+)\}/g, (_, n) => shown(form, n) || '—');

async function send(method, url, data, btn, { reload, form } = {}) {
  busy(btn);
  let res = await request(method, url, data);
  if (!res.ok && res.code === 'CONFIRM_LARGE_CHANGE' && window.confirm(res.message)) {
    res = await request(method, url, { ...data, confirm_large: true });
  }
  busy(btn, false);
  if (res.ok) {
    if (form) delete form.dataset.key;
    toast(res.data?.replayed ? 'این کار قبلاً انجام شده بود و دوباره انجام نشد.' : (res.data?.message_fa || 'انجام شد.'));
    if (reload) setTimeout(() => location.reload(), 800);
    return res;
  }
  if (form && res.errors) fieldErrors(form, res.errors);
  if (res.code !== 'REAUTH_REQUIRED' && res.code !== 'CONFIRM_LARGE_CHANGE') toast(res.message, { kind: 'error', timeout: 8000 });
  return res;
}

export function wireAdminActions(root = document) {
  root.addEventListener('submit', (e) => {
    const form = e.target.closest('form[data-action]');
    if (!form) return;
    e.preventDefault();
    const data = collect(form);
    const text = fill(form.dataset.confirm, form);
    if (text && !window.confirm(text)) return;
    if (form.hasAttribute('data-idem')) {
      form.dataset.key ||= idempotencyKey('adm');
      data.idempotency_key = form.dataset.key;
    }
    send(form.dataset.method || 'POST', form.dataset.action, data, form.querySelector('[type=submit]'), { reload: form.hasAttribute('data-reload'), form });
  });
  root.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-post]');
    if (!btn) return;
    if (btn.dataset.confirm && !window.confirm(btn.dataset.confirm)) return;
    send('POST', btn.dataset.post, {}, btn, { reload: btn.hasAttribute('data-reload') });
  });
}
