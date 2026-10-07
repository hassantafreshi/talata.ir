# پرداخت آنلاین، خرید پلن و شارژ پیامک — قرارداد ساخت

وضعیت: **آماده برای پیاده‌سازی** بر پایه تصمیم‌های مالک (2026-10-05): خرید پلن پایه/حرفه‌ای و شارژ پیامک از درگاه بانک با صفحه بازگشت موفق/ناموفق. قیمت‌ها و سهمیه‌ها از `PLANS_AND_QUOTAS.md` و پیکربندی `design/contracts/plans-pricing.json` خوانده می‌شوند. **درگاه پرداخت (PSP) هنوز انتخاب نشده** و فقط پشت adapter ساخته می‌شود. اتود: تابلوهای `SmsBuy` / `DesktopSmsBuy`، `PayReturnSms` / `DesktopPayReturnSms`، `PayReturnPlan` / `DesktopPayReturnPlan` و ورودی‌های آن‌ها در `Plans`، `Settings`، `Review`، `Issued` و `QuotaLimit`.

این سند جایگزین بند «No payment gateway is assumed» در `prompts/PHASE_1_MASTER_PROMPT.md` §11 است. فعال‌سازی دستی provider همچنان به‌عنوان مسیر پشتیبان و اصلاح می‌ماند.

## ۱. دامنه

| محصول | چه چیزی خریده می‌شود | چه کسی | نتیجه پس از تأیید بانک |
| --- | --- | --- | --- |
| `PLAN` | پلن پایه یا حرفه‌ای، ماهانه یا سالانه | مالک فروشگاه (مجوز `billing.manage`) | اشتراک جدید فعال و قابلیت‌ها/سهمیه‌ها از همان لحظه به‌روز |
| `SMS_CREDIT` | اعتبار تومانی پیامک (بسته ثابت) | مالک یا کاربر با `billing.manage` | ورودی اعتبار در دفتر پیامک (lot) |

خارج از دامنه فاز ۱: پرداخت خودکار تکراری، کیف پول عمومی، کد تخفیف، فاکتور رسمی مالیاتی خود زرلیو برای اشتراک (تصمیم provider)، بازپرداخت از داخل برنامه (فقط از پنل provider با ثبت).

## ۲. قیمت‌ها و قواعد (از پیکربندی، نه کد)

- پلن: `plans[].price_toman.{monthly,yearly}`؛ مبلغ در لحظه ساخت سفارش در سفارش snapshot می‌شود و تغییر بعدی قیمت روی سفارش باز اثر ندارد.
- شارژ پیامک: مبالغ مجاز `sms_credit.pack_amounts_toman` (۱۰۰/۲۰۰/۳۰۰/۵۰۰ هزار، ۱ میلیون)؛ حداقل به ازای پلن `sms_credit.min_purchase_toman` (رایگان ۴۰۰ هزار، پایه و حرفه‌ای ۱۰۰ هزار)؛ در رایگان فقط مبالغ ≥ حداقل نمایش داده می‌شوند. مبلغ آزاد پذیرفته نمی‌شود.
- قیمت هر بخش در **لحظه ارسال** از پلن فعلی tenant خوانده می‌شود: رایگان ۸۵۰، پایه ۵۰۰، حرفه‌ای ۳۵۰ تومان.
- مانده پایه/حرفه‌ای به ماه بعد منتقل می‌شود؛ مانده خریدشده در رایگان پایان همان ماه تقویمی (منطقه زمانی tenant) منقضی می‌شود.
- **مالیات بر ارزش افزوده ۱۰٪** (تصمیم مالک): قیمت پلن‌ها و بسته‌ها بدون مالیات است؛ `vat_irr = round_half_up(subtotal_irr × tax.vat_rate_percent / 100)` و `amount_irr = subtotal_irr + vat_irr` همان مبلغی است که به درگاه فرستاده و verify می‌شود. نرخ مالیات در سفارش snapshot می‌شود. اعتبار پیامک = `subtotal_irr` (مالیات جزو اعتبار نیست). نمونه: بسته ۲۰۰ هزار → پرداخت ۲۲۰ هزار؛ حرفه‌ای سالانه ۱۰٫۹ میلیون → ۱۱٫۹۹ میلیون.
- سرور تنها مرجع مبلغ است؛ کلاینت فقط `product`، `plan_id`/`period` یا `pack_amount` انتخابی را می‌فرستد.

