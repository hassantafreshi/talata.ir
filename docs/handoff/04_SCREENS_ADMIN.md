# Service admin console (provider) — version 1

Desktop-first (1280 px; must remain usable at 1024 px; mobile not required in v1). Area `/provider/*`, separate guard, chunk `provider`, never imported by merchant routes. Boards: `Provider`, `AdminTenants`, `AdminTenantDetail`, `AdminPlans`, `AdminPayments`, `AdminSms`, `AdminQuotes`, `AdminTax`, `AdminIntegrations`, `AdminStaff`, `AdminAudit`, `AdminSystem`.

## A-00 Shell, access and privacy rules

- Dark right sidebar (232 px): logo, «مدیریت سرویس · نسخه ۱», items with counters: «داشبورد», «فروشگاه‌ها ({n})», «پلن‌ها و قیمت‌ها», «پرداخت‌ها ({needs action})», «پیامک ({alerts})», «نرخ و مظنه», «قواعد مالیات», «اتصال‌ها», «کارکنان و دسترسی», «سوابق (audit)», «سلامت سیستم»; footer with staff name, role, «خروج».
- Page header: title, one-line description, actions, «داده نمونه» badge in non-production.
- **Staff auth:** mobile + OTP, then **mandatory passkey**; a staff user without passkey sees only the dashboard and a prompt to add one. Sessions shorter than merchant sessions (configurable, default 8 h), re-auth (passkey) for dangerous actions.
- **Roles** (A-10): مالک سرویس (owner), پشتیبانی (support), مالی (finance), عملیات فنی (ops). Every endpoint checks a permission, not a role name.
- **Privacy:** admin DTOs never contain merchant customers' names/mobiles, invoice item details or SMS bodies; recipient numbers are masked (`0912***0000`). No "log in as tenant" in v1. Viewing a tenant is itself audited (`tenant.viewed`).
- **Dangerous actions** (manual activation, credit adjustment, manual payment confirmation, emergency rate, suspend, publishing prices/tax rules, changing secrets): reason field required, confirmation dialog repeating the effect, passkey re-auth, audit entry, idempotency key.

## A-01 Dashboard — `/provider`

Tiles row 1: active tenants with plan split; revenue this month (with VAT; split plan / SMS / VAT); invoices issued today vs 7-day average and voids; new sign-ups this week and incomplete profiles. Tiles row 2 (status with icon + label, never colour alone): quote feed, SMS (24 h, unknowns), payment gateway mode, queues/schedulers. «هشدارهای باز» table (priority badge, text, age, action link) and «کارهای امروز» list (manual payment reviews, subscriptions ending in 7 days, free tenants at cap, expiring free SMS credit, support references). No charts in v1.

API: `GET /provider/api/dashboard`. States: loading skeleton, partial failure per tile («در دسترس نیست»), no alerts («هشداری باز نیست»).

## A-02 Tenants — `/provider/tenants`

Filter bar: search (shop name, business mobile, payment reference, verification token), plan, status (active/suspended/incomplete profile), quota (at cap/near cap), period end (7 days/expired); quick views with counts; table: shop (name + business mobile), plan·period, period end, invoices this month (used/limit, «—» unlimited), new customers this month, SMS credit (toman), status badge, «مشاهده»; pagination 25; CSV export (no customer PII).

API: `GET /provider/api/tenants?…`, `GET …/export.csv`.

## A-03 Tenant detail — `/provider/tenants/{id}`

Header: shop name, tenant id (short), sign-up date, owner name + login mobile; «تعلیق موقت» (danger; reason; reversible «رفع تعلیق»). Tabs: «خلاصه و پلن», «سهمیه‌ها», «اعتبار پیامک» (lots, ledger entries, expiries), «پرداخت‌ها», «قابلیت‌های ویژه» (feature overrides with expiry), «کاربران» (merchant staff list, no edit), «سوابق».

Summary tab: business profile (read-only, missing landline badge), current subscription (plan, period, source order link, usage meters, credit), plan history; **manual action panel** (segmented «پلن / اعتبار پیامک / قابلیت ویژه»):

