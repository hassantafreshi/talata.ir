# Build sequence — task cards for the coding model

Give the cards **in order**. Each card is self-contained: paste it as the user message after the system prompt. Screen IDs (M-xx, P-xx, A-xx) refer to `02`, `03` and `04`. "Board" names refer to `docs/design/reference-html/<Board>.html` and `docs/design/proposed/screenshots/<Board>.png`.

The order is the fastest path to a working vertical slice (log in → rate → items → issue → print/verify), then money flows (SMS, payments), then Professional features, then the admin console, then hardening.

---

## T00 — Skeleton, tooling, tokens, fonts

- **Read:** `IMPLEMENTATION_GUIDE.md` §2, §6; `PERFORMANCE_BUDGET.md`; `design/tokens/*`; `05_STATES_AND_PATTERNS.md` §1–3.
- **Build:** Laravel app with modules layout (`app/Core`, `app/Modules/*`), PostgreSQL + Redis via docker-compose, Vue 3 + TS + Inertia + Vite with per-route lazy chunks, Pest, Playwright, ESLint/Prettier, PHP-CS-Fixer/Pint, GitHub Actions CI (lint, unit, feature, build, bundle-size report). Copy `talata-tokens.css` and the Vazirmatn subsets; `resources/js/i18n/fa.ts` with the copy keys from `UI_BUILD_SPEC.md` §8; Persian digit formatting helpers; app shells (`MerchantMobileShell`, `MerchantDesktopShell`, `PublicShell`, `AdminShell`) with empty pages.
- **Done when:** `docker compose up` + one command boots the app; CI green; a blank merchant page renders RTL with the self-hosted font and no external requests; bundle report prints per-route sizes.

## T01 — Core primitives, tenancy, audit, outbox

- **Read:** master prompt §4–6, §16; `IMPLEMENTATION_GUIDE.md` §3.
- **Build:** `Money` (IRR integer, toman display), `Weight` (6 dp), `Purity` (ppt), `Percent`, `Clock` (fakeable); `tenants`, `memberships`, tenant context middleware, global scopes and composite keys; append-only `audit_events`; transactional `outbox_events` + dispatcher; structured error format `{code, message_fa, trace_id}`.
- **Done when:** cross-tenant read/write/FK attack tests fail closed for a sample model; audit rows cannot be updated or deleted; outbox survives a crash between commit and dispatch (test).

## T02 — Login, OTP, Passkey (M-01, M-02, M-03, M-24 desktop)

- **Read:** master prompt §5; `02` M-01..M-03; boards `Login`, `Otp`, `Passkey`, `DesktopLogin`.
- **Build:** OTP request/verify with rate limits and mock SMS adapter (codes logged only in local env); registration on first verify (tenant + owner membership); session; optional WebAuthn registration/login with server-side verification (choose a maintained PHP WebAuthn library via ADR); SMS recovery always available.
- **Done when:** all states in M-01..M-03 render; brute-force/expiry/reuse tests; no duplicate tenant on retry; Passkey offer shows once; virtual-authenticator Playwright test.

## T03 — Plans, capabilities, quotas, entitlements

- **Read:** `PLANS_AND_QUOTAS.md`; `design/contracts/plans-pricing.json`; master prompt §11.
- **Build:** seed plans/features/quotas from the JSON (versioned config); `Entitlements::can()` / `quota()`; monthly counters (tenant timezone, half-open intervals) for issued invoices, new customers, share links; yearly free-SMS allowance; `GET /api/entitlements`.
- **Done when:** Free = 50 invoices/50 new customers/10 links per month, 5 free SMS per year, current-month history only; Basic caps from config (seed 500/500/100); Pro unlimited; boundary tests with a frozen clock; no plan-name branching (grep test).

## T04 — Market prices, rate & Start, مظنه (M-04, M-05, M-17)

- **Read:** master prompt §8; `MAZNEH_AND_CALCULATOR.md`; `02` M-04, M-05, M-17; boards `Main`, `RateStates`, `DesktopRate`, `Mazneh`, `DesktopMazneh`.
- **Build:** quote adapters (mock), single-flight central fetch every 180 s, `market_quotes`, freshness, `GET /api/quotes/latest`, `GET /api/quotes/board`; tenant manual rate; client poll that pauses when hidden/offline; rate page with Start (creates draft with accepted rate, handles changed-rate acceptance).
- **Done when:** fake-clock tests for 3 ticks and failed fetch; all 6 rate states (fresh, stale, changed, manual, offline, error); مظنه shows 5 assets with missing-buy-price handling; Start never uses an unseen rate.

## T05 — Pricing engine and Golden Calculator (M-18)

