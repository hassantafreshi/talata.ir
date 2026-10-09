#!/usr/bin/env node
// Bundle-size gate for docs/PERFORMANCE_BUDGET.md (offline, no server needed).
// Reads public/build/manifest.json after `npm run build`, follows each critical route's static import
// graph (plus the modules its essential action loads), measures gzip sizes and compares them with the
// budgets. Exit code 1 when a budget is exceeded, so CI can block the release.
//
//   npm run build && npm run perf:bundle            # table
//   npm run perf:bundle -- --json > bundle.json     # machine-readable
import { readFileSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join } from 'node:path';

const ROOT = new URL('../../', import.meta.url).pathname;
const BUILD = join(ROOT, 'public/build');
const manifest = JSON.parse(readFileSync(join(BUILD, 'manifest.json'), 'utf8'));
const KiB = 1024;
const gz = (file) => gzipSync(readFileSync(join(BUILD, file)), { level: 9 }).length;

// Budgets (gzip KiB) from docs/PERFORMANCE_BUDGET.md. `extra` = modules the essential action loads lazily.
const ROUTES = [
  { name: 'login', page: 'resources/js/pages/login.js', extra: ['resources/js/lib/otp.js', 'resources/js/lib/pow.js'], jsBudget: 150 },
  { name: 'new-invoice (rate/Start)', page: 'resources/js/pages/rate.js', extra: [], jsBudget: 150 },
  { name: 'calculator', page: 'resources/js/pages/calculator.js', extra: [], jsBudget: 220 },
  { name: 'draft composer', page: 'resources/js/pages/items.js', extra: [], jsBudget: 220 },
];
const CSS_BUDGET = 30;
const FONT_BUDGET = 80;
const PUBLIC_INVOICE_JS_BUDGET = 20;

function keyFor(src) {
  if (manifest[src]) return src;
  // lib modules bundled as shared chunks are keyed by "_name-hash.js"
  const base = src.split('/').pop().replace(/\.js$/, '');
  return Object.keys(manifest).find((k) => manifest[k].name === base && k.startsWith('_')) ?? null;
}

function closure(keys) {
  const seen = new Set();
  const css = new Set();
  const walk = (k) => {
    if (!k || seen.has(k) || !manifest[k]) return;
    seen.add(k);
    for (const c of manifest[k].css ?? []) css.add(c);
    for (const i of manifest[k].imports ?? []) walk(i);
  };
  keys.forEach(walk);
  return { files: [...seen].map((k) => manifest[k].file).filter((f) => f.endsWith('.js')), css: [...css] };
}

const results = [];
let failed = false;
const check = (label, valueKiB, budgetKiB, detail = '') => {
  const ok = valueKiB <= budgetKiB;
  if (!ok) failed = true;
  results.push({ check: label, kib: +valueKiB.toFixed(1), budget_kib: budgetKiB, ok, detail });
};

for (const r of ROUTES) {
  const keys = ['resources/js/app.js', r.page, ...r.extra].map(keyFor);
  const missing = [r.page, ...r.extra].filter((s, i) => !keys[i + 1]);
  const { files } = closure(keys);
  const total = files.reduce((a, f) => a + gz(f), 0) / KiB;
  check(`JS ${r.name}`, total, r.jsBudget, `${files.length} files${missing.length ? `; not in manifest: ${missing.join(', ')}` : ''}`);
}

// Public invoice/verify pages ship no JS (CSS-only); keep the gate so a script tag cannot creep in unnoticed.
check('JS public invoice', 0, PUBLIC_INVOICE_JS_BUDGET, 'CSS-only page (scripts=false in the public layout)');

const cssEntry = manifest['resources/css/app.css'];
if (cssEntry) check('CSS app.css', gz(cssEntry.file) / KiB, CSS_BUDGET);
const invoiceCss = Object.values(manifest).find((e) => /invoice-.*\.css$/.test(e.file));
if (invoiceCss) check('CSS invoice (print/public)', gz(invoiceCss.file) / KiB, CSS_BUDGET);

const fonts = ['Vazirmatn-arabic-subset.woff2', 'Vazirmatn-latin-subset.woff2'];
const fontBytes = fonts.reduce((a, f) => a + statSync(join(ROOT, 'public/fonts', f)).size, 0);
check('Fonts (woff2, both subsets)', fontBytes / KiB, FONT_BUDGET, 'already compressed; raw bytes');

if (process.argv.includes('--json')) {
  console.log(JSON.stringify({ generated_at: new Date().toISOString(), results, ok: !failed }, null, 2));
} else {
  const pad = (s, n) => String(s).padEnd(n);
  console.log(pad('check', 34) + pad('gzip KiB', 10) + pad('budget', 8) + 'result');
  for (const r of results) console.log(pad(r.check, 34) + pad(r.kib, 10) + pad(r.budget_kib, 8) + (r.ok ? 'ok' : 'OVER') + (r.detail ? `  (${r.detail})` : ''));
  console.log(failed ? '\nBudget exceeded — see docs/PERFORMANCE_BUDGET.md before raising any limit.' : '\nAll bundle budgets met.');
}
process.exit(failed ? 1 : 0);
