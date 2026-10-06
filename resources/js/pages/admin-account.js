import { post, del } from '../lib/http.js';
import { busy, toast } from '../lib/ui.js';
import * as webauthn from '../lib/webauthn.js';

export default function () {
  const add = document.querySelector('[data-passkey-add]');
  if (!webauthn.supported()) add.disabled = true;
  add.addEventListener('click', async () => {
    busy(add);
    try {
      const opts = await post('/admin/api/account/passkeys/options');
      if (!opts.ok) { toast(opts.message, { kind: 'error' }); return; }
      let credential;
      try { credential = await webauthn.create(opts.data); } catch (e) { toast(webauthn.errorMessage(e), { kind: 'error' }); return; }
      const res = await post('/admin/api/account/passkeys', { credential });
      if (res.ok) location.reload(); else toast(res.message, { kind: 'error' });
    } finally { busy(add, false); }
  });
  document.querySelector('[data-passkey-list]').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-passkey-remove]');
    if (!b || !confirm('این کلید حذف شود؟')) return;
    const res = await del(`/admin/api/account/passkeys/${b.dataset.passkeyRemove}`);
    if (res.ok) b.closest('li').remove(); else toast(res.message, { kind: 'error' });
  });
}
