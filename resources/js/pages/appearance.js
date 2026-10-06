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
  let saved = structuredClone(boot.settings);   // what «لغو تغییرات» goes back to
  let version = boot.version;
  let timer = null, seq = 0, dirty = false;
  const history = [];                           // snapshots for «برگرداندن آخرین تغییر»
  const undoBtn = document.querySelector('[data-undo]');
  const cancelBtn = document.querySelector('[data-cancel]');
  const remember = () => { history.push(structuredClone(s)); if (history.length > 30) history.shift(); syncButtons(); };
  const syncButtons = () => { if (undoBtn) undoBtn.disabled = history.length === 0; if (cancelBtn) cancelBtn.disabled = !dirty; };
  const setDirty = (v) => { dirty = v; syncButtons(); };

  function drawBlocks() {
    const host = form.querySelector('[data-blocks]');
    host.innerHTML = s.blocks.map((b, i) => {
      const req = boot.required.includes(b.kind);
      const label = escapeHtml(boot.blockLabels[b.kind] || b.kind);
      return `<div class="row-card stack-sm" data-i="${i}">
        <div class="between">
          <label class="check"><input type="checkbox" data-b="visible" ${b.visible ? 'checked' : ''} ${req ? 'disabled' : ''}> ${label} ${req ? '<span class="xs muted">(الزامی)</span>' : ''}</label>
          <span class="cluster"><button type="button" class="btn btn-line sm" data-move="-1" aria-label="${label}: بالاتر" ${i === 0 ? 'disabled' : ''}>▲</button><button type="button" class="btn btn-line sm" data-move="1" aria-label="${label}: پایین‌تر" ${i === s.blocks.length - 1 ? 'disabled' : ''}>▼</button></span>
        </div>
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
    form.orientation.value = s.print?.orientation || 'portrait';
    form.margins.value = s.print?.margins || 'normal';
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
    s.print = { ...(s.print || {}), orientation: form.orientation.value || 'portrait', margins: form.margins.value || 'normal' };
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
      if (res.ok) { remember(); s = res.data.settings; fill(); frame.innerHTML = res.data.html; fit(); setDirty(true); }
      return;
    }
    remember(); read(); setDirty(true); schedule();
  });
  let typing = false;
  form.addEventListener('input', (e) => {
    if (e.target.name !== 'note_text') return;
    if (!typing) { remember(); typing = true; }          // one undo step per typing burst
    clearTimeout(form._typing); form._typing = setTimeout(() => { typing = false; }, 800);
    read(); setDirty(true); schedule();
  });

  // ▲/▼: order of the information blocks (rendered in this order within header and footer).
  form.querySelector('[data-blocks]').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-move]');
    if (!btn) return;
    read();
    const i = Number(btn.closest('[data-i]').dataset.i);
    const j = i + Number(btn.dataset.move);
    if (j < 0 || j >= s.blocks.length) return;
    remember();
    [s.blocks[i], s.blocks[j]] = [s.blocks[j], s.blocks[i]];
    drawBlocks(); setDirty(true); schedule();
    form.querySelector(`[data-i="${j}"] [data-move="${btn.dataset.move}"]`)?.focus();
  });

  undoBtn?.addEventListener('click', () => {
    if (!history.length) return;
    s = history.pop(); fill(); setDirty(JSON.stringify(s) !== JSON.stringify(saved)); schedule(); syncButtons();
  });
  cancelBtn?.addEventListener('click', () => {
    if (!dirty || !confirm('تغییرات ذخیره‌نشده کنار گذاشته شود؟')) return;
    remember(); s = structuredClone(saved); fill(); setDirty(false); schedule();
  });
  document.querySelector('[data-reset]')?.addEventListener('click', async () => {
    if (!confirm('همه تنظیمات این قالب به حالت پیش‌فرض برگردد؟ (تا «ذخیره» را نزنید چیزی عوض نمی‌شود)')) return;
    const res = await post('/api/settings/appearance/preview', { settings: { template_id: s.template_id } });
    if (res.ok) { remember(); s = res.data.settings; fill(); frame.innerHTML = res.data.html; fit(); setDirty(true); }
  });
  document.querySelectorAll('[name="pv"]').forEach((r) => r.addEventListener('change', () => { frame.className = `inv-frame ${r.value}`; fit(); }));
  window.addEventListener('resize', fit);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    read();
    const btn = form.querySelector('[type=submit]');
    busy(btn);
    const res = await put('/api/settings/appearance', { settings: s, version });
    busy(btn, false);
    if (res.ok) { version = res.data.version; saved = structuredClone(s); setDirty(false); toast('ظاهر فاکتور ذخیره شد.'); return; }
    if (res.code === 'LAYOUT_CONFLICT') {
      // Someone saved meanwhile (another device or teammate): load the latest instead of overwriting it.
      toast(res.message, { kind: 'error', timeout: 20000, action: { label: 'بارگذاری آخرین نسخه', onClick: () => { dirty = false; location.reload(); } } });
      return;
    }
    if (res.code?.startsWith('CAPABILITY_')) showQuota(res); else toast(res.message, { kind: 'error', timeout: 8000 });
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) e.preventDefault(); });

  fill();
  preview();
}
