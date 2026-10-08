// Run: node --test tests/js/ — product-name ranking on invoice rows (resources/js/lib/product-suggest.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { key, tier, rank } from '../../resources/js/lib/product-suggest.js';

const terms = [['انگشتر طلا', 95], ['انگشتر طلا زنانه', 90], ['النگو', 92], ['گوشواره طلا', 88], ['سرویس طلا', 60], ['انگشتر مردانه', 70]].map(([t, w]) => [t, w, key(t)]);
const shop = [
  { id: 'a', t: 'GOLD', n: 'انگشتر رینگی کارتیه', w: '3.2', wg: '18', pr: '7', c: 5 },
  { id: 'b', t: 'GOLD', n: 'النگو بافت', w: '12', wg: '12', pr: '7', c: 2 },
  { id: 'c', t: 'MISC', n: 'جعبه هدیه', m: '50000', c: 9 },
];

test('key folds Arabic letters, ZWNJ and Persian digits', () => {
  assert.equal(key('  گل‌سر  كوچك ۱۸ '), 'گل سر کوچک 18');
  assert.equal(key('ي'), 'ی');
});

test('tier: prefix beats word prefix beats substring', () => {
  assert.equal(tier('انگشتر طلا', 'ان'), 3);
  assert.equal(tier('طلا انگشتر', 'انگ'), 2);
  assert.equal(tier('نیم ست طلا', 'ست ط'), 2);
  assert.equal(tier('گوشواره', 'شوا'), 1);
  assert.equal(tier('گوشواره', 'xyz'), 0);
});

test("the shop's own products come before the shared list, then popularity", () => {
  const out = rank('ان', 'GOLD', shop, terms);
  assert.equal(out[0].kind, 'shop');
  assert.equal(out[0].label, 'انگشتر رینگی کارتیه');
  assert.deepEqual(out.slice(1).map((x) => x.label), ['انگشتر طلا', 'انگشتر طلا زنانه', 'انگشتر مردانه']);
});

test('narrows as the phrase is completed, and every typed word counts', () => {
  assert.deepEqual(rank('انگشتر طلا ز', 'GOLD', [], terms).map((x) => x.label), ['انگشتر طلا زنانه']);
  assert.deepEqual(rank('طلا گوش', 'GOLD', [], terms).map((x) => x.label), ['گوشواره طلا']);
});

test('an exact shop product still shows (to pre-fill it); a shared term the shop already has is not repeated', () => {
  const mine = [{ id: 'x', t: 'GOLD', n: 'انگشتر طلا', w: '2', c: 1 }];
  const out = rank('انگشتر طلا', 'GOLD', mine, terms);
  assert.equal(out[0].kind, 'shop');
  assert.equal(out.filter((x) => x.label === 'انگشتر طلا').length, 1);
});

test('MISC rows suggest only the shop\'s own MISC items; an empty field lists recent sales only', () => {
  assert.deepEqual(rank('جع', 'MISC', shop, terms).map((x) => x.label), ['جعبه هدیه']);
  assert.ok(rank('', 'GOLD', shop, terms).every((x) => x.kind === 'shop'));
});
