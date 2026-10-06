# Talata.ir — طلاتا

مستندات شروع ساخت سرویس SaaS ماژولار طلافروشی، با Laravel و تجربه کاربری فارسی برای صاحبان فروشگاه کم‌تجربه در استفاده از نرم‌افزار.

## اجرای وب‌اپ فروشنده (فاز ۱)

وب‌اپ کامل فروشنده با Laravel 13 و PostgreSQL در همین مخزن است: ورود با کد پیامکی، فاکتور (اقلام طلا/متفرقه، مرور، صدور و ارسال پیامکی، چاپ A4 با QR بالا-چپ، لینک مشتری، ابطال/جایگزین)، مظنه، ماشین‌حساب طلایی، مشتریان و اقساط (تقویم شمسی)، تنظیمات کسب‌وکار/ظاهر فاکتور/متن پیامک/کاربران، خرید پلن و اعتبار پیامک با درگاه آزمایشی. معماری و امنیت: [`docs/SECURITY.md`](docs/SECURITY.md)، [`docs/adr/0001-blade-ajax-frontend.md`](docs/adr/0001-blade-ajax-frontend.md).

پیش‌نیاز: PHP 8.3 با `pdo_pgsql`، `gd`، `intl`؛ PostgreSQL 16؛ Node 22.

```bash
composer install
cp .env.example .env && php artisan key:generate
# DB_* را در .env تنظیم کنید (pgsql)
php artisan migrate --seed          # نسخه قیمت، قاعده مالیات نمونه، اولین مظنه نمونه
npm ci && npm run build
php artisan serve
php artisan queue:work --queue=otp,default   # صف otp جدا و با اولویت؛ بدون آن کد ورود ارسال نمی‌شود
php artisan schedule:work                    # مظنه هر ۱۸۰ ثانیه، بررسی پرداخت، انقضای اعتبار، یادآوری اقساط
```

مدیر سامانه (کنسول `/admin`: داشبورد، لاگ فعالیت هر کاربر و هر سرویس، لاگ فنی):

```bash
php artisan talata:staff 09120000000 "نام مدیر" --role=admin   # یا --role=support (بدون لاگ فنی)؛ --deactivate برای غیرفعال‌کردن
```

پیامک با **کاوه‌نگار**: `TALATA_SMS_DRIVER=kavenegar`، `KAVENEGAR_API_KEY`، `KAVENEGAR_SENDER` و برای کد ورود یک الگوی Verify Lookup با `%token` در پنل کاوه‌نگار بسازید و نامش را در `KAVENEGAR_OTP_TEMPLATE` بگذارید. ورود با اثر انگشت به HTTPS و دامنه ثابت نیاز دارد (`TALATA_WEBAUTHN_RP_ID=talata.ir`).

در حالت توسعه پیامک‌ها در `storage/logs/laravel.log` نوشته می‌شوند (`TALATA_SMS_DRIVER=log`)، مظنه نمونه و برچسب‌دار است (`TALATA_QUOTE_DRIVER=demo`) و پرداخت آزمایشی است (`TALATA_PAYMENT_DRIVER=mock`).

آزمون‌ها (روی پایگاه `talata_test` در PostgreSQL):

```bash
php artisan test        # دامنه، جریان فاکتور، ضدسوءاستفاده پیامک/OTP، جداسازی tenant، پرداخت، صفحات
npm run test:js         # برابری محاسبه مرورگر و سرور با vectors مشترک
```

پیش از production:
- `APP_ENV=production`، `APP_DEBUG=false`، HTTPS و `TALATA_PUBLIC_URL` دامنه نهایی.
- **از `APP_KEY` نسخه پشتیبان بگیرید**: توکن خام QR فاکتورها با آن رمز شده است. برای چرخش کلید از `APP_PREVIOUS_KEYS` استفاده کنید؛ وگرنه چاپ دوباره QR فاکتورهای قدیمی ممکن نیست (بررسی اصالت چاپ‌های قبلی کار می‌کند).
- آداپتر پیامک و درگاه واقعی را پس از انتخاب مالک اضافه کنید؛ درگاه آزمایشی در production رد می‌شود مگر `TALATA_ALLOW_MOCK_PAYMENTS_IN_PRODUCTION=true`.
- `TALATA_OTP_DAILY_BUDGET` (شماره‌های جدید) و `TALATA_OTP_EXISTING_DAILY_BUDGET` (کاربران موجود) را با حجم واقعی تنظیم و هشدار آن را در لاگ فنی پایش کنید.
- `LOG_STACK=daily,errors_db` تا خطاها در کنسول مدیر دیده شوند؛ `TALATA_ADMIN_ALLOWED_IPS` برای محدودکردن کنسول به IP دفتر.
- worker صف با supervisor و cron برای `php artisan schedule:run` هر دقیقه.

