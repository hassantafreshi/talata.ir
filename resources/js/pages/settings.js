import { post } from '../lib/http.js';
import { toast, busy, sheet, fieldErrors } from '../lib/ui.js';
import { toPersian } from '../lib/digits.js';
import * as webauthn from '../lib/webauthn.js';

export default function () {
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-invite-accept],[data-invite-decline],[data-switch]');
    if (!b) return;
    let url;
    if (b.dataset.inviteAccept) url = `/api/invites/${b.dataset.inviteAccept}/accept`;
    else if (b.dataset.inviteDecline) { if (!confirm('این دعوت رد شود؟')) return; url = `/api/invites/${b.dataset.inviteDecline}/decline`; }
    else url = `/api/memberships/${b.dataset.switch}/switch`;
    b.disabled = true;
    const res = await post(url);
    if (res.ok) { if (res.data.next) location.href = res.data.next; else location.reload(); return; }
    b.disabled = false;
    toast(res.message, { kind: 'error' });
  });

  passkeys();
  mobileChange();
  document.querySelector('[data-sign-out-others]')?.addEventListener('click', (e) => signOutOthers(e.currentTarget));
}

async function signOutOthers(button) {
  if (!confirm('از همه دستگاه‌های دیگر خارج شوید؟ این دستگاه وارد می‌ماند.')) return;
  busy(button);
  const res = await post('/api/security/sign-out-others');
  busy(button, false);
  toast(res.ok ? 'از همه دستگاه‌های دیگر خارج شدید.' : res.message, { kind: res.ok ? 'info' : 'error' });
}

// Two-step login-number change, driven from a sheet. Each step talks to its own endpoint; the
// server holds the real state, so a reload simply restarts the ceremony.
function mobileChange() {
  const start = document.querySelector('[data-mobile-change-start]');
  const tpl = document.querySelector('[data-mch-tpl]');
  if (!start || !tpl) return;

  start.addEventListener('click', async () => {
    busy(start);
    const res = await post('/api/security/mobile/start');
    busy(start, false);
    if (!res.ok) { toast(res.message, { kind: 'error' }); return; }

    const { sheet: el, close } = sheet(tpl.innerHTML, { label: 'تغییر شماره ورود' });
    const forms = {
      old: el.querySelector('[data-mch-form="old"]'),
      ask: el.querySelector('[data-mch-form="ask-new"]'),
      new: el.querySelector('[data-mch-form="new"]'),
    };
    const steps = el.querySelectorAll('[data-step]');
    const show = (name, stepKey) => {
      Object.values(forms).forEach((f) => { f.hidden = true; });
      forms[name].hidden = false;
      steps.forEach((s) => s.classList.toggle('on', s.dataset.step === stepKey));
      forms[name].querySelector('input')?.focus();
    };
    const setMasked = (form, masked) => { const s = form.querySelector('[data-mch-masked]'); if (s) s.textContent = toPersian(masked); };

    // Resend countdown for an OTP form. `sender()` returns the http result of re-requesting the code;
    // on success the button re-arms itself with the fresh cooldown.
    const arm = (form, after, sender) => {
      const btn = form.querySelector('[data-mch-resend]');
      if (!btn) return;
      let left = after;
      btn.hidden = false; btn.disabled = true;
      const tick = () => { btn.textContent = left > 0 ? `ارسال دوباره کد (${toPersian(String(left))})` : 'ارسال دوباره کد'; btn.disabled = left > 0; };
      tick();
      const timer = setInterval(() => { left -= 1; if (left <= 0) clearInterval(timer); tick(); }, 1000);
      btn.onclick = async () => {
        clearInterval(timer); btn.hidden = true;
        const r = await sender();
        if (r.ok) { setMasked(form, r.data.masked); arm(form, r.data.resend_after_seconds, sender); } else toast(r.message, { kind: 'error' });
      };
    };

    setMasked(forms.old, res.data.masked);
    show('old', 'old');
    arm(forms.old, res.data.resend_after_seconds, () => post('/api/security/mobile/start'));

    forms.old.addEventListener('submit', async (e) => {
      e.preventDefault();
      fieldErrors(forms.old, null);
      const b = forms.old.querySelector('[type=submit]'); busy(b);
      const r = await post('/api/security/mobile/verify-current', { code: forms.old.code.value.trim() });
      busy(b, false);
      if (r.ok) { show('ask', 'new'); return; }
      if (r.errors) fieldErrors(forms.old, r.errors); else fieldErrors(forms.old, { code: r.message });
    });

    forms.ask.addEventListener('submit', async (e) => {
      e.preventDefault();
      fieldErrors(forms.ask, null);
      const b = forms.ask.querySelector('[type=submit]'); busy(b);
      const sendNew = () => post('/api/security/mobile/request-new', { mobile: forms.ask.mobile.value.trim() });
      const r = await sendNew();
      busy(b, false);
      if (!r.ok) {
        // Verification expired or spent on too many numbers: start over from the current number.
        if (r.code === 'MCH_FLOW' || r.code === 'MCH_TOO_MANY_TARGETS') { close(); toast(r.message, { kind: 'error', timeout: 9000 }); return; }
        if (r.errors) fieldErrors(forms.ask, r.errors); else fieldErrors(forms.ask, { mobile: r.message });
        return;
      }
      setMasked(forms.new, r.data.masked);
      show('new', 'new');
      arm(forms.new, r.data.resend_after_seconds, sendNew);
    });

    forms.new.addEventListener('submit', async (e) => {
      e.preventDefault();
      fieldErrors(forms.new, null);
      const b = forms.new.querySelector('[type=submit]'); busy(b);
      const r = await post('/api/security/mobile/confirm', { code: forms.new.code.value.trim() });
      busy(b, false);
      if (r.ok) { close(); toast('شماره ورود تغییر کرد و از دستگاه‌های دیگر خارج شدید.'); setTimeout(() => location.reload(), 900); return; }
      if (r.code === 'MCH_FLOW') { close(); toast(r.message, { kind: 'error' }); return; }
      if (r.errors) fieldErrors(forms.new, r.errors); else fieldErrors(forms.new, { code: r.message });
    });
  });
}

