// Friendly quota/capability sheet. The decision (title/note/actions) is a pure function in
// quota-panel.js so it can be unit-tested; this renders it and keeps the server's detailed message.
import { sheet, escapeHtml } from './ui.js';
import { quotaPanel } from './quota-panel.js';

export function showQuota(res) {
  const d = res.data || {};
  const upgrade = escapeHtml(d.upgrade_url || '/settings/plan');
  const panel = quotaPanel(res);

  const calc = panel.calc ? '<a class="btn btn-dark block" href="/calculator">محاسبه با ماشین‌حساب (بدون صدور)</a>' : '';
  sheet(`
    <h2 class="h3">${escapeHtml(panel.title)}</h2>
    <p class="small">${escapeHtml(res.message)}</p>
    <div class="notice ok">${escapeHtml(panel.note)}</div>
    ${calc}
    <a class="btn btn-gold block" href="${upgrade}">مشاهده پلن‌ها و ارتقا</a>
    <button class="btn btn-line block" type="button" data-close>فعلاً نه</button>`, { label: panel.label });
}