- **Read:** master prompt §7; `design/contracts/calculation-vectors.json`; `MAZNEH_AND_CALCULATOR.md` §2; boards `Calculator`, `DesktopCalculator`.
- **Build:** `GOLD_IR_V1` and `MANUAL_LINE_V1` policies in a policy registry; tax rules by category and date; identical TS preview; calculator page.
- **Done when:** every vector passes in PHP and TS with identical outputs; calculator never saves or consumes quota; "ساخت فاکتور با همین اعداد" creates a draft when Invoices is enabled.

## T06 — Business profile and settings shell (M-12, M-13)

- **Read:** `INVOICE_CUSTOMIZATION.md`; `02` M-12, M-13; boards `Settings`, `BusinessInfo`, `DesktopSettings`.
- **Build:** shop profile with required (name, business mobile, address) and optional fields, logo upload (validated raster, size/dimension limits), issuance guard, settings list (mobile) and sidebar (desktop).
- **Done when:** issuing without required fields is blocked server-side with the «ذخیره و برگشت به فاکتور» flow preserving the draft; landline/mobile fallback rule tested.

## T07 — Draft items (M-06)

- **Read:** master prompt §7 row types, §9; `02` M-06; boards `Items`, `DesktopItems`.
- **Build:** drafts with repeatable GOLD/MISC rows, sticky «افزودن ردیف» at the top (mobile), purity selector (18K default), per-row name/description, undoable delete, autosave with "ذخیره شد" indicator, "نرخ تازه" chip with explicit «استفاده از نرخ جدید».
- **Done when:** row-type switching, MISC never in gold tax base, independent purity recalculation, autosave offline/online tests, Playwright for add/remove/undo.

## T08 — Review and issue (M-07, M-08)

- **Read:** master prompt §9; `INVOICE_DELIVERY_AND_VERIFICATION.md`; `02` M-07, M-08; boards `Review`, `Issued`, `DesktopReview`, `DesktopIssued`.
- **Build:** review fingerprint, buyer name/mobile (normalize Persian/Latin digits, 09/+98), «صدور و ارسال پیامکی» / «فقط صدور», idempotent issue with numbering counter, immutable snapshot, verification token, share link creation (quota), SMS intent to outbox; issued screen with statuses.
- **Done when:** double tap / network loss yields one invoice; quota and SMS failures never roll back issuance; snapshot contains everything listed in master prompt §6.

## T09 — Print, presets and layout editor (P-03, M-14)

- **Read:** `INVOICE_CUSTOMIZATION.md`; `design/invoice-templates/*`; `03` P-03; `02` M-14; boards `Print`, `PrintShop`, `LayoutMobile`, `DesktopLayout`.
- **Build:** server-rendered print HTML for both presets from the snapshot + layout version, QR fixed upper-left, A4 CSS; layout editor (Basic/Pro) with live preview, guarded options, undo/reset, conflict handling; Free fixed layout.
- **Done when:** schema validation rejects invalid layouts; later layout edits do not change issued invoices; print screenshot tests for both presets, long names, multi-page.

## T10 — Public invoice and verification (P-01, P-02)

- **Read:** `INVOICE_DELIVERY_AND_VERIFICATION.md`; `03` P-01, P-02; boards `PublicInvoice`, `Verify`, `DesktopPublicInvoice`.
- **Build:** `/i/{token}` and `/v/{token}` Blade pages, minimal DTOs, states (valid, voided, replaced, invalid/revoked), noindex/no-store, ≤ 20 KiB JS.
- **Done when:** no buyer PII on `/v`; masked mobile on `/i`; status changes reflected; invalid tokens show no green mark.

## T11 — Invoice list, detail, void and replacement (M-09, M-10)

- **Read:** `02` M-09, M-10; boards `InvoiceList`, `InvoiceDetail`, `DesktopInvoiceList`, `DesktopInvoiceDetail`.
- **Build:** list with filters/search/pagination (25), Free current-month restriction notice, detail with snapshot render, actions (print, copy link, send SMS, replacement), void with reason and installment guard, history.
- **Done when:** Free cannot fetch older months via API either; void keeps the record and updates `/v`.

## T12 — SMS module and credit ledger

- **Read:** master prompt §12; `PAYMENTS_AND_SMS_CREDIT.md` §6; `PLANS_AND_QUOTAS.md` §3.
- **Build:** templates, segment estimator, outbox sender, delivery states and webhooks (mock), free yearly allowance, `sms_credit_lots`/`sms_credit_entries`, reserve/capture/release/expire, per-segment price by plan at send time, resend action, Free month-end expiry job.
- **Done when:** 3-segment Basic message debits 1,500 toman; unknown stays reserved until reconcile; expiry with fake clock; OTP never debits tenant credit.

## T13 — Billing: plans page, SMS purchase, bank return (M-15, M-16, P-04, P-05)

