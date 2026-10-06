import { get, post } from './http.js';
import { toLatin } from './digits.js';

// Login code request: proof-of-work (solver loaded with the challenge, not with the page), then the request.
// Always resolves to an http-style result, so the caller's button never stays busy (e.g. the solver chunk
// failed to download on a weak connection).
export async function requestCode(mobile, website = '') {
  let pow, solve, nonce;
  try {
    [pow, { solve }] = await Promise.all([get('/api/auth/pow'), import('./pow.js')]);
    if (!pow.ok) return pow;
    nonce = await solve(pow.data.challenge, pow.data.bits);
  } catch {
    return { ok: false, code: 'CHUNK_FAILED', message: 'بخشی از صفحه دریافت نشد. اینترنت را بررسی کنید و دوباره «دریافت کد» را بزنید.' };
  }
  const wait = 2100 - (Date.now() - pow.data.issued_at * 1000);
  if (wait > 0) await new Promise((r) => setTimeout(r, wait));
  return post('/api/auth/otp/request', { mobile: toLatin(mobile), pow_challenge: pow.data.challenge, pow_nonce: nonce, website });
}
