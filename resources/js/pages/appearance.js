import '../../css/invoice.css';
import { post, put } from '../lib/http.js';
import { busy, toast, escapeHtml } from '../lib/ui.js';
import { showQuota } from '../lib/quota.js';
import { toPersian } from '../lib/digits.js';

const ALIGN = [['right', 'راست'], ['center', 'وسط'], ['left', 'چپ']];

/** WCAG contrast against white (same rule as the server: LayoutSettings::readableOnWhite). */
function contrastOnWhite(hex) {
  if (!/^#[0-9a-f]{6}$/i.test(hex)) return 0;
  const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
  return 1.05 / (0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b) + 0.05);
}

export default function () {
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const form = document.querySelector('[data-layout]');
  const frame = document.querySelector('[data-preview]');
  const layout = document.querySelector('[data-ap]');
  const submitBtn = document.querySelector('[form="ap-form"][type=submit]');
  let s = structuredClone(boot.settings);
  let saved = structuredClone(boot.settings);   // what «لغو تغییرات» goes back to
  let version = boot.version;
  let timer = null, seq = 0, dirty = false;
  const history = [];                           // snapshots for «برگرداندن آخرین تغییر»
  const undoBtn = document.querySelector('[data-undo]');
  const cancelBtn = document.querySelector('[data-cancel]');
  const remember = () => { history.push(structuredClone(s)); if (history.length > 30) history.shift(); syncButtons(); };
  const syncButtons = () => { if (undoBtn) undoBtn.disabled = history.length === 0; if (cancelBtn) cancelBtn.disabled = !dirty; };
  const setDirty = (v) => { dirty = v; syncButtons(); document.querySelector('[data-dirty-note]')?.classList.toggle('warn-text', v); };

  // ---- phone: settings or preview -------------------------------------------------------------------
  document.querySelectorAll('[name="ap-view"]').forEach((r) => r.addEventListener('change', () => {
    layout.dataset.view = r.value;
    if (r.value === 'preview') requestAnimationFrame(fit);
  }));

  // ---- drawing ---------------------------------------------------------------------------------------
  function drawBlocks() {
    const host = form.querySelector('[data-blocks]');
    host.innerHTML = s.blocks.map((b, i) => {
      const req = boot.required.includes(b.kind);
      const label = escapeHtml(boot.blockLabels[b.kind] || b.kind);
      return `<div class="blk-row" data-i="${i}">
        <label class="check blk-name"><input type="checkbox" data-b="visible" ${b.visible ? 'checked' : ''} ${req ? 'disabled' : ''}> ${label}${req ? ' <span class="xs muted">(الزامی)</span>' : ''}</label>
        <div class="blk-ctl">
          <div class="seg seg-sm" role="radiogroup" aria-label="${label}: جای قرارگیری">${['header', 'footer'].map((a) => `<label><input type="radio" name="area-${i}" data-b="area" value="${a}" ${b.area === a ? 'checked' : ''} ${b.kind === 'shop_name' ? 'disabled' : ''}>${a === 'header' ? 'سربرگ' : 'پاورقی'}</label>`).join('')}</div>
          <div class="seg seg-sm" role="radiogroup" aria-label="${label}: چینش">${ALIGN.map(([v, l]) => `<label><input type="radio" name="align-${i}" data-b="align" value="${v}" ${b.align === v ? 'checked' : ''}>${l}</label>`).join('')}</div>
          <span class="blk-move"><button type="button" class="icon-btn sm" data-move="-1" aria-label="${label}: بالاتر" ${i === 0 ? 'disabled' : ''}>▲</button><button type="button" class="icon-btn sm" data-move="1" aria-label="${label}: پایین‌تر" ${i === s.blocks.length - 1 ? 'disabled' : ''}>▼</button></span>
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

  // One-line summaries next to each section title, so collapsed sections still tell what is set.
  function summarize() {
    const val = (k, text) => { const el = form.querySelector(`[data-val="${k}"]`); if (el) el.textContent = text; };
    val('template', boot.templates[s.template_id] || '');
    const t = s.typography || {};
    val('accent', t.accent === 'custom' ? 'دلخواه' : (boot.accents[t.accent] || boot.accents.ink));
    val('blocks', `${toPersian(String(s.blocks.filter((b) => b.visible).length))} مورد نمایش`);
    val('logo', !boot.canLogo ? 'پلن پایه و حرفه‌ای' : (s.logo?.visible ? 'نمایش' : 'بدون لوگو'));
    val('cols', `${toPersian(String(s.items_table.columns.length))} ستون`);
    val('summary', s.summary.signature_box !== false ? 'با جای امضا' : 'بدون جای امضا');
    val('print', `${(s.print?.orientation || 'landscape') === 'landscape' ? 'خوابیده' : 'ایستاده'} · متن ${t.text_size === 'large' ? 'درشت' : 'معمولی'}`);
  }

  function showCustom() {
    const custom = form.accent.value === 'custom';
    form.querySelector('[data-custom-color]').hidden = !custom;
    form.querySelector('[data-hex-label]').textContent = form.accent_hex.value.toUpperCase();
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
    if (s.typography.accent_hex) form.accent_hex.value = s.typography.accent_hex;
    form.orientation.value = s.print?.orientation || 'landscape';
    form.margins.value = s.print?.margins || 'normal';
    drawBlocks(); drawCols(); showCustom(); summarize();
  }

  function read() {
    form.querySelectorAll('[data-i]').forEach((row) => {
      const b = s.blocks[Number(row.dataset.i)];
      const vis = row.querySelector('[data-b="visible"]');
      if (!vis.disabled) b.visible = vis.checked;
      b.area = row.querySelector('[data-b="area"]:checked')?.value || b.area;
      b.align = row.querySelector('[data-b="align"]:checked')?.value || b.align;
    });
    s.logo = { visible: form.logo_visible.checked, size: form.logo_size.value || 'medium' };
    s.items_table.columns = [...form.querySelectorAll('[data-col]')].filter((c) => c.checked).map((c) => c.dataset.col);
    s.summary = { show_component_breakdown: form.show_component_breakdown.checked, signature_box: form.signature_box.checked, public_note: { visible: form.note_visible.checked, text: form.note_text.value.trim() } };
    const accent = form.accent.value || 'ink';
    const typo = { ...s.typography, text_size: form.text_size.value, density: form.density.value, accent };
    if (accent === 'custom') typo.accent_hex = form.accent_hex.value.toLowerCase(); else delete typo.accent_hex;
    s.typography = typo;
    s.print = { ...(s.print || {}), orientation: form.orientation.value || 'landscape', margins: form.margins.value || 'normal' };
  }

  // ---- preview -------------------------------------------------------------------------------------
  function fit() {
    const inv = frame.querySelector('.inv');
    if (!inv || !frame.clientWidth) return; // hidden (phone, settings tab): fitted when shown
    const zoom = document.querySelector('[data-zoom]');
    if (frame.classList.contains('print')) {
      inv.style.transform = '';
      const scale = Math.min(1, (frame.clientWidth - 20) / inv.offsetWidth);
      inv.style.transform = `scale(${scale})`;
      frame.style.height = `${inv.offsetHeight * scale + 20}px`;
      if (zoom) zoom.textContent = `اندازه ${toPersian(String(Math.round(scale * 100)))}٪ · ${inv.classList.contains('po-landscape') ? 'A4 خوابیده' : 'A4 ایستاده'}`;
    } else {
      inv.style.transform = ''; frame.style.height = '';
      if (zoom) zoom.textContent = 'همان‌طور که مشتری روی گوشی می‌بیند';
    }
  }
  function show(html) {
    // Server-rendered Blade (escaped); no user HTML. Its nonce'd <style> belongs to another response and would only
    // trip the CSP here, so drop it and set a custom accent through the CSSOM instead.
    const tpl = document.createElement('template');
    tpl.innerHTML = html;
    tpl.content.querySelectorAll('style').forEach((el) => el.remove());
    frame.replaceChildren(tpl.content);
    const inv = frame.querySelector('.inv');
    if (inv && s.typography?.accent === 'custom' && inv.dataset.accent) inv.style.setProperty('--inv-accent', inv.dataset.accent);
    fit();
  }
  async function preview() {
    const mine = ++seq;
    const res = await post('/api/settings/appearance/preview', { settings: s });
    if (mine !== seq) return;
    if (!res.ok) { frame.innerHTML = `<p class="notice err">${escapeHtml(res.message)}</p>`; return; }
    show(res.data.html);
  }
  const schedule = () => { clearTimeout(timer); timer = setTimeout(preview, 250); };

  // ---- editing ---------------------------------------------------------------------------------------
  form.addEventListener('change', async (e) => {
    if (e.target.name === 'template_id') {
      // A template switch starts from that template's defaults; the server owns presets.
      const res = await post('/api/settings/appearance/preview', { settings: { template_id: e.target.value } });
      if (res.ok) { remember(); s = res.data.settings; fill(); show(res.data.html); setDirty(true); }
      return;
    }
    if (e.target.name === 'accent' || e.target.name === 'accent_hex') {
      showCustom();
      const err = form.querySelector('[data-hex-err]');
      err.textContent = '';
      if (form.accent.value === 'custom' && contrastOnWhite(form.accent_hex.value) < boot.minContrast) {
        err.textContent = 'این رنگ روی کاغذ سفید کم‌رنگ چاپ می‌شود. رنگ تیره‌تری انتخاب کنید.';
        return; // keep the last readable colour in the preview
      }
    }
    remember(); read(); summarize(); setDirty(true); schedule();
  });
  let typing = false;
  form.addEventListener('input', (e) => {
    if (e.target.name === 'accent_hex') { form.querySelector('[data-hex-label]').textContent = e.target.value.toUpperCase(); return; }
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
    if (res.ok) { remember(); s = res.data.settings; fill(); show(res.data.html); setDirty(true); }
  });
  document.querySelectorAll('[name="pv"]').forEach((r) => r.addEventListener('change', () => { frame.className = `inv-frame ${r.value}`; fit(); }));
  window.addEventListener('resize', fit);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    read();
    busy(submitBtn);
    const res = await put('/api/settings/appearance', { settings: s, version });
    busy(submitBtn, false);
    if (res.ok) { version = res.data.version; saved = structuredClone(s); setDirty(false); toast('ظاهر فاکتور ذخیره شد.'); return; }
    if (res.code === 'LAYOUT_CONFLICT') {
      // Someone saved meanwhile (another device or teammate): load the latest instead of overwriting it.
      toast(res.message, { kind: 'error', timeout: 20000, action: { label: 'بارگذاری آخرین نسخه', onClick: () => { dirty = false; location.reload(); } } });
      return;
    }
    if (res.code === 'ACCENT_TOO_LIGHT') { form.querySelector('[data-hex-err]').textContent = res.message; toast(res.message, { kind: 'error' }); return; }
    if (res.code?.startsWith('CAPABILITY_')) showQuota(res); else toast(res.message, { kind: 'error', timeout: 8000 });
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) e.preventDefault(); });

  fill();
  preview();
}
