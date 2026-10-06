# Merchant screens — specification with all states

Conventions for every screen:

- **Boards** = reference HTML in `docs/design/reference-html/` + screenshot in `docs/design/proposed/screenshots/`. Mobile is 390 px wide, desktop 1280 px; build responsive between them (mobile layout < 768 px, desktop ≥ 1024 px, tablet uses mobile layout with max-width 640 px centred unless noted).
- **Data** names endpoints from `IMPLEMENTATION_GUIDE.md` §4 and types from `design/contracts/frontend-adapters.ts`; examples in `06_API_EXAMPLES.md`.
- **States** lists every state the screen must render. Global states (loading, offline banner, session expired, server error, forbidden, demo label) follow `05_STATES_AND_PATTERNS.md` and are not repeated unless the screen behaves differently.
- Copy in «» is exact Persian UI text (keys in `fa.ts`).
- Money is shown in toman with Persian digits and «٬» grouping; the unit «تومان» is always visible.

---

## M-01 Login — mobile number

| | |
| --- | --- |
| Route | `/login` (chunk `auth`, ≤150 KiB JS gz) |
| Boards | `Login`, `DesktopLogin` (card 1) |
| Access | logged-out only; logged-in users redirect to `/invoices/new` |

**Layout (top→bottom):** dark header with logo + «طلاتا» and tagline «فاکتور طلا؛ ساده، سریع، قابل بررسی»; step chip «مرحله ۱ از ۲ · شماره موبایل»; title «شماره موبایل خود را وارد کنید»; one large `tel` input (`inputmode="numeric"`, `autocomplete="tel"`, LTR digits, accepts Persian/Arabic/Latin digits, `09…`, `+98…`, `0098…`); helper text «کد تأیید به همین شماره پیامک می‌شود. اگر بار اول است، همین کد شما را ثبت‌نام هم می‌کند؛ رمز یا ایمیل لازم نیست.»; primary button «دریافت کد پیامکی»; secondary link «قبلاً ورود سریع را فعال کرده‌اید؟ ورود با اثر انگشت یا قفل گوشی» (only if WebAuthn is supported); footnote and support link.

**Data:** `POST /api/auth/otp/request {mobile}` → `{challenge_id, resend_after_seconds, masked_mobile}`.

**States:** empty (button disabled), invalid number («شماره موبایل درست نیست. نمونه: ۰۹۱۲ ۳۴۵ ۶۷۸۹»), sending (button spinner, input read-only), rate-limited («تعداد درخواست‌ها زیاد شد. {minutes} دقیقه دیگر دوباره امتحان کنید.»), SMS provider down («ارسال پیامک الان ممکن نیست. چند دقیقه دیگر امتحان کنید.»), offline (button disabled with offline banner), WebAuthn unsupported (passkey link hidden).

**Acceptance:** normalization tests for all digit forms; no account enumeration (same response for new and existing numbers); 44 px targets.

## M-02 Login — SMS code

| | |
| --- | --- |
| Route | `/login/code` |
| Boards | `Otp`, `DesktopLogin` (card 2) |

**Layout:** step chip «مرحله ۲ از ۲»; title «کد پیامک‌شده را وارد کنید»; «پیامک به {masked}» + link «ویرایش شماره»; 6 single-digit boxes (`autocomplete="one-time-code"`, paste fills all, auto-advance, backspace goes back); helper «با کامل‌شدن کد، ورود خودکار انجام می‌شود.»; button «ورود»; secondary «ارسال دوباره کد» disabled with countdown «تا ۰۱:۳۰»; expandable help «کد نرسید؟ …».

**Data:** `POST /api/auth/otp/verify {challenge_id, code}` → `{status: 'OK', is_new_tenant, next: '/login/passkey-offer' | '/invoices/new'}` or error codes `WRONG_CODE {attempts_left}`, `EXPIRED`, `LOCKED`.

**States:** typing, verifying (boxes disabled), wrong code («کد درست نیست. یک بار دیگر به پیامک نگاه کنید ({n} تلاش دیگر).»), expired («کد منقضی شد. کد تازه بگیرید.» + resend enabled), locked (wait message with minutes), resend countdown, resend sent toast «کد تازه فرستاده شد».

**Acceptance:** auto-submit on 6th digit once; no double submit; a draft in local storage survives re-login.

## M-03 Optional Passkey offer

| | |
| --- | --- |
| Route | `/login/passkey-offer` (shown once after first successful login on a device) |
| Boards | `Passkey`, `DesktopLogin` (card 3) |

