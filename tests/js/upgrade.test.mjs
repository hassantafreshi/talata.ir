// Run: node --test tests/js/ — wording of the «ارتقا» sheet (resources/js/lib/upgrade.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { upgradeContent, FEATURES } from '../../resources/js/lib/upgrade.js';

test('names the feature, the plans that include it and the starting price', () => {
  const c = upgradeContent('installments.manage', { plans: 'حرفه‌ای', price: '۱٬۹۰۰٬۰۰۰' });
  assert.equal(c.title, '«فروش اقساطی» در پلن حرفه‌ای است');
  assert.equal(c.price, 'از ۱٬۹۰۰٬۰۰۰ تومان در ماه');
  assert.ok(c.points.length >= 2);
});

test('unknown capabilities still give a usable sheet', () => {
  const c = upgradeContent('something.new');
  assert.equal(c.title, '«این امکان» در پلن بالاتر است');
  assert.deepEqual(c.points, []);
  assert.equal(c.price, '');
});

test('every locked Free capability shown in the app has wording', () => {
  for (const cap of ['invoice.customize', 'invoice.shop_logo', 'sms.template_edit', 'reports.financial', 'history.all', 'installments.manage', 'settings.backup', 'team.permissions_edit', 'proforma.configure']) {
    assert.ok(FEATURES[cap], cap);
  }
});
