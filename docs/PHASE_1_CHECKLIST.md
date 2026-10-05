# Phase 1 implementation and acceptance checklist

Status: documentation prepared; application work has not started. Check boxes require evidence, not a plan or a mock screenshot.

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
- [ ] Approved design decisions recorded and detailed UI contract finalized.
- [ ] Owner requests implementation work.

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
- [ ] Skip/cancel/unsupported/lost-device flows work; biometrics are never collected by Talata; virtual-authenticator evidence is separate from real-device tests.
- [ ] Permissions, feature flags and atomic quota primitives implemented.
- [ ] Two demo tenants prove HTTP, relation, cache, job and file isolation.
- [ ] Provider administration can manage plans/subscriptions/configuration with audit.

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
