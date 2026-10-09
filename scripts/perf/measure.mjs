#!/usr/bin/env node
// Throttled journey timings per docs/PERFORMANCE_BUDGET.md (profiles A/B, cold + warm, retained traces).
// Run it against the PRODUCTION deployment from a measurement machine; dev/Vite numbers do not count.
//
// One-time setup on the measurement machine (Playwright is a measurement tool, not an app dependency):
//   npm i --no-save playwright && npx playwright install chromium
//
// 1) Save a signed-in session for the authenticated screens (asks for the SMS code in this terminal):
//   node scripts/perf/measure.mjs --base https://zarlio.ir --save-session --mobile 09xxxxxxxxx
// 2) Measure (defaults: profiles A,B; 5 runs; targets login,rate,calculator,public_invoice,verify):
//   node scripts/perf/measure.mjs --base https://zarlio.ir --invoice-token <i-token> --verify-token <v-token>
//   add --targets composer to include the draft composer (creates one draft per run on that shop).
//
// Output: storage/perf/<timestamp>/results.json (+ one Playwright trace per run). Exit code 1 when a
// screen-usable timing, first-load transfer or request-count budget is exceeded.
import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import readline from 'node:readline/promises';

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, all) => {
  if (a.startsWith('--')) acc.push([a.slice(2), all[i + 1] && !all[i + 1].startsWith('--') ? all[i + 1] : true]);
  return acc;
}, []));
const BASE = String(args.base || process.env.PERF_BASE || '').replace(/\/$/, '');
if (!BASE) { console.error('Missing --base (e.g. https://zarlio.ir)'); process.exit(2); }
const ROOT = new URL('../../', import.meta.url).pathname;
const STATE = String(args.state || join(ROOT, 'storage/perf/session.json'));

let chromium;
try {
  ({ chromium } = createRequire(import.meta.url)('playwright'));
} catch {
  console.error('Playwright is not installed here. Run: npm i --no-save playwright && npx playwright install chromium');
  process.exit(2);
}

// docs/PERFORMANCE_BUDGET.md — "Reproducible network/device profiles" and budgets.
const PROFILES = {
  A: { down: 1.6e6 / 8, up: 0.75e6 / 8, rtt: 300, cpu: 4, budgets: { login: 4000, rate: 5000, calculator: 5000, composer: 5000, public_invoice: 3000, verify: 3000 } },
  B: { down: 0.4e6 / 8, up: 0.2e6 / 8, rtt: 800, cpu: 6, budgets: { login: 10000, rate: 12000, calculator: 12000, composer: 12000, public_invoice: 8000, verify: 8000 } },
};
const TRANSFER_KIB = { login: 300, rate: 300, calculator: 400, composer: 400, public_invoice: 180, verify: 180 };
const MAX_REQUESTS = { login: 8, calculator: 12 };
const VIEWPORT = { width: 390, height: 844 };

const loaded = (re) => (p) => p.waitForFunction((src) => performance.getEntriesByType('resource').some((r) => new RegExp(src).test(r.name) && r.responseEnd > 0), re.source);
const TARGETS = {
  login: { url: '/login', auth: false, ready: async (p) => { await p.waitForSelector('#mobile', { state: 'visible' }); await loaded(/\/assets\/login-[^/]+\.js$/)(p); } },
  rate: { url: '/invoices/new', auth: true, ready: async (p) => { await p.waitForSelector('[data-start]:not([disabled])', { state: 'visible' }); await loaded(/\/assets\/rate-[^/]+\.js$/)(p); } },
  calculator: { url: '/calculator', auth: true, ready: async (p) => { await p.waitForSelector('#c-weight', { state: 'visible' }); await p.fill('#c-weight', '2'); await p.waitForFunction(() => { const t = document.querySelector('[data-total]'); return t && t.textContent.trim() !== '—'; }); } },
  composer: { url: '/invoices/new', auth: true, ready: async (p) => {
    await p.click('[data-start]'); await p.waitForURL(/\/items$/, { timeout: 120000 });
    const w = p.locator('[data-f="net_weight_g"]').first(); await w.waitFor({ state: 'visible' }); await w.fill('2');
    await p.waitForFunction(() => { const t = document.querySelector('[data-payable]'); return t && /\d|[۰-۹]/.test(t.textContent); });
  } },
  // Readable = styled document (stylesheet and fonts loaded), not just parsed HTML.
  public_invoice: { url: () => `/i/${args['invoice-token']}`, auth: false, needs: 'invoice-token', ready: async (p) => { await p.waitForLoadState('load'); await p.waitForSelector('.kv', { state: 'visible' }); } },
  verify: { url: () => `/v/${args['verify-token']}`, auth: false, needs: 'verify-token', ready: async (p) => { await p.waitForLoadState('load'); await p.waitForSelector('main', { state: 'visible' }); } },
};

