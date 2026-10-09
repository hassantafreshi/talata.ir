import { post } from '../lib/http.js';
import { busy, fieldErrors, toast } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';
import { prepare, requestCode } from '../lib/otp.js';

// Weak-network budget (docs/PERFORMANCE_BUDGET.md: ≤8 requests for login): the proof-of-work solver and the
// passkey helpers load on demand, in parallel with the request that needs them, not with the page.

export default function () {
  const form = document.querySelector('[data-login-form]');
  // Start the security check as soon as the user starts on the number, not after the tap.
  ['focusin', 'input'].forEach((ev) => form.mobile.addEventListener(ev, prepare, { once: true }));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type=submit]');
    fieldErrors(form, null);
    const mobile = form.mobile.value.trim();
    if (toLatin(mobile).replace(/\D/g, '').length < 10) { fieldErrors(form, { mobile: ['شماره موبایل درست نیست. نمونه: ۰۹۱۲ ۳۴۵ ۶۷۸۹'] }); return; }
    busy(btn);
    const res = await requestCode(mobile, form.website.value);
    busy(btn, false);
    if (res.ok) { location.href = res.data.next; return; }
    if (res.errors) fieldErrors(form, res.errors); else toast(res.message, { kind: 'error' });
  });

  passkeyLogin();
}

async function passkeyLogin() {
  const box = document.querySelector('[data-passkey-login]');
  // Same test as webauthn.supported(), inlined so the helper module is fetched only on use.
  if (!box || !(window.PublicKeyCredential && navigator.credentials && window.isSecureContext)) return;
  box.classList.remove('hidden');
  const btn = box.querySelector('[data-passkey-btn]');
  // The helper module and the challenge are fetched once the page is idle (not with the first render), so the
  // fingerprint prompt can open right inside the tap — iPhone/Safari refuses it after a network wait.
  let webauthn = null;
  let options = null;
  const ready = import('../lib/webauthn.js').then((m) => { webauthn = m; options = m.prepared('/api/auth/passkey/options'); return m; });
  const warmUp = () => ready.then(() => options.warm()).catch(() => {});
  if ('requestIdleCallback' in window) requestIdleCallback(warmUp, { timeout: 2500 }); else setTimeout(warmUp, 800);

  const attempt = async (useHints) => {
    busy(btn);
    try {
      try { if (!webauthn) await ready; } catch {
        toast('بخشی از صفحه دریافت نشد. اینترنت را بررسی کنید و دوباره بزنید، یا با کد پیامکی وارد شوید.', { kind: 'error', timeout: 9000 });
        return;
      }
      const opts = await options.take();
      if (!opts.ok) { toast(opts.message, { kind: 'error' }); return; }
      const hints = useHints ? webauthn.knownIds('user') : [];
      let credential;
      try { credential = await webauthn.get(opts.data, hints); } catch (e) {
        webauthn.report('/api/webauthn/report', hints.length ? 'login-hinted' : 'login', e);
        // Named keys from this phone not found (e.g. the passkey now lives in another device's keychain):
        // one more try that lets the phone offer any passkey it has for this site.
        const retry = hints.length && e?.name === 'NotAllowedError' ? { label: 'تلاش دوباره', onClick: () => attempt(false) } : null;
        toast(webauthn.errorMessage(e), { kind: 'error', timeout: 12000, action: retry });
        return;
      }
      const res = await post('/api/auth/passkey/verify', { credential });
      if (res.ok) { webauthn.rememberId('user', credential.rawId); location.href = res.data.next; return; }
      toast(res.message, { kind: 'error', timeout: 9000 });
    } finally { busy(btn, false); options?.warm(); }
  };
  btn.addEventListener('click', () => attempt(true));
}
