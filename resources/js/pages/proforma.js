import { post } from '../lib/http.js';
import { busy, toast } from '../lib/ui.js';
import { showQuota } from '../lib/quota.js';

// Shop page of one پیش‌فاکتور: share the link (with the validity in the text), SMS, edit/cancel, issue.
export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  // Offer phone notifications for «مشتری تأیید کرد» only where they are possible and still off.
  const pushCard = document.querySelector('[data-push-card]');
  if (pushCard) import('../lib/push.js').then(async (m) => {
    const st = await m.pushState();
    if (st === 'off' || st === 'install-first') { pushCard.hidden = false; m.initPushCard(pushCard, toast); }
  });
  try {
    const note = sessionStorage.getItem('pf-sms-note');
    if (note) { sessionStorage.removeItem('pf-sms-note'); toast(`پیامک فرستاده نشد: ${note} لینک را با «اشتراک‌گذاری» بفرستید.`, { kind: 'error', timeout: 12000 }); }
  } catch {}

  document.querySelector('[data-pf-share]')?.addEventListener('click', async () => {
    const text = boot.text.replace(boot.link, '').trim();
    if (navigator.share) {
      try { await navigator.share({ title: `پیش‌فاکتور ${boot.number}`, text, url: boot.link }); return; } catch (e) { if (e?.name === 'AbortError') return; }
    }
    try { await navigator.clipboard.writeText(boot.text); toast('متن و لینک پیش‌فاکتور کپی شد.'); } catch { document.querySelector('[data-pf-link]')?.select(); }
  });

  const smsBtn = document.querySelector('[data-pf-sms]');
  smsBtn?.addEventListener('click', async () => {
    busy(smsBtn);
    const res = await post(boot.sms_api);
    busy(smsBtn, false);
    if (res.ok) { toast('پیامک پیش‌فاکتور در صف ارسال است.'); const b = document.querySelector('[data-pf-sms-badge]'); if (b) { b.className = 'badge info'; b.textContent = 'در صف ارسال'; } return; }
    if (res.code?.startsWith('CAPABILITY_')) showQuota(res); else toast(res.message, { kind: 'error', timeout: 8000 });
  });

  document.querySelectorAll('[data-pf-cancel]').forEach((btn) => btn.addEventListener('click', async () => {
    if (btn.dataset.confirm && !confirm(btn.dataset.confirm)) return;
    busy(btn);
    const res = await post(boot.cancel_api, { reason: btn.dataset.pfCancel });
    busy(btn, false);
    if (res.ok) { location.href = res.data.next; return; }
    toast(res.message, { kind: 'error' });
  }));

  const issueBtn = document.querySelector('[data-pf-issue]');
  issueBtn?.addEventListener('click', async () => {
    busy(issueBtn);
    const res = await post(boot.issue_api);
    busy(issueBtn, false);
    if (res.ok) { location.href = res.data.next; return; }
    if (res.code?.startsWith('QUOTA_')) showQuota(res); else toast(res.message, { kind: 'error', timeout: 9000 });
  });
}
