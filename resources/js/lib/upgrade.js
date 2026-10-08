// «ارتقا» sheet: shown when someone taps a feature their plan does not include (x-locked, or a server
// CAPABILITY_* answer). Says what the feature does in plain words, which plans have it and from what price.
import { sheet, escapeHtml } from './ui.js';

export const FEATURES = {
  'invoice.customize': ['ویرایش ظاهر فاکتور', ['انتخاب قالب و رنگ فاکتور', 'جای نام، لوگو و اطلاعات تماس در سربرگ و پاورقی', 'پیش‌نمایش زنده چاپ و موبایل']],
  'invoice.shop_logo': ['لوگوی فروشگاه روی فاکتور', ['لوگو روی فاکتور چاپی و لینک مشتری', 'پیش‌نمایش لینک در پیام‌رسان‌ها با لوگو و نام فروشگاه']],
  'invoice.hide_provider_brand': ['فاکتور بدون نام زرلیو', ['پای فاکتور فقط به نام فروشگاه شما']],
  'sms.template_edit': ['متن دلخواه پیامک فاکتور', ['نوشتن متن پیامک به سلیقه خودتان', 'هزینه کمتر برای هر پیامک']],
  'reports.financial': ['گزارش مالی کامل', ['سود و مالیات در داشبورد فروش', 'بازه‌های طولانی‌تر: ماه‌ها و سال']],
  'history.all': ['همه فاکتورهای ماه‌های قبل', ['دیدن و جستجوی فاکتورهای همه ماه‌ها', 'چاپ دوباره فاکتورهای قدیمی']],
  'installments.manage': ['فروش اقساطی', ['قرارداد اقساط برای هر فاکتور', 'ثبت پرداخت قسط‌ها و مانده', 'یادآوری پیامکی قسط به مشتری']],
  'installments.sms_remind': ['یادآوری پیامکی قسط', ['پیامک خودکار پیش از سررسید و پس از تأخیر']],
  'settings.backup': ['پشتیبان تنظیمات', ['۵۰ نسخه آخر تنظیمات', 'بازگرداندن هر بخش با یک لمس']],
  'team.permissions_edit': ['تعیین دسترسی همکاران', ['هر همکار فقط صفحه‌هایی را ببیند که اجازه دارد']],
  'proforma.configure': ['صدور دستی پس از تأیید پیش‌فاکتور', ['تأیید مشتری، سپس صدور فاکتور توسط شما (مثلاً پس از دریافت وجه)']],
};

export function upgradeContent(cap, { plans = '', price = '', message = '' } = {}) {
  const [name, points] = FEATURES[cap] || ['این امکان', []];
  return {
    title: `«${name}» در پلن ${plans || 'بالاتر'} است`,
    points,
    price: price ? `از ${price} تومان در ماه` : '',
    message,
  };
}

export function showUpgrade(cap, opts = {}) {
  const c = upgradeContent(cap, opts);
  const list = c.points.length ? `<ul class="up-points">${c.points.map((p) => `<li>${escapeHtml(p)}</li>`).join('')}</ul>` : '';
  sheet(`
    <div class="up-head"><span class="up-ico" aria-hidden="true">🔒</span><h2 class="h3">${escapeHtml(c.title)}</h2></div>
    ${c.message ? `<p class="small muted">${escapeHtml(c.message)}</p>` : ''}
    ${list}
    ${c.price ? `<p class="up-price">${escapeHtml(c.price)}</p>` : ''}
    <a class="btn btn-gold block lg" href="/settings/plan?feature=${encodeURIComponent(cap)}">مشاهده پلن‌ها و ارتقا</a>
    <button class="btn btn-line block" type="button" data-close>فعلاً نه</button>
    <p class="xs muted center">مظنه، ماشین‌حساب، صدور و چاپ فاکتور در همه پلن‌ها آزاد است.</p>`, { label: 'ارتقای پلن' });
}
