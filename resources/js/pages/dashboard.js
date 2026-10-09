// Sales dashboard: number tiles + one dependency-free SVG bar chart (docs/GOLD_RECEIVED_AND_DASHBOARD.md §10).
// All numbers come from the server; this file only formats and draws them.
import { get } from '../lib/http.js';
import { toast } from '../lib/ui.js';
import { toPersian } from '../lib/digits.js';

const NS = 'http://www.w3.org/2000/svg';
const full = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 0 });
const grams = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 3 });
const compact = new Intl.NumberFormat('fa-IR', { notation: 'compact', maximumFractionDigits: 1 });

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  let report = boot.report;
  let unit = 'toman';
  let metric = Object.keys(report.metrics)[0];
  let picked = null;
  const chart = document.querySelector('[data-chart]');
  const pickedEl = document.querySelector('[data-picked]');
  const custom = document.querySelector('[data-custom]');

  const hasGrams = (m) => report.metrics[m]?.series_g !== undefined;
  const effUnit = (m) => (unit === 'g' && hasGrams(m) ? 'g' : 'toman');
  const fmt = (v, u) => (u === 'g' ? `${grams.format(v)} گرم` : `${full.format(v)} تومان`);

  function tiles() {
    document.querySelectorAll('[data-metric]').forEach((tile) => {
      const m = report.metrics[tile.dataset.metric];
      if (!m) return;
      const u = effUnit(tile.dataset.metric);
      tile.querySelector('[data-big]').textContent = u === 'g' ? m.g_fa.replace(' گرم', '') : m.toman_fa;
      tile.querySelector('[data-unit]').textContent = u === 'g' ? 'گرم طلا' : 'تومان';
      tile.querySelector('[data-small]').textContent = m.g_fa === undefined ? '' : (u === 'g' ? `${m.toman_fa} تومان` : m.g_fa);
      const d = tile.querySelector('[data-delta]');
      d.className = 't-delta xs';
      if (m.delta_pct === undefined || m.delta_pct === null) { d.textContent = ''; return; }
      const n = Number(m.delta_pct);
      d.textContent = `${n > 0 ? '▲' : n < 0 ? '▼' : '='} ${toPersian(String(Math.abs(n)))}٪ ${report.compare_fa}`;
      d.classList.add(n > 0 ? 'up' : n < 0 ? 'down' : 'same');
    });
    document.querySelector('[data-label]').textContent = report.label_fa;
    document.querySelector('[data-count]').textContent = report.invoices_fa;
    document.querySelector('[data-empty]').classList.toggle('hidden', !report.empty);
  }

  function niceMax(v) {
    if (v <= 0) return 1;
    const p = 10 ** Math.floor(Math.log10(v));
    return [1, 2, 2.5, 5, 10].map((s) => s * p).find((s) => s >= v);
  }

  function el(name, attrs = {}, text) {
    const n = document.createElementNS(NS, name);
    Object.entries(attrs).forEach(([k, v]) => n.setAttribute(k, v));
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function draw() {
    const u = effUnit(metric);
    const values = (u === 'g' ? report.metrics[metric].series_g : report.metrics[metric].series_toman) || [];
    const n = values.length;
    // Real pixel width so labels stay readable on phones; y-axis labels sit on the right (RTL).
    const W = Math.max(280, Math.round(chart.clientWidth || 640)), H = 220, top = 12, bottom = 28, axisW = 64;
    const plotW = W - axisW - 4, plotH = H - top - bottom;
    const max = niceMax(Math.max(0, ...values));
    const svg = el('svg', { viewBox: `0 0 ${W} ${H}`, 'aria-hidden': 'true', focusable: 'false', direction: 'ltr' });
    [0, 0.5, 1].forEach((f) => {
      const y = top + plotH * (1 - f);
      svg.appendChild(el('line', { class: 'grid', x1: 0, x2: plotW, y1: y, y2: y }));
      svg.appendChild(el('text', { class: 'axis', x: W - 2, y: y + 4, 'text-anchor': 'end' }, u === 'g' ? grams.format(max * f) : compact.format(max * f)));
    });
    const slot = plotW / Math.max(n, 1);
    const bw = Math.max(2, Math.min(36, slot * 0.66));
    const every = Math.ceil(n / Math.max(3, Math.floor(plotW / 48)));
    values.forEach((v, i) => {
      // Time runs right-to-left: the first bucket sits next to the axis on the right.
      const cx = plotW - slot * (i + 0.5);
      const h = max ? (plotH * v) / max : 0;
      const bar = el('rect', { class: `bar${picked === i ? ' on' : ''}`, x: cx - bw / 2, y: top + plotH - h, width: bw, height: Math.max(h, v > 0 ? 1.5 : 0), rx: Math.min(4, bw / 3), tabindex: '0', 'data-i': i });
      bar.appendChild(el('title', {}, `${report.titles[i]}: ${fmt(v, u)}`));
      svg.appendChild(bar);
      if (i % every === 0) svg.appendChild(el('text', { class: 'axis', x: cx, y: H - 8, 'text-anchor': 'middle' }, report.labels[i]));
    });
    chart.replaceChildren(svg);
    chart.setAttribute('aria-label', `نمودار ستونی ${boot.labels[metric][0]} · ${report.label_fa}. جدول اعداد زیر نمودار است.`);
    document.querySelector('[data-chart-unit]').textContent = u === 'g' ? 'گرم طلای ۱۸ عیار' : 'تومان';
    if (picked !== null && picked < n) pickedEl.textContent = `${report.titles[picked]}: ${fmt(values[picked], u)}`;
    else pickedEl.textContent = 'برای دیدن عدد هر ستون، روی آن بزنید.';
    table(u, values);
  }

  function table(u, values) {
    const t = document.querySelector('[data-table]');
    const cell = (tag, text, attrs = {}) => { const c = document.createElement(tag); c.textContent = text; Object.entries(attrs).forEach(([k, v]) => c.setAttribute(k, v)); return c; };
    const head = document.createElement('thead');
    const hr = document.createElement('tr');
    hr.append(cell('th', 'زمان', { scope: 'col' }), cell('th', boot.labels[metric][0], { scope: 'col' }));
    head.append(hr);
    const body = document.createElement('tbody');
    values.forEach((v, i) => { const tr = document.createElement('tr'); tr.append(cell('th', report.titles[i], { scope: 'row' }), cell('td', fmt(v, u), { class: 'n' })); body.append(tr); });
    t.replaceChildren(head, body);
  }

  function pick(target) {
    const bar = target.closest('.bar');
    if (!bar) return;
    picked = Number(bar.dataset.i);
    draw();
  }
  chart.addEventListener('click', (e) => pick(e.target));
  chart.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(e.target); } });

  async function load(params) {
    const qs = new URLSearchParams(params).toString();
    const res = await get(`${boot.api}?${qs}`);
    if (!res.ok) {
      toast(res.message || 'دریافت گزارش ممکن نشد.', { kind: 'error' });
      if (res.data?.errors) Object.entries(res.data.errors).forEach(([f, msg]) => { const fe = custom?.querySelector(`[name="${f}"]`)?.closest('.field'); if (fe) { fe.classList.add('invalid'); fe.querySelector('.err').textContent = msg[0]; } });
      return;
    }
    report = res.data;
    if (!report.metrics[metric]) metric = Object.keys(report.metrics)[0];
    picked = null;
    tiles(); draw();
    try { localStorage.setItem('dash:range', report.range); } catch {}
  }

  document.querySelector('.dash-ranges').addEventListener('click', (e) => {
    const b = e.target.closest('[data-range]');
    if (!b) return;
    document.querySelectorAll('[data-range]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    if (b.dataset.range === 'custom') { custom.classList.remove('hidden'); custom.querySelector('button, [data-jdp] button')?.focus(); return; }
    custom?.classList.add('hidden');
    load({ range: b.dataset.range });
  });
  custom?.addEventListener('submit', (e) => {
    e.preventDefault();
    custom.querySelectorAll('.field.invalid').forEach((f) => f.classList.remove('invalid'));
    load({ range: 'custom', from: custom.querySelector('[name=from]').value, to: custom.querySelector('[name=to]').value });
  });
  document.querySelectorAll('input[name="unit"]').forEach((r) => r.addEventListener('change', () => { unit = r.value; tiles(); draw(); }));
  document.querySelector('[data-metric-tabs]').addEventListener('change', (e) => { if (e.target.name === 'metric') { metric = e.target.value; picked = null; draw(); } });

  let resizeTimer;
  window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(draw, 150); });
  tiles(); draw();
  // Reopen on the range used last time on this device (if this plan offers it).
  try {
    const last = localStorage.getItem('dash:range');
    const b = last && last !== report.range && last !== 'custom' && document.querySelector(`button[data-range="${CSS.escape(last)}"]`);
    if (b) b.click();
  } catch {}
}
