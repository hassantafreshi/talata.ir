import { get, post } from '../lib/http.js';
import { solve } from '../lib/pow.js';
import { busy, fieldErrors, toast } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';

export async function requestCode(mobile, website = '') {
  const pow = await get('/api/auth/pow');
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
}
