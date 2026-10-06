import { get, post } from './http.js';
import { toLatin } from './digits.js';

// Login code request: proof-of-work (solver loaded with the challenge, not with the page), then the request.
export async function requestCode(mobile, website = '') {
  const [pow, { solve }] = await Promise.all([get('/api/auth/pow'), import('./pow.js')]);
  if (!pow.ok) return pow;
  const nonce = await solve(pow.data.challenge, pow.data.bits);
  const wait = 2100 - (Date.now() - pow.data.issued_at * 1000);
  if (wait > 0) await new Promise((r) => setTimeout(r, wait));
  return post('/api/auth/otp/request', { mobile: toLatin(mobile), pow_challenge: pow.data.challenge, pow_nonce: nonce, website });
}
