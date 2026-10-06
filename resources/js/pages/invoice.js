import { get, post } from '../lib/http.js';
import { toast, busy, sheet, fieldErrors } from '../lib/ui.js';
import { showQuota } from '../lib/quota.js';

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const badge = document.querySelector('[data-sms-badge]');
  const sendBtn = document.querySelector('[data-sms-send]');
  const creditNote = document.querySelector('[data-sms-credit]');

  const note = sessionStorage.getItem('issue-sms-note');
  if (note) {
    sessionStorage.removeItem('issue-sms-note');
    const el = document.querySelector('[data-sms-note]');
    if (el) { el.textContent = `فاکتور صادر شد اما پیامک ارسال نشد: ${note}`; el.classList.remove('hidden'); }
  }

  function showSms(s) {
    if (!s || !badge) return;
    badge.textContent = s.label_fa;
    badge.className = `badge ${s.kind}`;
    creditNote?.classList.toggle('hidden', s.status !== 'AWAITING_CREDIT');
    sendBtn?.classList.toggle('hidden', !['FAILED', 'UNKNOWN', 'AWAITING_CREDIT'].includes(s.status));
  }
  showSms(boot.sms);

  // Poll delivery status for up to two minutes after opening or sending (spec: every few seconds, then stop);
  // never while the tab is hidden. Coming back to the tab checks again.
  let delay = 3000;
  let until = Date.now() + 120000;
  async function poll() {
    if (!boot.sms || boot.sms.final || document.hidden || Date.now() > until) return;
    const res = await get(boot.status_url);
    if (res.ok && res.data.sms) { boot.sms = res.data.sms; showSms(boot.sms); }
    delay = Math.min(delay * 1.4, 15000);
    if (!boot.sms.final) setTimeout(poll, delay);
  }
  setTimeout(poll, delay);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { delay = 3000; until = Date.now() + 120000; poll(); } });

  sendBtn?.addEventListener('click', async () => {
    busy(sendBtn, true);
    const res = await post(boot.sms_api);
    busy(sendBtn, false);
    if (res.ok) {
      boot.sms = { status: res.data.status, label_fa: res.data.label_fa, kind: res.data.kind, final: false };
      showSms(boot.sms);
      if (res.data.status === 'AWAITING_CREDIT') toast('اعتبار کافی نیست؛ پس از خرید اعتبار ارسال می‌شود.', { kind: 'error' });
      else { toast('پیامک در صف ارسال قرار گرفت.'); delay = 3000; until = Date.now() + 120000; setTimeout(poll, delay); }
      return;
    }
    if (res.code?.startsWith('QUOTA_') || res.code?.startsWith('CAPABILITY_')) showQuota(res); else toast(res.message, { kind: 'error', timeout: 8000 });
  });

  // Share link
  const box = document.querySelector('[data-share-box]');
  const urlInput = document.querySelector('[data-share-url]');
  const createBtn = document.querySelector('[data-share-create]');
  createBtn?.addEventListener('click', async () => {
    busy(createBtn, true);
    const res = await post(boot.share_api);
    busy(createBtn, false);
    if (!res.ok) { if (res.code?.startsWith('QUOTA_') || res.code?.startsWith('CAPABILITY_')) showQuota(res); else toast(res.message, { kind: 'error' }); return; }
    urlInput.value = res.data.url; box.classList.remove('hidden'); createBtn.classList.add('hidden');
  });
  document.querySelector('[data-copy]')?.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(urlInput.value); toast('لینک کپی شد.'); }
    catch { urlInput.select(); document.execCommand?.('copy'); toast('لینک انتخاب شد؛ کپی کنید.'); }
  });
  const nativeBtn = document.querySelector('[data-native-share]');
  if (nativeBtn && !navigator.share) nativeBtn.hidden = true;
  nativeBtn?.addEventListener('click', () => navigator.share({ title: `فاکتور ${boot.number}`, text: `فاکتور ${boot.number} — ${boot.shop}`, url: urlInput.value }).catch(() => {}));
  document.querySelector('[data-revoke]')?.addEventListener('click', async () => {
    if (!confirm('لینک غیرفعال شود؟ کسی که لینک را دارد دیگر فاکتور را نمی‌بیند. بارکد بررسی اصالت کار می‌کند.')) return;
    const res = await post(boot.revoke_api);
    if (res.ok) { box.classList.add('hidden'); createBtn?.classList.remove('hidden'); toast('لینک غیرفعال شد.'); } else toast(res.message, { kind: 'error' });
  });

  // Void
  document.querySelector('[data-void]')?.addEventListener('click', () => {
    const tpl = document.querySelector('[data-void-tpl]');
    const { sheet: el, close } = sheet(tpl.innerHTML, { label: 'ابطال فاکتور' });
    const form = el.querySelector('[data-void-form]');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('[type=submit]');
      busy(btn, true);
      const res = await post(boot.void_api, { reason: form.reason.value, note: form.note.value.trim() });
      busy(btn, false);
      if (res.ok) { close(); location.replace(res.data.next); return; }
      if (res.errors) fieldErrors(form, res.errors); else toast(res.message, { kind: 'error', timeout: 9000 });
    });
  });

  // Security revocation of the QR token: old printed sheets read «لغوشده»; a fresh print carries the new code.
  document.querySelector('[data-verify-revoke]')?.addEventListener('click', () => {
    const tpl = document.querySelector('[data-verify-revoke-tpl]');
    const { sheet: el, close } = sheet(tpl.innerHTML, { label: 'لغو امنیتی بارکد' });
    const form = el.querySelector('[data-verify-revoke-form]');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('[type=submit]');
      busy(btn, true);
      const res = await post(boot.verify_revoke_api, { reason: form.reason.value.trim() });
      busy(btn, false);
      if (res.ok) { close(); toast('بارکد قبلی لغو شد. برای مشتری برگه تازه چاپ کنید.', { timeout: 9000 }); setTimeout(() => location.reload(), 1200); return; }
      if (res.errors) fieldErrors(form, res.errors); else toast(res.message, { kind: 'error', timeout: 9000 });
    });
  });

  const replaceBtn = document.querySelector('[data-replace]');
  replaceBtn?.addEventListener('click', async () => {
    busy(replaceBtn, true);
    const res = await post(boot.replace_api);
    busy(replaceBtn, false);
    if (res.ok) location.href = res.data.next;
    else if (res.code?.startsWith('QUOTA_')) showQuota(res);
    else toast(res.message, { kind: 'error' });
  });
}
