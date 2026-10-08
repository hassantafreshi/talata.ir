// Run: node --test tests/js/ — which link taps show the page-load feedback (resources/js/lib/nav-feedback.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { trackable } from '../../resources/js/lib/nav-feedback.js';

const here = new URL('https://zarlio.ir/mazneh');

test('in-app page links show the loading feedback', () => {
  assert.equal(trackable({ href: '/invoices' }, here), true);
  assert.equal(trackable({ href: '/home' }, here), true);
  assert.equal(trackable({ href: 'https://zarlio.ir/settings?x=1' }, here), true);
});

test('new tabs, downloads, other sites, phone links and in-page anchors do not', () => {
  assert.equal(trackable({ href: '/invoices/x/print', target: '_blank' }, here), false);
  assert.equal(trackable({ href: '/file.pdf', download: true }, here), false);
  assert.equal(trackable({ href: 'https://example.com/' }, here), false);
  assert.equal(trackable({ href: 'tel:02112345678' }, here), false);
  assert.equal(trackable({ href: '#confirm' }, here), false);
  assert.equal(trackable({ href: '/x', noProgress: true }, here), false);
  assert.equal(trackable({ href: '' }, here), false);
});
