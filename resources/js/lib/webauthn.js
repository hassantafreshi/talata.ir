// WebAuthn helpers: JSON options from the server ⇄ browser credential API (base64url fields).
import { post } from './http.js';

const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const unb64 = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0));

export function supported() {
  return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
}

/** True when this device has a built-in fingerprint/face/screen-lock authenticator. */
export async function platformAvailable() {
  if (!supported()) return false;
  try { return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(); } catch { return false; }
}

/** In-app browsers (Telegram, Instagram, WhatsApp …) usually have no WebAuthn; the fix is «open in Chrome/Safari». */
export function inAppBrowser(ua = navigator.userAgent || '') {
  return /Telegram|Instagram|FBAN|FBAV|WhatsApp|; wv\)|Line\//i.test(ua);
}

// Credential ids enrolled or used on THIS device, so sign-in can name them. Some Android authenticators save a
// key that is not "discoverable": an empty allow-list (no account hint, by design) would never find it.
const idsKey = (scope) => `zarlio.pk.${scope}`;
export function knownIds(scope) {
  try { return JSON.parse(localStorage.getItem(idsKey(scope)) || '[]').filter((s) => typeof s === 'string' && /^[A-Za-z0-9_-]{8,700}$/.test(s)); } catch { return []; }
}
export function rememberId(scope, id) {
  try { localStorage.setItem(idsKey(scope), JSON.stringify([id, ...knownIds(scope).filter((x) => x !== id)].slice(0, 10))); } catch {}
}

/**
 * Ceremony options fetched BEFORE the tap. iPhone/Safari only lets a page call the fingerprint prompt right after
 * a tap; a network round trip in between (slow connections) uses that up and the prompt is refused as
 * «cancelled / not allowed». warm() fetches ahead; take() hands over a ready answer (refetching only if the
 * server's 3-minute challenge is about to expire). Call warm() again after each attempt — never during one, or
 * the server would replace the challenge the pending credential was made for.
 */
export function prepared(url, body = undefined, maxAgeMs = 150000) {
  let pending = null;
  let at = 0;
  const warm = () => { at = Date.now(); pending = post(url, body); pending.catch(() => {}); return pending; };
  return {
    warm,
    take() {
      if (!pending || Date.now() - at > maxAgeMs) warm();
      const p = pending;
      pending = null;
      return p;
    },
  };
}

export const isAndroid = (ua = navigator.userAgent || '') => /Android/i.test(ua);

/**
 * Turn on fingerprint sign-in from a button (settings, post-login card, admin account). First try a synced
 * passkey; if an Android phone refuses right after the fingerprint (NotAllowedError — typically Google Password
 * Manager could not save it), offer one more tap that keeps the key only on this phone.
 * @param {HTMLElement} button
 * @param {{optionsUrl:string, storeUrl:string, scope:'user'|'staff', reportUrl:string, stage:string,
 *          onOptionsError?:(res:object)=>void, onDone:(res:object)=>void, toast:Function, busy:Function}} cfg
 */
export function enroll(button, cfg) {
  const synced = prepared(cfg.optionsUrl);
  const device = prepared(cfg.optionsUrl, { device_bound: 1 });
  let mode = 'synced'; // which options are warmed: only ONE challenge lives in the session at a time
  const current = () => (mode === 'device' ? device : synced);
  current().warm();

  const attempt = async (useDevice) => {
    if (useDevice !== (mode === 'device')) { mode = useDevice ? 'device' : 'synced'; current().warm(); }
    cfg.busy(button);
    let next = mode;
    try {
      const opts = await current().take();
      if (!opts.ok) { (cfg.onOptionsError || ((r) => cfg.toast(r.message, { kind: 'error' })))(opts); return; }
      let credential;
      try { credential = await create(opts.data); } catch (e) {
        report(cfg.reportUrl, cfg.stage + (mode === 'device' ? '-device' : ''), e);
        if (mode === 'synced' && e?.name === 'NotAllowedError' && isAndroid() && !inAppBrowser()) {
          next = 'device';
          cfg.toast('گوشی کلید را ذخیره نکرد (معمولاً وقتی ذخیره در حساب گوگل ممکن نیست). یک بار دیگر بزنید تا کلید فقط روی همین گوشی ساخته شود.', {
            kind: 'error', timeout: 20000, action: { label: 'فعال‌سازی روی همین گوشی', onClick: () => attempt(true) },
          });
        } else {
          cfg.toast(errorMessage(e), { kind: 'error', timeout: 9000 });
        }
        return;
      }
      const res = await post(cfg.storeUrl, { credential });
      if (res.ok) { rememberId(cfg.scope, credential.rawId); cfg.onDone(res); return; }
      cfg.toast(res.message, { kind: 'error', timeout: 9000 });
    } finally {
      cfg.busy(button, false);
      // Re-arm for the next tap (after the attempt, never during it: one challenge per session).
      mode = next;
      if (button.isConnected) current().warm();
    }
  };
  button.addEventListener('click', () => attempt(mode === 'device'));
  return { attempt };
}