**Layout:** title «دفعه بعد سریع‌تر وارد شوید؟»; body about fingerprint/face/device lock; two reassurance bands («اثر انگشت یا چهره شما هیچ‌وقت به طلاتا فرستاده نمی‌شود…», «پیامک همیشه راه بازیابی می‌ماند…»); primary «فعال‌سازی با اثر انگشت یا قفل گوشی»; secondary «فعلاً نه، ادامه با پیامک».

**Data:** `POST /api/auth/passkey/register/options` → WebAuthn options; `POST …/verify`.

**States:** default, ceremony in progress, success toast «ورود سریع فعال شد» → redirect, user cancelled (stay, no error tone), unsupported device (screen skipped), server verify failed («فعال‌سازی انجام نشد. بعداً از تنظیمات امتحان کنید.»).

## M-04 New invoice — rate and Start (owner hard requirement)

| | |
| --- | --- |
| Route | `/invoices/new` (chunk `rate`, must not import composer/editor/QR code) |
| Boards | `Main` (palette 1; `RateP2`, `RateP3` show palettes 2/3), `DesktopRate` |
| Access | logged-in; capability `invoice.finalize` decides only whether issuing later is allowed — this page always renders |

**Layout (mobile):** shop header (store name, logo mark); page title «فاکتور جدید»; **dark hero card**: label «قیمت هر گرم طلای ۱۸ عیار», badge «عدد نمونه» only for demo data, the price in very large gold digits (≥ 39 px) + «تومان», then **«شروع»** full-width gold button directly below, then «آخرین دریافت: {HH:mm}» + freshness badge, «به‌روزرسانی هر ۳ دقیقه · منبع: {source}»; two small bands: 24K and USD; pill link «مظنه کامل: خرید و فروش ۱۸، ۲۴ عیار، دلار، انس» → M-17; band «ادامه پیش‌نویس قبلی» (if a draft exists, shows rows count and save time); links «نرخ در دسترس نیست؟ ثبت نرخ دستی» and «فاکتور فقط متفرقه (بدون نرخ طلا)»; bottom tab bar (M-11).

**Desktop:** same hero at left/centre with a right column «وضعیت‌های نرخ» legend; top nav; header strip shows plan, links left, SMS credit.

**Data:** `GET /api/quotes/latest` on entry/foreground/reconnect + poll every 180 s while visible and online; `POST /api/invoices/drafts {accepted_rate:{asset:'GOLD_18_SELL', value, fetched_at}}` on Start; `GET /api/entitlements` for the near-limit banner.

**States:** fresh («به‌روز» green), stale («قدیمی» amber, real last time, never zero), changed-at-Start (see M-05 §1), manual rate (M-05 §2), offline (M-05 §3), service error (M-05 §4), near quota banner («۲ فاکتور مانده …» from M-19), quota exhausted (M-19 sheet on Start), existing draft band, first-time (no draft band).

**Acceptance:** Start uses exactly the displayed value and `fetched_at`; if the server has a newer value the user must accept it; background polls never change an open draft.

## M-05 Rate states

| | |
| --- | --- |
| Boards | `RateStates` (4 states stacked for review; the app shows one), `DesktopRate` legend |

1. **Changed at Start** — bottom sheet «نرخ تازه رسید؛ با کدام عدد ادامه دهیم؟» showing «عددی که دیدید · {time}» and «نرخ تازه · {time}» with values; primary «ادامه با نرخ تازه ({value})», secondary «برگشت و دوباره نگاه کنم»; note that the accepted rate stays fixed until «استفاده از نرخ جدید».
2. **Manual rate** — sheet «ثبت نرخ دستی برای این فاکتور»: amount input (toman per gram 18K), reason segmented («نرخ بازار در دسترس نیست» / «توافق با مشتری» / «نرخ همکار»), note «روی فاکتور و صفحه بررسی، «نرخ دستی» نوشته می‌شود.», last market rate for comparison; «ثبت و شروع با این نرخ» / «انصراف».
3. **Offline** — hero greys, badge «آفلاین», text «آفلاین؛ آخرین نرخ دریافت‌شده {time}. می‌توانید محاسبه و پیش‌نویس را ادامه دهید؛ صدور، لینک و پیامک پس از اتصال.»; buttons «ادامه محاسبه با همین نرخ» and disabled «شروع فاکتور (پس از اتصال)».
4. **Service error** — badge «خطا», «سرویس نرخ پاسخ نمی‌دهد», last valid rate marked «(قدیمی)», auto-retry note, buttons «ثبت نرخ دستی», «فقط متفرقه», «تلاش دوباره الان».

