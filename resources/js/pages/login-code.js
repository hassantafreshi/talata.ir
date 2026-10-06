import { post } from '../lib/http.js';
import { busy, toast } from '../lib/ui.js';
import { toLatin, toPersian } from '../lib/digits.js';
import { requestCode } from '../lib/otp.js';

export default function () {
  const form = document.querySelector('[data-code-form]');
  const boxes = [...form.querySelectorAll('.otp-boxes input')];
  const err = form.querySelector('.err');
  const btn = form.querySelector('[type=submit]');
  const resend = form.querySelector('[data-resend]');
  let sending = false;

  const code = () => boxes.map((b) => toLatin(b.value)).join('');
  const fill = (digits) => { [...digits.slice(0, 6)].forEach((d, i) => { boxes[i].value = d; }); boxes[Math.min(digits.length, 5)].focus(); };

  boxes.forEach((box, i) => {
    box.addEventListener('input', () => {
      const v = toLatin(box.value).replace(/\D/g, '');
      if (v.length > 1) { fill(v); } else { box.value = v; if (v && i < 5) boxes[i + 1].focus(); }
      if (code().length === 6) submit();
    });
    box.addEventListener('keydown', (e) => { if (e.key === 'Backspace' && !box.value && i > 0) boxes[i - 1].focus(); });
    box.addEventListener('paste', (e) => { e.preventDefault(); fill(toLatin(e.clipboardData.getData('text')).replace(/\D/g, '')); if (code().length === 6) submit(); });
  });

  async function submit() {
    if (sending || code().length !== 6) return;
    sending = true; busy(btn); err.textContent = ''; form.querySelector('.field').classList.remove('invalid');
    const res = await post('/api/auth/otp/verify', { code: code() });
    sending = false; busy(btn, false);
    if (res.ok) { location.href = res.data.next; return; }
    form.querySelector('.field').classList.add('invalid'); err.textContent = res.message;
    if (res.code === 'OTP_EXPIRED' || res.code === 'OTP_LOCKED') resend.disabled = false;
    boxes.forEach((b) => { b.value = ''; }); boxes[0].focus();
  }
  form.addEventListener('submit', (e) => { e.preventDefault(); submit(); });

  // Resend countdown
  let resendAt = Number(form.dataset.resendAt) * 1000;
  const tick = () => {
    const left = Math.ceil((resendAt - Date.now()) / 1000);
    if (left > 0) { resend.disabled = true; resend.textContent = `ارسال دوباره کد تا ${toPersian(String(Math.floor(left / 60)).padStart(2, '0'))}:${toPersian(String(left % 60).padStart(2, '0'))}`; }
    else { resend.disabled = false; resend.textContent = 'ارسال دوباره کد'; }
  };
  tick(); setInterval(tick, 1000);
  // Resend right here, to the same number (same proof-of-work and limits as the first request).
  resend.addEventListener('click', async () => {
    busy(resend);
    const res = await requestCode(form.dataset.mobile || '');
    busy(resend, false);
    if (res.ok) {
      resendAt = Date.now() + (res.data.resend_after_seconds || 90) * 1000;
      tick();
      boxes.forEach((b) => { b.value = ''; }); boxes[0].focus();
      err.textContent = ''; form.querySelector('.field').classList.remove('invalid');
      toast('کد تازه فرستاده شد. همان آخرین پیامک را وارد کنید.');
      return;
    }
    toast(res.message, { kind: 'error', timeout: 8000 });
    tick();
  });

  if ('OTPCredential' in window) {
    navigator.credentials.get({ otp: { transport: ['sms'] } }).then((o) => { if (o?.code) { fill(o.code); submit(); } }).catch(() => {});
  }
}