## ۳. مدل داده (حداقل)

| جدول | فیلدهای کلیدی | قیدها |
| --- | --- | --- |
| `billing_orders` | `id` (ULID)، `public_ref` (مثل `TL-SMS-1405-0021`)، `tenant_id`، `created_by`، `product` (`PLAN`/`SMS_CREDIT`)، `plan_id`، `period`، `pack_amount_irr`، `subtotal_irr`، `vat_rate_percent`، `vat_irr`، `amount_irr` (= subtotal + vat، NUMERIC(24,0))، `price_snapshot` (JSON: نسخه پیکربندی، قیمت بخش، حداقل)، `status`، `idempotency_key`، `expires_at`، `fulfilled_at` | unique(`tenant_id`, `idempotency_key`)؛ unique(`public_ref`)؛ `amount_irr > 0` |
| `payment_attempts` | `id`، `order_id`، `gateway` (کد adapter)، `authority` (شناسه درگاه)، `amount_irr`، `status`، `ref_id` (شماره پیگیری بانک)، `card_mask` (فقط اگر درگاه بدهد، ماسک‌شده)، `bank_code`، `raw_result_redacted` (JSON)، `callback_at`، `verified_at` | unique(`gateway`, `authority`)؛ یک attempt `VERIFYING` در هر لحظه برای هر سفارش |
| `subscriptions` (موجود) | + `source_order_id`، `starts_at`، `ends_at`، `carry_over_days`، `activated_by` (`PAYMENT`/`PROVIDER`) | هیچ همپوشانی فعال برای یک tenant |
| `sms_credit_lots` | `id`، `tenant_id`، `source` (`PURCHASE`/`FREE_YEARLY`/`PROVIDER_ADJUST`)، `source_order_id`، `amount_irr`، `remaining_irr`، `carries_over` (bool)، `expires_at` (nullable)، `plan_at_purchase` | `remaining_irr` بین ۰ و `amount_irr` |
| `sms_credit_entries` (append-only) | `id`، `tenant_id`، `lot_id`، `type` (`CREDIT`/`RESERVE`/`CAPTURE`/`RELEASE`/`EXPIRE`/`ADJUST`)، `amount_irr`، `message_id`، `segments`، `per_segment_irr`، `actor`، `created_at` | بدون update/delete؛ مانده = مجموع |
| `audit_events` (موجود) | رویدادهای `billing.*` و `sms_credit.*` | — |

`public_ref` برای پشتیبانی و نمایش است؛ در URL بازگشت از شناسه غیرقابل‌حدس (`id` ULID + امضای کوتاه) استفاده می‌شود.

## ۴. ماشین وضعیت

سفارش (`billing_orders.status`):

```
CREATED ──redirect──▶ AWAITING_PAYMENT ──callback──▶ VERIFYING ──ok──▶ PAID ──fulfill (same tx)──▶ FULFILLED
                             │                           │
                             │ timeout (expires_at)      ├─ gateway says failed/cancelled ─▶ FAILED
                             ▼                           └─ verify timeout/unknown ─▶ PENDING_VERIFICATION ──reconcile──▶ PAID/FAILED
                          EXPIRED
```

- `FULFILLED` و `FAILED` و `EXPIRED` نهایی‌اند. سفارش `FAILED` دوباره پرداخت نمی‌شود؛ «تلاش دوباره» سفارش جدید با `idempotency_key` جدید می‌سازد.
- `PENDING_VERIFICATION` همان «حالت سوم — در حال بررسی» تابلوهاست.
- اگر بانک پول را کم کرد ولی verify ناموفق ماند، طبق رفتار معمول درگاه‌های شاپرکی برگشت خودکار انجام می‌شود (مهلت را adapter و پیکربندی تعیین می‌کند؛ متن UI «تا ۷۲ ساعت» پیش‌فرض قابل تنظیم است، نه واقعیت کشف‌شده).

