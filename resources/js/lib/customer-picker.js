// Buyer autocomplete on the invoice review: type a name → pick a saved customer (fills mobile + national ID);
// type a mobile that belongs to a saved customer → their name is filled (or offered when another name was typed).
// One mobile is one person in a shop (unique on the server), so the mobile is the reliable key.
import { get } from './http.js';
import { toLatin, toPersian } from './digits.js';

const MOBILE_RE = /^09(0[0-5]|1\d|2[0-2]|3\d|41|9\d)\d{7}$/;
const normMobile = (v) => toLatin(v || '').replace(/[\s-]/g, '').replace(/^\+98/, '0').replace(/^0098/, '0').replace(/^98(?=9\d{9}$)/, '0').replace(/^(?=9\d{9}$)/, '0');
const show = (m) => (m ? toPersian(`${m.slice(0, 4)} ${m.slice(4, 7)} ${m.slice(7)}`) : '');

export default function customerPicker({ nameInput, mobileInput, nidInput, saveBox }) {
  if (!nameInput || !mobileInput) return;
  const host = nameInput.closest('.input-wrap');
  host.classList.add('ac-host');
  const list = document.createElement('ul');
  list.className = 'ac-list';
  list.id = 'buyer-suggest';
  list.setAttribute('role', 'listbox');
  list.hidden = true;
  host.appendChild(list);
  nameInput.setAttribute('role', 'combobox');
  nameInput.setAttribute('aria-autocomplete', 'list');
  nameInput.setAttribute('aria-controls', list.id);
  nameInput.setAttribute('aria-expanded', 'false');

  const hint = document.createElement('p');
  hint.className = 'hint ac-hint';
  hint.hidden = true;
  mobileInput.closest('.field').appendChild(hint);

  let items = [];
  let active = -1;
  let timer = null;
  let seq = 0;

  const known = (on) => {
    if (saveBox) saveBox.closest('label').hidden = on; // already in the customer list
  };
  const close = () => { list.hidden = true; nameInput.setAttribute('aria-expanded', 'false'); nameInput.removeAttribute('aria-activedescendant'); active = -1; };
  const pick = (c) => {
    nameInput.value = c.name;
    if (c.mobile) mobileInput.value = show(c.mobile);
    if (nidInput && c.national_id) nidInput.value = toPersian(c.national_id);
    hint.hidden = true;
    known(true);
    close();
    mobileInput.dispatchEvent(new Event('input', { bubbles: true })); // SMS preview «به …»
  };
  const highlight = (i) => {
    active = i;
    [...list.children].forEach((li, k) => li.setAttribute('aria-selected', String(k === i)));
    if (i >= 0) { nameInput.setAttribute('aria-activedescendant', list.children[i].id); list.children[i].scrollIntoView({ block: 'nearest' }); }
  };
  const render = () => {
    list.replaceChildren(...items.map((c, i) => {
      const li = document.createElement('li');
      li.id = `buyer-opt-${i}`;
      li.setAttribute('role', 'option');
      li.className = 'ac-item';
      const n = document.createElement('strong'); n.textContent = c.name;
      const m = document.createElement('bdi'); m.className = 'num ltr'; m.dir = 'ltr'; m.textContent = c.mobile ? show(c.mobile) : 'بدون موبایل';
      li.append(n, m);
      li.addEventListener('mousedown', (e) => { e.preventDefault(); pick(c); }); // before the input blurs
      return li;
    }));
    list.hidden = items.length === 0;
    nameInput.setAttribute('aria-expanded', String(!list.hidden));
    active = -1;
  };
  const search = async (q) => {
    const my = ++seq;
    const res = await get(`/api/customers?q=${encodeURIComponent(q)}`);
    if (my !== seq || !res.ok) return;
    items = (res.data.items || []).slice(0, 6);
    render();
  };

  nameInput.addEventListener('input', () => {
    known(false);
    clearTimeout(timer);
    const q = nameInput.value.trim();
    if (q.length < 2) { items = []; close(); return; }
    timer = setTimeout(() => search(q), 250);
  });
  nameInput.addEventListener('keydown', (e) => {
    if (list.hidden || !items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); highlight((active + 1) % items.length); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlight((active - 1 + items.length) % items.length); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); pick(items[active]); }
    else if (e.key === 'Escape') close();
  });
  nameInput.addEventListener('blur', () => setTimeout(close, 150));
  // Phones: bring the field up so the suggestions are not under the keyboard or the fixed issue buttons.
  nameInput.addEventListener('focus', () => { if (window.matchMedia('(max-width: 1023px)').matches) setTimeout(() => nameInput.scrollIntoView({ block: 'start', behavior: 'smooth' }), 250); });

  // A complete mobile: look up its owner.
  let lastLooked = '';
  mobileInput.addEventListener('input', async () => {
    const m = normMobile(mobileInput.value);
    if (!MOBILE_RE.test(m)) { hint.hidden = true; if (m !== lastLooked) known(false); return; }
    if (m === lastLooked) return;
    lastLooked = m;
    const res = await get(`/api/customers?q=${m}`);
    const c = res.ok ? (res.data.items || []).find((x) => x.mobile === m) : null;
    if (normMobile(mobileInput.value) !== m) return; // typed on meanwhile
    if (!c) { hint.hidden = true; known(false); return; }
    known(true);
    const typed = nameInput.value.trim();
    if (!typed) {
      nameInput.value = c.name;
      if (nidInput && c.national_id && !nidInput.value) nidInput.value = toPersian(c.national_id);
      hint.textContent = `مشتری ثبت‌شده: ${c.name}`;
      hint.hidden = false;
    } else if (typed !== c.name) {
      hint.replaceChildren(`این شماره به نام «${c.name}» ثبت است. `);
      const use = document.createElement('button');
      use.type = 'button'; use.className = 'btn btn-link sm'; use.textContent = 'استفاده از همین نام';
      use.addEventListener('click', () => pick(c));
      hint.append(use);
      hint.hidden = false;
    } else hint.hidden = true;
  });
}
