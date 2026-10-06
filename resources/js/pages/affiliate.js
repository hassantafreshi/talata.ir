import { toast } from '../lib/ui.js';

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  document.querySelector('[data-copy-link]').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(boot.link); toast('لینک کپی شد.'); }
    catch { document.querySelector('[data-link]').select(); toast('لینک انتخاب شد؛ کپی کنید.'); }
  });
  const share = document.querySelector('[data-share]');
  if (!navigator.share) share.hidden = true;
  share.addEventListener('click', () => navigator.share({
    title: 'زرلیو', text: `با کد ${boot.code} در زرلیو فاکتور طلا بسازید و روی خرید اول تخفیف بگیرید.`, url: boot.link,
  }).catch(() => {}));
}
