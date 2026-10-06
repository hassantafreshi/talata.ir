# راهنمای ساخت طلاتا — بسته آماده برنامه‌نویسی

وضعیت: 2026-10-05. **برای ساخت با ChatGPT یا عامل مشابه از `docs/handoff/README.md` شروع کنید** (دستور ثابت، ۲۳ کارت کار به ترتیب، مشخصات همه صفحات با حالت‌ها، نمونه API، چک‌لیست). این سند نقطه شروع تیم برنامه‌نویسی است: کدام سند قرارداد است، ماژول‌ها و جدول‌ها، API، کارهای زمان‌بندی‌شده، ترتیب ساخت و تعریف تمام‌شدن. جزئیات در اسناد مرجع است و این‌جا تکرار نمی‌شود؛ اگر تناقضی دیدید، سند مرجع ستون «منبع» برنده است و تناقض باید در همان PR ثبت شود.

## ۱. نقشه اسناد

| موضوع | منبع (قرارداد) | وضعیت |
| --- | --- | --- |
| دامنه، معماری، مدل داده، محاسبه، تست | `prompts/PHASE_1_MASTER_PROMPT.md` | قرارداد اصلی |
| پلن، قیمت، سهمیه، شارژ پیامک | `PLANS_AND_QUOTAS.md` + `design/contracts/plans-pricing.json` | تصمیم مالک؛ چند عدد باز (§۹) |
| پرداخت آنلاین، خرید پلن، اعتبار پیامک | `PAYMENTS_AND_SMS_CREDIT.md` | آماده ساخت؛ درگاه پشت adapter |
| مظنه و ماشین‌حساب طلایی | `MAZNEH_AND_CALCULATOR.md` | آماده ساخت |
| QR، نمای موبایل فاکتور، پیامک هنگام صدور | `INVOICE_DELIVERY_AND_VERIFICATION.md` | قرارداد |
| پروفایل کسب‌وکار و ویرایش قالب فاکتور | `INVOICE_CUSTOMIZATION.md` + `design/invoice-templates/` | قرارداد |
| بودجه سرعت | `PERFORMANCE_BUDGET.md` | قرارداد؛ اعداد باید اندازه‌گیری شوند |
| نسخه ۲ و زیرساختی که فاز ۱ می‌سازد | `ROADMAP_V2_BUSINESS_TYPES.md` | فقط §۳ آن در فاز ۱ |
| صفحات، اجزا، متن‌ها، حالت‌ها | `design/UI_BUILD_SPEC.md` + `design/PAGE_COVERAGE.md` + بوم و `design/proposed/screenshots/` | پیشنهادی تا تأیید پالت/لوگو/toolkit |
| توکن‌ها و قرارداد فرانت | `design/tokens/`, `design/contracts/frontend-adapters.ts`, `calculation-vectors.json` | پیشنهادی (پالت ۱ پیش‌فرض کاری) |
| بسته تحویل برای مدل برنامه‌نویس | `handoff/` + `design/reference-html/` | آماده؛ مرجع ظاهری پیشنهادی |
| پنل مدیریت سرویس نسخه ۱ | `handoff/04_SCREENS_ADMIN.md` + تابلوهای `Provider`, `Admin*` | آماده ساخت |
| تصمیم‌های تأییدشده و باز UI | `design/UI_APPROVED_DECISIONS.md` | مرجع وضعیت تأیید |
| همکاری در فروش (افیلیت): کد تخفیف، لینک، کمیسیون، پنل همکار و مدیر | `AFFILIATE_PROGRAM.md` | پیاده‌سازی و آزموده |
| دسترسی همکاران در سطح صفحه (نقش آماده، وابستگی، منوی فیلترشده) | `TEAM_PERMISSIONS.md` | پیاده‌سازی و آزموده |
| طلای دریافتی از مشتری (GOLD_IN، وزن ۷۵۰، تفکیک طلایی/مبلغ) و داشبورد فروش | `GOLD_RECEIVED_AND_DASHBOARD.md` | پیاده‌سازی و آزموده |
| چک‌لیست پیشرفت | `PHASE_1_CHECKLIST.md` | با هر milestone به‌روز شود |

## ۲. Stack و ساختار مخزن

