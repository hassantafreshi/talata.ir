import { get, post } from '../lib/http.js';
import { solve } from '../lib/pow.js';
import { busy, toast, fieldErrors } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';
import * as webauthn from '../lib/webauthn.js';

export default function () {
  const mobileForm = document.querySelector('[data-admin-mobile]');
  const codeForm = document.querySelector('[data-admin-code]');
  mobileForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = mobileForm.querySelector('[type=submit]');
    busy(btn);
    const pow = await get('/admin/api/pow');
    if (!pow.ok) { busy(btn, false); toast(pow.message, { kind: 'error' }); return; }
    const nonce = await solve(pow.data.challenge, pow.data.bits);
    await new Promise((r) => setTimeout(r, 2100));
    const res = await post('/admin/api/otp/request', { mobile: toLatin(mobileForm.mobile.value), pow_challenge: pow.data.challenge, pow_nonce: nonce });
    busy(btn, false);
    if (!res.ok) { if (res.errors) fieldErrors(mobileForm, res.errors); else toast(res.message, { kind: 'error' }); return; }
    mobileForm.hidden = true; codeForm.hidden = false; codeForm.code.focus();
  });
  codeForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = codeForm.querySelector('[type=submit]');
    busy(btn);
    const res = await post('/admin/api/otp/verify', { code: toLatin(codeForm.code.value) });
    busy(btn, false);
    if (res.ok) location.href = res.data.next; else fieldErrors(codeForm, { code: res.message });
  });
  const pk = document.querySelector('[data-admin-passkey]');
  if (webauthn.supported()) pk.classList.remove('hidden');
  pk.addEventListener('click', async () => {
    busy(pk);
    try {
      const opts = await post('/admin/api/passkey/options');
      if (!opts.ok) { toast(opts.message, { kind: 'error' }); return; }
      let credential;
      try { credential = await webauthn.get(opts.data); } catch (err) { toast(webauthn.errorMessage(err), { kind: 'error' }); return; }
      const res = await post('/admin/api/passkey/verify', { credential });
      if (res.ok) location.href = res.data.next; else toast(res.message, { kind: 'error' });
    } finally { busy(pk, false); }
  });
}