## فایل‌های اصلی

| فایل | کاربرد |
| --- | --- |
| [Phase 1 Master Prompt](docs/prompts/PHASE_1_MASTER_PROMPT.md) | پرامپ اجرایی کامل معماری، داده، محاسبات، امنیت، پلن‌ها و مراحل ساخت |
| [UX-first preliminary prompt](docs/prompts/UI_UX_DISCOVERY_PROMPT.md) | پرامپ اولیه تحقیق و طراحی تجربه کاربری؛ مستقل از کتابخانه |
| [Rapid UI/UX execution prompt](docs/prompts/UI_UX_RAPID_IMPLEMENTATION_PROMPT.md) | اتود کلی و تأیید رنگ/لوگو، سپس اجرای سریع UI در پروژه |
| [UI/UX tool shortlist](docs/research/UI_UX_TOOL_SHORTLIST.md) | گزینه‌های بررسی‌شده برای Claude، کتابخانه‌ها و لینک نمونه‌ها |
| [Implementation checklist](docs/PHASE_1_CHECKLIST.md) | معیارهای پذیرش و پیگیری مراحل |
| [Performance budget](docs/PERFORMANCE_BUDGET.md) | معیار حجم اولیه و آزمون سرعت روی اینترنت ضعیف |
| [ChatGPT build package](docs/handoff/README.md) | بسته تحویل برای ساخت سریع با ChatGPT: دستور ثابت، ۲۳ کارت کار، مشخصات همه صفحات فروشگاه، عمومی، پرداخت و مدیریت با حالت‌ها، نمونه API، چک‌لیست؛ مرجع HTML صفحات در `docs/design/reference-html/` |
| [Implementation guide](docs/IMPLEMENTATION_GUIDE.md) | نقطه شروع ساخت: نقشه اسناد، ماژول‌ها و جدول‌ها، API، کارهای زمان‌بندی‌شده، ترتیب ساخت، تصمیم‌های باز |
| [Payments and SMS credit](docs/PAYMENTS_AND_SMS_CREDIT.md) | خرید پلن و شارژ پیامک از درگاه بانک، صفحه بازگشت موفق/ناموفق، دفتر اعتبار پیامک |
| [Plans and quotas](docs/PLANS_AND_QUOTAS.md) | قیمت پلن‌ها، سهمیه‌ها و شارژ پیامک به تصمیم مالک (رایگان: ۵۰ فاکتور و ۵۰ مشتری جدید در ماه، فقط ماه جاری، ۵ پیامک در سال؛ شارژ پیامک تومانی) |
| [Mazneh and calculator](docs/MAZNEH_AND_CALCULATOR.md) | قرارداد «مظنه» (خرید/فروش ۱۸، ۲۴ عیار، دلار، انس) و «ماشین‌حساب طلایی» |
| [Settings backups](docs/SETTINGS_BACKUPS.md) | ۵۰ نسخه آخر تنظیمات هر فروشگاه (پایه و حرفه‌ای)، بازگرداندن بخش‌به‌بخش توسط مالک یا مدیر سامانه |
| [Invoice numbering](docs/INVOICE_NUMBERING.md) | شماره‌گذاری فاکتور: سال-شماره، پیوسته (ادامه دفترچه کاغذی)، سال/ماه/شماره، پیشوند شعبه |
| [Admin pricing](docs/ADMIN_PRICING.md) | تغییر قیمت پلن پایه/حرفه‌ای و قیمت هر بخش پیامک هر پلن از پنل مدیریت؛ هر تغییر یک نسخه قیمت جدید |
| [Team permissions](docs/TEAM_PERMISSIONS.md) | دسترسی همکاران: مظنه، ماشین‌حساب، فاکتور جدید، فاکتورها، مشتریان، داشبورد… با نقش‌های آماده |
| [Gold received & dashboard](docs/GOLD_RECEIVED_AND_DASHBOARD.md) | طلای دریافتی از مشتری به‌جای پول (کهنه، سکه، آب‌شده) با وزن ۷۵۰ و تفکیک طلایی/مبلغ به سبک فاکتور بازار؛ داشبورد فروش با نمودار ساده |
| [Affiliate program](docs/AFFILIATE_PROGRAM.md) | همکاری در فروش: کد تخفیف و لینک معرفی، کمیسیون درصدی (پرداخت اول یا مادام‌العمر)، پنل همکار با موبایل ماسک‌شده، مدیریت در پنل ادمین |
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
