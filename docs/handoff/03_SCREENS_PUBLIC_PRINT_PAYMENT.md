# Public, print and payment-result screens

Same conventions as `02_SCREENS_MERCHANT.md`.

## P-01 Public invoice from a share link — `/i/{token}`

| | |
| --- | --- |
| Rendering | Server-rendered Blade, no SPA bundle, ≤ 20 KiB JS / ≤ 180 KiB total first load, `noindex`, `Cache-Control: no-store` |
| Boards | `PublicInvoice` (mobile), `DesktopPublicInvoice` |
| Data | `InvoiceShare` token → immutable issued snapshot (public DTO in `frontend-adapters.ts`) |

**Layout:** shop header from the snapshot (logo on Basic/Pro, name, address, contact); invoice title «فاکتور فروش {number}», status badge, date/time; buyer name + **masked** mobile («۰۹۱۲•••۰۰۰۰»); «نرخ مبنا»; item cards (GOLD with weight/purity/rate/components; MISC «متفرقه»); totals «جمع طلا», «جمع متفرقه», «مبلغ قابل پرداخت»; verification band with link to `/v/{token}`; «چاپ یا ذخیره PDF»; privacy note; footer «صادرشده با زرلیو» (Free) or shop footer per layout.

**States:** valid, voided (red band «این فاکتور باطل شده است · {date}»), replaced (amber band, **no link to the new invoice**), link revoked/expired/invalid («این لینک معتبر نیست» with no green mark and no invoice data), offline (browser default + short text), very long names and many rows.

## P-02 Verification after QR scan — `/v/{token}`

| | |
| --- | --- |
| Rendering | Same constraints as P-01; verification token is separate from share links and quota-free |
| Boards | `Verify` (mobile), `DesktopPublicInvoice` (status bar + QR column) |

**Layout:** title «بررسی اصالت فاکتور»; result band: green check + «این فاکتور با شماره {number} در زرلیو ثبت شده است.», status «قطعی», issue time; comparison instruction («شماره فاکتور، نام فروشگاه، اقلام و مبلغ زیر را با برگه کاغذی مقایسه کنید…»); seller block; items with breakdown; totals; limitation note («این بررسی یعنی سند با رکورد ثبت‌شده در زرلیو مطابقت دارد؛ تضمین تحویل کالا، عیار آزمایشگاهی، دریافت وجه یا ثبت در سامانه مؤدیان نیست.»); «اطلاعات خریدار در این صفحه نمایش داده نمی‌شود».

**States:** valid, voided (red, date and «باطل‌شده»), replaced (amber), invalid token (neutral grey, «این کد در زرلیو پیدا نشد»; never green), rate-limited, v2 product-photo tab (`VerifyPhoto`, not in v1).

## P-03 Printed invoice A4 — two presets

| | |
| --- | --- |
| Route | `/invoices/{id}/print` (server-rendered HTML + print CSS; browser «Save as PDF») |
| Boards | `Print` (preset `simple_readable`), `PrintShop` (preset `shop`) — 794×1123 = A4 at 96 dpi |
| Contracts | `design/invoice-templates/` schema, presets, `reference/print-*.html`, sample data |

**Fixed rules:** QR in the **physical upper-left** corner (in RTL that is the end side), ≥ 22 mm, quiet zone, with «بررسی اصالت فاکتور» and short URL; nothing may overlap QR or the payable total; buyer name and mobile printed in full; seller name/contact/address always; table columns: row, description, weight (g), purity, wage, profit, tax, amount (shop preset folds details into the description line); notes on base rate time and «قاعده نمونه» tax label until approved; totals block with payable emphasised; signature boxes; footer per layout; Free prints «صادرشده با زرلیو · zarlio.ir».

**States:** issued, voided (diagonal «باطل‌شده» watermark + void date), replaced, multi-page (header repeats, totals on last page, «صفحه {n} از {m}»), long address/many socials/wide logo, black-and-white printing.

## P-04 Bank return — plan purchase — `/pay/result/{order}`

| | |
| --- | --- |
| Boards | `PayReturnPlan`, `DesktopPayReturnPlan` |
| Contract | `docs/PAYMENTS_AND_SMS_CREDIT.md` §5.4 |
| Data | `GET /api/billing/orders/{id}` only (never gateway query params) |

The page shows **one** of these states (the boards show them stacked for review):

1. **Success** — green check, «پرداخت انجام شد», «پلن حرفه‌ای سالانه از همین لحظه فعال است»; white table: plan, plan price, «مالیات بر ارزش افزوده ۱۰٪», «مبلغ پرداخت‌شده», active until, previous-plan carry-over, bank tracking number (LTR), Zarlio reference (LTR); note that data was untouched and the new per-segment SMS price; buttons «شروع کار با حرفه‌ای · مشتریان و اقساط» (or `return_to`) and «رسید پرداخت (ذخیره / چاپ)».
2. **Failed / cancelled / expired** — red X, «پرداخت انجام نشد», «پلن شما تغییری نکرد»; green band «مبلغی از حساب شما کم نشده است. اگر کم شده باشد، بانک تا ۷۲ ساعت آن را برمی‌گرداند؛ نیازی به پرداخت دوباره قبل از آن نیست.»; table: requested plan, amount with VAT, reason (Persian mapping of `bank_code`), reference, current plan unchanged; «تلاش دوباره» (new order), «برگشت به تنظیمات»; support link with reference.
3. **Pending verification** — amber clock band «بانک هنوز نتیجه را نداده. تا ۱۰ دقیقه خودکار بررسی می‌کنیم و پیامک می‌دهیم؛ دوباره پرداخت نکنید.»; poll every 5 s up to 2 min then stop polling.

Also: logged-out view (status + reference only, then full details after login), mock label «حالت آزمایشی», reopen/refresh has no side effects. `role="status"` on the result heading.

## P-05 Bank return — SMS top-up

| | |
| --- | --- |
| Boards | `PayReturnSms`, `DesktopPayReturnSms` |

Same three states as P-04 with SMS wording: success «{amount} تومان شارژ پیامک اضافه شد (پرداخت {total} با مالیات)», rows «شارژ اضافه‌شده», «مالیات بر ارزش افزوده ۱۰٪», «مبلغ پرداخت‌شده», «مانده جدید اعتبار ≈ بخش», bank tracking, reference, time; carry-over note per plan; primary «ادامه صدور و ارسال پیامکی» when `return_to` is a draft/invoice, otherwise «برگشت به تنظیمات». Failure: «شارژی اضافه نشده است», reason, «مانده اعتبار (بدون تغییر)», «تلاش دوباره», «فعلاً فقط صدور (بدون پیامک)» when coming from review.

## P-06 Payment receipt — `/api/billing/orders/{id}/receipt`

Printable HTML (no board): Zarlio name, order reference, date, tenant name, product, base price, VAT 10%, total paid, bank tracking number, payment status, «این رسید پرداخت است؛ فاکتور رسمی مالیاتی جداگانه صادر می‌شود (در صورت الزام)». Mock mode watermark «آزمایشی».