## M-06 Draft items (GOLD / MISC rows)

| | |
| --- | --- |
| Route | `/invoices/{draft}/items` (chunk `composer`) |
| Boards | `Items`, `DesktopItems` |

**Layout (mobile):** header «اقلام فاکتور» + save indicator «پیش‌نویس ذخیره شد»; rate strip «نرخ معامله: {rate} تومان/گرم ۱۸ عیار» and, if newer market value exists, chip «{new} · استفاده از نرخ جدید»; **sticky «افزودن ردیف» button pinned at the top of the list** (stays visible while scrolling; new row appended below the last one and scrolled into view with focus on its first field); row cards:

- Row header «ردیف {n}» + segmented «طلا | متفرقه».
- GOLD: «نام کالا» (default «طلای ۱۸ عیار»), «وزن خالص طلا» (g, decimal), «عیار» chips «۱۸ عیار (۷۵۰)» default / «۲۱ عیار (۸۷۵)» / «۲۴ عیار (۱۰۰۰)» / «عیار دقیق…» (ppt input), hint «وزن سنگ و قسمت‌های غیرطلایی را وارد نکنید.», «نرخ این عیار: …», «اجرت» %, «سود» %, «تخفیف اجرت و سود» toman, optional «توضیح», row total.
- MISC: «عنوان (الزامی)», «قیمت ردیف (الزامی)» toman, hint «قیمت کل همین ردیف را بنویسید. برای متفرقه، وزن و عیار و محاسبه طلا به کار نمی‌رود.», optional description.
- «حذف (قابل برگشت)» → snackbar «ردیف حذف شد · برگرداندن» for 6 s.

Sticky footer: «جمع فاکتور ({n} ردیف)» + total + «مرور فاکتور».

**Desktop:** multi-row form with the same fields in a grid, right summary card with gold value/wage/profit/tax/gold total/misc total/payable, «مرور فاکتور».

**Data:** `PATCH /api/invoices/drafts/{id}` (debounced 800 ms, versioned); local preview via `PreviewFn`; offline queue.

**States:** empty draft (one GOLD row pre-added), invalid field (inline red message under the field, row total shows «—»), saving, saved, save failed («ذخیره نشد؛ دوباره تلاش می‌کنیم» with retry), offline (saved locally badge), new market rate chip, conflict (another device edited: «این پیش‌نویس در دستگاه دیگری تغییر کرد» + reload), 20+ rows performance.

**Acceptance:** MISC rows never enter gold tax base or weight totals; changing one row's purity recalculates only that row; undo restores row position.

## M-07 Review, buyer and issue

| | |
| --- | --- |
| Route | `/invoices/{draft}/review` |
| Boards | `Review`, `DesktopReview` |

**Layout (mobile):** rows summary band (each row name + key facts + total), component breakdown grid (gold value, wage, profit, «مالیات ۱۰٪ روی اجرت و سود»), heavy rule, «مبلغ قابل پرداخت» large; rate/time line + «ویرایش ردیف‌ها»; **buyer band**: «نام خریدار», «شماره موبایل مشتری», helper about printing and privacy, checkbox «ذخیره در دفتر مشتریان» (counts toward monthly new-customer quota; disabled with reason when exhausted); **SMS preview** (dashed band): «پیامک به {mobile}», «{segments} بخش · از ۵ پیامک رایگان امسال: {n} مانده», «بعد از آن از اعتبار: {balance} تومان ≈ {segments} بخش» + link «خرید پیامک بیشتر» (→ M-16 with `return_to=review`), final SMS text, link quota line; note «پس از صدور، نرخ و مبلغ‌ها ثابت می‌مانند. اصلاح مالی فقط با فاکتور جایگزین.»; buttons «صدور و ارسال پیامکی» (primary) and «فقط صدور (بدون پیامک)».

**Desktop:** left: live invoice preview in the current layout with QR placeholder upper-left; right: buyer, totals, SMS preview, two buttons.

**Data:** `POST /api/invoices/drafts/{id}/issue {mode, buyer, save_customer, review_fingerprint, idempotency_key}` → `ISSUED {invoice_id}` | `REVIEW_REQUIRED {reason, new_rate?|missing_profile?}` | error.

**States:** ready, buyer mobile missing for SMS mode (inline error, «فقط صدور» still enabled), invalid mobile, profile incomplete (redirect to M-13 with return), rate changed (sheet from M-05 §1), SMS credit empty (M-19 case 4 sheet: «خرید پیامک» / «فقط صدور»), link quota exhausted (SMS mode disabled with explanation; issue-only enabled), customer quota exhausted (checkbox disabled), issuing (both buttons disabled, spinner), unknown result after network loss («در حال بررسی نتیجه…» then retry with same key), issued → M-08.

