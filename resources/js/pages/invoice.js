import { get, post, idempotencyKey } from '../lib/http.js';
import { toast, busy, sheet, fieldErrors, escapeHtml } from '../lib/ui.js';
import { extractMobiles, displayMobile } from '../lib/mobiles.js';
import { toPersian } from '../lib/digits.js';
import { showQuota } from '../lib/quota.js';

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const creditNote = document.querySelector('[data-sms-credit]');
  const err = (res) => (res.code?.startsWith('QUOTA_') || res.code?.startsWith('CAPABILITY_') ? showQuota(res) : toast(res.message, { kind: 'error', timeout: 9000 }));

  const note = sessionStorage.getItem('issue-sms-note');
  if (note) {
    sessionStorage.removeItem('issue-sms-note');
    const el = document.querySelector('[data-sms-note]');
    if (el) { el.textContent = `فاکتور صادر شد اما پیامک ارسال نشد: ${note}`; el.classList.remove('hidden'); }
  }

  // Every badge of the customer SMS (page tile and the open sheet) shows the same state.
  function showSms(s) {
    if (!s) return;
    document.querySelectorAll('[data-sms-badge]').forEach((b) => { b.textContent = s.label_fa; b.className = `badge ${s.kind}`; });
    creditNote?.classList.toggle('hidden', s.status !== 'AWAITING_CREDIT');
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
  const watch = () => { delay = 3000; until = Date.now() + 120000; setTimeout(poll, delay); };
  setTimeout(poll, delay);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { delay = 3000; until = Date.now() + 120000; poll(); } });

  // «اشتراک‌گذاری»: the phone's own share sheet (WhatsApp, Telegram, Eitaa…) with the invoice link. The link
  // is made on first use; where the browser has no share sheet, the link is copied instead.
  const box = document.querySelector('[data-share-box]');
  const urlInput = document.querySelector('[data-share-url]');
  let shareUrl = boot.share_url;
  const showLink = (url) => { shareUrl = url; if (urlInput) urlInput.value = url; box?.removeAttribute('hidden'); };
  async function copy(url) {
    try { await navigator.clipboard.writeText(url); toast('لینک فاکتور کپی شد؛ در هر برنامه‌ای بچسبانید.'); }
    catch { if (urlInput) { box.open = true; urlInput.select(); } toast('لینک را از کادر «لینک فاکتور» کپی کنید.'); }
  }
  async function share(url) {
    if (!navigator.share) { await copy(url); return; }
    try { await navigator.share({ title: `فاکتور ${boot.number}`, text: `فاکتور ${boot.number} — ${boot.shop}`, url }); }
    catch (e) {
      // Some browsers drop the tap after the link request: the link is ready now, a second tap shares at once.
      if (e?.name === 'NotAllowedError') toast('لینک آماده است؛ دوباره «اشتراک‌گذاری» را بزنید.');
    }
  }
  const shareBtn = document.querySelector('[data-share-now]');
  shareBtn?.addEventListener('click', async () => {
    if (shareUrl) { share(shareUrl); return; }
    busy(shareBtn, true);
    const res = await post(boot.share_api);
    busy(shareBtn, false);
    if (!res.ok) { err(res); return; }
    showLink(res.data.url);
    share(res.data.url);
  });
  document.querySelector('[data-copy]')?.addEventListener('click', () => copy(urlInput.value));
  document.querySelector('[data-revoke]')?.addEventListener('click', async () => {
    if (!confirm('لینک غیرفعال شود؟ کسی که لینک را دارد دیگر فاکتور را نمی‌بیند. بارکد بررسی اصالت کار می‌کند.')) return;
    const res = await post(boot.revoke_api);
    if (res.ok) { box.setAttribute('hidden', ''); shareUrl = null; toast('لینک غیرفعال شد.'); } else err(res);
  });

  // «ارسال پیامک»: a sheet with «ارسال دوباره به مشتری» and «ارسال به شماره دیگر».
  document.querySelector('[data-sms-open]')?.addEventListener('click', () => {
    const tpl = document.querySelector('[data-sms-tpl]');
    if (!tpl) return;
    const { sheet: el } = sheet(tpl.innerHTML, { label: 'ارسال پیامک فاکتور' });
    showSms(boot.sms);
    customerSms(el);
    otherNumbers(el);
  });

  function customerSms(el) {
    const btn = el.querySelector('[data-sms-customer]');
    const send = async (confirmed) => {
      busy(btn, true);
      const res = await post(boot.sms_api, { confirm: confirmed });
      busy(btn, false);
      if (res.ok) {
        boot.sms = { status: res.data.status, label_fa: res.data.label_fa, kind: res.data.kind, final: false };
        showSms(boot.sms);
        btn.textContent = 'ارسال دوباره به مشتری';
        if (res.data.status === 'AWAITING_CREDIT') toast('اعتبار کافی نیست؛ پس از خرید اعتبار خودکار ارسال می‌شود.', { kind: 'error', action: { label: 'خرید اعتبار', onClick: () => { location.href = res.data.buy_url; } } });
        else { toast('پیامک مشتری در صف ارسال است.'); watch(); }
        return;
      }
      if (res.code === 'SMS_CONFIRM_RESEND' && confirm(res.message)) { send(true); return; }
      if (res.code !== 'SMS_CONFIRM_RESEND') err(res);
    };
    btn?.addEventListener('click', () => send(false));
  }

  function otherNumbers(el) {
    const form = el.querySelector('[data-sms-others]');
    if (!form) return;
    const chips = form.querySelector('[data-mobile-chips]');
    const submit = form.querySelector('[data-others-submit]');
    let key = idempotencyKey('copy');
    let parsed = { mobiles: [], invalid: [] };
    const draw = () => {
      parsed = extractMobiles(form.mobiles.value);
      const over = parsed.mobiles.length > boot.max_numbers;
      chips.innerHTML = parsed.mobiles.map((m) => `<span class="chip ok" dir="ltr">✓ ${displayMobile(m)}</span>`).join('')
        + parsed.invalid.map((f) => `<span class="chip bad" dir="ltr" title="شماره درست نیست">✕ ${escapeHtml(toPersian(f))}</span>`).join('');
      const n = parsed.mobiles.length;
      submit.disabled = !n || parsed.invalid.length > 0 || over;
      submit.textContent = n > 1 ? `ارسال به ${toPersian(n)} شماره` : 'ارسال';
      fieldErrors(form, parsed.invalid.length ? { mobiles: 'بخش قرمز شماره موبایل درستی نیست؛ آن را اصلاح یا پاک کنید.' } : over ? { mobiles: `هر بار حداکثر ${toPersian(boot.max_numbers)} شماره.` } : null);
      key = idempotencyKey('copy'); // a different list is a different request
    };
    form.mobiles.addEventListener('input', draw);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (submit.disabled) return;
      busy(submit, true);
      const res = await post(boot.copies_api, { mobiles: form.mobiles.value, idempotency_key: key }, { timeout: 30000 });
      busy(submit, false);
      if (!res.ok) { if (res.errors) fieldErrors(form, res.errors); else err(res); return; }
      chips.innerHTML = res.data.results.map((r) => `<span class="chip ${r.kind === 'err' || r.status === 'NOT_SENT' ? 'bad' : r.status === 'SKIPPED' ? '' : 'ok'}" dir="rtl"><bdi dir="ltr">${escapeHtml(r.mobile_fa)}</bdi>&nbsp;${escapeHtml(r.message_fa || r.label_fa)}</span>`).join('');
      if (res.data.queued) { toast(`پیامک فاکتور برای ${toPersian(res.data.queued)} شماره در صف ارسال است.`); form.mobiles.value = ''; submit.disabled = true; submit.textContent = 'ارسال'; }
      if (res.data.results.some((r) => r.code === 'SMS_NO_CREDIT')) toast('اعتبار پیامک کافی نیست.', { kind: 'error', action: { label: 'خرید اعتبار', onClick: () => { location.href = res.data.buy_url; } } });
    });
  }

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
