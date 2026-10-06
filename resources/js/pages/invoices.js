import { get } from '../lib/http.js';
import { toast } from '../lib/ui.js';
import { toPersian } from '../lib/digits.js';

export default function () {
  const form = document.querySelector('[data-filter]');
  const list = document.querySelector('[data-list]');
  const count = document.querySelector('[data-count]');
  let timer = null, seq = 0;

  async function load(url, append = false) {
    const mine = ++seq;
    list.setAttribute('aria-busy', 'true');
    const res = await get(url);
    if (mine !== seq) return; // a newer search won
    list.removeAttribute('aria-busy');
    if (!res.ok) { toast(res.message, { kind: 'error' }); return; }
    if (append) { list.querySelector('[data-more]')?.closest('li')?.remove(); list.insertAdjacentHTML('beforeend', res.data.html); }
    else { list.innerHTML = res.data.html; count.textContent = `${toPersian(res.data.total)} مورد`; }
  }

  function apply() {
    const params = new URLSearchParams(new FormData(form));
    if (!params.get('q')) params.delete('q');
    if (params.get('filter') === 'all') params.delete('filter');
    const qs = params.toString();
    history.replaceState(null, '', `/invoices${qs ? `?${qs}` : ''}`);
    load(`/api/invoices${qs ? `?${qs}` : ''}`);
  }

  form.addEventListener('input', (e) => { clearTimeout(timer); timer = setTimeout(apply, e.target.type === 'search' ? 350 : 0); });
  form.addEventListener('submit', (e) => { e.preventDefault(); apply(); });
  list.addEventListener('click', (e) => {
    const more = e.target.closest('[data-more]');
    if (!more) return;
    const u = new URL(more.dataset.more, location.origin);
    load(`/api/invoices${u.search}`, true);
  });
}