## M-08 Issued result

| | |
| --- | --- |
| Route | `/invoices/{id}/issued` |
| Boards | `Issued`, `DesktopIssued` |

**Layout:** success band with check icon, «فاکتور {number} صادر شد», amount, buyer/date/time, «نرخ و مبلغ‌ها از این لحظه ثابت‌اند»; status list: «فاکتور — قطعی», «پیامک به {mobile} — در صف ارسال / ارسال شد / تحویل شد / ناموفق / نامشخص», «لینک اشتراک — ساخته شد · {remaining} از {limit} باقی‌مانده»; note about SMS independence + «مانده اعتبار پیامک ≈ {n} بخش · خرید پیامک»; QR band with sample QR, text «همین QR بالای سمت چپ نسخه چاپی هم هست…» and link «بررسی این فاکتور»; action grid: «چاپ», «کپی لینک فاکتور», «ارسال دوباره (پس از نتیجه)» (enabled on FAILED/UNKNOWN resolved or after top-up), «فاکتور جدید».

**Data:** `GET /api/invoices/{id}` + poll SMS status every 5 s up to 2 min.

**States:** issue-only (no SMS row), SMS queued/sent/delivered/failed/unknown, SMS waiting for credit («منتظر اعتبار پیامک» + «خرید پیامک»), link quota exhausted (link row says so, copy disabled), offline after issue (status frozen with last-updated time).

## M-09 Invoice list

| | |
| --- | --- |
| Route | `/invoices` (chunk `list`) |
| Boards | `InvoiceList`, `DesktopInvoiceList` |

**Layout:** title + month summary («این ماه: {n} فاکتور · لینک: {n} مانده»); search; «فاکتور جدید»; filter chips «همه / پیش‌نویس ({n}) / قطعی / باطل‌شده / اقساطی / این ماه» (desktop adds date range and «پیامک ناموفق»); cards (mobile) or table (desktop) with number, status badge, buyer, date, amount, items summary, SMS status and quick actions (continue draft, resend, details, print, link); pagination 25.

**States:** loading skeleton (5 rows), empty («هنوز فاکتوری ندارید. با «فاکتور جدید» اولین فاکتور را بسازید.»), no search results, Free history restriction notice («فاکتورهای ماه‌های قبل در پلن پایه و حرفه‌ای در دسترس است. داده‌های شما حفظ شده‌اند.»), offline cached list with «آخرین به‌روزرسانی {time}», voided row with replacement link and reason, installment row with remaining balance.

## M-10 Invoice detail and void

| | |
| --- | --- |
| Route | `/invoices/{id}` |
| Boards | `InvoiceDetail`, `DesktopInvoiceDetail` |

**Layout:** number + status, payable, buyer, issue time and issuer, recorded rate, layout version; rows with breakdown; actions «چاپ / ذخیره PDF», «کپی لینک ({n} مانده)», «ارسال پیامک», «فاکتور جایگزین»; SMS/link/verification status rows («لغو لینک»); history timeline (desktop); «ابطال فاکتور» (danger).

**Void sheet/dialog:** title «ابطال فاکتور {number}», explanation that it is kept as «باطل‌شده» and `/v` shows it, reason radio («اشتباه در وزن یا عیار», «انصراف مشتری», «ثبت تکراری», «دلیل دیگر…»), internal note, installment guard text, «تأیید ابطال» / «انصراف».

**States:** issued, voided (red band with date/reason, replacement link), replaced, linked to installments (void blocked until resolution choice), permission denied for void (button hidden, tooltip), link revoked.

## M-11 App navigation and shells

- **Mobile bottom tabs (5):** «فاکتور جدید» (`/invoices/new`), «فاکتورها», «مظنه», «ماشین‌حساب», «بیشتر» (`/settings`, which lists customers, settings, users, plan, SMS). Active tab gold; labels always visible; 56 px height + safe-area padding.
- **Desktop top nav (dark bar):** logo + shop name; items «فاکتور جدید», «فاکتورها», «مظنه», «ماشین‌حساب», «مشتریان و اقساط», «تنظیمات»; active pill.
- Settings on desktop use a right sidebar: «اطلاعات کسب‌وکار», «ظاهر فاکتور», «کاربران فروشگاه», «پلن و اعتبار», «خرید پیامک», «ورود سریع (Passkey)», «شماره ورود و بازیابی», «پیامک و یادآوری اقساط».

