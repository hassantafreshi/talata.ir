# Phase 1 implementation and acceptance checklist

Status (2026-10-06): merchant web app and service admin console v1 implemented in this repository (Laravel 13 + PostgreSQL, Blade + AJAX); 144 PHP feature/unit tests (1,549 assertions) and 23 JS parity tests pass locally. Kavenegar SMS and ZarinPal adapters exist and are tested against faked HTTP only (no live send/payment yet). Visual direction: working defaults (palette midnight_gold, Vazirmatn, logo v2) used for production UI at the owner's instruction; formal approval record still open. Check boxes require evidence, not a plan or a mock screenshot.

## Planning state

- [x] Phase 1 master implementation prompt written.
- [x] Independent UX discovery prompt written.
- [x] Owner-confirmed mobile-number sign-in/registration and SMS OTP flow documented explicitly.
- [x] Optional Passkey/fingerprint-or-device-unlock enrollment after initial verified mobile login documented.
- [x] Owner clarification documented: repeatable GOLD/MISC rows, editable 18K-default purity, manual miscellaneous price and per-row product name/description.
- [x] Primary-source UI/design-tool shortlist prepared.
- [x] Owner priority documented: low-bandwidth loading, production asset budgets and weak-network acceptance profiles.
- [x] Owner-required 180-second gold refresh and large-price/Start invoice entry documented.
- [x] Rapid UI execution prompt written with a wireframe/visual-identity approval stage before detailed implementation.
- [x] Upper-left printed QR, secure mobile verification and responsive invoice contract documented.
- [x] Customer mobile at issue, explicit issue-and-SMS/only-issue and independent delivery recovery documented.
- [x] Required business profile, landline/mobile fallback, optional public fields and two-preset customization for Basic AND Professional documented; obsolete Professional-only rule removed.
- [ ] Owner selects design skill and component toolkit.
- [x] Overall wireframe/visual proposal shown (`docs/design/`, Stage A package, 2026-10-05).
- [ ] Colors/logo/font/toolkit explicitly approved by owner (see `docs/design/STAGE_A_REVIEW_REQUEST.md`).
- [x] Owner plan prices, quotas and SMS credit, مظنه/calculator contract and v2 business-type roadmap documented (2026-10-05); reflected in the draft boards.
- [x] Quotas enforced server-side (Free: 50 invoices and 50 new customers per month, current-month history, 5 free SMS per year; Basic configurable caps; Professional unlimited) with friendly notices.
- [x] ChatGPT handoff package (`docs/handoff/`), standalone reference HTML for all 68 boards and v1 service-admin boards (12 screens) prepared (2026-10-05).
- [x] Service admin console v1 (built at `/admin`, not `/provider`; see `docs/handoff/04_SCREENS_ADMIN.md` «وضعیت پیاده‌سازی»): staff OTP + mandatory passkey (configurable), permission-based roles, step-up re-auth, dashboard with alerts and today's tasks, tenants with filters/CSV and manual actions (plan activation, SMS credit ±, feature overrides, suspension), versioned pricing, payments (inquire, manual confirm, mark failed, monthly CSV), SMS operations, quotes + emergency 18K rate, versioned tax rules, integrations (read-only), staff, audit (activity log), system health. Every dangerous action: permission + recent sign-in + reason + idempotency key + audit.
- [x] Build package documented: implementation guide, payments/SMS credit contract, SMS purchase and bank-return boards (2026-10-05).
- [x] 10% VAT on plan and SMS-credit purchases: configurable rate snapshotted per order, base/VAT/payable shown separately, credit equals pre-VAT amount.
- [x] Billing module with MockGateway: plan purchase, SMS top-up, server-sourced result page, reconcile, receipts; real PSP after owner selection.
- [x] Prepaid SMS credit: toman balance, per-segment pricing by plan, reserve/refund via outbox, Free monthly expiry with audit.
- [x] مظنه board and ماشین‌حساب طلایی on the shared 180-second quote cadence and GOLD_IR_V1 preview, no quota use.
- [x] v2-ready infrastructure: business-type registry, pricing-policy registry, per-category tax rules, typed item attributes, sale/purchase direction, item asset table; Phase 1 behaviour unchanged.
- [x] Design tokens, invoice layout schema v1 with Simple/Shop presets, sample snapshot, reference renders, adapter/calculation contracts and the UI build spec saved under `docs/design/` (proposed, 2026-10-05).
- [ ] Approved design decisions recorded and detailed UI contract finalized.
- [x] Owner requests implementation work (2026-10-06: complete merchant web app, AJAX, hardened against intrusion and SMS abuse; Jalali date picker).

