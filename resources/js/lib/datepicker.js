// Mobile-first Jalali date picker (no dependencies).
// Markup: <div class="field" data-jdp data-min="1400/01/01" data-max="1410/12/29" data-quick="today,tomorrow,+1m" data-required>
//           <label id="x-l">…</label><div class="input-wrap"><input type="hidden" name="due" value="1405/08/13"></div></div>
import { MONTHS, WEEKDAYS, today, monthLength, weekday, format, parse, cmp, addDays, addMonths } from './jalali.js';
import { toPersian } from './digits.js';
import { sheet } from './ui.js';

const WEEKDAY_FULL = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
const fa = (n) => toPersian(String(n));
export const label = (d) => (d ? `${fa(d[2])} ${MONTHS[d[1] - 1]} ${fa(d[0])}` : '');

export function initDatePickers(root = document) {
  root.querySelectorAll('[data-jdp]:not([data-jdp-ready])').forEach(setup);
}

function setup(field) {
  field.dataset.jdpReady = '1';
  const input = field.querySelector('input[type="hidden"]');
  const wrap = field.querySelector('.input-wrap');
  const labelEl = field.querySelector('label, .label');
  const trigger = document.createElement('button');
  trigger.type = 'button';
  trigger.className = 'jdp-trigger';
  if (labelEl?.id) trigger.setAttribute('aria-labelledby', labelEl.id);
  wrap.appendChild(trigger);
  const render = () => {
    const d = parse(input.value);
    trigger.innerHTML = d
      ? `<span>${label(d)} <span class="muted small">${WEEKDAY_FULL[weekday(...d)]}</span></span>`
      : '<span class="placeholder">انتخاب تاریخ</span>';
    trigger.insertAdjacentHTML('beforeend', '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>');
  };
  render();
  trigger.addEventListener('click', () => open(field, input, () => { render(); input.dispatchEvent(new Event('change', { bubbles: true })); }));
}

