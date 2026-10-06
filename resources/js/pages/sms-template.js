import { put } from '../lib/http.js';
import { busy, toast, fieldErrors } from '../lib/ui.js';
import { toPersian } from '../lib/digits.js';

// Segment math mirrors app/Domain/Sms/Segments.php (GSM-7 vs UCS-2).
const GSM_CHARS = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
function segments(text) {
  const ucs = [...text].some((c) => !GSM_CHARS.includes(c));
  const len = [...text].length;
  const [single, multi] = ucs ? [70, 67] : [160, 153];
  return len <= single ? 1 : Math.ceil(len / multi);
}
const SAMPLE = { '{shop_name}': 'طلافروشی نمونه', '{invoice_number}': '۱۴۰۵-۰۰۱۲', '{amount}': '۲۱٬۵۶۲٬۰۰۰ تومان', '{invoice_link}': 'talata.ir/i/AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdEFG' };

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const form = document.querySelector('[data-tpl-form]');
  const out = document.querySelector('[data-preview]');
  const seg = document.querySelector('[data-segments]');
  const draw = () => {
    const text = Object.entries(SAMPLE).reduce((t, [k, v]) => t.split(k).join(v), form.template.value);
    out.textContent = text;
    const n = segments(text);
    seg.textContent = `${toPersian(n)} بخش · ${toPersian([...text].length)} نویسه · هزینه هر پیامک = تعداد بخش × تعرفه هر بخش`;
  };
  form.template.addEventListener('input', draw);
  form.querySelector('[data-reset]')?.addEventListener('click', () => { form.template.value = boot.default; draw(); });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type=submit]');
    busy(btn);
    const res = await put('/api/settings/sms-template', { template: form.template.value });
    busy(btn, false);
    if (res.ok) toast('متن پیامک ذخیره شد.'); else fieldErrors(form, { template: res.message });
  });
  draw();
}
