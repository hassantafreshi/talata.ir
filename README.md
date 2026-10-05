# Talata.ir — Phase 1 planning

مستندات شروع ساخت سرویس SaaS ماژولار طلافروشی، با Laravel و تجربه کاربری فارسی برای صاحبان فروشگاه کم‌تجربه در استفاده از نرم‌افزار.

## فایل‌های اصلی

| فایل | کاربرد |
| --- | --- |
| [Phase 1 Master Prompt](docs/prompts/PHASE_1_MASTER_PROMPT.md) | پرامپ اجرایی کامل معماری، داده، محاسبات، امنیت، پلن‌ها و مراحل ساخت |
| [UX-first preliminary prompt](docs/prompts/UI_UX_DISCOVERY_PROMPT.md) | پرامپ اولیه تحقیق و طراحی تجربه کاربری؛ مستقل از کتابخانه |
| [Rapid UI/UX execution prompt](docs/prompts/UI_UX_RAPID_IMPLEMENTATION_PROMPT.md) | اتود کلی و تأیید رنگ/لوگو، سپس اجرای سریع UI در پروژه |
| [UI/UX tool shortlist](docs/research/UI_UX_TOOL_SHORTLIST.md) | گزینه‌های بررسی‌شده برای Claude، کتابخانه‌ها و لینک نمونه‌ها |
| [Implementation checklist](docs/PHASE_1_CHECKLIST.md) | معیارهای پذیرش و پیگیری مراحل |
| [Performance budget](docs/PERFORMANCE_BUDGET.md) | معیار حجم اولیه و آزمون سرعت روی اینترنت ضعیف |
| [Implementation guide](docs/IMPLEMENTATION_GUIDE.md) | نقطه شروع ساخت: نقشه اسناد، ماژول‌ها و جدول‌ها، API، کارهای زمان‌بندی‌شده، ترتیب ساخت، تصمیم‌های باز |
| [Payments and SMS credit](docs/PAYMENTS_AND_SMS_CREDIT.md) | خرید پلن و شارژ پیامک از درگاه بانک، صفحه بازگشت موفق/ناموفق، دفتر اعتبار پیامک |
| [Plans and quotas](docs/PLANS_AND_QUOTAS.md) | قیمت پلن‌ها، سهمیه‌ها و شارژ پیامک به تصمیم مالک (رایگان: ۵۰ فاکتور و ۵۰ مشتری جدید در ماه، فقط ماه جاری، ۵ پیامک در سال؛ شارژ پیامک تومانی) |
| [Mazneh and calculator](docs/MAZNEH_AND_CALCULATOR.md) | قرارداد «مظنه» (خرید/فروش ۱۸، ۲۴ عیار، دلار، انس) و «ماشین‌حساب طلایی» |
| [V2 roadmap](docs/ROADMAP_V2_BUSINESS_TYPES.md) | نسخه ۲: نقره‌فروشی، سکه‌فروشی، طلای آب‌شده، چند نوع کسب‌وکار (حرفه‌ای)، ضمیمه عکس محصول؛ زیرساخت فاز ۱ |
| [UI build spec](docs/design/UI_BUILD_SPEC.md) | قرارداد کامل ساخت UI مرحله B به‌همراه توکن‌ها، قالب‌های فاکتور (schema + دو preset) و قراردادهای adapter |
| [Stage A design package](docs/design/README.md) | اتود پیشنهادی مرحله A: وایرفریم، اتود موبایل/دسکتاپ، پالت، لوگو، فونت و toolkit؛ در انتظار تأیید مالک (`docs/design/STAGE_A_REVIEW_REQUEST.md`) |

## نحوه استفاده با Claude Code

پرامپ اصلی انگلیسی است تا قراردادهای فنی دقیق باشند؛ تمام متن‌های محصول باید فارسی و RTL باشند.

```text
Read CLAUDE.md, docs/prompts/PHASE_1_MASTER_PROMPT.md,
docs/prompts/UI_UX_DISCOVERY_PROMPT.md and docs/PHASE_1_CHECKLIST.md.
Start with repository inspection, an implementation plan and foundational backend work.
Respect the pending UI selection. Do not install a UI library or design skill yet.
Record assumptions and progress in docs; implement in small verified milestones.
```

