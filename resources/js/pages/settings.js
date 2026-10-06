import { post } from '../lib/http.js';
import { toast } from '../lib/ui.js';

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
}
