# Phase 1 implementation and acceptance checklist

Status: documentation prepared; application work has not started. Check boxes require evidence, not a plan or a mock screenshot.

## Planning state

- [x] Phase 1 master implementation prompt written.
- [x] Independent UX discovery prompt written.
- [x] Owner-confirmed mobile-number sign-in/registration and SMS OTP flow documented explicitly.
- [x] Optional Passkey/fingerprint-or-device-unlock enrollment after initial verified mobile login documented.
- [x] Owner clarification documented: repeatable GOLD/MISC rows, editable 18K-default purity, manual miscellaneous price and per-row product name/description.
- [x] Primary-source UI/design-tool shortlist prepared.
- [ ] Owner selects design skill and component toolkit.
- [ ] Final UI prompt prepared after selection.
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

## M3 — Invoices and public views

- [ ] Draft items and optimistic save/version handling implemented.
- [ ] Add/edit/remove draft rows work repeatedly without reload; GOLD, MISC and mixed/MISC-only invoices supported.
- [ ] Every new row asks طلا/متفرقه; each GOLD row defaults to ۱۸ عیار (۷۵۰) and supports independent purity changes with proportional price recalculation.
- [ ] MISC requires a manual title and exact final row price with an explicit currency; no gold weight/wage/profit/tax inference.
- [ ] Per-row product names/descriptions and order survive draft, issue, print and public view.
- [ ] Conditional validation, incomplete rows, type switching, removal recovery and mixed totals verified against documented examples.
- [ ] Finalization idempotency, numbering and quota races tested.
- [ ] Issued values, shop details, template and branding are stable snapshots.
- [ ] Issued edits/deletes rejected; void/replacement and installment consequences defined.
- [ ] Print A4, multi-page Persian and browser Save as PDF verified.
- [ ] High-entropy public links, revocation/expiry/regeneration implemented.
- [ ] Public DTO hides private data; noindex/referrer/cache/log policies verified.
- [ ] Link quota counting, period boundaries and downgrade behavior tested.

## M4 — SMS, parties and installments

- [ ] Trial grant and paid/OTP budgets separated; reservations concurrency-safe.
- [ ] Provider adapter, final-text preview and segment policy validated.
- [ ] Failure/unknown/retry reconciliation and webhook idempotency tested.
- [ ] Professional customer management works without invoices.
- [ ] Standalone and invoice-linked installment agreements supported.
- [ ] Exact schedules, month-end rules, partial payments and reversals tested.
- [ ] Existing debt remains readable/payable after downgrade.
- [ ] Reminder opt-in/opt-out, quiet hours, deduplication and paid/void suppression tested.
- [ ] No real message sent from test/demo data.

## M5 — Selected design and PWA

- [ ] One primary toolkit selected and compatibility spike completed.
- [ ] Product-specific layout and tokens implemented from approved final UI prompt.
- [ ] Merchant, provider and public environments use appropriate separate hierarchies.
- [ ] Persian digits, numeric input, explicit units and mixed-direction text verified.
- [ ] Novice add-row flow tested for different gold purities and manual miscellaneous entry on mobile/desktop.
- [ ] RTL views at 360/390/768/1280, long names/values, keyboard and 200% zoom verified.
- [ ] Novice usability sessions performed, or clearly marked pending with no invented findings.
- [ ] Manifest, icons, app shell, direct routes and back/forward tested.
- [ ] Offline quote labeling, draft policy, safe reconnection and worker updates tested.
- [ ] Sensitive requests/documents excluded from worker cache.

## Release evidence

- [ ] Test, type/lint and build outputs recorded.
- [ ] Actual live integration setup distinguished from mocks.
- [ ] Scheduler, queues, outbox and failure recovery documented.
- [ ] Backup restore and historical-asset preservation verified.
- [ ] Secrets/private data absent from repository and diagnostic output.
- [ ] Known limitations and deferred modules documented.
- [ ] No claims of Modian submission, statutory certification or unpublished 2027 compliance.