function open(field, input, onPick) {
  const min = parse(field.dataset.min), max = parse(field.dataset.max);
  const t = today();
  const selected = parse(input.value);
  let view = 'days';
  let cursor = selected ? [...selected] : [...t];
  const title = field.querySelector('label, .label')?.textContent?.trim() || 'انتخاب تاریخ';
  const quick = (field.dataset.quick || 'today').split(',').filter(Boolean);
  const { sheet: el, close } = sheet('<div data-body></div>', { label: title });
  const body = el.querySelector('[data-body]');
  const inRange = (d) => (!min || cmp(d, min) >= 0) && (!max || cmp(d, max) <= 0);

  const pick = (d) => {
    if (!inRange(d)) return;
    input.value = format(d);
    close();
    onPick();
  };

  const quickDate = (q) => (q === 'today' ? t : q === 'tomorrow' ? addDays(t, 1) : q === '+1w' ? addDays(t, 7) : q === '+1m' ? addMonths(t, 1) : null);
  const quickLabel = { today: 'امروز', tomorrow: 'فردا', '+1w': 'یک هفته بعد', '+1m': 'یک ماه بعد' };

  function draw() {
    const [y, m] = cursor;
    let html = `<div class="between"><h2>${title}</h2><button type="button" class="icon-btn" data-close aria-label="بستن">✕</button></div>`;
    html += `<div class="jdp-head">
      <button type="button" class="icon-btn" data-nav="-1" aria-label="ماه قبل"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button>
      <button type="button" class="title-btn" data-view="months" aria-label="انتخاب ماه، ${MONTHS[m - 1]}">${MONTHS[m - 1]} ▾</button>
      <button type="button" class="title-btn" data-view="years" aria-label="انتخاب سال، ${fa(y)}">${fa(y)} ▾</button>
      <button type="button" class="icon-btn" data-nav="1" aria-label="ماه بعد"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>
    </div>`;
    if (view === 'days') {
      html += `<div class="jdp-week" aria-hidden="true">${WEEKDAYS.map((w) => `<span>${w}</span>`).join('')}</div>`;
      html += `<div class="jdp-days" role="grid" aria-label="${MONTHS[m - 1]} ${fa(y)}">`;
      const lead = weekday(y, m, 1);
      for (let i = 0; i < lead; i++) html += '<span></span>';
      const len = monthLength(y, m);
      for (let d = 1; d <= len; d++) {
        const date = [y, m, d];
        const wd = (lead + d - 1) % 7;
        const cls = [cmp(date, t) === 0 ? 'today' : '', wd === 6 ? 'friday' : ''].join(' ');
        const sel = selected && cmp(date, selected) === 0;
        const focus = cmp(date, cursor) === 0;
        html += `<button type="button" role="gridcell" class="${cls}" data-day="${d}" aria-selected="${sel}" tabindex="${focus ? 0 : -1}" ${inRange(date) ? '' : 'disabled'} aria-label="${fa(d)} ${MONTHS[m - 1]} ${fa(y)}، ${WEEKDAY_FULL[wd]}">${fa(d)}</button>`;
      }
      html += '</div>';
      if (window.matchMedia?.('(pointer: coarse)').matches) html += '<p class="xs muted center">برای ماه قبل یا بعد، تقویم را به چپ یا راست بکشید.</p>';
      if (quick.length) html += `<div class="jdp-quick">${quick.map((q) => quickDate(q) ? `<button type="button" class="chip" data-quick="${q}">${quickLabel[q]}</button>` : '').join('')}</div>`;
      html += `<div class="field"><label for="jdp-typed">یا تاریخ را تایپ کنید</label><div class="jdp-typed-row"><div class="input-wrap ltr-input"><input id="jdp-typed" inputmode="numeric" placeholder="۱۴۰۵/۰۸/۱۳" autocomplete="off" enterkeyhint="done"></div><button type="button" class="btn btn-dark" data-typed-ok>تأیید</button></div><div class="err">تاریخ درست نیست. نمونه: ۱۴۰۵/۰۸/۱۳</div></div>`;
      html += `<div class="jdp-foot">${field.hasAttribute('data-required') ? '' : '<button type="button" class="btn btn-line" data-clear>پاک‌کردن</button>'}<button type="button" class="btn btn-line" data-close>انصراف</button></div>`;
    } else if (view === 'months') {
      html += `<div class="jdp-grid" role="listbox" aria-label="ماه‌های ${fa(y)}">${MONTHS.map((name, i) => {
        const first = [y, i + 1, 1], last = [y, i + 1, monthLength(y, i + 1)];
        const ok = (!min || cmp(last, min) >= 0) && (!max || cmp(first, max) <= 0);
        return `<button type="button" role="option" data-month="${i + 1}" aria-selected="${i + 1 === m}" class="${t[0] === y && t[1] === i + 1 ? 'current' : ''}" ${ok ? '' : 'disabled'}>${name}</button>`;
      }).join('')}</div><button type="button" class="btn btn-line" data-view="days">برگشت به روزها</button>`;
    } else {
      const from = min ? min[0] : t[0] - 80, to = max ? max[0] : t[0] + 15;
      let years = '';
      // Future-only fields (due dates) list years ascending; past/birth-style fields list newest first.
      const list = [];
      for (let yy = from; yy <= to; yy++) list.push(yy);
      if (!(min && !max)) list.reverse();
      for (const yy of list) years += `<button type="button" role="option" data-year="${yy}" aria-selected="${yy === y}" class="${yy === t[0] ? 'current' : ''}">${fa(yy)}</button>`;
      html += `<div class="jdp-grid jdp-years" role="listbox" aria-label="سال">${years}</div><button type="button" class="btn btn-line" data-view="days">برگشت به روزها</button>`;
    }
    body.innerHTML = html;
    body.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', close));
    if (view === 'years') body.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'center' });
    (body.querySelector('.jdp-days [tabindex="0"]') || body.querySelector('[aria-selected="true"]') || body.querySelector('button'))?.focus();
  }

  body.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b || b.disabled) return;
    if (b.dataset.nav) { cursor = addMonths([cursor[0], cursor[1], 1], Number(b.dataset.nav)); draw(); }
    else if (b.dataset.view) { view = b.dataset.view; draw(); }
    else if (b.dataset.day) pick([cursor[0], cursor[1], Number(b.dataset.day)]);
    else if (b.dataset.month) { cursor = [cursor[0], Number(b.dataset.month), 1]; view = 'days'; draw(); }
    else if (b.dataset.year) { cursor = [Number(b.dataset.year), cursor[1], 1]; view = 'months'; draw(); }
    else if (b.dataset.quick) pick(quickDate(b.dataset.quick));
    else if (b.hasAttribute('data-clear')) { input.value = ''; close(); onPick(); }
    else if (b.hasAttribute('data-typed-ok')) submitTyped(body.querySelector('#jdp-typed'));
  });

  function submitTyped(el) {
    const d = parse(el.value);
    if (d && inRange(d)) pick(d); else el.closest('.field').classList.add('invalid');
  }

  // Touch: swipe horizontally on the day grid to change month (RTL: swipe right = next month).
  let touchX = null, touchY = null;
  body.addEventListener('touchstart', (e) => {
    if (view !== 'days' || !e.target.closest('.jdp-days')) { touchX = null; return; }
    touchX = e.touches[0].clientX; touchY = e.touches[0].clientY;
  }, { passive: true });
  body.addEventListener('touchend', (e) => {
    if (touchX === null) return;
    const dx = e.changedTouches[0].clientX - touchX, dy = e.changedTouches[0].clientY - touchY;
    touchX = null;
    if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
    cursor = addMonths([cursor[0], cursor[1], 1], dx > 0 ? 1 : -1);
    draw();
  }, { passive: true });

  body.addEventListener('keydown', (e) => {
    const typed = e.target.id === 'jdp-typed';
    if (typed && e.key === 'Enter') {
      e.preventDefault();
      submitTyped(e.target);
      return;
    }
    if (view !== 'days' || !e.target.dataset.day) return;
    const map = { ArrowLeft: 1, ArrowRight: -1, ArrowDown: 7, ArrowUp: -7 }; // RTL grid
    if (map[e.key] !== undefined) { e.preventDefault(); cursor = addDays([cursor[0], cursor[1], Number(e.target.dataset.day)], map[e.key]); draw(); }
    else if (e.key === 'PageDown' || e.key === 'PageUp') { e.preventDefault(); cursor = addMonths(cursor, e.key === 'PageDown' ? 1 : -1); draw(); }
  });

  draw();
}