## M-12 Settings home (mobile «بیشتر»)

| | |
| --- | --- |
| Route | `/settings` |
| Boards | `Settings` (desktop equivalent is the sidebar in `DesktopSettings`) |

**Layout:** user header (name, role, login mobile); section «فروشگاه»: «مشتریان و اقساط», «اطلاعات کسب‌وکار» (badge «{n} مورد ناقص»), «ظاهر فاکتور», «کاربران فروشگاه»; section «پلن و اعتبار»: plan item (plan, end date, link/free SMS/credit summary) → M-15, «خرید پیامک» (balance ≈ segments, price per segment, minimum) → M-16; section «ورود و امنیت»: passkey card with device list and «افزودن دستگاه دیگر», login-number item; «خروج از حساب (پیش‌نویس‌ها حفظ می‌شوند)».

## M-13 Business info

| | |
| --- | --- |
| Route | `/settings/business` |
| Boards | `BusinessInfo`, `DesktopSettings` |

**Layout:** incomplete notice when needed («برای صدور اولین فاکتور، نام، موبایل و آدرس کسب‌وکار لازم است. فاکتور در حال ساخت شما ({n} ردیف) حفظ شده…»); required section (name*, business mobile* with «عمومی است…», landline optional with fallback rule, address*); collapsible optional section (website, social accounts with add/remove and network select, union licence, online business licence, logo upload with preview and rules «PNG یا JPG تا ۱ مگابایت، حداقل ۴۰۰×۴۰۰»; logo shown on invoices only on Basic/Pro); buttons «ذخیره و برگشت به فاکتور» (when arrived from issuing) and «فقط ذخیره».

**States:** incomplete, saving, saved, validation errors per field, logo too large/wrong type/too small, Free plan logo note.

## M-14 Invoice appearance editor (Basic/Pro; Free view-only)

| | |
| --- | --- |
| Route | `/settings/invoice-appearance` (chunk `layout-editor`, lazy) |
| Boards | `LayoutMobile`, `DesktopLayout` |
| Contract | `design/invoice-templates/invoice-layout.schema.json`, presets `simple_readable` / `shop` |

**Layout:** unsaved indicator; live preview (toggle «چاپ / موبایل», desktop also «دسکتاپ») with sample data and fixed QR upper-left; «چاپ نمونه» (prints sample, no quota); step 1 preset cards «ساده و خوانا» / «فروشگاهی»; step 2 per-block placement (header/footer + right/centre/left; no dragging) for logo (size small/medium/large), name, contact, address, website, socials, licences with show toggles; QR row locked «ثابت: بالا، سمت چپ»; «تنظیمات بیشتر» (text size, density, columns, signature box, public footer note, A4 orientation/margins on desktop); footer «برگشت یک مرحله», «لغو تغییرات», «ذخیره ظاهر».

**States:** Free (all controls disabled + upgrade note; preview of fixed layout), unsaved, saving, saved, conflict (version mismatch → reload/merge prompt), guard violation («اگر بلوکی روی QR یا مبلغ بیفتد، ذخیره … متوقف می‌شود») with highlight, reset to preset confirmation.

## M-15 Plans and credit

| | |
| --- | --- |
| Route | `/settings/plan` |
| Boards | `Plans`, `DesktopPlans` |

**Layout:** current status band (plan + period end, link quota meter, SMS credit «{balance} تومان ≈ {n} بخش», «پیامک رایگان امسال: {n} مانده از ۵», near-limit note); segmented «ماهانه / سالانه (ارزان‌تر)»; three plan cards (Free, Basic marked «پلن فعلی شما», Pro «پیشنهاد برای اقساط») with price, period, monthly equivalent and savings, **VAT line «+ ۱۰٪ مالیات بر ارزش افزوده · قابل پرداخت …»**, feature list with crossed-out unavailable items, action («تغییر به رایگان در پایان دوره», «تمدید سالانه پایه», «ارتقا به حرفه‌ای · پرداخت از درگاه بانک»); SMS top-up card (per-segment table by plan, amount chips, summary, «خرید پیامک · شارژ …» → M-16); footer note about payment and downgrade.

**Desktop:** sidebar + cards in a row + right confirmation panel «درخواست ارتقا به حرفه‌ای» with period, plan price, VAT 10%, **payable** (bold), start/end, carry-over note, «پرداخت از درگاه بانک و ارتقا», «انصراف».

**Data:** `GET /api/billing/offers`; `POST /api/billing/orders {product:'PLAN', plan, idempotency_key, return_to}` → redirect to gateway.

