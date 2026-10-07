// Run: node --test tests/js/ — the install-prompt platform decision (resources/js/lib/install-prompt.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { installDecision } from '../../resources/js/lib/install-prompt.js';

const ANDROID = 'Mozilla/5.0 (Linux; Android 13; Pixel) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36';
const now = 1_700_000_000_000;

test('iOS Safari gets the step-by-step instructions (no native prompt exists there)', () => {
  assert.equal(installDecision({ standalone: false, snoozedUntil: 0, now, ua: IPHONE, hasBip: false }), 'ios');
});

test('Android shows the native prompt only once it has been captured', () => {
  assert.equal(installDecision({ standalone: false, snoozedUntil: 0, now, ua: ANDROID, hasBip: true }), 'android');
  assert.equal(installDecision({ standalone: false, snoozedUntil: 0, now, ua: ANDROID, hasBip: false }), 'none');
});

test('already installed (standalone) never prompts, on any platform', () => {
  assert.equal(installDecision({ standalone: true, snoozedUntil: 0, now, ua: IPHONE, hasBip: false }), 'none');
  assert.equal(installDecision({ standalone: true, snoozedUntil: 0, now, ua: ANDROID, hasBip: true }), 'none');
});

test('a live snooze suppresses the prompt until it expires', () => {
  assert.equal(installDecision({ standalone: false, snoozedUntil: now + 1000, now, ua: IPHONE, hasBip: false }), 'none');
  assert.equal(installDecision({ standalone: false, snoozedUntil: now - 1000, now, ua: IPHONE, hasBip: false }), 'ios');
});

test('desktop browsers without a native prompt stay silent', () => {
  assert.equal(installDecision({ standalone: false, snoozedUntil: 0, now, ua: DESKTOP, hasBip: false }), 'none');
});