- **Read:** `PAYMENTS_AND_SMS_CREDIT.md` (all); `02` M-15, M-16; `03` P-04, P-05; boards `Plans`, `DesktopPlans`, `SmsBuy`, `DesktopSmsBuy`, `PayReturnPlan`, `PayReturnSms`, desktop variants.
- **Build:** offers API, order creation with VAT, `MockGateway` with scenarios, callback/verify/fulfil/reconcile, result page (3 states), receipt, `return_to` handling, entry points listed in M-16.
- **Done when:** all tests in `PAYMENTS_AND_SMS_CREDIT.md` §11 pass, including duplicate/concurrent callbacks and amount mismatch.

## T14 — Quota notices everywhere (M-19)

- **Read:** `PLANS_AND_QUOTAS.md` §4; `02` M-19; boards `QuotaLimit`, `DesktopQuotaLimit`.
- **Build:** `QuotaNotice` component and triggers at new invoice/Start, new customer, review SMS, share link, invoice list history, SMS credit empty/low/expiring.
- **Done when:** each trigger appears only from server entitlements; printing issued invoices, مظنه and calculator never blocked.

## T15 — Customers (all plans) and installments (Pro) (M-20, M-21, M-22)

- **Read:** master prompt §13; `02` M-20..M-22; boards `Customers`, `CustomerDetail`, `InstallmentNew`, `DesktopCustomers`, `DesktopInstallments`.
- **Build:** customers with monthly new-customer quota, search, duplicates suggestion; agreements (invoice-linked or standalone), schedule preview with remainder on last installment, payments (partial, reversal), overdue, reminders (consent, quiet hours, credit).
- **Done when:** installment tests in master prompt §18; Free/Basic see installment gating notice; downgrade keeps repayment recording.

## T16 — Shop users and security (M-23)

- **Read:** `02` M-23; boards `Users`, `DesktopSecurity`.
- **Build:** invite by mobile, roles/permissions (issue, void/replace, customers/payments, settings, plan/payments), owner transfer with double confirmation, passkey device list/rename/remove, login-number change with two OTPs.
- **Done when:** permission tests on every write endpoint; removing a passkey disables it immediately.

## T17 — Admin console shell, staff auth, dashboard (A-01, A-10)

- **Read:** `04` A-00, A-01, A-10; boards `Provider`, `AdminStaff`.
- **Build:** separate `/provider` area and guard, staff accounts (mobile OTP + mandatory passkey), roles from A-10, dashboard tiles and alerts from real aggregates.
- **Done when:** merchant sessions cannot reach `/provider`; staff without passkey see only the dashboard; no customer PII anywhere in admin DTOs (test).

## T18 — Admin tenants and manual actions (A-02, A-03)

- **Build:** tenant list filters/quick views/CSV, tenant detail tabs, manual plan activation/extension and SMS credit adjustment with VAT breakdown, reason, double confirmation and audit; suspend/unsuspend.
- **Done when:** manual actions use the same fulfilment services as payments; audit entries complete; permissions enforced.

## T19 — Admin plans & pricing editor (A-04)

- **Build:** versioned pricing/quotas/capabilities/VAT/SMS-credit config with draft → scheduled publish, preview of merchant plans page, validation (no negative, min ≤ packs, etc.).
- **Done when:** publishing a new version never changes open orders or issued invoices; merchants see the new version from its effective date.

## T20 — Admin payments, SMS, quotes, tax, integrations, audit, system (A-05..A-09, A-11, A-12)

- **Build:** each screen in `04` with its actions; reconcile/inquire, manual confirm (finance role) through the normal fulfilment path; SMS unknown-status reconcile; quote thresholds and emergency global rate; versioned tax rules; adapter configuration with encrypted secrets and test actions; audit search/export; jobs/health/backups view.
- **Done when:** every action is permission-checked, audited and idempotent; secrets never returned after save.

## T21 — v2-ready infrastructure check

- **Read:** `ROADMAP_V2_BUSINESS_TYPES.md` §3, §6.
- **Done when:** business-type registry, pricing-policy registry, per-category tax rules, `item_attributes`, `direction`, `invoice_item_assets` exist and the structural test adding a fake policy passes without schema changes; no v2 UI exposed.

## T22 — Hardening and release

- **Read:** `PERFORMANCE_BUDGET.md`; master prompt §14, §16, §18; `07_QA_CHECKLIST.md`.
- **Build:** PWA/offline for drafts, chunk retry, performance measurements on slow profiles, accessibility pass, RTL screenshots at 360/390/768/1280 for every screen state, backups and restore drill doc, runbook.
- **Done when:** every item in `07_QA_CHECKLIST.md` is ticked with evidence or listed as a known limitation.
