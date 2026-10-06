import '../../css/invoice.css';
import { post, put } from '../lib/http.js';
import { busy, toast, escapeHtml } from '../lib/ui.js';
import { showQuota } from '../lib/quota.js';

const ALIGN = [['right', 'راست'], ['center', 'وسط'], ['left', 'چپ']];

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const form = document.querySelector('[data-layout]');
  const frame = document.querySelector('[data-preview]');
  let s = structuredClone(boot.settings);
  let version = boot.version;
  let timer = null, seq = 0, dirty = false;

  function drawBlocks() {
    const host = form.querySelector('[data-blocks]');
    host.innerHTML = s.blocks.map((b, i) => {
      const req = boot.required.includes(b.kind);
      return `<div class="row-card stack-sm" data-i="${i}">
        <label class="check"><input type="checkbox" data-b="visible" ${b.visible ? 'checked' : ''} ${req ? 'disabled' : ''}> ${escapeHtml(boot.blockLabels[b.kind] || b.kind)} ${req ? '<span class="xs muted">(الزامی)</span>' : ''}</label>
        <div class="between">
          <div class="seg" role="radiogroup" aria-label="جای قرارگیری">${['header', 'footer'].map((a) => `<label><input type="radio" name="area-${i}" data-b="area" value="${a}" ${b.area === a ? 'checked' : ''} ${b.kind === 'shop_name' ? 'disabled' : ''}>${a === 'header' ? 'سربرگ' : 'پاورقی'}</label>`).join('')}</div>
          <div class="seg" role="radiogroup" aria-label="چینش">${ALIGN.map(([v, l]) => `<label><input type="radio" name="align-${i}" data-b="align" value="${v}" ${b.align === v ? 'checked' : ''}>${l}</label>`).join('')}</div>
        </div></div>`;
    }).join('');
  }

  function drawCols() {
    const host = form.querySelector('[data-cols]');
    host.innerHTML = Object.entries(boot.colLabels).map(([k, l]) => {
      const fixed = k === 'name' || k === 'amount';
      return `<label class="chip"><input type="checkbox" data-col="${k}" ${s.items_table.columns.includes(k) ? 'checked' : ''} ${fixed ? 'disabled' : ''}>${escapeHtml(l)}</label>`;
    }).join('');
  }

  function fill() {
    form.template_id.value = s.template_id;
    form.logo_visible.checked = !!s.logo?.visible;
    form.logo_size.value = s.logo?.size || 'medium';
    form.show_component_breakdown.checked = s.summary.show_component_breakdown !== false;
    form.signature_box.checked = s.summary.signature_box !== false;
    form.note_visible.checked = !!s.summary.public_note?.visible;
    form.note_text.value = s.summary.public_note?.text || '';
    form.text_size.value = s.typography.text_size || 'normal';
    form.density.value = s.typography.density || 'comfortable';
    form.accent.value = s.typography.accent || 'ink';
    drawBlocks(); drawCols();
  }

  function read() {
    form.querySelectorAll('[data-i]').forEach((card) => {
      const b = s.blocks[Number(card.dataset.i)];
      const vis = card.querySelector('[data-b="visible"]');
      if (!vis.disabled) b.visible = vis.checked;
      b.area = card.querySelector('[data-b="area"]:checked')?.value || b.area;
      b.align = card.querySelector('[data-b="align"]:checked')?.value || b.align;
    });
    s.logo = { visible: form.logo_visible.checked, size: form.logo_size.value || 'medium' };
    s.items_table.columns = [...form.querySelectorAll('[data-col]')].filter((c) => c.checked).map((c) => c.dataset.col);
    s.summary = { show_component_breakdown: form.show_component_breakdown.checked, signature_box: form.signature_box.checked, public_note: { visible: form.note_visible.checked, text: form.note_text.value.trim() } };
    s.typography = { ...s.typography, text_size: form.text_size.value, density: form.density.value, accent: form.accent.value };
  }

  function fit() {
    const inv = frame.querySelector('.inv');
    if (!inv) return;
    if (frame.classList.contains('print')) {
      const scale = Math.min(1, (frame.clientWidth - 20) / inv.offsetWidth);
      inv.style.transform = `scale(${scale})`;
      frame.style.height = `${inv.offsetHeight * scale + 20}px`;
    } else { inv.style.transform = ''; frame.style.height = ''; }
  }

  async function preview() {
    const mine = ++seq;
    const res = await post('/api/settings/appearance/preview', { settings: s });
    if (mine !== seq) return;
    if (!res.ok) { frame.innerHTML = `<p class="notice err">${escapeHtml(res.message)}</p>`; return; }
    frame.innerHTML = res.data.html; // server-rendered Blade (escaped); no user HTML
    fit();
  }
  const schedule = () => { clearTimeout(timer); timer = setTimeout(preview, 250); };

  form.addEventListener('change', async (e) => {
    if (e.target.name === 'template_id') {
      // A template switch starts from that template's defaults; the server owns presets.
      const res = await post('/api/settings/appearance/preview', { settings: { template_id: e.target.value } });
      if (res.ok) { s = res.data.settings; fill(); frame.innerHTML = res.data.html; fit(); dirty = true; }
      return;
    }
    read(); dirty = true; schedule();
  });
  form.addEventListener('input', (e) => { if (e.target.name === 'note_text') { read(); dirty = true; schedule(); } });
  document.querySelectorAll('[name="pv"]').forEach((r) => r.addEventListener('change', () => { frame.className = `inv-frame ${r.value}`; fit(); }));
  window.addEventListener('resize', fit);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    read();
    const btn = form.querySelector('[type=submit]');
    busy(btn);
    const res = await put('/api/settings/appearance', { settings: s, version });
    busy(btn, false);
    if (res.ok) { version = res.data.version; dirty = false; toast('ظاهر فاکتور ذخیره شد.'); return; }
    if (res.code?.startsWith('CAPABILITY_')) showQuota(res); else toast(res.message, { kind: 'error', timeout: 8000 });
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) e.preventDefault(); });

  fill();
  preview();
}
