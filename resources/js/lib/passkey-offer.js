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
  // Options are fetched before the tap (iPhone) and an Android save failure offers a device-only retry.
  webauthn.enroll(add, {
    optionsUrl: '/api/passkeys/options', storeUrl: '/api/passkeys', scope: 'user', reportUrl: '/api/webauthn/report', stage: 'register-offer', toast, busy,
    onDone: () => { toast('ورود با اثر انگشت فعال شد. دفعه بعد با اثر انگشت وارد شوید.'); dismiss(true); },
  });
}