تصمیم کتابخانه UI، ابزار طراحی و جهت بصری هنوز گرفته نشده است. بسته اتود مرحله A (پیشنهادی) در `docs/design/` آماده و در انتظار تأیید مالک است؛ پس از تأیید، مرحله B طبق پرامپ اجرای سریع شروع می‌شود. این ریپو در این مرحله حاوی مستندات است؛ اپلیکیشن هنوز پیاده‌سازی نشده است.

برای شروع مرحله طراحی با Claude Code:

```text
Read CLAUDE.md and docs/prompts/UI_UX_RAPID_IMPLEMENTATION_PROMPT.md.
Execute Stage A first: present the overall wireframe and visual proposal,
including colors, logo/wordmark, Persian typography and proposed toolkit.
Wait for my explicit approval of that package before detailed production UI.
After approval, implement Stage B efficiently and verify the existing requirements.
```

## تصمیم‌های پایه

- مدل واقعی چندفروشگاهی، جداسازی اطلاعات هر فروشگاه و کنترل دسترسی سمت سرور.
- ورود و ثبت‌نام با شماره موبایل و کد یک‌بارمصرف پیامکی، بدون الزام به ایمیل یا رمز عبور؛ پیامک ورود مستقل از سهمیه ارسال فاکتور است.
- پس از اولین ورود تأییدشده با موبایل، فعال‌سازی اختیاری ورود با اثر انگشت، تشخیص چهره یا قفل دستگاه از طریق Passkey؛ ورود پیامکی برای بازیابی باقی می‌ماند.
- محاسبات دقیق و نسخه‌دار؛ ذخیره مستقل اصل طلا، اجرت، سود، حق‌العمل، تخفیف و مالیات.
- فاکتور قطعی با تصویر ثابت نرخ، اطلاعات فروشگاه و خروجی محاسبات.
- ثبت نام فروشگاه، موبایل و آدرس پیش از صدور؛ تلفن ثابت در صورت داشتن و نمایش موبایل در نبود آن. وب‌سایت، شبکه‌های اجتماعی، شماره مجوزها و لوگو اختیاری هستند.
- دو قالب آماده و [سفارشی‌سازی آسان فاکتور](docs/INVOICE_CUSTOMIZATION.md) برای **پایه و حرفه‌ای**: پیش‌نمایش زنده، چیدمان اطلاعات و لوگو در هدر/فوتر و کنترل‌های ساده ظاهر؛ رایگان با چیدمان ثابت.
- QR Code در بالای سمت چپ فاکتور و چاپ/PDF، لینک امن بررسی نسخه ثبت‌شده و وضعیت ابطال/جایگزینی؛ نمای فاکتور خوانا در موبایل. قرارداد: [نمایش، بررسی و ارسال فاکتور](docs/INVOICE_DELIVERY_AND_VERIFICATION.md).
- شماره موبایل مشتری در مرحله صدور و اقدام «صدور و ارسال پیامکی» برای ارسال لینک همان فاکتور؛ مسیر «فقط صدور» و وضعیت مستقل ارسال برای بازیابی خطا.
- تازه‌شدن مرکزی نرخ طلا هر سه دقیقه؛ نمایش بزرگ نرخ ۱۸ عیار در شروع فاکتور با دکمه «شروع» زیر آن و ثبت نرخ پذیرفته‌شده برای معامله.
- فاکتور چندردیفی با انتخاب «طلا/متفرقه» برای هر ردیف؛ عیار طلا پیش‌فرض ۱۸ و قابل تغییر با نرخ متناسب، قیمت دستی متفرقه و نام/توضیح مستقل هر کالا.
- ماشین‌حساب مستقل؛ حسابداری، انبار، آب‌شده و اتصال مؤدیان در فازهای بعد.
- نرخ مالیات تنظیم‌پذیر بر اساس تاریخ اثرگذاری؛ پیش‌فرض نمونه ۱۰٪ روی خدمات مشمول، با ثبت منبع تأیید برای استفاده عملیاتی.
- PWA و جابه‌جایی بدون بارگذاری کامل؛ نرخ آفلاین با برچسب صریح آخرین به‌روزرسانی.
- سرعت روی اینترنت ضعیف اولویت اصلی است؛ بارگذاری بخش‌ها هنگام نیاز، فونت/دارایی‌های اصلی مستقل از CDN خارجی و سنجش حجم واقعی خروجی الزامی است.

Prepared: 2026-10-05.
