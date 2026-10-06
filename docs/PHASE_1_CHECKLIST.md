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

Evidence: `php artisan test` (162 tests / 1710 assertions on PostgreSQL), `npm run test:js`, Playwright mobile journey with screenshots in `docs/screenshots/app/`. Security controls and fixed findings: `docs/SECURITY.md`. Frontend decision: `docs/adr/0001-blade-ajax-frontend.md`.

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
- [ ] Field performance measurement on throttled networks. Lab only so far (Playwright throttling against the dev server without gzip — not production): login 1.4 s / calculator 1.5 s / public invoice 1.8 s on profile A, login 4.6 s / public invoice 5.5 s on profile B; gzip sizes app.js 4 KB, CSS 7.3 KB, fonts 78.9 KiB. Must be re-measured on the production host with retained traces.
- [x] Login-number change (M-23): two OTP steps (current number, then new), atomic swap, other devices signed out, audited (`tests/Feature/MobileChangeTest.php`).
- [x] Fingerprint-login offer once after an SMS sign-in, where the device supports it and it was not dismissed (`tests/Feature/PasskeyOfferTest.php`).
- [x] Invoice-list filters «این ماه» and «اقساطی», combinable with the status chips, plan-gated (`tests/Feature/InvoiceFlowTest.php`).
- [x] Customer list shows and filters by outstanding installment balance (همه / مانده قسط دارند / تسویه‌شده), Professional-only (`tests/Feature/CustomerBalanceTest.php`).
- [x] Over-quota sheet adapts its title, reassurance and actions to the limit that was hit (`tests/js/quota-panel.test.mjs`).
- [ ] Independent penetration test and owner approval of visual direction.

## M0 — Foundations

- [ ] Repository inspected and current framework/package requirements verified.
- [ ] Versions and lockfiles recorded; setup reproducible.
- [ ] ERD, module dependency map, tenant strategy and ADRs created.
- [ ] IRR/toman, purity, net gold weight, decimals and rounding contracts documented.
- [ ] Assumptions register records quota period, segment trial policy, rate freshness and discount policy.
- [ ] Demonstration tax rule distinguished from an operationally verified rule.

## M1 — SaaS, identity and access

- [ ] Tenant memberships and provider/merchant access implemented.
- [ ] OTP normalization, expiry, replay prevention, throttling and session security tested.
- [ ] Shared mobile-first login/registration supports Persian digits, paste/autofill, edit-number/resend and expiry/delivery errors without requiring email/password.
- [ ] Normalized phone uniqueness, idempotent first-account/shop provisioning and invitation/membership authorization verified.
- [ ] Tenant invoice-SMS quota/trial depletion never prevents login OTP; identity-message abuse controls remain enforced.
- [ ] Optional Passkey enrollment after recent verified mobile login, returning login, credential management/revocation and SMS recovery implemented.
- [ ] Server verifies WebAuthn challenge/type/origin/RP/signature/ownership/user verification; expiry/replay/revoked-credential and tenant-authorization tests pass.
- [ ] Skip/cancel/unsupported/lost-device flows work; biometrics are never collected by Zarlio; virtual-authenticator evidence is separate from real-device tests.
- [ ] Permissions, feature flags and atomic quota primitives implemented.
- [ ] Two demo tenants prove HTTP, relation, cache, job and file isolation.
- [x] Provider administration can manage plans/subscriptions/configuration with audit (admin console v1; secrets stay in the server environment).

## M2 — Pricing

- [ ] Standalone exact calculator works without Invoices or MarketPrices enabled.
- [ ] Asset/currency/unit normalization and quote provenance implemented.
- [ ] Fresh/stale/manual/offline and tenant/transaction override behavior tested.
- [ ] Effective rules, exact discounts and HALF_UP line rounding implemented.
- [ ] Master prompt sample fixtures pass; browser/server results match.
- [ ] Quote updates never silently change an accepted transaction rate.
- [ ] Gold scheduler/visible-client refresh every 180 seconds, foreground/reconnect and single-flight behavior tested with a controlled clock.
- [ ] New-invoice entry shows large 18K price, unit/time/status and شروع immediately below; Start captures the visible accepted rate and handles a changed value explicitly.
- [ ] Stale/missing quote, manual rate and MISC-only entry remain recoverable; no fabricated fresh timestamp.

## M3 — Invoices and public views

