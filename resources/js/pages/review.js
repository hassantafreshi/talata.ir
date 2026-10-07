import { post, idempotencyKey } from '../lib/http.js';
import { toast, busy, fieldErrors } from '../lib/ui.js';
import { toLatin } from '../lib/digits.js';
import { showQuota } from '../lib/quota.js';

const MOBILE_RE = /^09(0[0-5]|1\d|2[0-2]|3\d|41|9\d)\d{7}$/;

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const form = document.querySelector('[data-issue-form]');
  // One key per page view: a double tap or a retry after a timeout replays the same issuance.
  const key = idempotencyKey('iss');
  let inFlight = false;
  let lastButton = null;
  const unknown = document.querySelector('[data-issue-unknown]');
  const stash = `review-buyer:${boot.id}`;

  // Buyer details typed here survive a detour to complete the business profile.
  try {
    const saved = JSON.parse(sessionStorage.getItem(stash) || 'null');
    if (saved) {
      if (!form.buyer_name.value) form.buyer_name.value = saved.name || '';
      if (!form.buyer_mobile.value) form.buyer_mobile.value = saved.mobile || '';
      if (form.save_customer && saved.save) form.save_customer.checked = true;
      sessionStorage.removeItem(stash);
    }
  } catch {}
  const smsTo = document.querySelector('[data-sms-to]');
  const autoHint = document.querySelector('[data-auto-hint]');
  const showTo = () => {
    const typed = form.buyer_mobile.value.trim();
    if (smsTo) smsTo.textContent = typed || '—';
    if (autoHint) autoHint.textContent = typed ? autoHint.dataset.with : autoHint.dataset.without;
  };
  form.buyer_mobile.addEventListener('input', showTo);
  showTo();

  let retry = () => { if (lastButton) form.requestSubmit(lastButton); };
  document.querySelector('[data-issue-retry]')?.addEventListener('click', () => retry());

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (inFlight) return;
    const button = e.submitter || form.querySelector('[data-mode]');
    lastButton = button;
    const mode = button.dataset.mode;
    const mobileRaw = toLatin(form.buyer_mobile.value).replace(/[\s-]/g, '').replace(/^\+98/, '0').replace(/^98(?=9)/, '0');
    if (mobileRaw && !MOBILE_RE.test(mobileRaw)) { fieldErrors(form, { buyer_mobile: 'شماره موبایل درست نیست. مثال: ۰۹۱۲۳۴۵۶۷۸۹' }); return; }
    if (mode === 'ISSUE_AND_SMS' && !mobileRaw) { fieldErrors(form, { buyer_mobile: 'برای ارسال پیامکی، موبایل مشتری را وارد کنید یا «صدور فاکتور» را بزنید.' }); return; }
    if (!boot.profile_complete) {
      try { sessionStorage.setItem(stash, JSON.stringify({ name: form.buyer_name.value.trim(), mobile: form.buyer_mobile.value.trim(), save: !!form.save_customer?.checked })); } catch {}
      location.href = boot.business_url;
      return;
    }
    fieldErrors(form, null);

    inFlight = true;
    form.querySelectorAll('[data-mode]').forEach((b) => { if (b !== button) b.disabled = true; });
    busy(button, true);
    const res = await post(boot.issue_url, {
      mode, version: boot.version, idempotency_key: key,
      buyer: { name: form.buyer_name.value.trim(), mobile: mobileRaw },
      save_customer: form.save_customer?.checked || false,
    }, { timeout: 30000 });

    if (res.ok) {
      unknown.classList.add('hidden');
      if (res.data.sms?.status === 'NOT_SENT') sessionStorage.setItem('issue-sms-note', res.data.sms.message_fa);
      location.replace(res.data.next);
      return;
    }
    inFlight = false;
    busy(button, false);
    form.querySelectorAll('[data-mode]').forEach((b) => { b.disabled = false; });
    switch (res.code) {
      case 'ALREADY_ISSUED': location.replace(`/invoices/${res.data.invoice}`); return;
      case 'PROFILE_INCOMPLETE': location.href = boot.business_url; return;
      case 'DRAFT_CONFLICT':
      case 'REVIEW_REQUIRED':
        unknown.querySelector('[data-issue-unknown-text]').textContent = res.message;
        unknown.querySelector('[data-issue-retry]').textContent = 'بارگذاری دوباره';
        retry = () => location.reload();
        unknown.classList.remove('hidden'); return;
      case 'ROWS_INVALID': location.href = boot.items_url; return;
      case 'BUYER_MOBILE_INVALID': case 'BUYER_MOBILE_REQUIRED': fieldErrors(form, { buyer_mobile: res.message }); return;
      case 'NETWORK': case 'OFFLINE':
        // Unknown outcome: stays on screen until resolved; retrying uses the same key, so never a duplicate.
        unknown.querySelector('[data-issue-unknown-text]').textContent = `${res.message} معلوم نیست فاکتور صادر شد یا نه. «بررسی دوباره» را بزنید؛ فاکتور تکراری صادر نمی‌شود.`;
        unknown.classList.remove('hidden');
        unknown.querySelector('[data-issue-retry]').focus(); return;
      default:
        if (res.code?.startsWith('QUOTA_') || res.code?.startsWith('CAPABILITY_')) { showQuota(res); return; }
        if (res.errors) { fieldErrors(form, Object.fromEntries(Object.entries(res.errors).map(([k, v]) => [k.replace('buyer.', 'buyer_'), v]))); return; }
        toast(res.message, { kind: 'error' });
    }
  });
}