- Plan: plan, period, start, computed end, price breakdown (plan price, VAT 10%, **received amount**), payment reference*, reason*, «ثبت با تأیید دوباره». Uses the same fulfilment service as online payments (`activated_by=PROVIDER`).
- SMS credit: amount (±), carries-over flag default by plan, reason*, reference.
- Feature override: capability key, value, expiry, reason*.

Recent events table (time, actor, event key, details) + link to filtered audit.

API: `GET /provider/api/tenants/{id}`, `POST …/manual-activation`, `POST …/sms-credit-adjustments`, `POST …/feature-overrides`, `POST …/suspend`, `POST …/unsuspend`.

## A-04 Plans and pricing — `/provider/plans`

Versioned commercial configuration: active version and effective date, draft version with change summary, effective-from date, «پیش‌نمایش صفحه پلن کاربر», «انتشار نسخه {n}». Sections: plan prices (monthly/yearly excl. VAT, computed with-VAT column, pending-confirmation badge for Basic monthly); limits and capabilities matrix (invoices/month, new customers/month, links/month, free SMS/year, history scope, customize, installments…); purchase VAT (rate %, rounding, prices-exclude-VAT flag); SMS credit (per-segment price, minimum purchase, carry-over per plan; allowed packs; behaviour on plan change); proration policy (pending owner decision).

Validation: no negatives; packs ≥ minimum for that plan; Free minimum shown packs filtered; caps integer or unlimited. Publishing never affects open orders (price snapshotted) or issued invoices.

API: `GET /provider/api/pricing/versions`, `PUT …/draft`, `POST …/publish {effective_from}`.

## A-05 Payments — `/provider/payments`

Tiles: completed today (count + amount), pending verification, failed/cancelled today with top reason, needs manual review. Segmented filter: all / needs action / completed / failed / plan / SMS. Table: status badge, reference (LTR, nowrap), tenant, product, order amount (with VAT), bank-verified amount, time. Selected-order detail card: tenant link, product, base/VAT/total, gateway authority, callback info, reconcile attempts and next run; actions «استعلام دوباره از بانک», «نمایش پاسخ خام (پوشانده)», finance-only «ثبت تأیید دستی…» (bank tracking number + reason; runs the normal idempotent fulfilment) and «علامت ناموفق…»; note that refunds happen outside the app and are recorded here. Monthly finance export (CSV: date, reference, tenant, product, base, VAT, total, status).

API: `GET /provider/api/payments?…`, `GET …/{id}`, `POST …/{id}/inquire`, `POST …/{id}/manual-confirm`, `POST …/{id}/mark-failed`, `GET …/export.csv?month=`.

## A-06 SMS — `/provider/sms`

Tiles: sent 24 h (invoice/reminder split), delivered, unknown > 30 min, OTP today vs daily budget. Table (needs action / queued / all): status, tenant, type, segments, cost (toman or «رایگان سالانه»), attempts, time, «استعلام». Settings cards: segment rules (Unicode single/multi, Latin single/multi; defaults to verify with the chosen provider), OTP budget and per-number limit with 80% alert, default invoice SMS template (`{shop_name}`, protected `{invoice_link}`; Free uses it fixed). Recipients masked; resend is merchant-only.

API: `GET /provider/api/sms/messages?…`, `POST …/{id}/inquire`, `PUT /provider/api/sms/settings`.

## A-07 Quotes and مظنه — `/provider/quotes`

Asset status table (asset label + code, value, unit, change vs previous, freshness badge, source) for `GOLD_18_BUY`, `GOLD_18_SELL`, `GOLD_24`, `USD_IRR`, `XAU_USD`; last fetches log (time, result, duration, note); thresholds (fetch interval 180 s **read-only**, stale after N minutes, request timeout, alert after N consecutive failures); **emergency global rate** card (18K sell value, validity 30 min / 1 h / until cancelled, reason*; merchants see «نرخ اعلامی طلاتا (دستی)»; issued invoices unaffected; shops can still use their own manual rate).

API: `GET /provider/api/quotes/status`, `PUT …/thresholds`, `POST …/emergency-rate`, `DELETE …/emergency-rate`.

