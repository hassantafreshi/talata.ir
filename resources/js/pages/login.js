import { get, post } from '../lib/http.js';
import { busy, fieldErrors, toast } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';

// Weak-network budget (docs/PERFORMANCE_BUDGET.md: ≤8 requests for login): the proof-of-work solver and the
// passkey helpers load on demand, in parallel with the request that needs them, not with the page.
export async function requestCode(mobile, website = '') {
  const [pow, { solve }] = await Promise.all([get('/api/auth/pow'), import('../lib/pow.js')]);
  if (!pow.ok) return pow;
  const nonce = await solve(pow.data.challenge, pow.data.bits);
  const wait = 2100 - (Date.now() - pow.data.issued_at * 1000);
  if (wait > 0) await new Promise((r) => setTimeout(r, wait));
  return post('/api/auth/otp/request', { mobile: toLatin(mobile), pow_challenge: pow.data.challenge, pow_nonce: nonce, website });
}

export default function () {
  const form = document.querySelector('[data-login-form]');
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
  btn.addEventListener('click', async () => {
    busy(btn);
    try {
      // Options and helper module in parallel: the browser prompt follows the tap as closely as before.
      const [opts, webauthn] = await Promise.all([post('/api/auth/passkey/options'), import('../lib/webauthn.js')]);
      if (!opts.ok) { toast(opts.message, { kind: 'error' }); return; }
      let credential;
      try { credential = await webauthn.get(opts.data); } catch (e) { toast(webauthn.errorMessage(e), { kind: 'error' }); return; }
      const res = await post('/api/auth/passkey/verify', { credential });
      if (res.ok) { location.href = res.data.next; return; }
      toast(res.message, { kind: 'error', timeout: 8000 });
    } finally { busy(btn, false); }
  });
}