## ۵. جریان‌ها

### ۵.۱ شروع پرداخت

1. `POST /api/billing/orders` با `{product, plan_id?, period?, pack_amount_toman?, idempotency_key}`.
2. سرور: مجوز `billing.manage`، اعتبار انتخاب در برابر پیکربندی و پلن فعلی (حداقل رایگان، کاهش پلن پرداختی نیست)، محاسبه مبلغ، ساخت سفارش `CREATED` و یک attempt؛ درخواست `request()` به adapter با `callback_url` ثابت و مبلغ ریالی.
3. پاسخ `{order_id, redirect_url, method: 'GET'|'POST', fields?}`؛ کلاینت با فرم POST یا redirect به درگاه می‌رود. سفارش `AWAITING_PAYMENT`، `expires_at` = اکنون + ۲۰ دقیقه (تنظیم).
4. دوبار کلیک یا retry شبکه با همان `idempotency_key` همان سفارش را برمی‌گرداند.

### ۵.۲ callback بانک

`GET|POST /pay/callback/{gateway}` (بدون CSRF، با rate limit، بدون نیاز به session):

1. پیدا کردن attempt با `(gateway, authority)`؛ ناموجود → لاگ امن و redirect به صفحه نتیجه عمومی «سفارش پیدا نشد».
2. قفل ردیف سفارش (`SELECT … FOR UPDATE`)؛ اگر نهایی است، فقط redirect به نتیجه (idempotent؛ بازکردن دوباره یا callback تکراری هیچ اثری ندارد).
3. اگر پارامتر درگاه «لغو/ناموفق» است → `FAILED` با `bank_code`.
4. در غیر این صورت `VERIFYING` و فراخوانی `verify(authority, amount_irr)` با **مبلغ از پایگاه داده**. فقط پاسخ موفق با مبلغ برابر = `PAID`. عدم تطابق مبلغ → `FAILED` + هشدار امنیتی provider.
5. در همان تراکنش `PAID` → اجرای fulfillment (§۵.۳) → `FULFILLED`؛ رویداد outbox برای پیامک/اعلان تأیید (از بودجه عملیاتی، نه اعتبار tenant).
6. timeout/خطای شبکه در verify → `PENDING_VERIFICATION` و زمان‌بندی reconcile.
7. redirect به `/pay/result/{order_id}?s={sig}`.

### ۵.۳ fulfillment

- `PLAN`: پایان اشتراک فعلی همین لحظه، شروع اشتراک جدید؛ باقی‌مانده پلن قبلی **بر اساس ارزش** تبدیل می‌شود (`PRORATE_BY_VALUE_V1`، پیاده‌شده 2026-10-06): روزهای باقی‌مانده × قیمت روزانه پلن فعلی ÷ قیمت روزانه پلن جدید (قیمت فهرست بدون مالیات، نسخه قیمت فعلی؛ ماهانه ۳۰ و سالانه ۳۶۵ روز)، گرد به پایین، به `carry_over_days` و انتهای دوره جدید اضافه می‌شود. تبدیل روز به روز (پیاده‌سازی قبلی) اجازه می‌داد پایه سالانه به ماه‌ها حرفه‌ای تبدیل شود. سیاست نهایی همچنان با مالک است. کش قابلیت‌ها/سهمیه‌ها باطل می‌شود. تمدید همان پلن: شروع از پایان دوره فعلی.
- `SMS_CREDIT`: یک `sms_credit_lot` به مبلغ `subtotal_irr` (بدون مالیات) با `carries_over` و `expires_at` بر اساس پلن **در لحظه خرید** (رایگان: پایان ماه تقویمی؛ پایه/حرفه‌ای: بدون انقضا) + ورودی `CREDIT`.
- هر دو با audit (`actor = PAYMENT`, `order_id`, `ref_id`).

### ۵.۴ صفحه نتیجه `/pay/result/{order_id}`