## A-08 Tax rules — `/provider/tax-rules`

Table of versioned rules: category (GOLD_SERVICES, MISC, reserved SILVER/COIN/MELTED_GOLD), id/version, taxable base, rate, from/to, status (active/scheduled/disabled), invoices using it. New-version form (rate, effective from, base, rounding policy, legal reference/note*). Active versions are immutable after their start date. Separate read-only card for purchase VAT on subscriptions/SMS (link to A-04). All rules labelled «نمونه» until a tax expert confirms.

API: `GET /provider/api/tax-rules`, `POST …` (new version), `POST …/{id}/disable` (only future-dated).

## A-09 Integrations — `/provider/integrations`

Three adapter cards with status badge: payment gateway (adapter, real PSP pending, callback path, order expiry, refund-hours display text; «سناریوی آزمایشی…» for Mock; «افزودن درگاه»), SMS provider (adapter, sender line masked, API key «تنظیم‌شده», webhook signature status, last error; «ارسال آزمایشی به شماره من» charged to operating budget), quote provider (main and ounce adapters, asset mapping n/5, key, live/not-live). Public links card: domain for `/i` and `/v` (changing it must keep old domain redirects; printed QRs never break). Secrets are write-only (never returned after save) and encrypted at rest.

API: `GET /provider/api/integrations`, `PUT …/{kind}`, `POST …/{kind}/test`.

## A-10 Staff and roles — `/provider/staff`

Staff table (name + mobile, role, passkey status/devices, last login, edit/remove); «دعوت همکار». Permission matrix (rows): dashboard & health; view tenants; manual plan activation / credit adjustment; manual payment confirmation; edit plans & prices; tax rules; integrations & secrets; emergency rate; suspend tenant (support with owner approval); staff & roles. Columns: owner, support, finance, ops. Removing staff revokes sessions immediately.

API: `GET/POST /provider/api/staff`, `PUT …/{id}`, `DELETE …/{id}`.

## A-11 Audit — `/provider/audit`

Filters: date range, actor type (staff/system/merchant users), event category (plans & subscription, payments, SMS credit, prices & tax, quotes, access, invoices), tenant search. Table: time, actor, event key (LTR), target, details, trace id. Pagination 50, CSV export. Append-only; never stores OTP codes, tokens, keys or full recipient numbers.

API: `GET /provider/api/audit?…`, `GET …/export.csv`.

## A-12 System health — `/provider/system`

Tiles: queue lag, failed jobs, outbox pending, last backup + restore-drill age (warn after 30 days). Scheduler table: job, cadence, last run, duration, result, next run — central quote fetch (180 s), payment reconcile (1 min), abandoned-order expiry (5 min), SMS unknown reconcile (1 min), free-credit expiry + warning (daily), instalment reminders (daily), database backup (daily). Failed jobs card (id, short error, «اجرای دوباره», idempotent). Version/environment card (app version, environment badge, last deploy, pending migrations).

API: `GET /provider/api/system`, `POST /provider/api/system/failed-jobs/{id}/retry`.

## Affiliates «همکاری در فروش» (`/admin/affiliates`, `/admin/affiliates/{id}`) — built; contract in `docs/AFFILIATE_PROGRAM.md`

**List:** «همکار جدید» form (admin role) with these fields:
- mobile — must already have an active shop panel
- commission % (0–50)
- mode: فقط پرداخت اول / مادام‌العمر
- buyer discount % (first plan purchase)
- optional code — blank means auto `TL…`
- «کمیسیون روی خرید اعتبار پیامک»
- internal note

Below the form: search by code or mobile, and a table with affiliate, code, terms, buyers, pending, payable and paid amounts, and status.

**Detail:**
- Code and referral link to hand to the person.
- Stat tiles.
- Editable terms: changes apply to future payments only.
- «ثبت واریز» with bank reference: pays all payable commissions.
- Referred shops: shop, full mobile, link/code, date.
- Commissions: order ref, pre-VAT base, %, amount, status, «لغو» with a reason.
- Payout history.

Support role: read-only.
