// Run: node --test tests/js/ — the quota sheet adapts to the limit that was hit.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { quotaPanel } from '../../resources/js/lib/quota-panel.js';

test('invoice cap offers the free calculator and keeps print open', () => {
  const p = quotaPanel({ code: 'QUOTA_INVOICES_PER_MONTH', data: { resource: 'invoices_per_month' } });
  assert.equal(p.title, 'سقف فاکتور این ماه پر شد');
  assert.equal(p.calc, true);
  assert.match(p.note, /چاپ/);
});

test('new-customer cap does not push the calculator and explains invoices still work', () => {
  const p = quotaPanel({ code: 'QUOTA_NEW_CUSTOMERS_PER_MONTH', data: { resource: 'new_customers_per_month' } });
  assert.equal(p.title, 'سقف مشتری جدید این ماه پر شد');
  assert.equal(p.calc, false);
  assert.match(p.note, /فاکتور صادر/);
});

test('link cap is about sharing, not issuing', () => {
  const p = quotaPanel({ code: 'QUOTA_LINKS_PER_MONTH', data: { resource: 'links_per_month' } });
  assert.equal(p.title, 'سقف لینک و پیامک فاکتور این ماه پر شد');
  assert.equal(p.calc, false);
  assert.match(p.note, /صدور و چاپ/);
});

test('a capability limit falls back to the plan-feature panel', () => {
  const p = quotaPanel({ code: 'CAPABILITY_INSTALLMENTS_MANAGE', data: { capability: 'installments.manage' } });
  assert.equal(p.title, 'این امکان در پلن فعلی نیست');
  assert.equal(p.calc, false);
});

test('a missing/unknown payload still returns the safe default panel', () => {
  const p = quotaPanel({});
  assert.equal(p.title, 'این امکان در پلن فعلی نیست');
  assert.equal(p.calc, false);
  assert.ok(p.label);
});