async function saveSession() {
  const mobile = String(args.mobile || '');
  if (!/^09\d{9}$/.test(mobile)) { console.error('--save-session needs --mobile 09xxxxxxxxx'); process.exit(2); }
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: VIEWPORT, locale: 'fa-IR' });
  const p = await ctx.newPage();
  await p.goto(`${BASE}/login`);
  await p.fill('#mobile', mobile);
  await p.click('[data-login-form] button[type=submit]');
  await p.waitForURL(/\/login\/code/, { timeout: 120000 });
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  const code = (await rl.question('SMS code: ')).trim();
  rl.close();
  await p.keyboard.type(code);
  await p.click('[data-code-form] button[type=submit]').catch(() => {});
  await p.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 120000 });
  mkdirSync(join(STATE, '..'), { recursive: true });
  await ctx.storageState({ path: STATE });
  await browser.close();
  console.log(`Session saved to ${STATE} (keep it private; delete it after measuring).`);
}

const median = (a) => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };

async function measure() {
  const runs = Number(args.runs || 5);
  const profiles = String(args.profiles || 'A,B').split(',');
  const wanted = String(args.targets || 'login,rate,calculator,public_invoice,verify').split(',');
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const out = join(ROOT, 'storage/perf', stamp);
  mkdirSync(out, { recursive: true });
  const browser = await chromium.launch();
  const results = { meta: { base: BASE, started_at: new Date().toISOString(), chromium: browser.version(), runs, viewport: VIEWPORT, profiles: Object.fromEntries(profiles.map((k) => [k, { ...PROFILES[k], budgets: undefined }])), note: 'Lab throttling (CDP), not field data. Record test location and device separately.' }, targets: {} };
  let failed = false;

  for (const pname of profiles) {
    const prof = PROFILES[pname];
    for (const tname of wanted) {
      const t = TARGETS[tname];
      if (!t) { console.warn(`unknown target ${tname}`); continue; }
      if (t.needs && !args[t.needs]) { console.warn(`skip ${tname}: pass --${t.needs}`); continue; }
      if (t.auth && !existsSync(STATE)) { console.warn(`skip ${tname}: no saved session (run --save-session first)`); continue; }
      const url = BASE + (typeof t.url === 'function' ? t.url() : t.url);
      const cold = []; const warm = []; let kib = 0; let requests = 0; let encoding = null;
      for (let run = 0; run < runs; run++) {
        const ctx = await browser.newContext({ viewport: VIEWPORT, locale: 'fa-IR', ...(t.auth ? { storageState: STATE } : {}) });
        await ctx.tracing.start({ screenshots: true, snapshots: false });
        const page = await ctx.newPage();
        const cdp = await ctx.newCDPSession(page);
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: prof.rtt, downloadThroughput: prof.down, uploadThroughput: prof.up });
        await cdp.send('Emulation.setCPUThrottlingRate', { rate: prof.cpu });
        let bytes = 0; let n = 0;
        cdp.on('Network.loadingFinished', (e) => { bytes += e.encodedDataLength; n++; });
        cdp.on('Network.responseReceived', (e) => { if (/\.(js|css)(\?|$)/.test(e.response.url) && !encoding) encoding = e.response.headers['content-encoding'] || e.response.headers['Content-Encoding'] || 'none'; });
        let s = Date.now();
        await page.goto(url, { waitUntil: 'commit', timeout: 180000 });
        await t.ready(page);
        cold.push(Date.now() - s);
        if (run === 0) { await page.waitForLoadState('networkidle').catch(() => {}); kib = bytes / 1024; requests = n; }
        // Warm: same context (HTTP cache filled), fresh navigation.
        s = Date.now();
        await page.goto(url, { waitUntil: 'commit', timeout: 180000 });
        if (tname === 'calculator') await page.fill('#c-weight', '');
        await t.ready(page);
        warm.push(Date.now() - s);
        await ctx.tracing.stop({ path: join(out, `${pname}-${tname}-run${run + 1}.zip`) });
        await ctx.close();
      }
      const budget = prof.budgets[tname];
      const r = {
        cold_median_ms: median(cold), cold_worst_ms: Math.max(...cold), warm_median_ms: median(warm), warm_worst_ms: Math.max(...warm),
        first_load_kib: +kib.toFixed(1), requests, asset_encoding: encoding, budget_ms: budget, transfer_budget_kib: TRANSFER_KIB[tname], max_requests: MAX_REQUESTS[tname] ?? null,
      };
      r.ok = r.cold_worst_ms <= budget && r.first_load_kib <= TRANSFER_KIB[tname] && (r.max_requests === null || requests <= r.max_requests);
      if (!r.ok) failed = true;
      results.targets[`${pname}/${tname}`] = r;
      console.log(`${pname} ${tname.padEnd(15)} cold ${r.cold_median_ms}ms (worst ${r.cold_worst_ms}) warm ${r.warm_median_ms}ms  ${r.first_load_kib} KiB  ${requests} req  ${r.ok ? 'ok' : 'OVER'}`);
    }
  }
  await browser.close();
  results.meta.finished_at = new Date().toISOString();
  results.ok = !failed;
  writeFileSync(join(out, 'results.json'), JSON.stringify(results, null, 2));
  console.log(`\nResults and traces: ${out}`);
  process.exit(failed ? 1 : 0);
}

if (args['save-session']) await saveSession(); else await measure();
