// Visible feedback while the next page loads (mobile first): the tapped tab becomes active at once and pulses,
// a gold progress bar runs at the top, the page dims slightly, and on a slow connection a short note says so.
// Plain page loads only — AJAX buttons keep their own «در حال …» state (ui.js busy()).

/** Pure: does a click on this link start a page load we should show? (unit-tested) */
export function trackable(link, here) {
  if (!link || !link.href) return false;
  if (link.target && link.target !== '_self') return false;
  if (link.download || link.noProgress) return false;
  let url;
  try { url = new URL(link.href, here.href); } catch { return false; }
  if (!/^https?:$/.test(url.protocol) || url.origin !== here.origin) return false;
  // Same page with only a #fragment: no load.
  if (url.pathname === here.pathname && url.search === here.search && url.hash) return false;
  return true;
}

export default function navFeedback(toast) {
  const bar = document.createElement('div');
  bar.className = 'nav-progress';
  bar.setAttribute('aria-hidden', 'true');
  document.body.append(bar);
  let slow = null;
  let giveUp = null;

  const reset = () => {
    document.documentElement.classList.remove('is-navigating');
    bar.classList.remove('run');
    document.querySelectorAll('.is-loading').forEach((el) => el.classList.remove('is-loading'));
    clearTimeout(slow); clearTimeout(giveUp);
  };
  const start = (el) => {
    reset();
    document.documentElement.classList.add('is-navigating');
    void bar.offsetWidth; // restart the animation
    bar.classList.add('run');
    if (el) {
      const tab = el.closest('.tabs a, .topnav a');
      if (tab) {
        tab.parentElement.querySelectorAll('a[aria-current]').forEach((a) => a.removeAttribute('aria-current'));
        tab.setAttribute('aria-current', 'page');
      }
      (tab || el.closest('.list-item, .btn, .action-tile, .chip') || el).classList.add('is-loading');
    }
    slow = setTimeout(() => toast?.('در حال بارگذاری… اینترنت کند است، کمی صبر کنید.', { timeout: 6000 }), 3500);
    // A cancelled «leave this page?» dialog or a download keeps us here: undo after a while.
    giveUp = setTimeout(reset, 20000);
  };

  document.addEventListener('click', (e) => {
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest?.('a[href]');
    if (!a) return;
    const link = { href: a.getAttribute('href'), target: a.getAttribute('target'), download: a.hasAttribute('download'), noProgress: a.hasAttribute('data-no-progress') };
    // After every other handler: one that cancels the click (sheets, confirms) wins.
    setTimeout(() => { if (!e.defaultPrevented && trackable(link, location)) start(a); }, 0);
  });
  document.addEventListener('submit', (e) => {
    const form = e.target;
    setTimeout(() => { if (!e.defaultPrevented && !form.hasAttribute('data-no-progress') && form.getAttribute('target') !== '_blank') start(e.submitter || form.querySelector('[type=submit]')); }, 0);
  });
  // Back/forward from the browser cache shows the old page as it was left: clear the loading state.
  window.addEventListener('pageshow', reset);
}