**States:** each current plan variant, pending order («پرداخت در حال بررسی است…»), downgrade scheduled, gateway down («درگاه پرداخت الان در دسترس نیست…»), mock mode label «حالت آزمایشی».

## M-16 Buy SMS (top-up)

| | |
| --- | --- |
| Route | `/settings/sms` |
| Boards | `SmsBuy`, `DesktopSmsBuy` |
| Entry points | Settings item «خرید پیامک»; Plans SMS card; Review SMS preview link «خرید پیامک بیشتر»; Issued «خرید پیامک»; M-19 «اعتبار پیامک تمام شد»; low-credit banner |

**Layout:** status band (plan and per-segment price, balance ≈ segments, free SMS this year, carry-over note); «مبلغ شارژ را انتخاب کنید» + «حداقل {min} · بدون مالیات»; amount tiles 100k/200k/300k/500k/1M (radio group; each shows «≈ {n} بخش»; on Free only ≥ 400k shown); dark summary: «مبلغ شارژ (اعتبار شما)», «مالیات بر ارزش افزوده ۱۰٪», **«قابل پرداخت»** large, ≈ segments, balance after top-up, 3-segment example; button «پرداخت {total} تومان از درگاه بانک»; hint about VAT and redirect; Free-plan note (850 toman, min 400k, expires end of month); link «قیمت هر بخش را با تغییر پلن کمتر کنید»; desktop adds «سوابق شارژ».

**States:** default selection 200k (400k on Free), each amount, creating order, gateway down, mock label, Free expiring balance banner («{balance} تومان شارژ پیامک تا پایان ماه معتبر است…»).

## M-17 مظنه (quote board)

| | |
| --- | --- |
| Route | `/mazneh` (chunk `rate`) |
| Boards | `Mazneh`, `DesktopMazneh` |

**Layout:** title + «آخرین دریافت {time} · به‌روزرسانی هر ۳ دقیقه» + freshness badge; dark card «طلای ۱۸ عیار (هر گرم)» with two tiles «خرید از شما» and «فروش به مشتری · مبنای فاکتور» (gold outline), each with change «+ ۰٫۳٪ از ۳ دقیقه قبل», spread line, unit line, button «شروع فاکتور با نرخ فروش»; bands for 24K, USD, global ounce (USD per ounce) with change arrows in neutral ink; «ماشین‌حساب طلایی» and «تلاش دوباره الان» buttons; explanatory footnote.

**States:** fresh, stale, offline (last values + «آفلاین»), error (keep values), missing buy price («در دسترس نیست», no spread), demo label.

## M-18 Golden Calculator

| | |
| --- | --- |
| Route | `/calculator` (chunk `composer`) |
| Boards | `Calculator`, `DesktopCalculator` |

