// Run: node --test tests/js/ — same examples as tests/Unit/MobileExtractTest.php.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extractMobiles, displayMobile } from '../../resources/js/lib/mobiles.js';

const { vectors } = JSON.parse(readFileSync(new URL('../fixtures/mobile-extract-vectors.json', import.meta.url)));

for (const v of vectors) {
  test(`extract ${JSON.stringify(v.in)}`, () => {
    assert.deepEqual(extractMobiles(v.in), { mobiles: v.mobiles, invalid: v.invalid });
  });
}

test('display groups Persian digits', () => {
  assert.equal(displayMobile('09121234567'), '۰۹۱۲ ۱۲۳ ۴۵۶۷');
});