## Implementation progress (2026-10-06)

Evidence: `php artisan test` (254 tests / 2454 assertions on PostgreSQL, 2026-10-07), `npm run test:js`, Playwright mobile journey with screenshots in `docs/screenshots/app/`. Security controls and fixed findings: `docs/SECURITY.md`. Frontend decision: `docs/adr/0001-blade-ajax-frontend.md`.

- [x] Mobile OTP login with proof-of-work, layered limits, global budget, lockout (Passkey not yet).
- [x] Tenancy fail-closed scope, ULID public ids, per-route permissions, member removal kills sessions.
- [x] Invoice composer (GOLD/MISC rows, autosave with versioning, local BigInt preview), review, idempotent issue with ISSUE_AND_SMS / ISSUE_ONLY, snapshot, numbering, void/replace.
- [x] A4 print with upper-left QR, public `/v/{token}` (no buyer PII) and `/i/{token}`, tokens hashed + encrypted.
- [x] SMS outbox, credit ledger, all abuse caps (see SECURITY.md), installment reminders max two per installment.
- [x] Customers, Pro-only installments with Jalali schedule, payments with idempotency and overpayment guard.
- [x] Settings: business profile (phishing-safe name), logo re-encode, appearance editor with live A4/mobile preview, SMS template, users.
- [x] Plans + SMS credit purchase with VAT shown separately, MockGateway, bank-return page, receipt.
- [x] Mobile-friendly Jalali date picker (year/month grids, swipe, quick chips, typed date).
- [x] Kavenegar SMS adapter (send, Verify Lookup OTP, delivery status, error mapping, key redaction); tested with a faked API — live send needs the owner's key/line/template.
- [x] Passkey (fingerprint/face/device-lock) sign-in for merchants and staff; SMS login stays as recovery.
- [x] Team: members have full access by default; per-permission restriction only on Basic/Pro (`team.permissions_edit`).
- [x] Activity log per user/shop/service and technical log per service, visible only in the admin console `/admin` (staff guard, OTP/passkey, idle timeout, roles).
- [x] Settings backups (docs/SETTINGS_BACKUPS.md): last 50 settings states per shop on Basic/Pro, manual backup, per-section restore by owner or admin with an undo backup, audited.
- [x] Configurable invoice numbering (docs/INVOICE_NUMBERING.md): presets, prefix, year/month parts, yearly/monthly/never reset, start number, unique per shop, never reused, LRM-safe display.
- [x] Admin price editor (docs/ADMIN_PRICING.md): Basic/Pro monthly+yearly and per-plan SMS segment price publish a new pricing version (never in place), >50% typo guard, restore, audit; paid/pending orders keep their amounts.
- [x] Screen-level team permissions (docs/TEAM_PERMISSIONS.md): chosen when adding a member, presets, dependencies, filtered menu, /home redirect, seller sees own invoices only.
- [x] Sales dashboard (docs/GOLD_RECEIVED_AND_DASHBOARD.md §8–§12): sales, wage, profit, gold received (toman or 750-grams) and VAT; today/week/Jalali month/3 months/year/custom with simple SVG bar chart; Free = sales, wage, gold received for day/week/month (owner decision, capability dashboard.view), reports.financial unlocks the rest; reports.view team permission.
- [x] Weight settlement and third template «حساب طلا و ریال (بد/بس)» (docs/GOLD_RECEIVED_AND_DASHBOARD.md §13): gold-for-gold sale and received rows, melted-gold sale with assay slip, per-row value + بد/بس for gold (750-g) and money, «مانده سند» row with date/time; shared vectors.
- [x] Gold received from the customer (docs/GOLD_RECEIVED_AND_DASHBOARD.md): GOLD_IN rows (old gold, coin, melted + assay ref), GOLD_IN_V1 with buy/sell/manual rate and melting deduction, payable = sales − gold received (customer credit when negative), 750-weight column and gold/money split on print, shared PHP/JS vectors.
- [x] Affiliate program (docs/AFFILIATE_PROGRAM.md): admin enrolment per mobile, code + referral link, buyer discount, % commission first-payment or lifetime, hold → payable → paid, masked affiliate panel.
- [x] Real PSP adapter: ZarinPal v4 (`ZarinpalGateway`) behind a PSP registry — switching PSP is one class + one config line; in-flight payments stay with their own gateway; payer-never-returned recovery; faked-HTTP tests. Live sandbox/production payment not yet performed (official docs host blocked from the build environment).
- [x] Rest of the service admin console (manual tenant actions, SMS/payment operations, quotes, tax rules, staff, system health) — tests in `tests/Feature/AdminOpsTest.php`, screenshots 53–64.
- [x] UI review pass (P1/P2 and cheap P3): composer keeps unsaved edits on the device and blocks review until saved, persistent unknown-issuance retry, QR panel + verify link, CSS-only public invoice/verify pages, inline load/order errors with retry, 44px touch targets and focus rings, Persian validation messages, error pages with a reference code, calculator «پاک‌کردن», remembered dashboard range, SMS packs default to 200k (or the plan minimum) with «≈ N پیامک» and an exact «پرداخت … تومان» button, yearly plans show the monthly equivalent and saving, confirmation sheet before the bank, banner for an unresolved earlier payment. Browser-checked at 360px (screenshots 65–69).
- [x] Integration and measurement tooling ready (no live runs, by owner decision 2026-10-11): ZarinPal and Kavenegar switch on by env only (`docs/DEPLOYMENT.md` §۷–§۸, separate number-change template); `talata:preflight --live` checks the Kavenegar key via `account/info` (no SMS), ZarinPal reachability (no payment created) and the scheduler heartbeat (`tests/Feature/PreflightTest.php`); `npm run perf:bundle` budget gate (all routes within budget; fonts 78.9/80 KiB) and `npm run perf:measure` for production profile A/B journeys with traces (`docs/PERFORMANCE_BUDGET.md`); login trimmed to 8 requests; pen-test scope and logistics in `docs/PENTEST_READINESS.md`.
- [ ] Field performance measurement on throttled networks (run `npm run perf:measure` against production). Lab only so far (Playwright throttling against the dev server without gzip — not production): login 1.4 s / calculator 1.5 s / public invoice 1.8 s on profile A, login 4.6 s / public invoice 5.5 s on profile B; gzip sizes app.js 4 KB, CSS 7.3 KB, fonts 78.9 KiB. Must be re-measured on the production host with retained traces.
- [x] Login-number change (M-23): two OTP steps (current number, then new), atomic swap, other devices signed out, audited (`tests/Feature/MobileChangeTest.php`).
- [x] Fingerprint-login offer once after an SMS sign-in, where the device supports it and it was not dismissed (`tests/Feature/PasskeyOfferTest.php`).
- [x] Invoice-list filters «این ماه» and «اقساطی», combinable with the status chips, plan-gated (`tests/Feature/InvoiceFlowTest.php`).
- [x] Customer list shows and filters by outstanding installment balance (همه / مانده قسط دارند / تسویه‌شده), Professional-only (`tests/Feature/CustomerBalanceTest.php`).
- [x] Over-quota sheet adapts its title, reassurance and actions to the limit that was hit (`tests/js/quota-panel.test.mjs`).
- [x] Security revocation of an invoice's QR verification code (docs/INVOICE_DELIVERY_AND_VERIFICATION.md): separate action for members who may void, reason required, audited `invoice.verification_revoked`; the merchant is told that every sheet printed so far will read «لغوشده» and to print a fresh one; the invoice itself is unchanged (`tests/Feature/PublicPagesTest.php`).
- [x] Shop names (owner request 2026-10-07: «اسم مغازه هر چیزی می‌تواند باشد»): any real name is accepted («آفتاب»، «عدالت»، «ثنا»، «سپه»، «دولت‌آبادی»…); only links/phone numbers and names that present the sender as a bank, government/judicial body, Shaparak, a mobile operator or Zarlio are refused, after normalising spaces/half-spaces/diacritics/Arabic letters/Latin; staff can approve a genuine exception (`tenants.manage`, reason, idempotency, audit) (`SmsAbuseTest::test_ordinary_shop_names_are_not_mistaken_for_impersonation`, `AdminOpsTest::test_staff_can_approve_a_real_shop_name_that_reads_like_an_authority`).
- [x] Automatic invoice SMS after issuance with a per-shop switch (default on), «صدور بدون پیامک» for one invoice, never blocks issuance; issued page «اشتراک‌گذاری» (native share sheet, copy fallback) and «ارسال پیامک» sheet: resend to customer (never in flight/unknown, confirmation after delivery) and up to 3 other numbers parsed from any +98/0098/98/09/9 form, glued or separated (`InvoiceSmsDeliveryTest`, `tests/Unit/MobileExtractTest.php` + `tests/js/mobiles.test.mjs` on shared vectors; browser-checked, screenshots 76–81).
- [x] Owner approvals 2026-10-07: palette 1, Vazirmatn, refined two-ingot Z logo, 10% VAT (`docs/design/UI_APPROVED_DECISIONS.md`).
- [x] Real price feed adapter BrsApi (toman → IRR, no invented buy price; `BrsApiQuoteProviderTest` on the owner's sample response). Not yet fetched live from a server (this environment cannot reach the host).
- [x] Staff discount codes up to 100% with bank-free fulfilment of zero orders (`PromoCodeTest`); admin-editable support phone (`SitePagesTest`); simple zarlio.ir home/terms/privacy pages.
- [x] Shared-hosting test-server mode: SQLite + no worker (`phpunit.sqlite.xml`, whole suite green on both databases), deploy job and server script (`docs/DEPLOYMENT.md` §۱۳). Deployment waits for the repository secrets.
- [ ] Independent penetration test.

## M0 — Foundations

- [x] Repository inspected and current framework/package requirements verified. (Laravel 13 / PHP 8.3 / PostgreSQL 16 / Node 22 (`composer.json`, `package.json` engines, `.nvmrc`); `composer audit` and `npm audit` in CI.)
- [x] Versions and lockfiles recorded; setup reproducible. (`composer.lock`, `package-lock.json`, `.nvmrc`; CI builds and tests from a clean checkout on Postgres 16 (`.github/workflows/ci.yml`).)
- [x] ERD, module dependency map, tenant strategy and ADRs created. (`docs/ERD.md` (generated by `php artisan talata:erd`), module table in `docs/IMPLEMENTATION_GUIDE.md`, `docs/adr/0001–0003` (0003 = tenant isolation).)
- [x] IRR/toman, purity, net gold weight, decimals and rounding contracts documented. (`docs/design/contracts/frontend-adapters.ts` (decimal strings, IRR storage), `calculation-vectors.json`, `docs/ASSUMPTIONS.md` #11.)
- [x] Assumptions register records quota period, segment trial policy, rate freshness and discount policy. (`docs/ASSUMPTIONS.md` rows 1, 6, 9–11.)
- [x] Demonstration tax rule distinguished from an operationally verified rule. (`tax_rules.is_sample`, printed note «نرخ مالیات نمونه است…» on invoice and summary; admin «نمونه» badge; future-dated versions only (`AdminOpsTest`).)

## M1 — SaaS, identity and access

- [x] Tenant memberships and provider/merchant access implemented. (`TenantIsolationTest`, `TeamPermissionsTest`, `AdminConsoleTest`.)
- [x] OTP normalization, expiry, replay prevention, throttling and session security tested. (`OtpLifecycleTest`, `MobileNormalizeTest`, `AuthAbuseTest`.)
- [x] Shared mobile-first login/registration supports Persian digits, paste/autofill, edit-number/resend and expiry/delivery errors without requiring email/password. (`auth/login` + `auth/code` (`inputmode=numeric`, `autocomplete=one-time-code`, «ویرایش شماره», timed «ارسال دوباره کد», «کد نرسید؟»); `OtpLifecycleTest::test_code_page_learns_when_the_login_sms_failed`, `DigitsTest`.)
- [x] Normalized phone uniqueness, idempotent first-account/shop provisioning and invitation/membership authorization verified. (`OtpLifecycleTest::test_first_login_is_idempotent` (advisory lock), `MobileChangeTest`, `TeamPermissionsTest`.)
- [x] Tenant invoice-SMS quota/trial depletion never prevents login OTP; identity-message abuse controls remain enforced. (`OtpLifecycleTest::test_a_shop_with_no_sms_allowance_left_can_still_sign_in`, `AuthAbuseTest`.)
- [x] Optional Passkey enrollment after recent verified mobile login, returning login, credential management/revocation and SMS recovery implemented. (`PasskeyTest` (recent-login enrolment, removal), `PasskeyOfferTest`; SMS login always remains.)
- [x] Server verifies WebAuthn challenge/type/origin/RP/signature/ownership/user verification; expiry/replay/revoked-credential and tenant-authorization tests pass. (`PasskeyTest` (11 tests).)
- [ ] Skip/cancel/unsupported/lost-device flows work; biometrics are never collected by Zarlio; virtual-authenticator evidence is separate from real-device tests. (Built: dismissible offer, hidden when unsupported, SMS sign-in after a lost device plus «خروج از دستگاه‌های دیگر»; only virtual-authenticator evidence so far — real-device test pending.)
- [x] Permissions, feature flags and atomic quota primitives implemented. (`Entitlements` capabilities/quotas, admin feature overrides (`AdminOpsTest`), issue/quota under row lock (`QuotaAndIsolationTest`).)
- [x] Two demo tenants prove HTTP, relation, cache, job and file isolation. (`TenantIsolationTest` (HTTP/relation by id, fail-closed scope, two shops: logo files, SMS job charges only its shop, cached config vs per-shop entitlements), `QuotaAndIsolationTest::test_ids_from_another_shop_in_a_payload_never_cross_over`.)
- [x] Provider administration can manage plans/subscriptions/configuration with audit (admin console v1; secrets stay in the server environment).

## M2 — Pricing

- [x] Standalone exact calculator works without Invoices or MarketPrices enabled. (`InvoiceAcceptanceTest::test_calculator_works_with_no_market_rate_and_without_invoice_rights`.)
- [x] Asset/currency/unit normalization and quote provenance implemented. (`QuoteService::normalize`: toman and per-مثقال feeds converted to IRR per gram, unknown units/non-positive values refused and logged; each invoice stores `rate_provenance` — quote id, feed, demo flag, freshness, quote/fetch time, market value, mode — also in the issued snapshot. `FreshnessAndWindowsTest`, `InvoiceAcceptanceTest`.)
- [ ] Fresh/stale/manual/offline and tenant/transaction override behavior tested. (Fresh/stale/manual, emergency rate (`AdminOpsTest`) and per-invoice manual rate tested; offline labelling checked by hand only.)
- [x] Effective rules, exact discounts and HALF_UP line rounding implemented. (Shared vectors incl. discounts and HALF_UP (`PricingVectorsTest`, `tests/js/pricing.test.mjs`); future-dated tax rule versions (`AdminOpsTest`).)
- [x] Master prompt sample fixtures pass; browser/server results match. (One vector file `docs/design/contracts/calculation-vectors.json` run by `tests/Unit/PricingVectorsTest.php` and by `tests/js/pricing.test.mjs` against the same `resources/js/lib/pricing.js` the browser loads; run in Node, not in a browser.)
- [x] Quote updates never silently change an accepted transaction rate. (`InvoiceFlowTest::test_a_newer_rate_is_applied_only_when_it_is_the_one_the_merchant_saw`, `test_market_start_with_stale_rate_returns_rate_changed`.)
- [ ] Gold scheduler/visible-client refresh every 180 seconds, foreground/reconnect and single-flight behavior tested with a controlled clock. (Server side tested: `*/3` schedule without overlap, single-flight lock, failure keeps the old timestamp, stale after 240 s with a controlled clock — `FreshnessAndWindowsTest`. Client foreground/reconnect refresh exists in the مظنه page but has no automated browser test yet.)
- [x] New-invoice entry shows large 18K price, unit/time/status and شروع immediately below; Start captures the visible accepted rate and handles a changed value explicitly. (`InvoiceFlowTest::test_market_start_with_stale_rate_returns_rate_changed`, rate provenance in `InvoiceAcceptanceTest`; screenshots in `docs/screenshots/app/`.)
- [x] Stale/missing quote, manual rate and MISC-only entry remain recoverable; no fabricated fresh timestamp. (`InvoiceAcceptanceTest::test_manual_rate_start_and_misc_only_invoice_without_any_market_rate`, `FreshnessAndWindowsTest`.)

## M3 — Invoices and public views

- [x] Draft items and optimistic save/version handling implemented. (`InvoiceFlowTest::test_draft_version_conflict_is_rejected`.)
- [x] Add/edit/remove draft rows work repeatedly without reload; GOLD, MISC and mixed/MISC-only invoices supported. (`scripts/e2e/ui-evidence.mjs` (21K GOLD + MISC rows), `InvoiceAcceptanceTest::test_manual_rate_start_and_misc_only_invoice_without_any_market_rate`.)
- [x] Every new row asks طلا/متفرقه; each GOLD row defaults to ۱۸ عیار (۷۵۰) and supports independent purity changes with proportional price recalculation. (`PricingVectorsTest::test_two_gold_rows_different_purities`, composer row type choice.)
- [x] MISC requires a manual title and exact final row price with an explicit currency; no gold weight/wage/profit/tax inference. (`InvoiceFlowTest::test_misc_row_requires_title_and_price_and_ignores_gold_math`.)
- [x] Per-row product names/descriptions and order survive draft, issue, print and public view. (`InvoiceAcceptanceTest::test_row_order_names_and_descriptions_survive_review_issue_print_and_public_view`.)
- [ ] Conditional validation, incomplete rows, type switching, removal recovery and mixed totals verified against documented examples. (Validation and mixed totals tested; row removal has «برگرداندن»; type-switching/removal recovery not covered by an automated browser test.)
- [ ] Finalization idempotency, numbering and quota races tested. (Idempotency and numbering tested; races are serialized by row locks but not load-tested in parallel.)
- [x] Issued values, shop details, template and branding are stable snapshots. (`InvoiceAcceptanceTest::test_issued_invoice_keeps_its_layout_and_logo_after_edits_and_a_downgrade`.)
- [x] Business name/mobile/address required server-side before issue; optional landline fallback, optional website/social/licenses/logo and return-to-draft completion flow work on every plan. (`InvoiceFlowTest::test_issue_requires_complete_business_profile`, `InvoiceAcceptanceTest::test_incomplete_profile_detours_to_business_settings_and_returns_to_the_same_draft`.)
- [ ] Business contact is separate from login/customer identity; public-contact notice, valid URLs/phone parsing and logo-upload checks implemented.
- [x] Simple/Shop presets and Basic/Professional layout rights work; Free profile edit vs fixed layout, server permission checks and tenant isolation verified. (`PagesTest::test_appearance_preview_save_and_snapshot_isolation`, `TeamPermissionsTest`.)
- [x] Per-block header/footer/alignment/order, optional visibility/logo sizing, permitted columns/readable appearance and print settings work with local preview, undo/cancel/reset/save/conflict recovery. (Appearance editor (screenshot 73); `TeamPermissionsTest::test_invoice_layout_order_and_print_settings_reach_the_printed_invoice_and_need_settings_rights`.)
- [x] Mobile/keyboard editing works without dragging; protected QR/essential fields cannot hide/overlap, and long-address/logo/multi-page layouts remain readable. (▲/▼ buttons, required blocks locked server-side (`LayoutSettings::REQUIRED_BLOCKS`), 30-row PDF evidence (screenshot 71).)
- [x] Complete layout/schema/template/business-field/asset snapshot preserves old print/mobile views after edits/downgrade; stored custom settings retained while new Free invoices use fixed template. (`InvoiceAcceptanceTest::test_issued_invoice_keeps_its_layout_and_logo_after_edits_and_a_downgrade`, `PagesTest::test_logo_upload_is_reencoded_and_gated_by_plan`.)
- [x] Issued edits/deletes rejected; void/replacement and installment consequences defined. (`InvoiceAcceptanceTest::test_issued_invoices_cannot_be_edited_or_deleted_and_an_sms_issue_replays_once`, `InvoiceFlowTest::test_void_and_replace`, agreement cancel before void (`CustomerBalanceTest`).)
- [x] Print A4, multi-page Persian and browser Save as PDF verified. (Chromium print-to-PDF of a 30-row Persian invoice (screenshot 71); other browsers not checked.)
- [ ] QR at physical upper-left survives print/PDF and reprint; actual paper/PDF scanning and four-module quiet zone checked. (Upper-left in Chromium PDF and four-module quiet zone (`QrTest`) checked; scanning a real paper print is pending.)
- [x] Stable InvoiceVerification token/page serves issued snapshot and void/replacement/revocation states, independently of share quotas; drafts/offline/invalid tokens never falsely verify. (`PublicPagesTest`: stable across share revoke + downgrade, void, replaced without link, security revoke → «لغوشده» 410; drafts have no token; the service worker never caches `/v/` — `tests/js/sw.test.mjs`.)
- [x] Merchant/customer invoice reflows at 360/390/768px without A4 shrinking or horizontal page scrolling; same-phone verification link works. (`scripts/e2e/ui-evidence.mjs` reflow sweep at 360/390/768/1280 and 200% zoom (screenshot 72); «بررسی این فاکتور» link on the invoice page.)
- [ ] Public verification/share DTOs omit customer mobile/private data; token storage, tenant isolation, no-store/log redaction and revoked-token behavior checked. (All checked in `PublicPagesTest` except web-server log redaction, which is a deploy config (`docs/DEPLOYMENT.md`) not yet verified on the production server.)
- [x] High-entropy public links, revocation/expiry/regeneration implemented. (256-bit tokens; share link revoke/expiry/new link; separate, permission-gated and audited security revocation of the QR code with a new code for future prints — `PublicPagesTest`.)
- [ ] Public DTO hides private data; noindex/referrer/cache/log policies verified. (Same: app headers tested; server access-log redaction pending on production.)
- [ ] Link quota counting, period boundaries and downgrade behavior tested. (Counting and cap tested (`QuotaAndIsolationTest`); month boundary tested for invoices, not separately for links.)

## M4 — SMS, parties and installments

- [x] Trial grant and paid/OTP budgets separated; reservations concurrency-safe. (Separate OTP path and budgets (`OtpLifecycleTest`, `AuthAbuseTest`), credit reserve/capture/release under row locks (`SmsAbuseTest`); no parallel-load test.)
- [x] Provider adapter, final-text preview and segment policy validated. (`KavenegarGatewayTest` (faked API), `SmsAbuseTest::test_review_preview_counts_the_same_segments_as_the_real_message`.)
- [x] Failure/unknown/retry reconciliation and webhook idempotency tested. (`SmsAbuseTest` (unknown blocks resend, reconcile sweeper), `KavenegarGatewayTest`, payment callback replay (`BillingTest`, `ZarinpalGatewayTest`). Delivery status is polled, not webhooked.)
- [x] Issue review accepts normalized customer mobile; explicit issue-and-SMS vs only-issue works without customer login/OTP/Professional customer management. (`InvoiceFlowTest::test_full_invoice_journey_renders_every_page`, `SmsAbuseTest`.)
- [x] Recipient/text/actual segment cost/link quota shown; insufficient budget, share/send failure and unknown outcomes preserve issued invoice and offer safe recovery. (`SmsAbuseTest` (no credit → AWAITING_CREDIT, released on top-up; unknown blocks resend), review preview.)
- [x] Double tap, idempotent issue retry and outbox retry produce one invoice/initial-send intent; definite failed-send retry uses existing invoice. (`InvoiceFlowTest::test_issue_is_idempotent_and_never_double_numbers`, `SmsAbuseTest::test_initial_send_is_idempotent_and_resend_only_after_failure`.)
- [x] Professional customer management works without invoices. (`PagesTest::test_customers_and_installments_flow` (customer + agreement with no invoice).)
- [x] Standalone and invoice-linked installment agreements supported. (`PagesTest::test_customers_and_installments_flow` (standalone), `SmsAbuseTest::test_installment_reminders_are_at_most_two_per_installment` (invoice-linked).)
- [x] Exact schedules, month-end rules, partial payments and reversals tested. (`InstallmentScheduleTest`.)
- [x] Existing debt remains readable/payable after downgrade. (`CustomerBalanceTest::test_old_debt_stays_payable_after_a_downgrade_and_an_agreement_can_be_cancelled_before_voiding`.)
- [x] Reminder opt-in/opt-out, quiet hours, deduplication and paid/void suppression tested. (`InstallmentScheduleTest` (quiet hours, paid line, cancelled agreement — required before void), `SmsAbuseTest` (opt-in, opt-out, at most two, scheduler twice).)
- [x] No real message sent from test/demo data. (Tests use the fake driver (`phpunit.xml`); production refuses dev drivers (`KavenegarGatewayTest::test_production_refuses_dev_sms_driver`); demo quotes labelled.)

## M5 — Selected design and PWA

- [ ] One primary toolkit selected and compatibility spike completed.
- [ ] Stage A approval completed before Stage B detail work; approved design reused without routine per-page approval loops.
- [ ] Selected toolkit production slice passes login/calculator route and transfer budgets; no eager unrelated modules/icons.
- [ ] Product-specific layout and tokens implemented from approved final UI prompt.
- [x] Merchant, provider and public environments use appropriate separate hierarchies. (Separate layouts: `components/layouts/app`, `admin`, `public` (public pages ship no merchant JS).)
- [x] Persian digits, numeric input, explicit units and mixed-direction text verified. (`DigitsTest`, `MobileNormalizeTest`, `inputmode=numeric` inputs, units on every amount.)
- [x] Novice add-row flow tested for different gold purities and manual miscellaneous entry on mobile/desktop. (Automated only (`ui-evidence.mjs` desktop, Playwright mobile journey); not a usability test.)
- [x] RTL views at 360/390/768/1280, long names/values, keyboard and 200% zoom verified. (`scripts/e2e/ui-evidence.mjs` (screenshot 72).)
- [x] Novice usability sessions performed, or clearly marked pending with no invented findings. (**Pending — not performed.** No usability findings are claimed.)
- [ ] Manifest, icons, app shell, direct routes and back/forward tested.
- [ ] Offline quote labeling, draft policy, safe reconnection and worker updates tested.
- [x] Sensitive requests/documents excluded from worker cache. (`public/sw.js` caches only hashed assets, fonts, icons and the offline page (`tests/js/sw.test.mjs`).)
- [ ] Production cold/warm weak-network profiles and local numeric-preview responsiveness pass `docs/PERFORMANCE_BUDGET.md` with retained traces.
- [ ] Essential fonts/assets self-hosted; public invoice independent of merchant bundle; chunk retry/interrupted network preserve draft inputs.
- [x] Real supported lower-end phone and representative Iran-network checks recorded or explicitly pending. (**Pending — not performed** (owner decision 2026-10-11: no field measurement in this phase).)

## Release evidence

- [x] Test, type/lint and build outputs recorded. (CI on every push: Pint, Vite build, perf:bundle gate, JS + PHP tests, composer/npm audit.)
- [x] Critical asset/payload regression gates and bundle report recorded; target timings distinguished from actual measurements. (`npm run perf:bundle` gate in CI; `docs/PERFORMANCE_BUDGET.md` separates targets from lab numbers.)
- [x] Actual live integration setup distinguished from mocks. (`docs/DEPLOYMENT.md` §۷–§۸, `talata:preflight --live`; ZarinPal/Kavenegar tested only with faked HTTP.)
- [x] Scheduler, queues, outbox and failure recovery documented. (`docs/DEPLOYMENT.md`, `docs/IMPLEMENTATION_GUIDE.md`.)
- [ ] Backup restore and historical-asset preservation verified. (Settings backup restore and logo-version preservation tested; a full database backup restore drill is pending.)
- [x] Secrets/private data absent from repository and diagnostic output. (`git grep` scan for API keys, private keys and APP_KEY values clean on 2026-10-11; `.env` git-ignored; `TechLog::redact`; `KavenegarGatewayTest` — API key never logged, OTP code never stored in plain text.)
- [x] Known limitations and deferred modules documented. (`docs/ASSUMPTIONS.md` «تعویق آگاهانه», README, this checklist.)
- [x] No claims of Modian submission, statutory certification or unpublished 2027 compliance. (The public verification page states the check is not a Modian submission; no doc or view claims submission or certification.)