**Layout:** title + «محاسبه فوری و محلی؛ چیزی ذخیره نمی‌شود» + «همه پلن‌ها»; rate input prefilled «از مظنه · فروش · {time}» (editable, note it doesn't affect anything else); weight, purity chips, wage %, profit %, discount toman; hints; dark result «مبلغ این قطعه» + breakdown; note about `GOLD_IR_V1`; «ساخت فاکتور با همین اعداد», «پاک‌کردن»; link to مظنه.

**States:** invalid input (result «—» and field error), offline with last labelled rate or manual rate, no Invoices module (create button hidden).

## M-19 Quota and credit notices (component `QuotaNotice`)

| | |
| --- | --- |
| Boards | `QuotaLimit` (4 cases), `DesktopQuotaLimit` |

Sheet (mobile) / dialog (desktop) structure, always in this order: icon + title, what is used and when it resets, green band of what still works, primary safe action, upgrade/top-up action with price «+ مالیات», dismiss («این ماه دیگر نشان نده» only for warnings).

1. Free 50 invoices used — at «فاکتور جدید»/Start: «۵۰ فاکتور این ماه صادر شد» … primary «محاسبه با ماشین‌حساب (بدون صدور)», upgrade «ارتقا به پایه · ماهانه ۷۹۰ هزار تومان + مالیات · فاکتور بیشتر».
2. Free 50 new customers — at «مشتری جدید»/save-customer: «۵۰ مشتری جدید این ماه ثبت شد», buyer can still be typed on the invoice; upgrade; «فعلاً نه، برگشت به فاکتور».
3. Near limit banner (2 left) — small band with badge «۲ فاکتور مانده» and «ارتقا» link.
4. SMS credit empty — at «صدور و ارسال پیامکی»: «اعتبار پیامک تمام شد», cost of this message, free SMS left, green band «فاکتور صادر می‌شود…», «خرید پیامک · از ۱۰۰ هزار تومان», «فقط صدور (بدون پیامک)».

Other triggers (`plans-pricing.json#quota_notice_triggers`): links exhausted, history restricted, installments Professional-only, SMS balance low/expiring. Never blocks printing issued invoices, مظنه, calculator or the QR.

## M-20 Customers list

| | |
| --- | --- |
| Route | `/customers` (chunk `customers`) |
| Boards | `Customers`, `DesktopCustomers` |

**Layout:** title + «{n} مشتری · {m} جدید این ماه · اقساط: حرفه‌ای»; search (name/mobile, debounced); «مشتری جدید (نام و موبایل کافی است)»; filters «همه / دارای مانده / سررسید گذشته»; cards with initial avatar, name, mobile, status line (overdue amount/date, next instalment, last invoice), balance; pagination; note «مشتری برای فروش ساده لازم نیست…». Desktop: list + detail split view.

**States:** empty, no results, quota exhausted on create (M-19 case 2), duplicate suggestion on create («این شماره قبلاً برای {name} ثبت شده»; never auto-merge), installments gated on Free/Basic.

## M-21 Customer detail and record payment

| | |
| --- | --- |
| Route | `/customers/{id}` |
| Boards | `CustomerDetail`, `DesktopCustomers` (right pane), `DesktopInstallments` (payment dialog) |

**Layout:** name, mobile, «ویرایش»; balance card «مانده کل اقساط», overdue count; tabs «اقساط / فاکتورها ({n})»; agreement card with instalment rows (date, amount, status «پرداخت شد / {n} روز گذشته / در انتظار»), reminder line + «توقف»; buttons «ثبت پرداخت», «قرارداد اقساط جدید».

**Record payment sheet:** explanation (no gateway; partial allowed), amount, method «نقدی / کارت‌خوان / کارت‌به‌کارت / سایر», date, reference, allocation preview («این مبلغ به قسط ۲ … نسبت داده می‌شود. مانده پس از ثبت: …»), desktop allocation choice «قدیمی‌ترین بدهی / انتخاب دستی», «ثبت پرداخت», note on reversal.

**States:** no agreements, overdue, fully paid, reversal confirmation, Free/Basic (installment actions show M-19 installments notice).

## M-22 New installment agreement (Pro)

| | |
| --- | --- |
| Route | `/customers/{id}/agreements/new` |
| Boards | `InstallmentNew`, `DesktopInstallments` |

**Layout:** numbered questions: ۰ for which sale (issued invoice picker or «مبلغ مستقل»), ۱ down payment received, ۲ count (2/3/4/6/12/other) and frequency (monthly/weekly), ۳ first due date (end-of-month rule text); live schedule preview table; total check «جمع اقساط + پیش‌پرداخت»; rounding note (remainder on last instalment); reminder toggle «۲ روز قبل از هر سررسید (هر پیامک ۱ بخش از اعتبار)»; «ثبت قرارداد اقساط», «انصراف»; note that recording an agreement is not receiving money.

**States:** invoice already has an active agreement (blocked), principal ≤ 0, invalid date, SMS credit low warning for reminders.

## M-23 Shop users and security

| | |
| --- | --- |
| Routes | `/settings/users`, `/settings/security` |
| Boards | `Users`, `DesktopSecurity` |

**Users:** count + pending invites; «دعوت کاربر» (mobile number, role); owner card (cannot be removed; «انتقال مالکیت با تأیید دوباره»); member cards with permission toggles «ساخت و صدور فاکتور», «ابطال و فاکتور جایگزین», «مشتریان و ثبت پرداخت», «تنظیمات و ظاهر فاکتور», «پلن و پرداخت»; pending invite with «ارسال دوباره دعوت» / «لغو دعوت».

**Security:** passkey device table (name, added, last used, rename, remove with confirmation and immediate effect), «افزودن این دستگاه»; login number change (two OTP steps: current then new); explanation that business mobile is separate.

**States:** invite sent, invite expired, last owner protection, removing own passkey on current device, change-number in progress.

## Affiliate panel «همکاری در فروش» (`/affiliate`) — built; full contract in `docs/AFFILIATE_PROGRAM.md`

**Entry:** Settings list item «همکاری در فروش» (code + فعال/متوقف badge), shown only when the logged-in person is an affiliate. Otherwise `/affiliate` is 404.

**Content:** dark hero with the code (large, LTR), one-line explanation including the buyer discount %, read-only referral link, «کپی لینک» and «اشتراک‌گذاری» (Web Share API, hidden when unsupported); stat tiles کل درآمد / در انتظار تأیید / قابل پرداخت / پرداخت‌شده / مشتریان معرفی‌شده; commission terms line; «خریداران شما» table (masked mobile `۰۹۱*****۵۶۷`, since, payments count, income); «ریز درآمد» list (masked mobile, date, product, %, amount, status badge); «واریزها».

**States:** paused (warning banner), no buyers yet (empty-row hint to share the link). Never show full buyer mobiles, shop names or purchase amounts.

**Plans page addition:** «کد تخفیف یا کد معرف» field + «اعمال». It reprices every plan card (adds a «تخفیف» row and updates VAT and payable) through `POST /api/billing/discount`, is prefilled from the referral link or an existing referral, and shows errors at the field.

## طلای دریافتی از مشتری (ردیف GOLD_IN) — `/invoices/{id}/items`

- انتخاب نوع ردیف سه‌گزینه‌ای است: «فروش طلا | طلای دریافتی | متفرقه».
- طلای دریافتی این فیلدها را دارد:
  - تراشه نوع (کهنه، سکه = عیار ۹۰۰ خودکار، آب‌شده، دیگر)
  - نام، وزن و عیار
  - کارت‌های نرخ (خرید بازار، فروش/معاوضه، دستی)
  - کسر ذوب (اختیاری)
  - شماره برگه عیارسنجی (فقط آب‌شده)
- مبلغ ردیف منفی نوشته می‌شود («از مبلغ فاکتور کم می‌شود»). نوار پایین «فروش · طلای دریافتی» و «قابل پرداخت» یا «مانده به نفع مشتری» را نشان می‌دهد.
- حالت‌ها:
  - فقط طلای دریافتی: هشدار و غیرفعال شدن «مرور».
  - نرخ خرید ناموجود: خطای فیلد نرخ.
  - کسر بیش از ۵۰٪: خطای فیلد.
- چاپ: ستون «وزن ۷۵۰» و دو کادر «تفکیک طلایی / تفکیک مبلغ». مرجع کامل: `docs/GOLD_RECEIVED_AND_DASHBOARD.md` §۵.

## داشبورد فروش — `/dashboard`

- تراشه‌های بازه، سوییچ «تومان | گرم طلا»، کاشی‌های اعداد، یک نمودار ستونی ساده با تب شاخص و جدول جایگزین.
- حالت‌ها:
  - بارگذاری اول با اعداد سرور.
  - خالی.
  - بازه قفل (پلن رایگان) با لینک به پلن.
  - خطای تاریخ بازه دلخواه.
  - آفلاین (toast).
- مرجع: `docs/GOLD_RECEIVED_AND_DASHBOARD.md` §۸–§۱۲.

## کاربران و دسترسی‌ها — `/settings/users` (فقط مالک)

افزودن همکار با موبایل + نقش آماده (کامل، فروشنده، صندوق‌دار، فقط قیمت، حسابدار) + چک‌لیست گروهی. حالت‌ها: پلن بدون `team.permissions_edit` (فقط پیام «دسترسی کامل»)، خالی (`PERMISSIONS_EMPTY`)، عضو دعوت‌شده، سقف تعداد کاربر. مرجع: `docs/TEAM_PERMISSIONS.md`.

## شماره‌گذاری فاکتور — `/settings/numbering`

پیش‌نمایش بزرگ «شماره فاکتور بعدی» و چهار روش آماده: سال-شماره، پیوسته، سال/ماه/شماره، و حرف + سال. «تنظیم دقیق» شامل پیشوند، سال، ماه، شروع دوباره، جداکننده و تعداد رقم است. «شروع از شماره» هم برای ادامه دفترچه کاغذی هست. حالت‌ها: ترکیب نامعتبر (هشدار زیر فیلد)، شماره شروع کمتر از شماره‌های صادرشده، و تعارض ذخیره هم‌زمان. مرجع: `docs/INVOICE_NUMBERING.md`.

## پشتیبان تنظیمات — `/settings/backups` (فقط مالک)

پشتیبان دستی با نام، و فهرست زمانی نسخه‌ها با «فرق با الان»، «مشاهده محتوا» و «بازگرداندن…» (چک‌لیست بخش‌ها + تأیید). حالت‌ها: پلن رایگان (پیام ارتقا)، بدون نسخه، نسخه برابر با الان (نشان «همین تنظیمات فعلی»، بدون دکمه بازگرداندن)، بخش انتخاب‌نشده. مرجع: `docs/SETTINGS_BACKUPS.md`.