- server-rendered یا داده از `GET /api/billing/orders/{id}`؛ **وضعیت فقط از سرور**، هرگز از query string درگاه.
- `FULFILLED` → موفق: قیمت پایه، مالیات ۱۰٪، مبلغ پرداخت‌شده، نتیجه (پلن و تاریخ پایان / مانده جدید ≈ بخش)، شماره پیگیری بانک، شماره مرجع، زمان، «ادامه» به مقصد ذخیره‌شده (`return_to`: مرور فاکتور، مشتریان، تنظیمات)، «رسید پرداخت».
- `FAILED`/`EXPIRED` → ناموفق: «مبلغی کم نشده؛ اگر کم شده بانک برمی‌گرداند»، علت قابل فهم (نگاشت `bank_code` به متن فارسی)، شماره مرجع، وضعیت بدون تغییر، «تلاش دوباره»، مسیر جایگزین (مثلاً «فقط صدور بدون پیامک»)، لینک پشتیبانی.
- `PENDING_VERIFICATION`/`VERIFYING` → در حال بررسی: poll هر ۵ ثانیه تا ۲ دقیقه، سپس پیام «نتیجه را پیامک می‌کنیم؛ دوباره پرداخت نکنید».
- اگر کاربر login نیست (session منقضی در درگاه)، صفحه فقط وضعیت کلی و شماره مرجع را نشان می‌دهد و پس از ورود جزئیات کامل.
- `return_to` فقط از فهرست سفید مسیرهای داخلی؛ هیچ open redirect.

### ۵.۵ reconcile و انقضا (scheduler)

- هر ۱ دقیقه: attemptهای `PENDING_VERIFICATION` با backoff تا ۲۴ ساعت `inquire()/verify()` می‌شوند؛ نتیجه نهایی همان fulfillment idempotent را اجرا می‌کند و پیامک نتیجه می‌فرستد.
- هر ۵ دقیقه: `AWAITING_PAYMENT` گذشته از `expires_at` → `EXPIRED`.
- پایان هر ماه تقویمی (tenant timezone): lotهای `carries_over = false` با `remaining_irr > 0` → ورودی `EXPIRE`؛ ۳ روز قبل، بنر `quota.sms_balance_expiring`.
- داشبورد provider: سفارش‌های در انتظار، ناموفق‌های پرتکرار، عدم تطابق مبلغ، سلامت درگاه.

### ۵.۶ کارهای مالی در کنسول مدیریت (`/admin/payments`، `/admin/tenants/{id}`) — پیاده‌شده 2026-10-06

- **استعلام دوباره از بانک** (`BillingService::inquire`): از همان درگاهی که پرداخت را باز کرد می‌پرسد. سفارش باز طبق پاسخ پیش می‌رود؛ سفارش FAILED/EXPIRED فقط وقتی اعمال می‌شود که بانک پرداخت را با همان مبلغ ذخیره‌شده تأیید کند؛ سفارش انجام‌شده تغییر نمی‌کند.
- **ثبت تأیید دستی** (`manualConfirm`، اجازه `payments.manage`): وقتی واریز در صورت‌حساب بانک دیده شده؛ شماره پیگیری بانک + دلیل؛ همان fulfillment یک‌باره؛ سفارش انجام‌شده دوباره تأیید نمی‌شود.
- **علامت ناموفق** (`markFailed`): فقط سفارش باز؛ کد `MANUAL_FAILED`. اگر بعداً بانک پرداخت واقعی را تأیید کند، callback یا استعلام سفارش را دوباره اعمال می‌کند.
- **فعال‌سازی دستی پلن** (`manualActivation`، اجازه `tenants.manage`): برای کارت‌به‌کارت/کارت‌خوان. سفارش با `channel = MANUAL` و مبلغ دریافتی (با مالیات) که به پایه + مالیات تفکیک می‌شود؛ صفر = رایگان. همان fulfillment (اشتراک با `activated_by = PROVIDER`، تمدید یا جابه‌جایی روزهای باقی‌مانده، کمیسیون همکار روی پایه واقعی).
- **تغییر اعتبار پیامک** (`SmsCredit::adjust`): افزایش = lot با منبع `PROVIDER_ADJUST` (انقضای پایان ماه مگر «منتقل شود»)، کاهش = از نزدیک‌ترین انقضا و هرگز زیر صفر؛ ورودی `ADJUST` در دفتر و رویداد `sms_credit.adjusted`.
- همه با دلیل، تأیید دوباره، ورود تازه، کلید idempotency (`admin_actions`) و لاگ فعالیت با نام کارمند. خروجی ماهانه مالی CSV (تاریخ، شماره سفارش، فروشگاه، محصول، پایه، مالیات، جمع، وضعیت، روش، شماره پیگیری) با اجازه `payments.export`.

