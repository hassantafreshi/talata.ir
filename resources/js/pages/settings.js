import { post } from '../lib/http.js';
import { toast, busy } from '../lib/ui.js';
import * as webauthn from '../lib/webauthn.js';

export default function () {
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-invite-accept],[data-invite-decline],[data-switch]');
    if (!b) return;
    let url;
    if (b.dataset.inviteAccept) url = `/api/invites/${b.dataset.inviteAccept}/accept`;
    else if (b.dataset.inviteDecline) { if (!confirm('این دعوت رد شود؟')) return; url = `/api/invites/${b.dataset.inviteDecline}/decline`; }
    else url = `/api/memberships/${b.dataset.switch}/switch`;
    b.disabled = true;
    const res = await post(url);
    if (res.ok) { if (res.data.next) location.href = res.data.next; else location.reload(); return; }
    b.disabled = false;
    toast(res.message, { kind: 'error' });
  });

  passkeys();
}

async function passkeys() {
  const add = document.querySelector('[data-passkey-add]');
  if (!add) return;
  if (await webauthn.platformAvailable()) add.classList.remove('hidden');
  else document.querySelector('[data-passkey-unsupported]').classList.remove('hidden');
  add.addEventListener('click', async () => {
    busy(add);
    try {
      const opts = await post('/api/passkeys/options');
      if (!opts.ok) {
        if (opts.code === 'REAUTH_REQUIRED') { toast(opts.message, { kind: 'error', timeout: 9000, action: { label: 'ورود دوباره', onClick: () => logoutTo() } }); return; }
        toast(opts.message, { kind: 'error' }); return;
      }
      let credential;
      try { credential = await webauthn.create(opts.data); } catch (e) { toast(webauthn.errorMessage(e), { kind: 'error' }); return; }
      const res = await post('/api/passkeys', { credential });
      if (res.ok) { toast('ورود با اثر انگشت فعال شد.'); setTimeout(() => location.reload(), 700); } else toast(res.message, { kind: 'error' });
    } finally { busy(add, false); }
  });
  document.querySelector('[data-passkey-list]')?.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-passkey-remove]');
    if (!b || !confirm('ورود با اثر انگشت روی این دستگاه حذف شود؟')) return;
    const res = await (await import('../lib/http.js')).del(`/api/passkeys/${b.dataset.passkeyRemove}`);
    if (res.ok) { b.closest('li').remove(); toast('حذف شد.'); } else toast(res.message, { kind: 'error' });
  });
}

function logoutTo() {
  const form = document.createElement('form');
  form.method = 'POST'; form.action = '/logout?then=passkey';
  const t = document.createElement('input'); t.type = 'hidden'; t.name = '_token'; t.value = document.querySelector('meta[name="csrf-token"]').content;
  form.appendChild(t); document.body.appendChild(form);
  form.submit();
}
