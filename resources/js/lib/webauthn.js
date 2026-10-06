// WebAuthn helpers: JSON options from the server ⇄ browser credential API (base64url fields).
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

export async function get(o) {
  const cred = await navigator.credentials.get({ publicKey: {
    ...o, challenge: unb64(o.challenge), allowCredentials: (o.allowCredentials || []).map((c) => ({ ...c, id: unb64(c.id) })),
  } });
  return {
    id: cred.id, rawId: b64(cred.rawId), type: cred.type,
    response: {
      clientDataJSON: b64(cred.response.clientDataJSON), authenticatorData: b64(cred.response.authenticatorData),
      signature: b64(cred.response.signature), userHandle: cred.response.userHandle ? b64(cred.response.userHandle) : null,
    },
  };
}

/** Persian message for a browser-side WebAuthn error (user cancelled, timeout, not allowed …). */
export function errorMessage(e) {
  if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) return 'لغو شد یا زمان آن تمام شد. دوباره امتحان کنید.';
  if (e && e.name === 'InvalidStateError') return 'اثر انگشت این دستگاه قبلاً فعال شده است.';
  if (e && e.name === 'SecurityError') return 'این صفحه برای ورود با اثر انگشت امن نیست (HTTPS لازم است).';
  return 'دستگاه شما ورود با اثر انگشت را پشتیبانی نکرد. با کد پیامکی وارد شوید.';
}
