// Product-name suggestions on invoice rows: the shop's own sold products first (with last weight, wage, profit),
// then the shared Persian dataset. Both lists are loaded once, on the first focus, and filtered in the browser so
// every keystroke answers instantly even on a weak connection.
import { get, del } from './http.js';
import { toPersian } from './digits.js';

const MAX = 8;

/** Matching key; same rules as ProductCatalog::key on the server. */
export function key(s) {
  return String(s || '')
    .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[ۀة]/g, 'ه').replace(/[أإ]/g, 'ا')
    .replace(/[‌]/g, ' ').replace(/[‏ـ]/g, '')
    .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
    .replace(/\s+/g, ' ').trim().toLowerCase();
}

/** 3 = starts with the query, 2 = every typed word starts a word, 1 = contains it, 0 = no match. */
export function tier(candidate, q) {
  if (!q) return 1;
  if (candidate.startsWith(q)) return 3;
  const words = candidate.split(' ');
  const parts = q.split(' ');
  if (parts.every((p) => words.some((w) => w.startsWith(p)))) return 2;
  return candidate.includes(q) ? 1 : 0;
}

/**
 * Ranked suggestions for one row. Shop products of the row's type always come before dataset terms; within each
 * group: better match, then more sales (shop) or popularity (dataset), then shorter.
 * @returns {{kind:'shop'|'base', item?:object, term?:string, label:string}[]}
 */
export function rank(query, type, shop, terms, max = MAX) {
  const q = key(query);
  const mine = shop.filter((p) => p.t === type)
    .map((p) => ({ p, k: p._k ??= key(p.n) }))
    .map((x) => ({ ...x, s: x.k === q ? 4 : tier(x.k, q) })).filter((x) => x.s > 0)
    .sort((a, b) => b.s - a.s || (b.p.c || 0) - (a.p.c || 0))
    .slice(0, max)
    .map((x) => ({ kind: 'shop', item: x.p, label: x.p.n }));
  if (type !== 'GOLD' || !q) return mine; // the shared list is gold jewellery; an empty field shows recent sales only
  const taken = new Set(mine.map((m) => key(m.label)));
  const base = [];
  for (const [t, w, k] of terms) {
    if (taken.has(k) || k === q) continue;
    const s = tier(k, q);
    if (s > 0) base.push({ t, w, s, len: t.length });
  }
  base.sort((a, b) => b.s - a.s || b.w - a.w || a.len - b.len);
  return mine.concat(base.slice(0, Math.max(0, max - mine.length)).map((b) => ({ kind: 'base', term: b.t, label: b.t })));
}

/** Highlights the typed part (text nodes only; never HTML from data). */
function labelNode(text, query) {
  const span = document.createElement('span');
  span.className = 'ps-name';
  const q = query.trim();
  const i = q ? text.indexOf(q) : -1;
  if (i < 0) { span.textContent = text; return span; }
  const mark = document.createElement('mark');
  mark.textContent = text.slice(i, i + q.length);
  span.append(text.slice(0, i), mark, text.slice(i + q.length));
  return span;
}

const fa = (v) => toPersian(String(v));

function metaText(p) {
  if (p.t === 'MISC') return p.m ? `آخرین قیمت ${fa(Number(p.m).toLocaleString('en-US'))} تومان` : '';
  const bits = [];
  if (p.w) bits.push(`${fa(p.w)} گرم`);
  if (p.wg && p.wg !== '0') bits.push(`اجرت ${fa(p.wg)}٪`);
  if (p.pr && p.pr !== '0') bits.push(`سود ${fa(p.pr)}٪`);
  return bits.join(' · ');
}

/**
 * @param {{host: HTMLElement, typeOf: (input: HTMLInputElement) => string|null, onPick: (input, choice) => void, namesUrl: string}} cfg
 */
