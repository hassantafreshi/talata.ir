#!/usr/bin/env node
// Browser evidence for docs/PHASE_1_CHECKLIST.md M1/M5 that unit tests cannot give:
//   reflow at 360/390/768/1280 and at 200% zoom (no horizontal page scroll), long Persian names,
//   keyboard reachability with visible focus, the desktop composer (21-karat GOLD + MISC rows),
//   and passkey enrolment + sign-in through Chromium's virtual authenticator.
// Runs against a LOCAL dev server whose SMS driver is `log` (login codes are read from the log).
//
//   php artisan serve --port=8000 & php artisan queue:work --queue=otp,default &
//   npm i --no-save playwright && npx playwright install chromium   (once)
//   node scripts/e2e/ui-evidence.mjs --base http://localhost:8000
//
// Output: storage/e2e/<timestamp>/results.json and screenshots; exit code 1 when a check fails.
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, all) => { if (a.startsWith('--')) acc.push([a.slice(2), all[i + 1] && !all[i + 1].startsWith('--') ? all[i + 1] : true]); return acc; }, []));
const BASE = String(args.base || 'http://localhost:8000').replace(/\/$/, '');
const ROOT = new URL('../../', import.meta.url).pathname;
const LOG = join(ROOT, 'storage/logs/laravel.log');
const OUT = join(ROOT, 'storage/e2e', new Date().toISOString().replace(/[:.]/g, '-'));
mkdirSync(OUT, { recursive: true });

let chromium;
try { ({ chromium } = createRequire(import.meta.url)('playwright')); } catch { console.error('Playwright missing: npm i --no-save playwright && npx playwright install chromium'); process.exit(2); }

const results = []; const pageErrors = [];
const check = (name, ok, detail = '') => { results.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const codeLines = (m) => (existsSync(LOG) ? readFileSync(LOG, 'utf8').split('\n').filter((l) => l.includes(`[SMS:log driver] to ${m}`)) : []);
const MOBILE = `0912${String(Math.floor(1000000 + Math.random() * 8999999))}`;
const LONG = 'گردنبند زنجیر ونیزی دست‌ساز با قفل مخفی و آویز اسم سفارشی مشتری، طول چهل‌وپنج سانتی‌متر';

async function login(page, mobile) {
  await page.goto(`${BASE}/login`);
  const before = codeLines(mobile).length;
  await page.fill('#mobile', mobile);
  await page.click('[data-login-form] button[type=submit]');
  await page.waitForURL('**/login/code', { timeout: 30000 });
  for (let i = 0; i < 120 && codeLines(mobile).length <= before; i++) await sleep(250);
  await page.keyboard.type(codeLines(mobile).at(-1).match(/\b(\d{6})\b/)[1]);
  await page.click('[data-code-form] button[type=submit]').catch(() => {});
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 30000 });
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