- [ ] Draft items and optimistic save/version handling implemented.
- [ ] Add/edit/remove draft rows work repeatedly without reload; GOLD, MISC and mixed/MISC-only invoices supported.
- [ ] Every new row asks طلا/متفرقه; each GOLD row defaults to ۱۸ عیار (۷۵۰) and supports independent purity changes with proportional price recalculation.
- [ ] MISC requires a manual title and exact final row price with an explicit currency; no gold weight/wage/profit/tax inference.
- [ ] Per-row product names/descriptions and order survive draft, issue, print and public view.
- [ ] Conditional validation, incomplete rows, type switching, removal recovery and mixed totals verified against documented examples.
- [ ] Finalization idempotency, numbering and quota races tested.
- [ ] Issued values, shop details, template and branding are stable snapshots.
- [ ] Business name/mobile/address required server-side before issue; optional landline fallback, optional website/social/licenses/logo and return-to-draft completion flow work on every plan.
- [ ] Business contact is separate from login/customer identity; public-contact notice, valid URLs/phone parsing and logo-upload checks implemented.
- [ ] Simple/Shop presets and Basic/Professional layout rights work; Free profile edit vs fixed layout, server permission checks and tenant isolation verified.
- [ ] Per-block header/footer/alignment/order, optional visibility/logo sizing, permitted columns/readable appearance and print settings work with local preview, undo/cancel/reset/save/conflict recovery.
- [ ] Mobile/keyboard editing works without dragging; protected QR/essential fields cannot hide/overlap, and long-address/logo/multi-page layouts remain readable.
- [ ] Complete layout/schema/template/business-field/asset snapshot preserves old print/mobile views after edits/downgrade; stored custom settings retained while new Free invoices use fixed template.
- [ ] Issued edits/deletes rejected; void/replacement and installment consequences defined.
- [ ] Print A4, multi-page Persian and browser Save as PDF verified.
- [ ] QR at physical upper-left survives print/PDF and reprint; actual paper/PDF scanning and four-module quiet zone checked.
- [ ] Stable InvoiceVerification token/page serves issued snapshot and void/replacement/revocation states, independently of share quotas; drafts/offline/invalid tokens never falsely verify.
- [ ] Merchant/customer invoice reflows at 360/390/768px without A4 shrinking or horizontal page scrolling; same-phone verification link works.
- [ ] Public verification/share DTOs omit customer mobile/private data; token storage, tenant isolation, no-store/log redaction and revoked-token behavior checked.
- [ ] High-entropy public links, revocation/expiry/regeneration implemented.
- [ ] Public DTO hides private data; noindex/referrer/cache/log policies verified.
- [ ] Link quota counting, period boundaries and downgrade behavior tested.

## M4 — SMS, parties and installments

- [ ] Trial grant and paid/OTP budgets separated; reservations concurrency-safe.
- [ ] Provider adapter, final-text preview and segment policy validated.
- [ ] Failure/unknown/retry reconciliation and webhook idempotency tested.
- [ ] Issue review accepts normalized customer mobile; explicit issue-and-SMS vs only-issue works without customer login/OTP/Professional customer management.
- [ ] Recipient/text/actual segment cost/link quota shown; insufficient budget, share/send failure and unknown outcomes preserve issued invoice and offer safe recovery.
- [ ] Double tap, idempotent issue retry and outbox retry produce one invoice/initial-send intent; definite failed-send retry uses existing invoice.
- [ ] Professional customer management works without invoices.
- [ ] Standalone and invoice-linked installment agreements supported.
- [ ] Exact schedules, month-end rules, partial payments and reversals tested.
- [ ] Existing debt remains readable/payable after downgrade.
- [ ] Reminder opt-in/opt-out, quiet hours, deduplication and paid/void suppression tested.
- [ ] No real message sent from test/demo data.

## M5 — Selected design and PWA

- [ ] One primary toolkit selected and compatibility spike completed.
- [ ] Stage A approval completed before Stage B detail work; approved design reused without routine per-page approval loops.
- [ ] Selected toolkit production slice passes login/calculator route and transfer budgets; no eager unrelated modules/icons.
- [ ] Product-specific layout and tokens implemented from approved final UI prompt.
- [ ] Merchant, provider and public environments use appropriate separate hierarchies.
- [ ] Persian digits, numeric input, explicit units and mixed-direction text verified.
- [ ] Novice add-row flow tested for different gold purities and manual miscellaneous entry on mobile/desktop.
- [ ] RTL views at 360/390/768/1280, long names/values, keyboard and 200% zoom verified.
- [ ] Novice usability sessions performed, or clearly marked pending with no invented findings.
- [ ] Manifest, icons, app shell, direct routes and back/forward tested.
- [ ] Offline quote labeling, draft policy, safe reconnection and worker updates tested.
- [ ] Sensitive requests/documents excluded from worker cache.
- [ ] Production cold/warm weak-network profiles and local numeric-preview responsiveness pass `docs/PERFORMANCE_BUDGET.md` with retained traces.
- [ ] Essential fonts/assets self-hosted; public invoice independent of merchant bundle; chunk retry/interrupted network preserve draft inputs.
- [ ] Real supported lower-end phone and representative Iran-network checks recorded or explicitly pending.

## Release evidence

- [ ] Test, type/lint and build outputs recorded.
- [ ] Critical asset/payload regression gates and bundle report recorded; target timings distinguished from actual measurements.
- [ ] Actual live integration setup distinguished from mocks.
- [ ] Scheduler, queues, outbox and failure recovery documented.
- [ ] Backup restore and historical-asset preservation verified.
- [ ] Secrets/private data absent from repository and diagnostic output.
- [ ] Known limitations and deferred modules documented.
- [ ] No claims of Modian submission, statutory certification or unpublished 2027 compliance.
