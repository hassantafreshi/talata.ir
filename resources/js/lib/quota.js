// Friendly quota/capability sheet built from the server's structured error.
import { sheet, escapeHtml } from './ui.js';

export function showQuota(res) {
  const d = res.data || {};
  const upgrade = d.upgrade_url || '/settings/plan';
  sheet(`
    <h2>${escapeHtml(res.message)}</h2>
    <div class="notice ok">مظنه، ماشین‌حساب طلایی و چاپ فاکتورهای صادرشده همیشه آزاد است.</div>
    <a class="btn btn-dark block" href="/calculator">محاسبه با ماشین‌حساب (بدون صدور)</a>
    <a class="btn btn-gold block" href="${escapeHtml(upgrade)}">مشاهده پلن‌ها و ارتقا</a>
    <button class="btn btn-line block" type="button" data-close>فعلاً نه</button>`, { label: 'سقف سهمیه' });
}
