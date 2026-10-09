import { get, post } from './http.js';
import { toLatin } from './digits.js';

// Login code request: proof-of-work, then the request. The work starts early (prepare(): when the user starts
// typing the number), so by the time «دریافت کد» is tapped the answer is usually ready. Always resolves to an
// http-style result, so the caller's button never stays busy (e.g. the solver chunk failed on a weak connection).
let pending = null;

function solveFresh() {
  const started = Date.now();
  const p = Promise.all([get('/api/auth/pow'), import('./pow.js')]).then(async ([pow, { solve }]) => {
    if (!pow.ok) return { res: pow };
    return { pow, nonce: await solve(pow.data.challenge, pow.data.bits) };
  });
  p.started = started;
  return p;
}

/** Starts the proof-of-work in the background (once); a challenge is single-use and valid for 5 minutes. */
export function prepare() {
  if (!pending || Date.now() - pending.started > 240_000) {
    pending = solveFresh();
    pending.catch(() => { pending = null; });
  }
}

export async function requestCode(mobile, website = '') {
  prepare();
  const job = pending;
  pending = null; // the next request needs a new challenge
  let done;
  try {
    done = await job;
  } catch {
    return { ok: false, code: 'CHUNK_FAILED', message: 'بخشی از صفحه دریافت نشد. اینترنت را بررسی کنید و دوباره «دریافت کد» را بزنید.' };
  }
  if (done.res) return done.res;
  const { pow, nonce } = done;
  const wait = 2100 - (Date.now() - pow.data.issued_at * 1000);
  if (wait > 0) await new Promise((r) => setTimeout(r, wait));
  return post('/api/auth/otp/request', { mobile: toLatin(mobile), pow_challenge: pow.data.challenge, pow_nonce: nonce, website });
}
