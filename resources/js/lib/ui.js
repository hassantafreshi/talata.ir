// Toasts, bottom sheets/dialogs, busy buttons, field errors.
export function toast(message, { kind = 'info', action = null, timeout = 5000 } = {}) {
  let host = document.querySelector('.toasts');
  if (!host) { host = document.createElement('div'); host.className = 'toasts'; host.setAttribute('aria-live', 'polite'); document.body.appendChild(host); }
  // The same message twice (e.g. the HTTP layer and the page both report an ended session) shows once.
  const same = [...host.children].find((t) => t.dataset.msg === message);
  if (same) return same;
  const el = document.createElement('div');
  el.dataset.msg = message;
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
  sheet._remove?.(); // sheets built on the fly are removed however they close (Escape, backdrop, button)
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
  el._remove = () => backdrop.remove();
  openSheet(el);
  const close = () => closeSheet(el);
  backdrop.onclick = (e) => { if (e.target === backdrop) close(); };
  el.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', close));
  return { sheet: el, close };
}

let errSeq = 0;
export function fieldErrors(form, errors) {
  form.querySelectorAll('.field.invalid').forEach((f) => f.classList.remove('invalid'));
  form.querySelectorAll('[aria-invalid="true"]').forEach((i) => i.removeAttribute('aria-invalid'));
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
    // Screen readers read the message with the field (WCAG 3.3.1).
    err.id ||= `err-${++errSeq}`;
    const described = new Set((input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
    described.add(err.id);
    input.setAttribute('aria-describedby', [...described].join(' '));
    input.setAttribute('aria-invalid', 'true');
    first ??= input;
  }
  first?.focus();
}

/** One field's error, announced with the field (aria-invalid + aria-describedby). */
export function markInvalid(field, message) {
  field.classList.add('invalid');
  const err = field.querySelector('.err');
  if (err) { err.textContent = message; err.id ||= `err-${++errSeq}`; }
  const input = field.querySelector('input:not([type=hidden]), select, textarea');
  if (input) { input.setAttribute('aria-invalid', 'true'); if (err) input.setAttribute('aria-describedby', err.id); }
}

export function clearInvalid(root) {
  root.querySelectorAll('.field.invalid').forEach((f) => { f.classList.remove('invalid'); f.querySelectorAll('[aria-invalid]').forEach((i) => i.removeAttribute('aria-invalid')); });
}

export function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
