// Run: node --test tests/js/  — same vectors as tests/Unit/PricingVectorsTest.php.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { priceGold, priceManual, PricingError } from '../../resources/js/lib/pricing.js';
import { toLatin, parseTomanToIrr, toman } from '../../resources/js/lib/digits.js';

const { vectors } = JSON.parse(readFileSync(new URL('../../docs/design/contracts/calculation-vectors.json', import.meta.url)));

for (const v of vectors) {
  test(`vector ${v.id}`, () => {
    const { input: i, expected: e } = v;
    if (i.rows) {
      let gold = 0n, misc = 0n, vat = 0n;
      for (const r of i.rows) {
        if (r.ref) { const res = priceGold(vectors.find((x) => x.id === r.ref).input); gold += BigInt(res.T); vat += BigInt(res.V); }
        else misc += BigInt(priceManual({ manual_total_irr: r.manual_price_irr }).T);
      }
      assert.equal(gold.toString(), e.gold_rows_total);
      assert.equal(misc.toString(), e.misc_rows_total);
      assert.equal((gold + misc).toString(), e.payable_total);
      assert.equal(vat.toString(), e.gold_vat_total);
      return;
    }
    if (i.display_currency) { assert.equal(parseTomanToIrr(i.displayed_price_per_g), e.stored_price18_irr_per_g); return; }
    if (i.raw_weight) { assert.equal(toLatin(i.raw_weight), e.net_weight_g); assert.equal(toLatin(i.raw_price), e.displayed_price_per_g); return; }
    if (e.error) {
      assert.throws(() => priceGold(i), (err) => err instanceof PricingError && err.code === e.error && err.context.eligible_irr === e.eligible_irr);
      return;
    }
    const res = priceGold(i);
    for (const [k, val] of Object.entries(e)) {
      if (k === 'effective_rate_irr_per_g') { assert.equal(res[k].slice(0, 12), val.slice(0, 12)); continue; }
      assert.equal(res[k], val, `${v.id} ${k}`);
    }
    assert.equal(res.T, (BigInt(res.M) + BigInt(res.B) + BigInt(res.V)).toString());
  });
}

test('toman formatting', () => {
  assert.equal(toman('215620000'), '۲۱٬۵۶۲٬۰۰۰');
  assert.equal(toman('5'), '۰٫۵');
});