export default function productSuggest({ host, typeOf, onPick, namesUrl }) {
  let shop = null, terms = [], loading = null;
  let input = null, items = [], active = -1, timer = null;
  const list = document.createElement('ul');
  list.className = 'ac-list ps-list';
  list.id = 'product-suggest';
  list.setAttribute('role', 'listbox');
  list.setAttribute('aria-label', 'پیشنهاد نام کالا');
  list.hidden = true;

  const load = () => loading ??= Promise.all([get('/api/products'), get(namesUrl)]).then(([mine, base]) => {
    shop = mine.ok ? mine.data.items || [] : [];
    terms = base.ok ? (base.data.terms || []).map(([t, w]) => [t, w, key(t)]) : [];
  });

  const close = () => {
    list.hidden = true; active = -1;
    if (input) { input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
  };
  const highlight = (i) => {
    active = i;
    [...list.children].forEach((li, k) => li.setAttribute('aria-selected', String(k === i)));
    if (i >= 0 && list.children[i]) { input.setAttribute('aria-activedescendant', list.children[i].id); list.children[i].scrollIntoView({ block: 'nearest' }); }
  };
  const choose = (choice) => { const at = input; close(); onPick(at, choice); };

  const draw = () => {
    if (!input || !shop) return;
    const type = typeOf(input);
    const q = input.value;
    items = type ? rank(q, type, shop, terms) : [];
    const head = items.some((x) => x.kind === 'shop') && items.some((x) => x.kind === 'base');
    list.replaceChildren(...items.map((x, i) => {
      const li = document.createElement('li');
      li.id = `ps-opt-${i}`;
      li.setAttribute('role', 'option');
      li.className = `ac-item ps-item ps-${x.kind}`;
      if (head && i > 0 && x.kind === 'base' && items[i - 1].kind === 'shop') li.classList.add('ps-first-base');
      const body = document.createElement('span');
      body.className = 'ps-body';
      body.append(labelNode(x.label, q));
      if (x.kind === 'shop') {
        const meta = metaText(x.item);
        const m = document.createElement('span');
        m.className = 'ps-meta';
        m.textContent = meta ? `فروخته‌اید · ${meta}` : 'فروخته‌اید';
        body.append(m);
      }
      li.append(body);
      if (x.kind === 'shop') {
        const rm = document.createElement('button');
        rm.type = 'button'; rm.className = 'ps-remove'; rm.textContent = '×';
        rm.setAttribute('aria-label', `حذف «${x.label}» از پیشنهادها`);
        rm.tabIndex = -1;
        rm.addEventListener('mousedown', (e) => e.preventDefault());
        rm.addEventListener('click', async (e) => {
          e.stopPropagation();
          shop = shop.filter((p) => p !== x.item);
          draw();
          await del(`/api/products/${encodeURIComponent(x.item.id)}`);
        });
        li.append(rm);
      }
      // pointerdown + preventDefault keeps the keyboard open and the input focused on phones.
      li.addEventListener('pointerdown', (e) => { if (e.target.closest('.ps-remove')) return; e.preventDefault(); choose(x); });
      return li;
    }));
    const wrap = input.closest('.input-wrap');
    if (list.parentElement !== wrap) wrap.appendChild(list);
    list.hidden = items.length === 0;
    input.setAttribute('aria-expanded', String(!list.hidden));
    active = -1;
  };

  const isName = (el) => el?.matches?.('input[data-f="name"]') && typeOf(el);

  host.addEventListener('focusin', async (e) => {
    if (!isName(e.target)) return;
    input = e.target;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('autocomplete', 'off');
    input.closest('.input-wrap')?.classList.add('ac-host');
    // A row's default name is selected, so typing replaces it.
    if (input.dataset.defaultName && input.value === input.dataset.defaultName) input.select();
    // Phones: bring the field to the top so the list is not under the keyboard.
    if (window.matchMedia('(max-width: 1023px)').matches) setTimeout(() => input?.scrollIntoView({ block: 'start', behavior: 'smooth' }), 250);
    await load();
    if (input === e.target && document.activeElement === input) draw();
  });
  host.addEventListener('input', (e) => {
    if (e.target !== input) return;
    clearTimeout(timer);
    timer = setTimeout(draw, 60);
  });
  host.addEventListener('keydown', (e) => {
    if (e.target !== input || list.hidden || !items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); highlight((active + 1) % items.length); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlight((active - 1 + items.length) % items.length); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(items[active]); }
    else if (e.key === 'Escape') close();
  });
  host.addEventListener('focusout', (e) => { if (e.target === input) setTimeout(() => { if (document.activeElement !== input) close(); }, 120); });

  return { forget: () => { shop = null; loading = null; } };
}
