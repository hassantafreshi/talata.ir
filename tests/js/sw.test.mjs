// Run: node --test tests/js/ — the service worker's caching rules (public/sw.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');
const listeners = {};
const sandbox = { self: { location: { origin: 'https://zarlio.ir' }, addEventListener: (t, f) => { listeners[t] = f; } }, URL };
vm.runInNewContext(source, sandbox);
const route = (path, method = 'GET', mode = 'cors') => sandbox.routeFor(new URL(path, 'https://zarlio.ir'), method, mode);

test('only hashed assets, fonts and icons are cached', () => {
  assert.equal(route('/build/assets/app-z2zuxqxv.js'), 'cache-first');
  assert.equal(route('/build/assets/app-DcaCDEb6.css'), 'cache-first');
  assert.equal(route('/fonts/Vazirmatn-arabic-subset.woff2'), 'cache-first');
  assert.equal(route('/icons/icon-192.png'), 'cache-first');
});

test('private and token traffic is never cached', () => {
  for (const p of ['/api/invoices', '/api/auth/otp/request', '/i/abc', '/i/abc/print', '/v/abc', '/pay/result/x', '/login', '/admin', '/admin/tenants']) {
    assert.notEqual(route(p), 'cache-first', p);
    assert.notEqual(route(p, 'GET', 'navigate'), 'cache-first', p);
  }
  // Pages: network only; the static offline page appears only when the network is gone.
  assert.equal(route('/invoices/new', 'GET', 'navigate'), 'network-offline');
  assert.equal(route('/calculator', 'GET', 'navigate'), 'network-offline');
});

test('writes and other origins pass straight through', () => {
  assert.equal(route('/api/invoices/drafts', 'POST'), 'network');
  assert.equal(route('/build/assets/app.js', 'POST'), 'network');
  assert.equal(sandbox.routeFor(new URL('https://payment.zarinpal.com/pg/StartPay/x'), 'GET', 'navigate'), 'network');
});

test('the worker registers its lifecycle handlers', () => {
  assert.deepEqual(Object.keys(listeners).sort(), ['activate', 'fetch', 'install']);
});
