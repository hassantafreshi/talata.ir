import { put } from '../lib/http.js';
import { toast } from '../lib/ui.js';
import { showQuota } from '../lib/quota.js';

// پیش‌فاکتور settings: each tap saves at once (no separate save button to forget on a phone).
export default function () {
  const card = document.querySelector('[data-push-card]');
  if (card) import('../lib/push.js').then((m) => m.initPushCard(card, toast));
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const note = document.querySelector('[data-saved]');
  document.querySelectorAll('input[name="auto_issue"], input[name="hours"], input[name="notify_sms"]').forEach((input) => input.addEventListener('change', async () => {
    const body = input.name === 'auto_issue' ? { auto_issue: input.value === '1' } : input.name === 'notify_sms' ? { notify_sms: input.checked } : { hours: Number(input.value) };
    note.textContent = 'در حال ذخیره…';
    const res = await put(boot.api, body);
    if (res.ok) { note.textContent = 'ذخیره شد ✓'; toast('ذخیره شد.'); return; }
    note.textContent = 'ذخیره نشد.';
    if (res.code?.startsWith('CAPABILITY_')) showQuota(res); else toast(res.message, { kind: 'error' });
  }));
}