async function passkeys() {
  const add = document.querySelector('[data-passkey-add]');
  if (!add) return;
  document.querySelector('[data-passkey-list]')?.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-passkey-remove]');
    if (!b || !confirm('ورود با اثر انگشت روی این دستگاه حذف شود؟')) return;
    const res = await (await import('../lib/http.js')).del(`/api/passkeys/${b.dataset.passkeyRemove}`);
    // A removed key often means a lost phone: offer to end that phone's sessions too.
    if (res.ok) { b.closest('li').remove(); toast('حذف شد. اگر این گوشی گم شده، از دستگاه‌های دیگر هم خارج شوید.', { timeout: 12000, action: { label: 'خروج از دستگاه‌های دیگر', onClick: () => signOutOthers(document.querySelector('[data-sign-out-others]')) } }); } else toast(res.message, { kind: 'error' });
  });
  if (!(await webauthn.platformAvailable())) {
    const note = document.querySelector('[data-passkey-unsupported]');
    if (webauthn.inAppBrowser()) note.textContent = 'این مرورگرِ داخل برنامه (مثل تلگرام) از اثر انگشت پشتیبانی نمی‌کند. سایت را در Chrome یا Safari باز کنید.';
    note.classList.remove('hidden');
    return;
  }
  add.classList.remove('hidden');
  const options = webauthn.prepared('/api/passkeys/options');
  options.warm();
  add.addEventListener('click', async () => {
    busy(add);
    try {
      const opts = await options.take();
      if (!opts.ok) {
        if (opts.code === 'REAUTH_REQUIRED') { toast(opts.message, { kind: 'error', timeout: 9000, action: { label: 'ورود دوباره', onClick: () => logoutTo() } }); return; }
        toast(opts.message, { kind: 'error' }); return;
      }
      let credential;
      try { credential = await webauthn.create(opts.data); } catch (e) { webauthn.report('/api/webauthn/report', 'register', e); toast(webauthn.errorMessage(e), { kind: 'error', timeout: 9000 }); return; }
      const res = await post('/api/passkeys', { credential });
      if (res.ok) { webauthn.rememberId('user', credential.rawId); toast('ورود با اثر انگشت فعال شد.'); setTimeout(() => location.reload(), 700); } else toast(res.message, { kind: 'error', timeout: 9000 });
    } finally { busy(add, false); options.warm(); }
  });
}

function logoutTo() {
  const form = document.createElement('form');
  form.method = 'POST'; form.action = '/logout?then=passkey';
  const t = document.createElement('input'); t.type = 'hidden'; t.name = '_token'; t.value = document.querySelector('meta[name="csrf-token"]').content;
  form.appendChild(t); document.body.appendChild(form);
  form.submit();
}