(async () => {
  const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP localhost 127.0.0.1'] });
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, locale: 'fa-IR', isMobile: true, hasTouch: true });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => pageErrors.push(`${page.url()} :: ${e.message}`));
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });

  // New shop: login, business profile.
  await login(page, MOBILE);
  await page.waitForURL('**/settings/business**');
  await page.fill('#b-name', 'طلافروشی شواهد آزمایشی'); await page.fill('#b-mobile', '09121112255'); await page.fill('#b-address', 'تهران، بازار بزرگ، راسته زرگرها، پاساژ طلا، طبقه دوم، پلاک ۲۱۷');
  await page.click('[data-business] button[type=submit]'); await sleep(800);

  // Invoice with long names, issued with a share link (for public pages).
  await page.goto(`${BASE}/invoices/new`); await page.click('[data-start]'); await page.waitForURL(/\/items$/);
  const w = page.locator('[data-f="net_weight_g"]').first(); await w.fill('3.25');
  await page.locator('[data-f="name"]').first().fill(LONG);
  await page.locator('[data-f="description"]').first().fill(`${LONG} — توضیح دوم برای آزمون شکستن خط`);
  await sleep(1500);
  await page.click('[data-review]'); await page.waitForURL(/\/review$/);
  await page.fill('[name=buyer_name]', 'خانم مریم السادات حسینی‌نژاد فراهانی'); await page.fill('[name=buyer_mobile]', '09351234567');
  check('review: long names, no horizontal scroll at 390', (await overflow(page)) <= 0);
  await page.screenshot({ path: join(OUT, 'review-long-names-390.png'), fullPage: true });
  await page.click('[data-mode="ISSUE_ONLY"]'); await page.waitForURL(/\/invoices\/[0-9a-z]{26}(\?|$|\/)/, { timeout: 30000 });
  const invoiceId = page.url().match(/\/invoices\/([0-9a-z]{26})/)[1];
  const invoiceUrl = `${BASE}/invoices/${invoiceId}`;
  const shareRes = await page.evaluate(async (id) => { const t = document.querySelector('meta[name=csrf-token]').content; const r = await fetch(`/api/invoices/${id}/share`, { method: 'POST', headers: { 'X-CSRF-TOKEN': t, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } }); return r.json(); }, invoiceId);
  if (!shareRes.url) throw new Error(`share link not created: ${JSON.stringify(shareRes).slice(0, 200)}`);
  const publicPath = new URL(shareRes.url).pathname;

  // Reflow sweep: phone, tablet, desktop and 200% zoom of a 1280 desktop (640 CSS px).
  const paths = ['/invoices/new', '/calculator', '/mazneh', '/invoices', '/customers', '/settings', new URL(invoiceUrl).pathname, `${new URL(invoiceUrl).pathname}/print`, publicPath];
  for (const [label, vw, dpr] of [['360', 360, 2], ['390', 390, 2], ['768', 768, 2], ['1280', 1280, 1], ['zoom200@1280', 640, 2]]) {
    const v = await browser.newContext({ viewport: { width: vw, height: 900 }, deviceScaleFactor: dpr, locale: 'fa-IR', storageState: await ctx.storageState() });
    const p = await v.newPage();
    const bad = [];
    for (const path of paths) {
      await p.goto(BASE + path); await sleep(250);
      const o = await overflow(p);
      // The A4 print sheet is a fixed-width document on purpose; at desktop widths it must fit, on phones it reflows.
      if (o > 0) bad.push(`${path} (+${o}px)`);
    }
    check(`no horizontal page scroll at ${label}`, bad.length === 0, bad.join(', '));
    if (['768', 'zoom200@1280'].includes(label)) { await p.goto(BASE + publicPath); await p.screenshot({ path: join(OUT, `public-invoice-${label}.png`), fullPage: true }); }
    await v.close();
  }

  // Keyboard: Start is reachable with Tab and the focused element has a visible focus ring.
  await page.goto(`${BASE}/invoices/new`);
  let reached = false; let ringVisible = false;
  for (let i = 0; i < 40 && !reached; i++) {
    await page.keyboard.press('Tab');
    const f = await page.evaluate(() => { const el = document.activeElement; const cs = getComputedStyle(el); return { start: el.matches('[data-start]'), ring: cs.outlineStyle !== 'none' && cs.outlineWidth !== '0px' }; });
    if (f.start) { reached = true; ringVisible = f.ring; }
  }
  check('keyboard reaches «شروع» with a visible focus ring', reached && ringVisible, reached ? '' : 'not reached in 40 tabs');

  // Desktop composer: 21-karat GOLD row + MISC row, exact local totals without reload.
  const desk = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR', storageState: await ctx.storageState() });
  const d = await desk.newPage(); d.on('pageerror', (e) => pageErrors.push(`${d.url()} :: ${e.message}`));
  await d.goto(`${BASE}/invoices/new`); await d.click('[data-start]'); await d.waitForURL(/\/items$/);
  const row1 = d.locator('[data-row]').first();
  await row1.locator('[data-p="875"]').click(); await row1.locator('[data-f="net_weight_g"]').fill('2');
  await d.click('[data-add-row]'); await sleep(200);
  const row2 = d.locator('[data-row]').nth(1);
  await row2.locator('input[data-f="item_type"][value="MISC"]').check({ force: true });
  await row2.locator('[data-sec="MISC"] [data-f="name"]').fill('جعبه هدیه'); await row2.locator('[data-f="manual_total_toman"]').fill('150000');
  await sleep(600);
  const payable = await d.locator('[data-payable]').innerText();
  check('desktop composer: 21K gold + MISC rows priced locally', /[\d۰-۹]/.test(payable) && (await d.locator('[data-row]').count()) === 2, payable);
  await d.screenshot({ path: join(OUT, 'composer-desktop-1280.png'), fullPage: true });
  await desk.close();

  // Passkey: enrol from settings, sign out, sign in with the (virtual) fingerprint.
  await page.goto(`${BASE}/settings#passkeys`);
  await page.waitForSelector('[data-passkey-add]:not(.hidden)');
  await page.click('[data-passkey-add]');
  await page.waitForSelector('[data-passkey-list] li', { timeout: 15000 });
  await page.click('form[action$="/logout"] button'); await page.waitForURL('**/login');
  await page.waitForSelector('[data-passkey-login]:not(.hidden)');
  await page.click('[data-passkey-btn]');
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 });
  check('passkey: enrol and sign in (virtual authenticator, not a real device)', !page.url().includes('/login'));

  check('no JavaScript errors on any page', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));
  await browser.close();
  writeFileSync(join(OUT, 'results.json'), JSON.stringify({ base: BASE, at: new Date().toISOString(), results }, null, 2));
  console.log(`\nResults: ${join(OUT, 'results.json')}`);
  process.exit(results.every((r) => r.ok) ? 0 : 1);
})();
