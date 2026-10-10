import { toPersian } from './digits.js';

// «همین الان · به‌روزرسانی بعدی حدود ۲ دقیقه دیگر»: counts down to the next scheduled fetch (QuoteSchedule on the
// server), so a quiet hour reads as planned instead of a stalled site. Same wording as QuoteService::clockText.
export function clockText(ago, next) {
  if (ago === null) return 'در انتظار اولین دریافت نرخ';
  const agoFa = ago < 60 ? 'همین الان' : `${toPersian(String(Math.floor(ago / 60)))} دقیقه پیش`;
  let nextFa;
  if (next <= 0) nextFa = 'در حال به‌روزرسانی…';
  else if (next < 60) nextFa = 'به‌روزرسانی بعدی کمتر از یک دقیقه دیگر';
  else nextFa = `به‌روزرسانی بعدی حدود ${toPersian(String(Math.ceil(next / 60)))} دقیقه دیگر`;
  return `${agoFa} · ${nextFa}`;
}

/** Keeps every [data-clock] element ticking; returns set(clock) for fresh API data and delayMs() for the next poll. */
export function startClock() {
  const els = [...document.querySelectorAll('[data-clock]')];
  let state = null;
  const parse = (c) => (c ? { fetched: c.fetched_at ? Date.parse(c.fetched_at) : null, interval: c.interval_seconds || 60 } : null);
  try { state = parse(JSON.parse(els[0]?.dataset.clock || 'null')); } catch { state = null; }
  const render = () => {
    if (!state) return;
    const ago = state.fetched === null ? null : Math.max(0, Math.round((Date.now() - state.fetched) / 1000));
    const next = ago === null ? 0 : state.interval - ago;
    els.forEach((el) => { el.textContent = clockText(ago, next); });
  };
  render();
  setInterval(render, 15000);
  return {
    set(c) { if (c) { state = parse(c); render(); } },
    // Ask again shortly after the next scheduled fetch (never sooner than 20 s, never later than the interval).
    delayMs() {
      if (!state || state.fetched === null) return 60000;
      const due = state.fetched + state.interval * 1000 + 8000 - Date.now();
      return Math.min(state.interval * 1000, Math.max(20000, due));
    },
  };
}
