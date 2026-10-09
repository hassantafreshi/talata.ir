// Pure decision for the quota/capability sheet: given the server's structured error, choose the
// title, reassurance, which actions to offer and the dialog label. No DOM here, so it is unit-testable;
// quota.js turns this into HTML. The server still owns the detailed message (res.message).
const FREE = 'مظنه، ماشین‌حساب طلایی و چاپ فاکتورهای صادرشده همیشه آزاد است.';

export function quotaPanel(res) {
  const d = (res && res.data) || {};
  switch (d.resource) {
    case 'invoices_per_month':
      return {
        title: 'سقف فاکتور این ماه پر شد',
        note: 'چاپ و اشتراک فاکتورهای قبلی، مظنه و ماشین‌حساب همچنان باز است.',
        label: 'سقف فاکتور',
        calc: true, // the calculator is the useful "do it now, free" path
      };
    case 'new_customers_per_month':
      return {
        title: 'سقف مشتری جدید این ماه پر شد',
        note: 'می‌توانید بدون ذخیرهٔ مشتری جدید فاکتور صادر کنید؛ مشتریان قبلی در دسترس‌اند.',
        label: 'سقف مشتری جدید',
        calc: false,
      };
    case 'links_per_month':
      return {
        title: 'سقف لینک و پیامک فاکتور این ماه پر شد',
        note: 'صدور و چاپ فاکتور باز است؛ فقط ارسال لینک یا پیامک این ماه به سقف رسیده.',
        label: 'سقف لینک فاکتور',
        calc: false,
      };
    default:
      // Any capability limit (installments, financial reports, full history, …).
      return { title: 'این امکان در پلن فعلی نیست', note: FREE, label: 'محدودیت پلن', calc: false };
  }
}