## ۶. مصرف اعتبار پیامک

1. پیش‌نمایش پیامک: `segments` (Unicode/URL-aware) × `per_segment_irr` پلن فعلی؛ نمایش «از ۵ پیامک رایگان امسال» اگر مانده دارد.
2. ارسال از outbox: اول سهمیه رایگان سالانه (به ازای پیامک، هر بخش یک واحد)، سپس `RESERVE` از lotها به ترتیب نزدیک‌ترین `expires_at`، سپس بی‌انقضاها.
3. تحویل/ارسال موفق ← `CAPTURE`؛ شکست قطعی ← `RELEASE`؛ نامشخص ← رزرو می‌ماند تا reconcile.
4. اعتبار ناکافی: صدور فاکتور ادامه می‌یابد؛ ارسال در وضعیت «منتظر اعتبار» و پیام `quota.sms_balance_low`/«اعتبار پیامک تمام شد» با «خرید پیامک» و «فقط صدور». پس از شارژ موفق با `return_to` به فاکتور، «ارسال دوباره» یک‌کلیک است (نه ارسال خودکار بدون تأیید).
5. OTP و پیامک‌های تأیید پرداخت از بودجه عملیاتی زرلیو؛ یادآوری اقساط حرفه‌ای از همین اعتبار.
6. تغییر پلن: lotها مانده و انقضای خودشان را حفظ می‌کنند؛ قیمت بخش از پلن جدید (فرض در انتظار تأیید مالک، قابل تنظیم با `sms_credit.on_plan_change`).

## ۷. adapter درگاه (PSP) — پیاده‌سازی‌شده: زرین‌پال + درگاه آزمایشی

> کد: `app/Domain/Billing/PaymentGateway.php` (قرارداد)، `PaymentGateways.php` (رجیستری)، `Gateways/ZarinpalGateway.php`، `Gateways/MockGateway.php`. آزمون: `tests/Feature/ZarinpalGatewayTest.php` (HTTP جعلی)، `tests/Feature/BillingTest.php`.

```php
interface PaymentGateway {
    public function code(): string;                       // 'zarinpal', 'mock' — روی هر PaymentAttempt ذخیره می‌شود
    public function isMock(): bool;
    public function request(string $orderRef, string $amountIrr, string $callbackUrl, ?string $mobile): array; // authority, redirect_url, method, fields — یا PaymentGatewayError
    public function redirectFor(string $authority): array; // بازگشت دوباره به همان صفحه بانک (درخواست تکراری)
    public function parseCallback(Request $r): array;     // authority, status (فقط نشانه؛ هرگز مبنای تأیید نیست), raw
    public function verify(string $authority, string $amountIrr): array; // OK | FAILED | UNKNOWN با مبلغ ذخیره‌شده
    public function formActionHosts(): array;              // فقط برای PSPهایی که POST می‌خواهند (CSP form-action)
}
```

### ۷.۱ تعویض درگاه با کمترین تغییر

- رجیستری `config('talata.payments.gateways')` نگاشت «کد ← کلاس» است.
- **افزودن درگاه جدید** (مثلاً سامان، ملت، آی‌دی‌پی، نکست‌پی) سه گام دارد:
  1. یک کلاس که `PaymentGateway` را پیاده می‌کند.
  2. یک خط در `gateways`.
  3. یک متغیر محیطی `TALATA_PAYMENT_DRIVER=<کد>`.
