// Toasts, bottom sheets/dialogs, busy buttons, field errors.
export function toast(message, { kind = 'info', action = null, timeout = 5000 } = {}) {
  let host = document.querySelector('.toasts');
  if (!host) { host = document.createElement('div'); host.className = 'toasts'; host.setAttribute('aria-live', 'polite'); document.body.appendChild(host); }
  const el = document.createElement('div');
  el.className = 'toast' + (kind === 'error' ? ' err' : '');
  el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
  const span = document.createElement('span'); span.textContent = message; el.appendChild(span);
  if (action) {
    const b = document.createElement('button'); b.type = 'button'; b.textContent = action.label;
    b.addEventListener('click', () => { action.onClick(); el.remove(); }); el.appendChild(b);
  }
  host.appendChild(el);
  setTimeout(() => el.remove(), timeout);
  return el;
}

export function busy(button, on = true) {
  if (!button) return;
  if (on) {
    button.dataset.label = button.innerHTML;
    button.disabled = true; button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<span class="spin" aria-hidden="true"></span><span>' + (button.dataset.busyText || 'لطفاً صبر کنید…') + '</span>';
  } else {
    button.disabled = false; button.removeAttribute('aria-busy');
    if (button.dataset.label) button.innerHTML = button.dataset.label;
  }
}

let lastFocus = null;
export function openSheet(sheet) {
  const backdrop = sheet.closest('.sheet-backdrop');
  lastFocus = document.activeElement;
  backdrop.hidden = false;
  document.body.style.overflow = 'hidden';
  const focusable = sheet.querySelector('[autofocus], button, [href], input, select, textarea');
  focusable?.focus();
  const onKey = (e) => {
    if (e.key === 'Escape') closeSheet(sheet);
    if (e.key === 'Tab') trapFocus(e, sheet);
  };
  sheet._onKey = onKey;
  document.addEventListener('keydown', onKey);
  backdrop.onclick = (e) => { if (e.target === backdrop) closeSheet(sheet); };
}

export function closeSheet(sheet) {
  const backdrop = sheet.closest('.sheet-backdrop');
  backdrop.hidden = true;
  document.body.style.overflow = '';
  if (sheet._onKey) document.removeEventListener('keydown', sheet._onKey);
  lastFocus?.focus?.();
}

function trapFocus(e, root) {
  const els = [...root.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')].filter((x) => !x.disabled && x.offsetParent !== null);
  if (!els.length) return;
  const first = els[0], last = els[els.length - 1];
  if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
  else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
}

/** Builds a sheet on the fly. Returns {sheet, close}. */
export function sheet(html, { label = 'پنجره' } = {}) {
  const backdrop = document.createElement('div');
  backdrop.className = 'sheet-backdrop'; backdrop.hidden = true;
  backdrop.innerHTML = `<div class="sheet" role="dialog" aria-modal="true" aria-label="${label}">${html}</div>`;
  document.body.appendChild(backdrop);
  const el = backdrop.querySelector('.sheet');
  openSheet(el);
  const close = () => { closeSheet(el); backdrop.remove(); };
  backdrop.onclick = (e) => { if (e.target === backdrop) close(); };
  el.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', close));
  return { sheet: el, close };
}

export function fieldErrors(form, errors) {
  form.querySelectorAll('.field.invalid').forEach((f) => f.classList.remove('invalid'));
  if (!errors) return;
  let first = null;
  for (const [name, msgs] of Object.entries(errors)) {
    const input = form.querySelector(`[name="${CSS.escape(name)}"]`);
    const field = input?.closest('.field');
    if (!field) continue;
    field.classList.add('invalid');
    let err = field.querySelector('.err');
    if (!err) { err = document.createElement('div'); err.className = 'err'; field.appendChild(err); }
    err.textContent = Array.isArray(msgs) ? msgs[0] : msgs;
    input.setAttribute('aria-invalid', 'true');
    first ??= input;
  }
  first?.focus();
}

export function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
