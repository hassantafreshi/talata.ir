// «اعلان روی گوشی»: Web Push for this device (installed web app or browser that allows notifications).
import { get, post } from './http.js';

const b64ToBytes = (s) => {
  const raw = atob(s.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (s.length % 4)) % 4));
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
};

export const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent);
export const isStandalone = () => window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;

/** unsupported | install-first | denied | on | off */
export async function pushState() {
  if (!('serviceWorker' in navigator) || !window.isSecureContext) return 'unsupported';
  if (!('PushManager' in window) || !('Notification' in window)) return isIos() && !isStandalone() ? 'install-first' : 'unsupported';
  if (Notification.permission === 'denied') return 'denied';
  const reg = await navigator.serviceWorker.getRegistration('/');
  const sub = await reg?.pushManager.getSubscription();
  return sub && Notification.permission === 'granted' ? 'on' : 'off';
}

export async function enablePush() {
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') return { ok: false, state: permission === 'denied' ? 'denied' : 'off' };
  const key = await get('/api/push/key');
  if (!key.ok) return { ok: false, message: key.message };
  const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(() => navigator.serviceWorker.ready);
  let sub;
  try {
    sub = await reg.pushManager.getSubscription() || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(key.data.key) });
  } catch {
    return { ok: false, message: 'این مرورگر اجازه اعلان نداد. اگر از ایران و بدون فیلترشکن هستید، سرویس اعلان مرورگر ممکن است در دسترس نباشد؛ پیامک همیشه فرستاده می‌شود.' };
  }
  const res = await post('/api/push/subscribe', sub.toJSON());
  return res.ok ? { ok: true, state: 'on' } : { ok: false, message: res.message };
}

export async function disablePush() {
  const reg = await navigator.serviceWorker.getRegistration('/');
  const sub = await reg?.pushManager.getSubscription();
  if (sub) { await post('/api/push/unsubscribe', { endpoint: sub.endpoint }); await sub.unsubscribe().catch(() => {}); }
  return { ok: true, state: 'off' };
}

const TEXT = {
  unsupported: 'این مرورگر اعلان وب ندارد. خبر تأیید با پیامک می‌رسد.',
  'install-first': 'در آیفون، اعلان فقط وقتی کار می‌کند که زرلیو را به صفحه اصلی اضافه کرده باشید (اشتراک‌گذاری ← Add to Home Screen) و از همان آیکون باز کنید.',
  denied: 'اعلان برای این سایت در تنظیمات مرورگر بسته شده است. از تنظیمات مرورگر ← اعلان‌ها، zarlio را مجاز کنید.',
  on: 'روشن است: وقتی مشتری پیش‌فاکتور را تأیید کند، روی همین گوشی خبر می‌دهیم.',
  off: 'خاموش است. با روشن کردن، تأیید مشتری روی همین گوشی اعلان می‌شود.',
};

/** Wires a [data-push-card] block: status text, on/off button and «اعلان آزمایشی». */
export async function initPushCard(card, toast) {
  const status = card.querySelector('[data-push-status]');
  const toggle = card.querySelector('[data-push-toggle]');
  const testBtn = card.querySelector('[data-push-test]');
  const show = (state) => {
    card.dataset.state = state;
    if (status) status.textContent = TEXT[state] || '';
    if (toggle) { toggle.hidden = !['on', 'off'].includes(state); toggle.textContent = state === 'on' ? 'خاموش کردن اعلان این گوشی' : 'روشن کردن اعلان روی این گوشی'; toggle.className = `btn block ${state === 'on' ? 'btn-line' : 'btn-gold'}`; }
    if (testBtn) testBtn.hidden = state !== 'on';
  };
  show(await pushState());
  toggle?.addEventListener('click', async () => {
    toggle.disabled = true;
    const r = card.dataset.state === 'on' ? await disablePush() : await enablePush();
    toggle.disabled = false;
    if (r.message) toast(r.message, { kind: 'error', timeout: 12000 });
    show(r.state || await pushState());
    if (r.ok && r.state === 'on') toast('اعلان روی این گوشی روشن شد.');
  });
  testBtn?.addEventListener('click', async () => {
    testBtn.disabled = true;
    const res = await post('/api/push/test');
    testBtn.disabled = false;
    toast(res.ok ? 'اعلان آزمایشی فرستاده شد؛ چند ثانیه صبر کنید.' : res.message, { kind: res.ok ? 'info' : 'error', timeout: 12000 });
  });
}