- **هیچ** تغییری در سفارش، تأیید، تحویل، reconcile، صفحه نتیجه یا UI لازم نیست.
- پرداخت‌های **در جریان** همیشه با درگاهی تأیید و reconcile می‌شوند که آن‌ها را باز کرده است (`PaymentAttempt.gateway` و مسیر `/pay/callback/{code}`). پس تعویض درگاه وسط کار هیچ پرداختی را بی‌صاحب نمی‌کند (آزموده).
- درگاه‌هایی که با فرم POST به بانک می‌روند، میزبان خود را از `formActionHosts()` اعلام می‌کنند. CSP فقط در صفحه‌های خرید پلن و پیامک همان میزبان‌ها را به `form-action` اضافه می‌کند.
- درگاه آزمایشی در production رد می‌شود، مگر با `TALATA_ALLOW_MOCK_PAYMENTS_IN_PRODUCTION=true`.

### ۷.۲ زرین‌پال (REST v4)

| گام | فراخوانی |
|---|---|
| درخواست | `POST https://payment.zarinpal.com/pg/v4/payment/request.json` با `merchant_id`، `amount` (ریال، عدد صحیح)، `currency: "IRR"`، `description`، `callback_url = {APP}/pay/callback/zarinpal`، `metadata.order_id`؛ `metadata.mobile` فقط با `TALATA_ZARINPAL_SEND_MOBILE=true`. موفق: `data.code = 100` و `data.authority` |
| انتقال | `GET https://payment.zarinpal.com/pg/StartPay/{authority}` |
| بازگشت | `GET /pay/callback/zarinpal?Authority=…&Status=OK\|NOK`. شکل `Authority` بررسی می‌شود و `Status` فقط نشانه است |
| تأیید | `POST https://payment.zarinpal.com/pg/v4/payment/verify.json` با `merchant_id`، `amount` (**مبلغ ذخیره‌شده سفارش**)، `authority`. `100` یعنی پرداخت‌شده و `101` یعنی قبلاً تأیید شده (هر دو موفق، idempotent). `ref_id` و `card_pan` ماسک‌شده ذخیره می‌شوند |
| محیط آزمایشی | `TALATA_ZARINPAL_SANDBOX=true` ← `https://sandbox.zarinpal.com/pg/...` |

**نگاشت نتیجه:**

| پاسخ زرین‌پال | نتیجه سفارش |
|---|---|
| `100`/`101` | پرداخت‌شده و سپس تحویل (یک‌بار) |
| `-50` (مبلغ متفاوت) | `FAILED` با `AMOUNT_MISMATCH` |
| `-51` (ناموفق) و بقیه کدهای منفی | `FAILED` با همان کد و متن فارسی (`ZarinpalGateway::CODES_FA`) |
| `-12`، `-52`، خطای شبکه، 5xx، پاسخ غیر JSON | `UNKNOWN`؛ سفارش `PENDING_VERIFICATION` می‌شود و reconcile با تأخیر افزایشی دوباره می‌پرسد |

**پرداخت‌کننده‌ای که برنگشت:** اگر مشتری پرداخت کرد ولی مرورگر را بست و callback نیامد، reconcile پیش از «منقضی» کردن سفارش (تا `reconcile_max_hours`) از زرین‌پال `verify` می‌پرسد. اگر پرداخت شده بود، تحویل انجام می‌شود (آزموده).

**مهلت‌ها و امنیت:**
- اتصال ۵ ثانیه و کل درخواست ۱۵ ثانیه (قابل تنظیم با env).
- شناسه پذیرنده (UUID ۳۶ نویسه‌ای) فقط در env است و در لاگ نمی‌آید. نبودن یا خراب بودن آن هنگام راه‌اندازی خطا می‌دهد.
- اگر زرین‌پال درخواست را رد کند، سفارشی ساخته نمی‌شود: `503 GATEWAY_UNAVAILABLE` با متن «مبلغی کسر نشده است».

**منبع و تأیید:** سایت و مستندات رسمی زرین‌پال (`zarinpal.com`، `docs.zarinpal.com`) از محیط توسعه این نسخه **در دسترس نبود** (سیاست شبکه). قرارداد از کتابخانه کلاینت متن‌باز زرین‌پال روی GitHub (`peymanr34/riviera-zarinpal`، نسخه V4) و دانش عمومی API استخراج شد. پیش از راه‌اندازی زنده این کارها لازم است:
1. مستندات رسمی فعلی دوباره خوانده شود.
2. یک پرداخت واقعی در sandbox و یک پرداخت کم‌مبلغ واقعی انجام شود.
3. متن کدهای خطا با پنل زرین‌پال مقایسه شود.