- Laravel (نسخه پایدار پشتیبانی‌شده هنگام شروع، قفل‌شده)، PHP سازگار، PostgreSQL، Redis برای صف/کش/rate limit، Pest/PHPUnit، Playwright.
- فرانت پیشنهادی: Vue 3 + TypeScript + Inertia + Vite با chunk به ازای مسیر (`UI_BUILD_SPEC.md` §۲)؛ toolkit تا انتخاب مالک نصب نمی‌شود. صفحات عمومی (`/i`, `/v`, `/pay/result` در حالت بدون ورود) server-rendered و سبک.
- ساختار ماژول‌ها (پیشنهادی، طبق پرامپ اصلی §۴):

```text
app/Core/{Tenancy,Money,Metal,Access,Events,Clock}
app/Modules/{Saas,Identity,Billing,MarketPrices,Pricing,Invoices,PublicInvoices,Parties,Installments,Sms,Notifications,Audit,Domains,Provider}
resources/js/{pages,components,composables,domain,layouts,i18n}
tests/{Unit,Feature,Browser}
docs/{adr,architecture,operations}
```

## ۳. ماژول‌ها، مالکیت داده و رویدادها

| ماژول | مسئولیت | جدول‌های اصلی | رویدادها / وابستگی |
| --- | --- | --- | --- |
| Saas | tenant، پروفایل فروشگاه، اعضا، نقش‌ها، پلن، قابلیت، سهمیه، اشتراک، نوع کسب‌وکار (زیرساخت v2) | `tenants`, `shop_profiles`, `memberships`, `plans`, `plan_features`, `plan_quotas`, `subscriptions`, `tenant_feature_overrides`, `quota_usages`, `tenant_business_types` | `SubscriptionActivated`, `QuotaConsumed`؛ سرویس `Entitlements::can/quota` |
| Identity | OTP، نشست، Passkey | `otp_challenges`, `passkey_credentials`, `webauthn_challenges` | — |
| Billing | سفارش، تلاش پرداخت، adapter درگاه، رسید، reconcile | `billing_orders`, `payment_attempts` | `BillingOrderFulfilled` → Saas (اشتراک) / Sms (lot) |
| MarketPrices | دریافت مرکزی ۱۸۰ ثانیه، مظنه، override | `provider_configurations`, `market_quotes`, `tenant_rate_overrides` | `QuotesRefreshed` |
| Pricing | موتور دقیق، policy registry، قواعد مالیات، ماشین‌حساب | `tax_rules`, `tax_rule_assignments`, `pricing_policies` | بدون وابستگی به Invoices |
| Invoices | پیش‌نویس، ردیف GOLD/MISC، صدور idempotent، شماره‌گذاری، snapshot، ابطال/جایگزین، قالب | `invoices`, `invoice_items` (+`item_attributes`, `direction`), `invoice_layouts`, `invoice_counters`, `invoice_item_assets` (v2) | `InvoiceFinalized`, `InvoiceVoided` |
| PublicInvoices | توکن اشتراک و بررسی، صفحه `/i` و `/v` | `invoice_shares`, `invoice_verifications` | `InvoiceShareCreated` |
| Parties | دفتر مشتری با سقف ماهانه | `parties`, `party_roles`, `party_contacts` | `PartyCreated` (مصرف سهمیه) |
| Installments | قرارداد، برنامه، پرداخت دستی، یادآوری (حرفه‌ای) | `agreements`, `schedule_lines`, `payment_records`, `payment_allocations` | `InstallmentPaymentRecorded` |
| Sms | قالب، پیام، تلاش ارسال، webhook، اعتبار تومانی | `sms_templates`, `sms_messages`, `sms_attempts`, `sms_credit_lots`, `sms_credit_entries`, `sms_free_allowances` | reserve/capture/release/expire |
| Audit / Ops | audit append-only، outbox، سلامت یکپارچه‌سازی | `audit_events`, `outbox_events`, `integration_health` | — |
| Provider | پنل provider: پلن‌ها، قیمت‌ها، درگاه، پیامک، فعال‌سازی دستی، سفارش‌های معلق | از ماژول‌های بالا از طریق سرویس | — |

همه جدول‌های tenant-owned ستون `tenant_id` و کلید/ایندکس مرکب دارند؛ دسترسی بین tenantها در تست HTTP، job و cache رد می‌شود.

## ۴. فهرست API و مسیرها (خلاصه)

