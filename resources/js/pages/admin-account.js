import { del } from '../lib/http.js';
import { busy, toast } from '../lib/ui.js';
import * as webauthn from '../lib/webauthn.js';

export default function () {
  const add = document.querySelector('[data-passkey-add]');
  if (!webauthn.supported()) add.disabled = true;
  if (webauthn.supported()) {
    webauthn.enroll(add, {
      optionsUrl: '/admin/api/account/passkeys/options', storeUrl: '/admin/api/account/passkeys', scope: 'staff', reportUrl: '/admin/api/webauthn/report', stage: 'register', toast, busy,
      onDone: () => location.reload(),
    });
  }
  document.querySelector('[data-passkey-list]').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-passkey-remove]');
    if (!b || !confirm('این کلید حذف شود؟')) return;
    const res = await del(`/admin/api/account/passkeys/${b.dataset.passkeyRemove}`);
    if (res.ok) b.closest('li').remove(); else toast(res.message, { kind: 'error' });
  });
}