**اتصال زنده هنوز انجام نشده است.**

**راه‌اندازی:**
```
TALATA_PAYMENT_DRIVER=zarinpal
TALATA_ZARINPAL_MERCHANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
TALATA_ZARINPAL_SANDBOX=false
```
- آدرس بازگشت در پنل زرین‌پال باید دامنه `APP_URL` باشد.
- سرور باید به `payment.zarinpal.com` دسترسی خروجی داشته باشد.
- زمان‌بند (`php artisan schedule:work` یا cron هر دقیقه) برای `talata:payments-reconcile` باید فعال باشد.

## ۷-ب. کدهای تخفیف سرویس (2026-10-07)

- مدیر سامانه با `pricing.manage` در «قیمت پلن‌ها و پیامک» کد می‌سازد: درصد (۱ تا ۱۰۰) از مبلغ بدون مالیات، برای خرید پلن و/یا شارژ پیامک، سقف دفعات، مهلت؛ دلیل، کلید idempotency و ثبت رویداد `billing.promo_created`. غیرفعال‌کردن با دلیل.
- هر فروشگاه هر کد را یک بار. کد سرویس بر کد معرف مقدم است و کمیسیون همکار ندارد. سقف دفعات زیر قفل ردیف کد دوباره بررسی می‌شود.
- **کد ۱۰۰٪:** مبلغ قابل پرداخت صفر می‌شود؛ سفارش بدون رفتن به بانک با `channel=FREE` و همان اعمال idempotent تکمیل می‌شود و به صفحه نتیجه می‌رود (`billing.free_order`). برای آزمایش خرید پیش از اتصال زرین‌پال.
- اعتبار پیامک برابر **بسته خریداری‌شده** (مبلغ فهرست بدون مالیات) است؛ تخفیف فقط مبلغ پرداختی را کم می‌کند، نه اعتبار را.

## ۸. API

| متد و مسیر | کار | پاسخ |
| --- | --- | --- |
| `GET /api/billing/offers` | پلن‌ها، بسته‌های شارژ مجاز این tenant، قیمت بخش، حداقل، وضعیت فعلی | `BillingOffers` |
| `POST /api/billing/orders` | ساخت سفارش و دریافت دستور redirect | `{order_id, redirect}` یا خطای اعتبارسنجی |
| `GET /api/billing/orders/{id}` | وضعیت سفارش برای صفحه نتیجه/poll | `BillingOrderResult` |
| `GET /api/billing/orders?page=` | سوابق پرداخت | فهرست |
| `GET /api/billing/orders/{id}/receipt` | رسید HTML قابل چاپ | HTML |
| `GET /api/sms/credit` | مانده، lotها، انقضا، سهمیه رایگان | `SmsCredit` |
| `GET|POST /pay/callback/{gateway}` | بازگشت از بانک | redirect |
| `GET /pay/result/{order_id}` | صفحه نتیجه | HTML/route فرانت |

قرارداد TypeScript: `design/contracts/frontend-adapters.ts` → `BillingAdapter`, `SmsCredit`.

## ۹. امنیت و درستی

- مبلغ، محصول و tenant فقط از پایگاه داده؛ verify همیشه با مبلغ ذخیره‌شده؛ callback بدون session فقط با `authority` یکتا.
- idempotency در سه لایه: کلید سفارش، unique `(gateway, authority)`، قفل سفارش هنگام نهایی‌سازی. fulfillment دوبار اجرا نمی‌شود (چک وضعیت در همان تراکنش).
- بدون ذخیره شماره کامل کارت یا داده حساس؛ `card_mask` فقط در صورت ارائه درگاه.
- rate limit روی ساخت سفارش (مثلاً ۱۰ در ساعت برای هر tenant) و callback.
- همه تغییرات پلن و اعتبار در audit؛ اصلاح دستی provider با دلیل اجباری.
- هیچ داده مشتری فروشگاه به درگاه ارسال نمی‌شود؛ فقط موبایل مالک در صورت نیاز درگاه و با اطلاع.

