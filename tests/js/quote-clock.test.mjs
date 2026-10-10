import test from 'node:test';
import assert from 'node:assert/strict';
import { clockText } from '../../resources/js/lib/quote-clock.js';

test('clock reads as planned in quiet hours, never as stalled', () => {
  assert.equal(clockText(null, 0), 'در انتظار اولین دریافت نرخ');
  assert.equal(clockText(10, 110), 'همین الان · به‌روزرسانی بعدی حدود ۲ دقیقه دیگر');
  assert.equal(clockText(150, 30), '۲ دقیقه پیش · به‌روزرسانی بعدی کمتر از یک دقیقه دیگر');
  assert.equal(clockText(185, -5), '۳ دقیقه پیش · در حال به‌روزرسانی…');
});