| گروه | مسیرها |
| --- | --- |
| ورود | `POST /api/auth/otp/request`, `POST /api/auth/otp/verify`, `POST /api/auth/passkey/{register,login}/{options,verify}`, `POST /api/auth/logout` |
| نرخ و مظنه | `GET /api/quotes/latest`, `GET /api/quotes/board` |
| محاسبه | `POST /api/pricing/preview` (اختیاری؛ محاسبه محلی با همان fixtures) |
| فاکتور | `POST /api/invoices/drafts` (Start با نرخ پذیرفته)، `PATCH /api/invoices/drafts/{id}`, `POST /api/invoices/drafts/{id}/issue` (کلید idempotency، `mode=ISSUE_ONLY|ISSUE_AND_SMS`)، `GET /api/invoices`, `GET /api/invoices/{id}`, `POST /api/invoices/{id}/void`, `POST /api/invoices/{id}/share`, `POST /api/invoices/{id}/sms/resend`, `GET /invoices/{id}/print` |
| عمومی | `GET /i/{token}`, `GET /v/{token}` |
| مشتری و اقساط | `GET|POST /api/customers`, `GET /api/customers/{id}`, `POST /api/agreements`, `POST /api/agreements/{id}/payments`, `POST /api/payments/{id}/reverse` |
| تنظیمات | `GET|PUT /api/settings/profile`, `POST /api/settings/logo`, `GET|PUT /api/settings/layout`, `GET /api/entitlements` |
| پرداخت و پیامک | `GET /api/billing/offers`, `POST /api/billing/orders`, `GET /api/billing/orders[/{id}]`, `GET /api/billing/orders/{id}/receipt`, `GET /api/sms/credit`, `GET|POST /pay/callback/{gateway}`, `GET /pay/result/{order}` |
| provider | `/provider/*` (جدا، با نقش provider): `GET /provider/api/dashboard`، `tenants` (+`/{id}`, `manual-activation`, `sms-credit-adjustments`, `feature-overrides`, `suspend`)، `pricing/versions` (+`draft`, `publish`)، `payments` (+`inquire`, `manual-confirm`, `mark-failed`, `export.csv`)، `sms/messages`، `quotes/status` (+`thresholds`, `emergency-rate`)، `tax-rules`، `integrations` (+`test`)، `staff`، `audit`، `system` — جزئیات در `handoff/04_SCREENS_ADMIN.md` |

قالب پاسخ: decimal string برای پول/وزن/نرخ، ریال در API، خطای ساخت‌یافته `{code, message_fa, trace_id}`. قرارداد نوع‌ها: `design/contracts/frontend-adapters.ts`.

## ۵. کارهای زمان‌بندی‌شده و صف

| کار | تناوب | سند |
| --- | --- | --- |
| دریافت مرکزی نرخ‌ها (single-flight) | هر ۱۸۰ ثانیه | پرامپ اصلی §8، `MAZNEH_AND_CALCULATOR.md` |
| ارسال پیامک از outbox، reconcile وضعیت نامشخص | پیوسته / هر ۱ دقیقه | پرامپ اصلی §12 |
| reconcile پرداخت‌های در حال بررسی | هر ۱ دقیقه با backoff تا ۲۴ ساعت | `PAYMENTS_AND_SMS_CREDIT.md` §۵.۵ |
| انقضای سفارش‌های رهاشده | هر ۵ دقیقه | همان |
| انقضای شارژ پیامک رایگان + هشدار ۳ روز قبل | روزانه، اجرای پایان ماه tenant | همان |
| صفرشدن سهمیه‌های ماهانه | آغاز ماه تقویمی tenant (محاسبه تنبل بر اساس بازه نیز کافی است) | `PLANS_AND_QUOTAS.md` |
| تازه‌شدن ۵ پیامک رایگان سالانه | سالانه طبق تعریف | همان |
| یادآوری اقساط | روزانه با کلید یکتا | پرامپ اصلی §13 |

## ۶. پیکربندی و seed

- `plans-pricing.json` منبع seed پلن‌ها، قیمت‌ها، سهمیه‌ها، `sms_credit` و محرک‌های پیام سقف است؛ seeder آن را به جدول‌ها می‌برد و provider از پنل ویرایش می‌کند. هیچ عدد قیمت در کد دامنه یا UI ثابت نمی‌شود.
- متغیرهای محیطی (بدون مقدار واقعی در مخزن): اتصال DB/Redis، `QUOTE_PROVIDER` و کلیدها، `SMS_PROVIDER` و کلیدها، `PAYMENT_GATEWAY` (`mock` در توسعه) و merchant id، `APP_TIMEZONE_DEFAULT=Asia/Tehran`، دامنه عمومی برای لینک‌های `/i` و `/v`.
- داده نمونه همیشه برچسب «نمونه» دارد؛ دو tenant نمایشی با داده متمایز.

## ۷. ترتیب ساخت (milestoneها با مرز پذیرش)