## ۱۰. متن‌های رابط (کلیدها)

| کلید | متن |
| --- | --- |
| `billing.vat_line` | مالیات بر ارزش افزوده {vat_rate}٪: {vat} تومان · قابل پرداخت {total} تومان |
| `billing.redirect_hint` | به درگاه بانک منتقل می‌شوید و پس از پرداخت به زرلیو برمی‌گردید. |
| `billing.success.sms` | پرداخت انجام شد. {amount} تومان شارژ پیامک اضافه شد. |
| `billing.success.plan` | پرداخت انجام شد. پلن {plan} {period} از همین لحظه فعال است. |
| `billing.failed` | پرداخت انجام نشد. مبلغی از حساب شما کم نشده است؛ اگر کم شده باشد، بانک تا {refund_hours} ساعت آن را برمی‌گرداند. |
| `billing.pending` | بانک هنوز نتیجه را نداده. خودکار بررسی می‌کنیم و پیامک می‌دهیم؛ دوباره پرداخت نکنید. |
| `billing.not_found` | این پرداخت پیدا نشد. اگر مبلغی کم شده، با شماره پیگیری بانک با پشتیبانی تماس بگیرید. |
| `sms.credit_empty` | اعتبار پیامک تمام شد. فاکتور صادر می‌شود؛ برای ارسال، پیامک بخرید. |
| `sms.buy_entry` | خرید پیامک بیشتر |

## ۱۱. تست‌های پذیرش

- مالیات ۱۰٪: `amount = subtotal + vat` در سفارش، درگاه، verify، رسید و صفحه نتیجه یکسان؛ اعتبار پیامک برابر subtotal؛ تغییر نرخ در پیکربندی روی سفارش‌های باز اثر ندارد.
- قیمت‌ها از پیکربندی؛ مبلغ دست‌کاری‌شده کلاینت رد می‌شود؛ حداقل رایگان ۴۰۰ هزار و مبالغ غیرمجاز رد می‌شوند.
- سناریوهای `MockGateway`: موفق، لغو، عدم تطابق مبلغ، timeout ← reconcile موفق/ناموفق، callback تکراری و هم‌زمان (فقط یک fulfillment)، بازکردن دوباره صفحه نتیجه، session منقضی.
- خرید پلن: قابلیت‌ها/سهمیه‌ها پس از `FULFILLED` تغییر می‌کنند و پیش از آن نه؛ تمدید زنجیره‌ای؛ بدون همپوشانی.
- اعتبار: هزینه سه‌بخشی پایه = ۱٬۵۰۰ تومان؛ رایگان ۸۵۰ × بخش؛ FIFO انقضا؛ انقضای پایان ماه در رایگان با fake clock و timezone؛ بدون انقضا در پایه/حرفه‌ای؛ رزرو/برگشت/نامشخص؛ OTP از اعتبار کم نمی‌کند.
- صدور فاکتور با اعتبار صفر موفق است و ارسال «منتظر اعتبار» می‌ماند؛ بازگشت از پرداخت موفق به همان فاکتور با «ارسال دوباره».
- tenant isolation روی سفارش، lot، رسید و صفحه نتیجه؛ open redirect ممنوع.
- UI: تابلوهای این سند در ۳۶۰/۳۹۰/۱۲۸۰، ۲۰۰٪، صفحه‌خوان (نتیجه با `role="status"`)، بدون موفقیت جعلی در حالت mock.

## ۱۲. تصمیم‌های باز برای مالک

1. ~~انتخاب درگاه پرداخت~~ — زرین‌پال انتخاب شد (۱۴۰۵/۰۷). قرارداد پذیرندگی و merchant id با مالک است.
2. سیاست باقی‌مانده پلن قبلی هنگام ارتقا (proration).
3. رفتار مانده شارژ هنگام تغییر پلن و بسته‌های رایگان بالاتر از ۴۰۰ هزار.
4. صدور فاکتور رسمی اشتراک زرلیو (با مالیات ۱۰٪) برای فروشگاه‌ها و الزامات سامانه مؤدیان طرف زرلیو.
