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

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (inFlight) return;
    const button = e.submitter || form.querySelector('[data-mode]');
    const mode = button.dataset.mode;
    const mobileRaw = toLatin(form.buyer_mobile.value).replace(/[\s-]/g, '').replace(/^\+98/, '0').replace(/^98(?=9)/, '0');
    if (mobileRaw && !MOBILE_RE.test(mobileRaw)) { fieldErrors(form, { buyer_mobile: 'شماره موبایل درست نیست. مثال: ۰۹۱۲۳۴۵۶۷۸۹' }); return; }
    if (mode === 'ISSUE_AND_SMS' && !mobileRaw) { fieldErrors(form, { buyer_mobile: 'برای ارسال پیامکی، موبایل مشتری را وارد کنید یا «فقط صدور» را بزنید.' }); return; }
    if (!boot.profile_complete) { location.href = boot.business_url; return; }
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
        toast(res.message, { kind: 'error', action: { label: 'بارگذاری دوباره', onClick: () => location.reload() }, timeout: 10000 }); return;
      case 'ROWS_INVALID': location.href = boot.items_url; return;
      case 'BUYER_MOBILE_INVALID': case 'BUYER_MOBILE_REQUIRED': fieldErrors(form, { buyer_mobile: res.message }); return;
      case 'NETWORK': case 'OFFLINE':
        toast(`${res.message} اگر دوباره بزنید، فاکتور تکراری صادر نمی‌شود.`, { kind: 'error', timeout: 9000 }); return;
      default:
        if (res.code?.startsWith('QUOTA_') || res.code?.startsWith('CAPABILITY_')) { showQuota(res); return; }
        if (res.errors) { fieldErrors(form, Object.fromEntries(Object.entries(res.errors).map(([k, v]) => [k.replace('buyer.', 'buyer_'), v]))); return; }
        toast(res.message, { kind: 'error' });
    }
  });
}