| گام | خروجی | پذیرش (حداقل) |
| --- | --- | --- |
| M0 | اسکلت، ADRها، ERD، CI، Money/Metal/Clock، seed پیکربندی | lint/test سبز در CI؛ ERD با جدول‌های §۳ |
| M1 | tenant، OTP، Passkey اختیاری، نقش‌ها، موتور قابلیت/سهمیه با سهمیه‌های مالک | تست جداسازی tenant؛ ۵۰ فاکتور/۵۰ مشتری رایگان با fake clock |
| M2 | نرخ مرکزی، مظنه، موتور محاسبه، ماشین‌حساب | vectors سبز در سرور و مرورگر؛ تیک ۱۸۰ ثانیه |
| M3 | پیش‌نویس، ردیف‌ها، صدور، snapshot، QR، چاپ، اشتراک، ابطال، فهرست با محدودیت ماه جاری رایگان | تست‌های §18 پرامپ اصلی مربوط به فاکتور |
| M4 | پیامک و اعتبار تومانی، Billing با MockGateway، خرید پلن و شارژ، صفحه نتیجه، مشتری با سقف، اقساط حرفه‌ای | تست‌های §۱۱ `PAYMENTS_AND_SMS_CREDIT.md` |
| M5 | UI نهایی پس از تأیید پالت/لوگو/toolkit، PWA، سرعت، runbook | اسکرین‌شات ۳۶۰/۳۹۰/۷۶۸/۱۲۸۰، بودجه سرعت با اندازه‌گیری |
| v2-ready | زیرساخت `ROADMAP_V2_BUSINESS_TYPES.md` §۳ در همان M0–M3 | تست ساختاری افزودن policy بدون تغییر schema |

بخش‌های بک‌اند (M0–M4) و جریان‌های خنثی UI بدون انتظار برای تأیید ظاهر قابل شروع‌اند؛ UI نهایی (M5) پس از تأیید تلفیقی مالک.

## ۸. نگاشت تابلو ← مسیر ← جزء

جدول کامل در `design/PAGE_COVERAGE.md`؛ هر صفحه در `design/UI_BUILD_SPEC.md` §۵ مسیر، اجزا، حالت‌ها، adapter و پذیرش دارد. تابلوهای جدید این نسخه:

| تابلو | مسیر | بخش spec |
| --- | --- | --- |
| `SmsBuy` / `DesktopSmsBuy` | `/settings/sms` | §۵.۱۸ |
| `PayReturnPlan` / `DesktopPayReturnPlan` | `/pay/result/{order}` (محصول PLAN) | §۵.۱۹ |
| `PayReturnSms` / `DesktopPayReturnSms` | `/pay/result/{order}` (محصول SMS_CREDIT) | §۵.۱۹ |
| `Mazneh`, `Calculator` و نسخه دسکتاپ | `/mazneh`, `/calculator` | §۵.۱۵–۵.۱۶ |

## ۹. تصمیم‌های باز و اثرشان

| تصمیم | چه چیزی را نگه می‌دارد | چه چیزی را نگه نمی‌دارد |
| --- | --- | --- |
| پالت، لوگو، toolkit UI | M5 (UI نهایی) | بک‌اند، API، تست‌ها، UI خنثی |
| درگاه پرداخت (PSP) | اتصال زنده پرداخت | Billing کامل با MockGateway |
| ارائه‌دهنده پیامک و نرخ | ارسال/دریافت زنده | adapterها و mock |
| ماهانه پایه (۷۹۰ هزار؟) و سقف فاکتور/مشتری پایه | مقدار نهایی seed | ساخت؛ مقدار در پیکربندی |
| proration ارتقا، رفتار مانده شارژ با تغییر پلن، بسته‌های رایگان بالای ۴۰۰ هزار | مقدار سیاست | ساخت؛ پیش‌فرض‌های قابل تنظیم |
| قواعد مالیاتی نهایی | برچسب «قاعده نمونه» | موتور نسخه‌دار |

## ۱۰. تعریف تمام‌شدن هر کار

- تست رفتاری (نه آینه پیاده‌سازی)، جداسازی tenant، و fake clock برای هر چیز زمانی.
- هیچ ادعای «زنده»، «تست کاربر» یا «سرعت محقق‌شده» بدون شواهد؛ mockها صریح گزارش شوند.
- به‌روزرسانی `PHASE_1_CHECKLIST.md` و در صورت تغییر تصمیم، `UI_APPROVED_DECISIONS.md`.
- متن‌ها از کلیدهای `i18n/fa.ts` و جدول متن‌های spec؛ اعداد نمایش فارسی، داده لاتین.