export async function create(o) {
  const cred = await navigator.credentials.create({ publicKey: {
    ...o, challenge: unb64(o.challenge), user: { ...o.user, id: unb64(o.user.id) },
    excludeCredentials: (o.excludeCredentials || []).map((c) => ({ ...c, id: unb64(c.id) })),
  } });
  return {
    id: cred.id, rawId: b64(cred.rawId), type: cred.type,
    response: {
      clientDataJSON: b64(cred.response.clientDataJSON), attestationObject: b64(cred.response.attestationObject),
      transports: cred.response.getTransports ? cred.response.getTransports() : [],
    },
  };
}

/** $hints: credential ids known on this device (knownIds); empty = any discoverable passkey for this site. */
export async function get(o, hints = []) {
  const allow = (o.allowCredentials && o.allowCredentials.length)
    ? o.allowCredentials
    : hints.map((id) => ({ type: 'public-key', id, transports: ['internal', 'hybrid'] }));
  const cred = await navigator.credentials.get({ publicKey: {
    ...o, challenge: unb64(o.challenge), allowCredentials: allow.map((c) => ({ ...c, id: unb64(c.id) })),
  } });
  return {
    id: cred.id, rawId: b64(cred.rawId), type: cred.type,
    response: {
      clientDataJSON: b64(cred.response.clientDataJSON), authenticatorData: b64(cred.response.authenticatorData),
      signature: b64(cred.response.signature), userHandle: cred.response.userHandle ? b64(cred.response.userHandle) : null,
    },
  };
}

/**
 * Tells the server why the browser refused (name and message only — no credential data), so failures on real
 * phones show up in the technical log. Never throws; never blocks the page.
 */
export function report(url, stage, e) {
  try {
    post(url, { stage, name: String(e?.name || 'Error').slice(0, 60), message: String(e?.message || '').slice(0, 300), in_app: inAppBrowser() }).catch(() => {});
  } catch {}
}

/** Persian message for a browser-side WebAuthn error, with the browser's error name for support. */
export function errorMessage(e) {
  const code = e && e.name ? ` (${e.name})` : '';
  if (inAppBrowser()) return 'این مرورگرِ داخل برنامه از اثر انگشت پشتیبانی نمی‌کند. سایت را در Chrome یا Safari باز کنید.';
  if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) return `لغو شد یا زمان آن تمام شد. دوباره بزنید و اثر انگشت را بلافاصله تأیید کنید.${code}`;
  if (e && e.name === 'InvalidStateError') return 'اثر انگشت این دستگاه قبلاً فعال شده است.';
  if (e && e.name === 'SecurityError') return `آدرس این صفحه با تنظیمات امنیتی سایت جور نیست (HTTPS و دامنه درست لازم است).${code}`;
  if (e && e.name === 'NotSupportedError') return `این دستگاه نوع کلید لازم را پشتیبانی نمی‌کند. با کد پیامکی وارد شوید.${code}`;
  return `دستگاه شما ورود با اثر انگشت را انجام نداد. با کد پیامکی وارد شوید.${code}`;
}
