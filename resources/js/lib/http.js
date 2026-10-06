// AJAX client: same-origin, CSRF header, JSON, timeout, structured Persian errors.
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export function idempotencyKey(prefix = 'k') {
  const rnd = (globalThis.crypto && crypto.randomUUID) ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  return `${prefix}-${rnd}`;
}

export async function request(method, url, data = null, { timeout = 20000, headers = {} } = {}) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeout);
  const opts = {
    method,
    credentials: 'same-origin',
    signal: ctrl.signal,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf(), ...headers },
  };
  if (data instanceof FormData) opts.body = data;
  else if (data !== null) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(data); }
  try {
    const res = await fetch(url, opts);
    let body = null;
    const type = res.headers.get('content-type') || '';
    if (type.includes('application/json')) body = await res.json();
    else body = { html: await res.text() };
    if (!res.ok) {
      const message = body?.message_fa || (body?.errors ? Object.values(body.errors)[0]?.[0] : null) || defaultMessage(res.status);
      if (res.status === 401 || res.status === 419) {
        // Session ended (idle timeout, signed out elsewhere): one clear way back, not «درخواست انجام نشد».
        const admin = location.pathname.startsWith('/admin');
        const to = res.status === 419 ? location.href : (body?.login || (admin ? '/admin/login' : '/login'));
        import('./ui.js').then(({ toast }) => toast(message, { kind: 'error', timeout: 60000, action: { label: res.status === 419 ? 'بارگذاری دوباره' : 'ورود دوباره', onClick: () => { location.href = to; } } }));
      } else if ((body?.code === 'REAUTH_REQUIRED' || body?.code === 'PASSKEY_SIGNIN_REQUIRED') && body.login) {
        // Admin step-up: offer a fresh sign-in and come back to this page.
        const back = encodeURIComponent(location.pathname);
        import('./ui.js').then(({ toast }) => toast(message, { kind: 'error', timeout: 15000, action: { label: 'ورود دوباره', onClick: () => { location.href = `${body.login}&back=${back}`; } } }));
      }
      return { ok: false, status: res.status, data: body, message, code: body?.code || null, errors: body?.errors || null };
    }
    return { ok: true, status: res.status, data: body };
  } catch (e) {
    const offline = !navigator.onLine;
    return { ok: false, status: 0, data: null, code: offline ? 'OFFLINE' : 'NETWORK', message: offline ? 'اینترنت قطع است. پس از اتصال دوباره تلاش کنید.' : 'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.' };
  } finally {
    clearTimeout(timer);
  }
}

function defaultMessage(status) {
  if (status === 419) return 'نشست شما منقضی شد. صفحه را دوباره باز کنید.';
  if (status === 401) return 'نشست شما تمام شد. دوباره وارد شوید.';
  if (status === 429) return 'تعداد درخواست‌ها زیاد شد. کمی صبر کنید.';
  if (status === 403) return 'دسترسی این کار را ندارید.';
  if (status === 404) return 'پیدا نشد.';
  if (status >= 500) return 'خطای سرور. دوباره تلاش کنید.';
  return 'درخواست انجام نشد.';
}

export const get = (url, opts) => request('GET', url, null, opts);
export const post = (url, data, opts) => request('POST', url, data ?? {}, opts);
export const put = (url, data, opts) => request('PUT', url, data ?? {}, opts);
export const del = (url, data, opts) => request('DELETE', url, data ?? {}, opts);
