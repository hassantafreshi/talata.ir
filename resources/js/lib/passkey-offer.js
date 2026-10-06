import { post } from './http.js';
import { toast, busy } from './ui.js';
import * as webauthn from './webauthn.js';

const DISMISSED = 'zarlio.pkOfferDismissed';

// The one-time "turn on fingerprint login?" card shown after an SMS login. The server only renders it
// when the person has no passkey; this reveals it only where the device actually supports a platform
// authenticator and the person has not said "not now" before.
export default async function passkeyOffer() {
  const card = document.querySelector('[data-passkey-offer]');
  if (!card) return;
  const remove = () => card.remove();

  try { if (localStorage.getItem(DISMISSED) === '1') { remove(); return; } } catch {}
  if (!(await webauthn.platformAvailable())) { remove(); return; }

  card.hidden = false;
  card.classList.remove('hidden');

  const dismiss = (persist) => {
    if (persist) { try { localStorage.setItem(DISMISSED, '1'); } catch {} }
    remove();
  };

  card.querySelector('[data-passkey-offer-dismiss]').addEventListener('click', () => dismiss(true));

  const add = card.querySelector('[data-passkey-offer-add]');
  add.addEventListener('click', async () => {
    busy(add);
    try {
      const opts = await post('/api/passkeys/options');
      if (!opts.ok) { toast(opts.message, { kind: 'error' }); return; }
      let credential;
      try { credential = await webauthn.create(opts.data); }
      catch (e) { toast(webauthn.errorMessage(e), { kind: 'error' }); return; }
      const res = await post('/api/passkeys', { credential });
      if (res.ok) { toast('ورود با اثر انگشت فعال شد. دفعه بعد با اثر انگشت وارد شوید.'); dismiss(true); }
      else toast(res.message, { kind: 'error' });
    } finally { busy(add, false); }
  });
}
