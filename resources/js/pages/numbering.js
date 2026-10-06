import { put } from '../lib/http.js';
import { busy, toast, fieldErrors } from '../lib/ui.js';
import { toLatin, toPersian } from '../lib/digits.js';

// Live preview mirrors App\Domain\Invoices\Numbering::format(); the server validates and allocates.
export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  let version = boot.version;
  const form = document.querySelector('[data-numbering]');
  // LRM after a letter prefix keeps «ط-05-0012» from showing reversed (mirrors Digits::invoiceNumber).
  const iso = (s) => `\u2066${toPersian(s.replace(/(\p{L}+)(?=[^\p{L}]|$)/gu, '$1\u200E'))}\u2069`;

  function read() {
    const v = (n) => form.querySelector(`[name="${n}"]:checked`)?.value;
    return {
      prefix: form.prefix.value.trim(), year: v('year') ?? 'full', month: form.month.checked,
      separator: v('separator') ?? '-', digits: Number(form.digits.value), reset: v('reset') ?? 'yearly',
    };
  }
  function write(s) {
    form.prefix.value = s.prefix;
    ['year', 'separator', 'reset'].forEach((n) => form.querySelectorAll(`[name="${n}"]`).forEach((r) => { r.checked = r.value === String(s[n]); }));
    form.month.checked = !!s.month;
    form.digits.value = String(s.digits);
  }
  function format(s, seq) {
    const parts = [];
    if (s.prefix) parts.push(s.prefix);
    if (s.year !== 'none') parts.push(s.year === 'short' ? String(boot.jy % 100).padStart(2, '0') : String(boot.jy));
    if (s.month && s.year !== 'none') parts.push(String(boot.jm).padStart(2, '0'));
    parts.push(String(seq).padStart(s.digits, '0'));
    return parts.join(s.separator);
  }
  function problems(s) {
    if (s.reset === 'yearly' && s.year === 'none') return ['year', 'اگر شماره هر سال از ۱ شروع شود، سال باید در شماره بیاید.'];
    if (s.reset === 'monthly' && (s.year === 'none' || !s.month)) return ['month', 'برای شروع ماهانه، سال و ماه باید در شماره بیایند.'];
    if (s.month && s.year === 'none') return ['month', 'ماه بدون سال معنی ندارد.'];
    return null;
  }
  function refresh() {
    const s = read();
    const typed = toLatin(form.next.value.trim());
    const n = /^\d{1,8}$/.test(typed) ? Number(typed) : boot.next[s.reset];
    form.next.placeholder = toPersian(String(boot.next[s.reset]));
    document.querySelector('[data-next-number]').textContent = iso(format(s, n));
    document.querySelector('[data-following]').textContent = `بعدی‌ها: ${iso(format(s, n + 1))} · ${iso(format(s, n + 2))}`;
    form.querySelectorAll('.field.invalid').forEach((f) => f.classList.remove('invalid'));
    const p = problems(s);
    if (p) {
      const field = p[0] === 'year' ? form.querySelector('[name=year]').closest('.field') : form.querySelector('[data-month-err]');
      field.classList.add('invalid'); field.querySelector('.err').textContent = p[1];
    }
    const match = Object.entries(boot.presets).find(([, ps]) => Object.keys(ps).every((k) => String(ps[k]) === String(s[k])));
    form.querySelectorAll('[name=preset]').forEach((r) => { r.checked = !!match && r.value === match[0]; });
    if (!match) form.querySelector('[data-custom]').open = true;
  }

  form.addEventListener('change', (e) => {
    if (e.target.name === 'preset') { write(boot.presets[e.target.value]); }
    refresh();
  });
  form.addEventListener('input', refresh);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const s = read();
    if (problems(s)) { toast('شماره‌گذاری کامل نیست.', { kind: 'error' }); return; }
    const btn = form.querySelector('[type=submit]');
    busy(btn);
    const res = await put('/api/settings/numbering', { settings: s, next: toLatin(form.next.value.trim()) || null, version });
    busy(btn, false);
    if (res.ok) { version = res.data.version; toast('شماره‌گذاری ذخیره شد.'); setTimeout(() => location.reload(), 600); return; }
    if (res.errors) fieldErrors(form, res.errors); else toast(res.message, { kind: 'error' });
  });

  write(boot.current);
  refresh();
  if (!form.querySelector('[name=preset]:checked')) form.querySelector('[data-custom]').open = true;
}
