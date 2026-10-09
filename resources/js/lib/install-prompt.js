// «نصب برنامه» hint shown once the merchant is inside the app. Android/Chromium uses the native
// beforeinstallprompt (captured early in app.js as window.__bip); iOS Safari has no such event, so we show
// step-by-step «افزودن به صفحه اصلی» instructions. Dismissals are snoozed per device (localStorage).

const SNOOZE_KEY = 'pwa-install-snooze';
const SNOOZE_DAYS = 14;

/**
 * Pure decision: what (if anything) to offer. Kept separate from the DOM so it can be unit-tested.
 * @returns {'android'|'ios'|'none'}
 */
export function installDecision({ standalone, snoozedUntil, now, ua, hasBip }) {
  if (standalone) return 'none'; // already installed / running as an app
  if (snoozedUntil && now < snoozedUntil) return 'none';
  const isIOS = /iphone|ipad|ipod/i.test(ua) || (/Macintosh/i.test(ua) && /Mobile|Touch/i.test(ua));
  if (isIOS) return 'ios';
  if (hasBip) return 'android';
  return 'none'; // non-iOS without a native prompt: unsupported browser or already installed
}

function readSnooze() {
  try { return +(localStorage.getItem(SNOOZE_KEY) || 0) || 0; } catch { return 0; }
}
function snooze() {
  try { localStorage.setItem(SNOOZE_KEY, String(Date.now() + SNOOZE_DAYS * 864e5)); } catch {}
}

function banner(inner) {
  const el = document.createElement('div');
  el.className = 'install-banner';
  el.setAttribute('role', 'dialog');
  el.setAttribute('aria-label', 'نصب برنامه زرلیو');
  el.innerHTML = inner;
  document.body.appendChild(el);
  el.querySelector('[data-close]')?.addEventListener('click', () => { snooze(); el.remove(); });
  return el;
}

function showAndroid(evt) {
  const el = banner(`
    <div class="install-body">
      <strong>نصب زرلیو روی گوشی</strong>
      <span class="small">سریع‌تر باز می‌شود و مثل یک برنامه کار می‌کند.</span>
    </div>
    <div class="install-actions">
      <button class="btn btn-gold sm" type="button" data-install>نصب</button>
      <button class="btn btn-link sm" type="button" data-close>بعداً</button>
    </div>`);
  el.querySelector('[data-install]')?.addEventListener('click', async () => {
    try {
      evt.prompt();
      await evt.userChoice;
    } catch {}
    window.__bip = null;
    el.remove();
    snooze();
  });
}

function showIos() {
  // iOS Safari: installation is manual via the Share sheet.
  banner(`
    <div class="install-body">
      <strong>افزودن زرلیو به صفحه اصلی</strong>
      <ol class="install-steps small">
        <li>در نوار پایین مرورگر، دکمه «اشتراک‌گذاری» را بزنید
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="M8 7l4-4 4 4"/><path d="M5 12v7a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-7"/></svg>
        </li>
        <li>گزینه «Add to Home Screen / افزودن به صفحه اصلی» را انتخاب کنید.</li>
        <li>روی «افزودن» بزنید؛ آیکون زرلیو روی صفحه اصلی ساخته می‌شود.</li>
      </ol>
    </div>
    <div class="install-actions">
      <button class="btn btn-link sm" type="button" data-close>متوجه شدم</button>
    </div>`);
}

export default function () {
  const standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  const decide = (hasBip) => installDecision({ standalone, snoozedUntil: readSnooze(), now: Date.now(), ua: navigator.userAgent || '', hasBip });

  const d = decide(!!window.__bip);
  if (d === 'ios') { showIos(); return; }
  if (d === 'android') { showAndroid(window.__bip); return; }
  // Android prompt may not have fired yet: wait for it once, then re-decide (iOS already handled above).
  if (!standalone && readSnooze() <= Date.now()) {
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      window.__bip = e;
      if (decide(true) === 'android') showAndroid(e);
    }, { once: true });
  }
}
