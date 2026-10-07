import { post, del } from '../lib/http.js';
import { busy, toast } from '../lib/ui.js';
import * as webauthn from '../lib/webauthn.js';

export default function () {
  const add = document.querySelector('[data-passkey-add]');
  if (!webauthn.supported()) add.disabled = true;
  // Fetched before the tap so the fingerprint prompt follows it at once (required on iPhone).
  const options = webauthn.prepared('/admin/api/account/passkeys/options');
  if (webauthn.supported()) options.warm();
  add.addEventListener('click', async () => {
    busy(add);
    try {
      const opts = await options.take();
      if (!opts.ok) { toast(opts.message, { kind: 'error' }); return; }
      let credential;
      try { credential = await webauthn.create(opts.data); } catch (e) { webauthn.report('/admin/api/webauthn/report', 'register', e); toast(webauthn.errorMessage(e), { kind: 'error', timeout: 9000 }); return; }
      const res = await post('/admin/api/account/passkeys', { credential });
      if (res.ok) { webauthn.rememberId('staff', credential.rawId); location.reload(); } else toast(res.message, { kind: 'error', timeout: 9000 });
    } finally { busy(add, false); options.warm(); }
  });
  document.querySelector('[data-passkey-list]').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-passkey-remove]');
    if (!b || !confirm('این کلید حذف شود؟')) return;
    const res = await del(`/admin/api/account/passkeys/${b.dataset.passkeyRemove}`);
    if (res.ok) b.closest('li').remove(); else toast(res.message, { kind: 'error' });
  });
}
