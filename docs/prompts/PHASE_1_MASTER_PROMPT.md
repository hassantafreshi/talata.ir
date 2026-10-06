# Zarlio.ir — Phase 1 Master Implementation Prompt

Status: execution specification for a future build request. UI toolkit and final visual direction remain pending owner selection.
Date: 2026-10-05.

## 1. Your role and mission

Act as a senior Laravel engineer, product architect and test engineer. Build Zarlio.ir, a Persian-first, multi-tenant SaaS for Iranian jewelry merchants. The provider sells access to independent modules. Merchants have very low digital literacy but understand their trade. The product must help them calculate a gold sale, issue a stable invoice, print/share it manage customers and, on the Professional entitlement set, installments with minimal confusion.

Read the entire specification and `docs/PERFORMANCE_BUDGET.md` before changing code. Fast usable loading on weak/unreliable internet in Iran is an owner-confirmed core acceptance requirement. Inspect repository files, instructions and existing dependencies. Preserve existing work. If starting from an empty repository, scaffold a maintainable modular monolith. First produce a concise plan, data model, module dependency map, risk/decision register and milestone checklist, then implement the authorized scope incrementally. Make routine reversible engineering decisions; ask only for missing choices that materially change business behavior. Do not stop after scaffolding or a decorative demo.

All customer-facing and merchant-facing labels, help, validation, email/SMS templates and empty states must be natural Persian. Technical identifiers and documentation may be English. Use `lang=fa`, RTL, correct Persian typography and explicit units.

## 2. Scope and non-goals

Deliver in Phase 1:

1. Provider administration: tenants, memberships, plans, subscriptions, module entitlements, quota rules, tax configurations, integrations and operational health.
2. Mobile number is the primary account identifier: SMS one-time-code first registration/login, plus optional Passkey/device-unlock login enabled after verified mobile sign-in, tenant selection where applicable, owner/staff access and provider access. Phase 1 does not require a username, email or password to sign in.
3. Central market feed for 18K gold, 24K gold and USD, with an owner-required 180-second gold refresh cadence, visible provenance, timestamps and freshness; new-invoice entry prominently shows the current 18K quote with a `شروع` button immediately below it.
4. Standalone gold calculator, product name «ماشین‌حساب طلایی», plus the quote board «مظنه» (see `../MAZNEH_AND_CALCULATOR.md`): editable effective 18K rate, weight, purity default 750, wage percentage, profit percentage, component-aware discount and tax rule.
5. Multi-item draft invoices with independently typed GOLD and MISC rows (including mixed invoices), authoritative finalization, printable layout, browser Save as PDF, public secure links, share actions and SMS delivery.
6. Tenant shop profile, owner/staff permissions, branding and invoice customization controlled by features.
7. Free/Basic/Professional feature and quota engine with the owner's quotas in `../PLANS_AND_QUOTAS.md`; five free SMS per tenant per year; prepaid toman SMS credit charged per segment.
8. Customer directory in every plan (monthly new-customer caps on Free/Basic); Professional installments, manual payment recording, overdue status and reminders.
9. Audit events, reliable queues, error handling, PWA app shell and controlled offline calculator behavior.
10. Custom-domain-ready routing interfaces, but automatic DNS/TLS provisioning is deferred.

Explicitly defer: full accounting, double-entry financial postings, metal ledger postings, inventory, barcode operations, assay/melted gold, silver and coin calculations, product-photo attachments, customer OTP account portal, native apps, payment gateway, online shop, Modian submissions and automatic custom-domain provisioning. Silver, coin, melted gold, multi-business-type tenants and product photos are the v2 roadmap in `../ROADMAP_V2_BUSINESS_TYPES.md`: Phase 1 must prepare the infrastructure it lists (business-type registry, pricing-policy registry, per-category tax rules, typed item attributes, sale/purchase direction, item asset table) without implementing v2 behaviour. Create clear extension contracts and ADRs, not placeholder CRUD for every future module. Do not expose nonfunctional future menus.

The printable sales invoice is not automatically a legally submitted tax-system invoice. No UI badge may imply tax submission or government approval.

## 3. Technology and decisions

- Backend: a currently supported stable Laravel version, with compatible PHP and dependencies verified against official documentation at implementation time. Pin exact resolved versions and commit lockfiles.
- Proposed frontend: Vue 3 + TypeScript + Inertia + Vite, a single Laravel application with client navigation and real URLs. This is a proposal, not an instruction to pick an unapproved UI library. If evidence supports a different compatible approach, document an ADR before changing the architecture.
- Proposed database: PostgreSQL for transactions, composite constraints and exact numeric types. Redis for queues/cache/rate limits where available. Local development may use simpler drivers, but concurrency/tenant integration tests must use production-equivalent SQL behavior.
- Tests: Laravel feature/unit tests using Pest or PHPUnit; browser flow tests with Playwright or equivalent when available. Use official docs, not outdated package snippets.
- Shared exact arithmetic: server decimal/value-object calculation engine; browser decimal library for preview parity. Never calculate money with native PHP float or JavaScript Number arithmetic.
- Dates: UTC persistence; default merchant timezone `Asia/Tehran`, configurable. Persian calendar is presentation/input conversion; business dates and billing boundaries use explicit timezone rules.
- Frontend component library and third-party design skills: PENDING. Consult the shortlist, wait for the owner's selection, then lock one primary toolkit. Do not install all candidates.
- Server-generated downloadable PDF may be added only if a tested Persian shaping/RTL renderer is available. Baseline is an accurate printable invoice and browser Save as PDF; never expose a fake PDF button.

Provide environment configuration examples without real secrets, reproducible setup commands and sensible dev fixtures. Do not deploy production automatically.

## 4. Architecture and module independence

Use a modular monolith with explicit Application/Domain/Infrastructure/Presentation boundaries where useful; avoid ceremony for simple CRUD. Core contains primitives/contracts and tenant context, not every business model. Suggested layout:

```text
app/Core/{Tenancy,Money,Metal,Access,Events,Clock}
app/Modules/Saas
app/Modules/Identity
app/Modules/MarketPrices
app/Modules/Pricing
app/Modules/Invoices
app/Modules/Parties
app/Modules/Installments
app/Modules/Sms
app/Modules/Notifications
app/Modules/PublicInvoices
app/Modules/Audit
app/Modules/Domains
resources/js/{pages,components,composables,domain,layouts}
tests/{Unit,Feature,Browser}
docs/{adr,architecture,operations}
```

Use interfaces/application services to cross boundaries. Modules must not reach into another module's controllers or private persistence details. Do not use microservices for the MVP.

| Module | Required dependencies | Optional integration |
| --- | --- | --- |
| MarketPrices | Core, provider configuration | tenant overrides |
| Pricing/Calculator | exact primitives, tax profiles | market prices, invoices |
| Invoices | Pricing contracts, tenant/access | Parties, SMS, Installments |
| Parties | tenant/access | invoice and installment history |
| Installments | tenant/access, Party identity, money | invoice linkage, notification adapter |
| SMS | tenant/access, credit accounting | invoice and reminder events |
| PublicInvoices | share/verification-token resolvers, immutable invoice read models | domain resolver |

Customers can exist without invoices. Installment agreements can be standalone with a party and explicit principal, or linked to a finalized invoice. Pricing must run with manual input if MarketPrices is disabled/unavailable. If Invoices is disabled, calculator still works and no create-invoice action is shown. SMS disabling never prevents invoice finalization. No financial record creation depends on SMS provider success.

Billing (online plan purchase and SMS credit through a payment-gateway adapter) is a Phase 1 module per `../PAYMENTS_AND_SMS_CREDIT.md`. Future extension modules: FinancialLedger, MetalLedger, Inventory, Assay, Silver, ModianConnector, Commerce and customer account portal. Define versioned events such as `InvoiceFinalized`, `InvoiceVoided`, `InstallmentPaymentRecorded`, `InvoiceShareCreated`, with event ID, schema version, tenant ID, actor, occurrence time and object ID. Persist an outbox entry in the same transaction as critical state changes; consumers are idempotent. Do not post real ledger entries in Phase 1.

## 5. Tenancy and identity

One `Tenant` represents one shop/business in Phase 1. A user may have memberships in multiple tenants. One user identity is not equivalent to one shop. Future multiple branches can be represented later without weakening tenant isolation.

- Tenant-owned rows carry a non-null `tenant_id`; globally managed rows are explicitly documented as global.
- Tenant context comes from authenticated membership and validated routing, never a client-supplied tenant ID alone.
- Enforce scoped queries, policies and tenant-aware composite foreign keys/unique constraints where supported. A global ORM scope alone is insufficient.
- Scope caches, exports, file paths, queue jobs, locks, notifications and search results by tenant. Jobs reconstruct tenant context and clear it after execution.
- Global resources include raw provider quotes, users, plan definitions, shared default tax profiles and integration configuration. Tenant tax assignments and overrides are scoped.
- Provider administrator access is distinct and audited. High-risk provider account changes require recent authentication; privileged OTP policy is stricter. Impersonation is deferred unless explicitly requested.
- No cross-tenant ownership references for parties, calculation snapshots, invoice items, payments or attachments. Test even when UUIDs are used.
- Staff permission baseline: Owner, Manager, Seller; model Accountant/Cashier role permissions for future use without shipping unused workflows. Permissions are capabilities, not hard-coded role checks throughout controllers.

### Mobile-number sign-in — owner-confirmed requirement

Use one clear initial/fallback entry flow: `شماره موبایل -> دریافت کد پیامکی -> تأیید کد -> ورود`. This supports returning-user login and first-time account registration; do not add a separate email/password registration screen. After the first verified mobile login, the user may explicitly enable the optional Passkey/device-unlock flow below for later logins. Email may be an optional contact field, never a prerequisite for either flow.

- A normalized, verified mobile number uniquely identifies a User globally; tenant membership determines shop access. Equivalent `09...`/`+98...` and Persian/Arabic/Latin-digit representations resolve to the same identity. A customer's phone stored on an invoice/Party does not grant merchant access or create a merchant membership.
- Create a new account only after successful single-use OTP verification. If no membership exists, route to minimal owner/shop onboarding under the registration policy. Invited staff join only through a valid invitation matching the verified phone; existing users return to their shop or tenant selector. Possession of a phone number never grants provider/staff privileges without server-managed authorization.
- Verification/account provisioning is idempotent: retries do not create duplicate users, shops or memberships. Reuse the same verified identity for additional tenant memberships rather than making separate accounts per shop.
- Login OTP sending must remain available when invoice-sharing trial credits are exhausted or a merchant plan/SMS balance changes. Use the separate operational identity-message budget with abuse controls; do not charge login against the five tenant trial segments.
- Return the user to a validated in-app destination after login, preserving allowed drafts; never trust arbitrary return URLs. Provide logout/session-expiry recovery. Protect changing the sign-in phone with a dedicated re-verification policy, not a normal editable profile field that silently switches account identity.

OTP: normalize Iranian `09...` and `+98...` to canonical form; accept Persian/Arabic digits. Challenge-bound hashed codes, expiry, attempt limits, resend cooldown, IP/mobile/device-aware throttles, generic enumeration-resistant responses, single-use consumption and session rotation. Never log codes. Development OTP driver is explicit and disabled outside development/testing. Separate OTP messages/operational budget from tenant marketing/share trial credits. Add CSRF/session protection and secure cookie configuration. Do not invent a real SMS integration without credentials.

### Optional fingerprint/device-unlock login after mobile verification

Owner-requested Phase 1 scope: after a successful mobile/SMS sign-in, offer optional `فعال‌سازی ورود با اثر انگشت یا قفل دستگاه (Passkey)`. Skipping leaves SMS login usable; this is an account security preference, not a paid-plan feature. Returning users with a registered credential can authenticate with a Passkey without requesting a new SMS on every login.

Implement WebAuthn/Passkeys over the canonical HTTPS authentication origin with a maintained server verification library compatible with the chosen Laravel/PHP versions. Use official documentation and pin the dependency; do not implement cryptographic verification from scratch. Biometrics remain with the device/authenticator; Zarlio stores credential metadata and a public key, never fingerprints or biometric templates. The OS may approve through fingerprint, face recognition or device PIN/lock. A website cannot guarantee or reliably detect that fingerprint was the specific verification method; product copy must explain the available alternatives.

- **Enrollment:** only a logged-in user with recent successful mobile OTP verification may add a credential. Create a short-lived, one-use server registration challenge bound to user/session and ceremony. On explicit user action, invoke the browser registration ceremony with user verification required, a discoverable credential and privacy-preserving attestation settings. Persist only after full server validation; cancellation never enables a toggle or marks setup complete. Exclude already registered credentials to prevent duplicates.
- **Subsequent login:** offer an explicit Passkey sign-in action where browser support permits. Discoverable authentication can select the account in the OS/browser without retyping the mobile number; a mobile-first credential lookup may also be offered without exposing account/credential existence. Verify the assertion server-side, resolve the actual credential owner, rotate session and re-evaluate memberships/roles before routing. Do not trust a client-submitted phone, user ID or tenant ID as credential ownership proof.
- **Verification:** use distinct one-use registration/authentication challenges, expiry and replay protection; validate ceremony type, challenge, allowed origin, RP ID/hash, user presence and user verification, signature, credential ownership and active/revoked state. Enforce secure handling of cross-origin/top-origin signals. Apply standards-aware signature-counter/backup-state handling; do not universally require a monotonically increasing nonzero counter from synced credentials. Tests must cover invalid signatures/challenges/origins, expired/replayed ceremonies and absent verification flags.
- **Credential model:** user-owned global PasskeyCredential (not tenant-owned), unique credential identifier scoped appropriately to the RP, opaque stable user handle, public key/algorithm, transports and relevant authenticator metadata, created_at, last_used_at, revoked_at and optional user-chosen label. Use no phone/PII in the opaque user handle. A credential proves user identity; tenant authorization remains separate.
- **Management:** settings list registered credentials with labels and dates; allow add/rename/revoke with recent authentication appropriate to the action. Revoking on the server disables subsequent assertions even if the OS retains its copy; explain OS-side removal separately. Support more than one credential per user. Do not confuse sign-out with deleting a credential.
- **Recovery/support:** SMS entry remains available for unsupported browsers, cancellation, lost/replaced devices, missing/revoked credentials and failed authentication. After SMS recovery, the owner can revoke a lost credential and enroll another. Do not create another account or erase invoices when a Passkey is missing. A Passkey may sync through the user's OS/password manager; do not promise it is confined to one physical phone or available on every device.
- **Origin/domain boundary:** register and authenticate on the central trusted application origin, with configured RP ID and exact allowed origins independent of arbitrary Host headers. Future merchant invoice domains do not automatically become authentication origins or share these credentials. Development uses a standards-permitted secure context; production requires HTTPS. No credentials/challenges/auth responses in service-worker caches or localStorage bearer-token substitutes.
- **UX/availability:** feature-detect WebAuthn and platform user-verifying capability rather than user-agent strings. Capability detection does not prove a fingerprint sensor exists. The OS authentication sheet follows a user action; no repeated automatic prompts. Always keep an obvious SMS fallback. Activation on a shared sales-counter device is opt-in with a brief explanation of shared-device implications; never silently enroll it.

Acceptance: first SMS login -> choose enable -> device prompt -> verified registration -> logout -> successful Passkey login to the same user -> correct tenant access; skip/cancel leaves SMS flow unchanged; revoked credential cannot sign in; unsupported environment and lost-device recovery work without data loss. Use virtual authenticators for protocol/browser automation and real supported mobile/PWA/desktop checks for actual device UX; do not claim hardware fingerprint testing from a mocked credential.

Implementation references (verify current compatibility at build time):

- https://www.w3.org/TR/webauthn-3/
- https://developer.mozilla.org/en-US/docs/Web/Security/Authentication/Passkeys
- https://developers.google.com/identity/passkeys/
- https://developers.google.com/identity/passkeys/ux/communicating-passkeys

## 6. Data model and invariants

These are required concepts, not a demand for identical class/table names. Supply an ERD, migrations, indexes, constraints and relation ownership notes.

| Area | Minimum entities/concepts |
| --- | --- |
| Billing | BillingOrder, PaymentAttempt, gateway adapter configuration, receipt |
| SaaS | Tenant, ShopProfile, User, Membership, Role/Permission, Plan, PlanFeature, PlanQuota, Subscription, TenantFeatureOverride, QuotaPeriod, QuotaUsage/Reservation |
| Identity | OTP challenge, authentication session, PasskeyCredential, one-use WebAuthn ceremony challenge |
| Pricing | Metal/Purity value objects, TaxRule version, TaxRuleAssignment, CalculationSnapshot, CalculationItemSnapshot, PriceSnapshot |
| Markets | ProviderConfiguration, MarketQuote, TenantRateOverride |
| Invoices | Invoice, InvoiceItem, InvoiceLayout, immutable issued snapshot, RevisionLink, InvoiceShare, InvoiceVerification, invoice numbering counter |
| Parties | Party, PartyRole, contact details, optional duplicate-resolution metadata |
| Installments | Agreement, ScheduleLine, PaymentRecord, PaymentAllocation, reversal relation, reminder policy/history |
| SMS | Template, Message, DeliveryAttempt, CreditLedger/Reservation, webhook receipt |
| Ops | AuditEvent, OutboxEvent, IntegrationHealth, optional DomainMapping interface/minimal model |

Money:

- Persist currency as IRR, never an ambiguous `amount`. UI can default to تومان with an always-visible label; conversion is exactly 10 IRR = 1 toman. Tenant display preference is explicit.
- Persist posted monetary values as integer rials in adequate-width columns (e.g. `NUMERIC(24,0)`); serialize exact values as decimal strings. USD display quotes have their own asset/quote currency/unit metadata.
- Use weight in grams with at least 6 decimal storage places and 3 visible default places. Normalize input carefully; never silently round a user's entered weight before calculation.
- Purity is parts per thousand: 750 means 18K; permitted range >0 and <=1000, with category-specific validation. Stones/non-gold weight are not counted as gold. Phase 1 accepts net gold weight and explicitly labels it; gemstone valuation is deferred.
- Rates are exact decimals; distinguish 2 percent from 0.02 fraction in API contracts.
- Define bounds and overflow limits; reject negative weights, prices, rates, discounts or nonfinite/exponent input unless explicitly supported.

All invoice item snapshots independently store: stable row ID, display order, item_type (`GOLD` or `MISC`), item_name/title, optional description, input/display currency, posted IRR row total and the row calculation version. GOLD snapshots additionally store: quantity if applicable, net weight, metal, selected karat label and canonical purity, reference 18K quote, purity-adjusted effective rate, metal value, wage type/rate/amount, profit policy/rate/amount, commission, discount scope and component allocations, taxable base, VAT rate/rule ID+version, VAT and rounding policy. MISC snapshots store the manually entered row price and its unit; gold-only inputs/components are not applicable and remain null, not fabricated gold values.

Do not store only weight, price and total. Keep raw inputs, calculated pre-discount components, applied discount allocation, post-discount components and rounded outputs. Capture customer display details (if supplied), shop name/contact/address/logo asset version, branding entitlement, invoice template version and seller at issue time. Later shop edits and plan downgrade cannot rewrite an issued document.

`Party` has multiple possible roles; Customer is one role. No global customer sharing. Free/Basic invoices may have optional customer name/phone captured as invoice-local fields without enabling a managed customer database. Creating/selecting persistent Parties requires customer-management entitlement; standalone installment agreements require a Party.

## 7. Calculation engine: exact and auditable

Implement a pure Pricing service with immutable inputs/outputs and no dependency on HTTP, Eloquent, Invoices or a live provider. Frontend preview and backend authoritative calculation use an explicitly versioned contract.

Initial policy identifier: `GOLD_IR_V1`. Regulatory configuration is separate from the formula identifier. Wage model supports `PERCENT`, `PER_GRAM`, `FIXED`, `MIXED` as typed concepts; only `PERCENT` is enabled for Phase 1. Reject other modes with a clear capability validation, rather than partially calculating them. Commission is modeled and zero/hidden in Phase 1.

For each GOLD item, before discounts (MISC rows follow the separate manual-price contract below):

```text
M = net_weight_g * reference_18k_price_irr_per_g * (purity_ppt / 750)
W0 = M * wage_percent / 100
P0 = (M + W0) * profit_percent / 100
C0 = 0 in Phase 1
```

Gold component tax profile: original metal is excluded from the services taxable base. The agreed sample VAT setting is 10 percent on eligible wage/profit/commission. Load the rate from a versioned effective-date rule, never a literal 0.10 in domain code. Seed a clearly labeled demonstration rule; operational activation records an official source reference, reviewer and effective date. This document does not independently certify the statutory rate. No silent fallback if no applicable rule exists at finalization.

Tax rules contain: category, applicable components, rate, effective interval, version, source reference, verification status and superseded relationship. Reject overlapping active intervals. Updating a rule creates a version; old snapshots retain old rules. Silver never inherits the gold tax profile. Invoice issue date in the tenant timezone determines tax-rule selection.

### Discount policy

Expose a simple Phase 1 field `تخفیف اجرت و سود` with explicit scope `TAXABLE_COMPONENTS`; optional advanced scope choices `WAGE` and `PROFIT`. Accept an exact fixed amount in the selected display currency, converted to IRR before pricing. Phase 1 does not allow discounts to metal or direct discounts to VAT. `ITEM` is a future concept, disabled until its allocation policy is specified.

- Calculate M, W0, P0 and C0 first. Discount does not recalculate the pre-discount profit formula; it reduces the chosen components under this versioned policy.
- Validate requested discount <= eligible component sum, never silently clamp or produce a negative taxable base.
- For `TAXABLE_COMPONENTS`, allocate proportionally to W0/P0/C0 using exact arithmetic; reconcile integer-rial allocation by deterministic largest-remainder, with stable component order as tie breaker. Store allocation details.
- Define posted `W`, `P`, `C` as rounded pre-discount components minus their integer-rial allocated discounts. M is unchanged.
- `B = W + P + C`, `V = round_irr(B * vat_rate_percent / 100)`, `T = M + W + P + C + V`.
- Do not subtract the same discount from T again. The final total already uses discounted components.
- Discount validation/allocations are per item. Invoice-wide discount is deferred; UI may apply a requested allocation to explicit items, never hide allocation in a total field.

### Rounding and parity

Use sufficient internal precision (minimum 18 fractional places or a justified stronger policy) for intermediates. Posted pre-discount M/W0/P0/C0 round HALF_UP to whole IRR. Gold tax uses the posted discounted taxable base and rounds HALF_UP once per GOLD line. Invoice payable total is the sum of posted GOLD line totals plus posted MISC manual line totals, not a second unrounded recomputation or another gold-tax calculation over the invoice total. Save `rounding_policy=IRR_LINE_HALF_UP_V1`. Clearly display any display-unit fraction; do not change legal/posting totals to obtain a prettier toman number.

Use explicit decimal strings across JSON. Build shared fixtures that the browser and server both pass. In preview, show invalid/incomplete input state rather than a misleading zero total. Debounce network recalculation; immediate local preview is labeled until validated. Server always recomputes finalization and ignores client totals.

Required worked examples (IRR):

| Example | Input | Expected output |
| --- | --- | --- |
| Base | 2g, 750, Price18=100,000,000, wage=2%, profit=5%, VAT rule=10% | M=200,000,000; W0=4,000,000; P0=10,200,000; B=14,200,000; V=1,420,000; T=215,620,000 |
| Wage discount | same inputs, discount 1,000,000 scoped WAGE | W=3,000,000; P=10,200,000; B=13,200,000; V=1,320,000; T=214,520,000 |
| Purity | 1g, purity 900, Price18=100,000,000, zero wage/profit | M=120,000,000; B=0; V=0; T=120,000,000 |
| Currency | displayed 10,000,000 toman per gram | stored quote 100,000,000 IRR per gram |

Also test zero wage, zero profit, full eligible discount, proportional allocation residuals, maximum precision weight, limits/overflow, no rule, changed tax rates, Persian/Arabic digit parsing and multiple lines. Assert T equals the sum of posted components and VAT never includes original gold in this profile.

### Invoice row types and add-item contract — owner clarification

The invoice editor must support repeated `افزودن ردیف` actions. Every new row asks `نوع کالا` with visible choices `طلا` / `متفرقه`. One invoice can contain several GOLD rows of different purities, several MISC rows, or any mixture; a MISC-only invoice is valid. Keep the standalone gold calculator focused on gold, while the invoice composer dispatches each row to its matching pricing contract.

| Field/behavior | GOLD — طلا | MISC — متفرقه |
| --- | --- | --- |
| Name/title | editable product name; usable default such as `طلای ۱۸ عیار` | required manually entered title |
| Description | optional editable product description | optional editable description |
| Purity | ask visibly; default `۱۸ عیار (۷۵۰)`; editable per row | hidden/not applicable |
| Weight | positive net gold weight in grams | hidden/not applicable |
| Price | calculate from accepted 18K reference rate and selected purity | required manually entered final row price, with explicit تومان/ریال |
| Wage/profit/gold VAT | existing GOLD_IR_V1 rules, per row | no automatic gold wage/profit/VAT additions |

- Persist `purity_ppt` as the canonical calculation input; 18K is 750 and 24K is 1000. The UI provides an easy karat selector and an advanced precise purity input if needed; avoid asking a novice to understand a bare `750`. Other presets must use a documented mapping. Changing purity changes only that row's price: `effective_price_per_g = reference_18k_price_per_g * purity_ppt / 750`; then `metal_value = net_weight_g * effective_price_per_g`. For an exact selected karat K, the equivalent proportion is K/18; retain enough precision in the karat-to-purity conversion rather than rounding the preset to a different assay silently.
- Recalculate that GOLD row's metal, wage, profit, eligible discount and tax, then invoice totals, when its weight/purity/accepted reference price changes. Do not change another row's selected purity or manual price. Display its adjusted per-gram rate alongside the selected purity. A changed row invalidates the reviewed fingerprint and requires review before issue.
- For MISC, the Phase 1 manual input is the **final price for the entire row**, not a unit price multiplied by an implicit quantity. Label it `قیمت ردیف`; record `price_basis=ROW_TOTAL` and `formula_version=MANUAL_LINE_V1`. A quantity/unit-price workflow may be added only with an explicit separate contract. Validate required title, a positive exact price, currency and bounds; accept normalized Persian/Arabic/Latin digits. No catalog/inventory registration is required for either row type.
- MISC manual pricing is a commercial entry rule, not an assertion of tax exemption. Do not inherit GOLD_TAX_PROFILE or set an exemption flag from the word `متفرقه`. Keep miscellaneous tax classification separate/unclassified unless an explicit applicable profile is configured, and do not infer or add tax on top of the entered final price. A future tax adapter must resolve that classification before a tax-system submission. Scope displayed GOLD tax subtotals to gold rows; do not describe them as known taxes for unclassified miscellaneous goods.
- For a mixed invoice, `payable_total = sum(gold_row.final_total) + sum(misc_row.manual_total_irr)`. Gold weight, metal, wage/profit and gold tax breakdowns sum GOLD rows only. MISC prices have their own subtotal; do not count them as metal value or recalculate tax over the combined payable total. Do not apply gold-only discount to MISC rows.
- Each row has a stable ID and position. Adding/editing/removing rows updates the preview without a full page reload and preserves other rows, current focus and unsaved input. An incomplete new row stays visibly incomplete; it is never silently ignored during finalization. At least one valid row is required. A documented technical maximum may protect against abuse, but do not invent a commercial one-row limit.
- Switching a draft row's type changes conditional fields and validation. Do not destructively erase entered content without explanation; inactive type-specific fields must never leak into the submitted/calculated active row. Draft removal is recoverable before issue. Name/description appear in the review, issued snapshot, print and public invoice; preserve long Persian text and row order.
- For finalized invoices, adding goods creates a guided linked replacement/revision rather than mutating the issued snapshot. Adding/removing rows in drafts remains unrestricted within the documented technical bounds. Keep this lifecycle rule consistent with the already agreed immutable-invoice policy.

Required acceptance examples in IRR:

| Case | Expected result |
| --- | --- |
| Two gold rows at different purities | With reference18=100,000,000, 1g at 750 is metal=100,000,000; 1g at 1000 is metal=133,333,333 after line rounding; zero wage/profit yields payable=233,333,333 |
| Gold plus miscellaneous | Existing 2g/750 worked example total=215,620,000 plus a MISC row titled `جعبه هدیه`, manual price=2,000,000 gives payable=217,620,000; gold VAT remains 1,420,000 |
| Miscellaneous only | One valid titled MISC row at 2,000,000 gives payable=2,000,000 without any gold inputs or a gold tax-rule lookup |
| Row independence | Changing only the second GOLD row from 750 to 1000 updates that row and aggregate totals; other GOLD purity and MISC price remain unchanged |
| Row validation and rendering | Blank MISC title/price, invalid GOLD weight/purity and incomplete added rows prevent issue with local errors; long item names/descriptions survive draft, snapshot, print and public view |

## 8. Market price module

Define `PriceProvider` and normalized `MarketQuote` contracts. Asset examples: `GOLD_18`, `GOLD_24`, `USD_IRR`; specify quote currency and unit for every provider field. USD/IRR is a currency exchange quote, not a gold per-gram price. Verify whether each provider reports rial or toman; never infer by magnitude.

- Secrets/configuration only on backend. Poll centrally with locks/scheduler, not once per merchant tab. Cache and keep controlled history; use retries/backoff and health reporting.
- Persist source/provider, provider quote time if available, fetched_at, normalized amount, asset, unit, status, conversion provenance and verification of mapping.
- Gold refresh interval is owner-confirmed: **180 seconds (3 minutes)**. Seed `gold_refresh_interval_seconds=180` in configuration; use one centrally scheduled provider refresh, a single-flight lock and timestamped normalized quotes. Active visible clients read compact server quote updates at that cadence, without making independent provider calls. Fetch the latest server quote on page entry and foreground/reconnect; pause client polling when hidden/offline and coalesce multi-tab activity. A failed fetch never fabricates a fresh timestamp. Freshness is separate from polling: initial configurable stale threshold is 240 seconds (one 180-second interval plus 60 seconds transport grace), documented as a technical assumption, with failed/degraded status visible immediately. Do not retain the superseded 60-second polling assumption. 24K/USD follow the shared batch cadence where the provider supports it, with separate source/status metadata.
- Distinguish LIVE/FRESH, STALE, UNAVAILABLE, MANUAL and OFFLINE in the UI with text plus status indicators. An old cached value is never labeled live.
- Tenant override affects only that tenant; transaction-level editable price does not mutate the global quote. Store override actor, reason, original value and time.
- If 24K is derived rather than fetched, label it derived and retain the conversion policy. Never fabricate a provider timestamp.
- Calculator captures a rate for the current transaction. Background feed updates do not silently change the filled calculation. Present the new rate and an explicit `استفاده از نرخ جدید` action.
- Finalization compares the reviewed draft fingerprint with authoritative inputs/rules/quote policy. If inputs, applicable rule or accepted price materially changed, return a review-required response and preserve fields. Merchant may explicitly retain a permitted manual/older rate with recorded acknowledgment; configurable stale-rate policy determines whether finalization is allowed.
- Development ships a visible mock provider; production integration requires documented real API mapping, credentials and contract tests. No claim that rates are live while using fixtures.

PriceSnapshot stores provider quote, merchant-effective value, currency/unit, source, fetched_at, quote_time, used_at, freshness status, override flag/reason/actor and quoted asset. Drafts may be repriced explicitly; finalized invoices never reference mutable current price for display.

### New-invoice entry: current price first, then Start

Add `/app/invoices/new` as the primary new-sale entry (or an equivalent deep-linkable view):

1. Show a large, high-contrast current quote, labelled `قیمت هر گرم طلای ۱۸ عیار`, with an explicit تومان/ریال unit. Show last received time, source/freshness state and `به‌روزرسانی هر ۳ دقیقه` in smaller readable text. 24K/USD may appear as subordinate information; they must not compete with the main price.
2. Place the prominent `شروع` button immediately below that price. At this stage it starts the calculation/draft flow, not finalization. The next view supports the agreed repeatable GOLD/MISC item editor and review/issue/print/share path.
3. At entry and before starting a new gold calculation, retrieve the latest available normalized server quote. Use its actual fetched_at/quote time: `current` here is the most recently acquired feed value, not a guarantee of tick-by-tick market pricing. Do not insert a mock number or label an unavailable/stale quote live.
4. Start captures the explicitly displayed/accepted effective rate and quote identifier/provenance in the calculation/draft. If a new server value differs from what was displayed during Start validation, update the visible rate and ask the user to accept that new value before proceeding. Do not silently substitute a rate the user has not seen. Tenant overrides are visibly distinguished from the market reference and captured accordingly.
5. After Start, periodic feed changes update the market indicator only. Existing line calculations remain on their accepted transaction rate until the merchant selects `استفاده از نرخ جدید`; issued snapshots remain immutable. Any accepted repricing updates affected gold rows and review fingerprint, never MISC prices.
6. For an unavailable/stale feed, provide clearly labeled manual-rate recovery under the configured policy; MISC-only invoice creation must remain possible without a gold quote. Network failures do not erase rows or pretend a draft was issued. For standalone Calculator access with Invoices disabled, the same current-price/Start interaction launches only the calculator, preserving module independence.

### Quote board (مظنه) and the Golden Calculator (ماشین‌حساب طلایی) — owner requirement

Two always-available entries in the main navigation (mobile tabs «مظنه» and «ماشین‌حساب»; desktop menu items), contract in `../MAZNEH_AND_CALCULATOR.md`:

- **مظنه** shows the latest normalized quotes in a plain, readable layout: 18K buy-from-customer, 18K sell-to-customer (the invoice reference, `GOLD_18_SELL`), 24K, USD/IRR and the global ounce in USD, each with real `fetched_at`, freshness and change versus the previous central fetch. Same 180-second central cadence, no browser-to-provider calls, no claim of tick-by-tick prices, offline/stale labels, no fabricated buy price when the provider lacks it.
- **ماشین‌حساب طلایی** is the standalone calculator: rate prefilled from مظنه and editable, weight, purity, wage, profit, discount and the applicable tax rule, computed locally with the shared `GOLD_IR_V1` preview and fixtures; it never consumes quotas, never saves, and can hand its numbers to a new draft when Invoices is enabled.

## 9. Invoice lifecycle and finalization

Read `../INVOICE_DELIVERY_AND_VERIFICATION.md` as the required contract for printed QR verification, responsive invoice views and SMS-at-issuance. These are Phase 1 requirements, not implemented capabilities.

Read `../INVOICE_CUSTOMIZATION.md` as the required business-profile and layout-editor contract. Both Basic and Professional can customize; the previous Professional-only rule is superseded. Require shop name, public business mobile and address before issuance (server enforced), with a landline when available and business-mobile fallback when absent. Optional website/social accounts/license numbers/logo belong to the profile on all plans; editing profile data never silently changes login identity. Calculator and drafts remain usable before profile completion.

Use lifecycle `DRAFT -> FINALIZED -> VOIDED` with an explicit linked replacement/revision workflow. Sharing and payment are orthogonal statuses/derived properties: a finalized invoice can be shared repeatedly and be unpaid/partially paid/paid. Avoid a single Draft->Shared->Paid enum that loses document status. Record share events without mutating legal document content.

- Draft may contain multiple independently typed GOLD/MISC items, invoice-local customer details, notes and selected template. Provide explicit add/edit/remove-row actions and a per-row name/description. Save progress with a visible save state and optimistic locking/version field.
- Finalization is one database transaction: validate tenant/permission/features, every typed row, applicable GOLD rules (only for GOLD rows), input version, reviewed fingerprint and effective rates; compute authoritative GOLD outputs and validate exact MISC manual row totals; aggregate without applying gold rules to MISC; allocate tenant invoice number; create immutable snapshot; persist audit/outbox; commit.
- Invoice number unique per tenant, allocated concurrency-safely. Finalization has a tenant-scoped idempotency key and request hash; retry same key/input returns the same result, key with different input is rejected. Double tap cannot issue two invoices.
- Issued financial inputs and outputs cannot be edited or hard-deleted by normal endpoints. Void requires permission and reason; replacement creates a new linked invoice and snapshots its own rate/rules. Existing public views clearly show void/replaced state.
- Payments and communications append independently to issued documents. Payment reversal is a recorded compensating action; no silent alteration of history.
- Full/partial sales refunds are outside Phase 1. Reserve concepts but do not simulate tax correction submission.
- Void/replacement of a linked installment agreement must be resolved explicitly in a guided workflow; block unresolved allocations/outstanding agreement linkage, never leave an active debt against a silently voided invoice.
- Print uses issued snapshot and a documented template. A4 RTL baseline, repeated table headers, long descriptions, multiple pages, footer/page break behavior, black-and-white readability, shop identity, component breakdown, net weight/purity, dates, totals and status. Test actual browser print/PDF.
- Place a secure verification QR at the physical upper-left of the issued sales invoice, retained in print/PDF. Generate first-party, with a four-module quiet zone and an initial ~30mm print-area target adjusted after actual scans. Drafts never carry a valid verification QR. Mobile merchant/customer invoice layouts reflow at 360/390/768px without shrinking A4 or requiring horizontal page scrolling; print CSS remains independent.
- Free plan shows Zarlio branding and a fixed Simple invoice layout; all plans can edit business profile data. Basic and Professional both enable `invoice.customize`, choose between Simple and Shop presets, and display an optional validated raster shop logo. Do not render untrusted SVG/HTML or arbitrary CSS. Customization uses a versioned allowlisted schema and simple live-preview controls, not arbitrary scripts.
- Layout controls cover per-block header/footer placement, right/center/left alignment, order, optional visibility, logo size, readable typography/accent/spacing, permitted item columns, public end notes/signature box and safe A4 print options. Protected shop identity/contact/address, invoice number/date/status, essential item data, totals/unit and the physical upper-left QR cannot be hidden or overlapped. Buttons/selectors work on mobile/keyboard without dragging; optional bounded drag-and-drop is secondary. Local undo/cancel/reset and explicit save preserve unsaved work and handle optimistic conflicts.
- Snapshot the full layout schema/template version and business fields/logo asset version at issue; reuse it for historical print/PDF/mobile. Keep QR verification's minimal DTO and status semantics. Downgrade preserves custom settings and issued renders; subsequent Free invoices use the fixed Free layout with an explained entitlement change, while editing remains gated server-side. Do not load editor/canvas/PDF dependencies into login/calculator/public invoice routes.

## 10. Public share links and privacy

`InvoiceShare` is a real entity: tenant_id, invoice_id, token hash, created_by, created_at, optional expires_at, revoked_at and access policy. Generate cryptographically random tokens with at least 128 bits entropy, ideally 32 random bytes URL-safe. Store token hashes; return raw token once. A copy-again UX may use a protected encrypted retrievable secret if explicitly designed, or regenerate after explaining prior-link invalidation. Do not promise re-copy of an irretrievable hash.

Public route `/i/{token}` resolves share and tenant independently of authentication. No predictable IDs, phone numbers or sequential invoice numbers in the URL. Public response uses a minimal DTO, no authenticated invoice serializer or internal Party record. Mask private phone/customer identifiers, omit customer address/private notes/credentials by default, and show only the invoice customer-visible content chosen at issue. Required public business address/contact are seller identity fields, distinct from private customer/login data. A link grants possession-based access; tell the merchant to share it with the intended customer.

Support revoke/regenerate/optional expiry and test old-token behavior. Public pages have noindex, no sitemap inclusion, restrictive Referrer-Policy, appropriate private/no-store caching and no third-party tracking. Never cache invoices through the PWA worker. Rate limit token resolution and avoid storing raw tokens in logs/analytics. Immutable URLs retain document snapshot while clearly reflecting void/replacement status.

Public link generation is independent of domain. Build a `PublicInvoiceUrlBuilder` and `TenantDomainResolver` interface; central origin works in Phase 1. Domain entities may carry PENDING/VERIFYING/ACTIVE/FAILED and verification metadata, but automatic DNS/TLS setup is future work. Never trust arbitrary Host or user redirects. If domain activation is later added, verify ownership and host allowlist before routing. Do not let custom domains bypass tenant checks.

### Printed QR verification, separate from paid sharing

`InvoiceVerification` is a tenant/invoice-scoped entity with high-entropy token hash, created_at and revocation status. Create it atomically at finalization for every plan; use 32 random URL-safe bytes and protected encrypted raw-token storage so reprints use the same URL. Resolve `/v/{token}` on the trusted central origin using a dedicated minimal verification DTO. Exclude private customer identity/contact/notes/history and all internal data; include shop identity, invoice number/date, public items and monetary breakdown sufficient to compare the paper invoice. Apply the public-route privacy/cache/log/rate-limiting rules above.

QR verifies the issued record, not delivery, payment, laboratory purity or statutory filing. Clearly show finalized/voided/replaced status; replacements do not authorize access to another invoice. A copied QR cannot certify altered paper contents, so invite comparison of invoice number/shop/items/total. Invalid/revoked/expired links never show success. Current verification requires server connectivity; offline decoding or cached data is not a fresh validation. Provide a tappable verification link for same-phone use; no in-app camera scanner or third-party QR service is required.

Verification survives quota exhaustion, period end, downgrade and ordinary share-token regeneration. Do not expire it by default; security revocation is separate and audited with explicit printed-copy consequences. Voiding updates the result rather than removing its history. This limited verification route neither consumes `invoice.link` quota nor replaces quota-controlled full `InvoiceShare` delivery/SMS features. Preserve the separate share quotas and SMS segment budgets.

## 11. Plans, features and quota engine

Enforce `can(capability)` and `quota(resource)` on server; UI only reflects them. No `if plan == professional` in business services. Capabilities combine module activation, permissions, subscription/overrides and quotas. Snapshot relevant issue-time entitlements for historical documents.

| Capability/resource | Free | Basic | Professional |
| --- | --- | --- | --- |
| calculator.use | yes | yes | yes |
| mazneh.view (quote board) | yes | yes | yes |
| invoice.finalize — issued invoices per calendar month | 50 | configurable cap (owner figure pending; seed 500) | unlimited |
| invoice.print (already issued) | yes, current-month invoices | yes | yes |
| invoice history and financial reports | current calendar month only | all | all |
| shop profile | required + optional fields | required + optional fields | required + optional fields |
| invoice.hide_provider_brand | no | yes | yes |
| invoice.shop_logo | no | yes | yes |
| invoice.customize | no | yes | yes |
| invoice_links limit per configured period | 10 | 100 | 1000 |
| invoice.social_share | yes | yes | yes |
| invoice.sms_share | 5 free SMS per year, then prepaid credit (850 toman/segment, min 400,000 toman, expires at month end) | prepaid credit (500 toman/segment, min 100,000 toman, carries over) | prepaid credit (350 toman/segment, min 100,000 toman, carries over) |
| sms.template_edit | no | yes | yes |
| customers.manage — new customers per calendar month | 50 | configurable cap (owner figure pending; seed 500) | unlimited |
| installments.manage | no | no | yes |
| installments.sms_remind | no | no | yes |

Quota assumptions for the initial seed: one subscription-month interval, displayed start/end in tenant local time; Free uses its own explicit monthly anchor. The owner has not specified a period, so make this configurable and record the assumption. All timestamps use half-open intervals and a frozen clock in tests.

- Link quota counts first successful share creation for a distinct invoice in the period. Re-copy/share via social/reuse active link and token regeneration do not count again. Revocation does not refund historical usage. Document this counting rule; quota consumption is transactional and concurrency-safe.
- Owner decisions 2026-10-05 (`../PLANS_AND_QUOTAS.md` is the single source): Free issues at most 50 invoices and registers at most 50 new customers per calendar month; Basic has higher configurable caps (figures pending); Professional is unlimited. Free sees only the current calendar month's invoices and no financial reports; data is retained, the verification QR and existing links keep working, and upgrading restores history. Printing an already issued invoice, the quote board (مظنه) and the calculator never depend on quotas. Link quota exhaustion never prevents calculation or printing. Abuse controls are separate from plan limits.
- Historical valid links remain readable after downgrade/period end unless revoked/expired; creation rights follow current entitlements. Downgrade preserves issued snapshots and financial records. Customer/installment records remain readable/exportable, and payment recording for existing debts remains available with appropriate permission; new agreements/customers and paid reminders are gated. Do not strand repayment workflows behind an upgrade.
- Five free SMS per tenant per year (owner decision 2026-10-05; replaces the earlier lifetime allowance) are separate from plan definitions. An explicit trial grant enables only invoice SMS sharing even on Free until exhausted; free template remains fixed. Professional reminders require the appropriate feature and paid SMS balance. Trial credits are not refreshed by changing plans; they reset yearly.
- Link quota, SMS message segments, paid SMS balance and operational OTP budget are different resources. Display them separately.
- Online payment (owner decision 2026-10-05): Basic/Professional purchase and SMS credit are paid through a bank payment gateway behind a `PaymentGateway` adapter, with server-side verify using the stored amount, idempotent fulfillment, a server-sourced result page (success/failed/pending) and reconciliation; see `../PAYMENTS_AND_SMS_CREDIT.md`. Plan and SMS-credit prices exclude VAT; 10% VAT (configurable `tax.vat_rate_percent`, snapshotted per order) is added at checkout and shown separately everywhere a price is paid; SMS credit equals the pre-VAT pack amount. The concrete PSP is pending owner selection; build and test with `MockGateway`. Provider can still activate/extend subscriptions and adjust credit manually with audit. Prices, checkout and renewal commercial terms remain configuration, not invented values.

## 12. SMS and notifications

Prepaid SMS credit (owner decision 2026-10-05, configuration in `../PLANS_AND_QUOTAS.md` §3): tenants buy a toman balance (packs 100k/200k/300k/500k/1M toman; minimum 100k on Basic/Professional, 400k on Free) and each sent segment is charged at the current plan's per-segment price (Free 850, Basic 500, Professional 350 toman; a three-segment message costs three times that). Basic/Professional balances carry over month to month; Free balance expires at the end of the calendar month with an audited expiry event. Reserve on send, refund on failure, hold while unknown; OTP never draws on it. Behaviour of a remaining balance on plan change is an assumption to confirm with the owner.

Adapters: SMS provider send/status/webhook, template renderer, segment estimator and notification dispatcher. Use persisted message ID, tenant context and idempotency key.

- Review exposes `شماره موبایل مشتری` with Persian/Latin digits and 09/+98 normalization, reason for collection and edit action. It is required for explicit `صدور و ارسال پیامکی`, optional for `فقط صدور`; no customer account/OTP or Professional Party capability is required. This destination is distinct from the merchant's login mobile. Do not publish it through QR/public DTOs.
- Before issuance-and-send, show destination, final SMS text with the same invoice's secure InvoiceShare link, actual segment cost/balance and link-quota eligibility. Treat the reviewed invoice link as pending until server issuance/share creation succeeds. Explicitly selecting issuance-and-send authorizes one initial message; typing a mobile alone does not. Save/send choices and request hashes together to prevent duplicate invoice/message requests.
- Commit the invoice idempotently, then create/use its quota-controlled share and reserve/enqueue SMS through a recoverable outbox workflow with a unique invoice/initial-send intent. Preflight missing/invalid phone or known entitlement/balance failure offers only-issue/print; post-commit send/share/reservation failures leave the invoice issued and report communication failure. Never undo financial issuance or issue another invoice to retry SMS. Unknown provider outcomes follow reconciliation, not blind retry; explicit retry after definite failure targets the existing invoice with rechecked recipient/cost.
- Safe placeholders allow `{shop_name}`, `{invoice_link}`, optionally recipient-safe details. Invoice link placeholder is protected in the editor. Preview the final rendered text before send, recipient and segment cost.
- Treat the suggested 35-character link reservation as a provisional editing aid, not a valid final size rule. Estimate on the actual URL and encoding. Persian usually requires Unicode SMS; concatenation capacity and pricing are provider-specific. Define configurable segment rules; test with the selected provider contract, emoji/surrogate pairs and long URLs. Do not blindly count JavaScript string length.
- One trial credit equals one provider-billable segment in the initial assumption; show if a composed message uses multiple segments. Document/configure the policy.
- Reservation occurs atomically before queueing. Definite rejection releases reservation; accepted sends consume it. Timeout/unknown delivery remains reserved until reconciliation, preventing duplicate send/refund loops. Persist provider ID and attempt history. Provider idempotency or explicit ambiguous-send handling is required before automatic retries.
- Delivery states QUEUED/SENDING/ACCEPTED/DELIVERED/FAILED/UNKNOWN/CANCELLED are distinct. `ACCEPTED` does not mean delivered. Webhooks authenticated, replay-protected and idempotent; suppress duplicates and tenant mismatch.
- No automatic initial invoice SMS unless the merchant explicitly selects issuance-and-send or a later send action; sharing shows cost and destination. Reminders require the merchant to enable a schedule and appropriate recipient consent/opt-out handling. Do not send real messages from fixtures/testing.
- Reminders are skipped for paid/voided/inactive agreements, opted-out recipients, quiet hours and unavailable entitlement/balance. Recheck before dispatch. Use unique agreement/schedule/due-date/policy keys so scheduler retries do not spam.
- Jobs failing to send do not change invoice amount or payment state. Show retry/recovery in Persian and provider health separately.

## 13. Customers and installments

Customer records in every plan, with monthly new-customer caps on Free and Basic (`../PLANS_AND_QUOTAS.md`): name, normalized phone, optional notes and minimal necessary fields. Search by phone/name with tenant scoping. Offer duplicate suggestions, never auto-merge. Limit collection of national ID/address to a confirmed business need; those are not default required fields.

Invoice-local fields allow issuing without onboarding a customer. Users may save/link them to a Party explicitly within their plan's monthly cap. Customer history derives from scoped records and is understandable as `فاکتورها`, `پرداخت‌ها`, `اقساط`, not ledger jargon.

Installment MVP:

1. Link to a finalized invoice or start a standalone agreement with a customer and explicit principal.
2. Input down payment actually received, count, first due date, frequency and optional custom schedule. No interest/late fee engine in Phase 1; amounts cannot silently include financing charges.
3. For an invoice, principal is invoice final total minus posted allocated payments/down payment. Prevent duplicate active agreements/over-allocation to the same invoice. Validate currency and linked Party ownership.
4. Draft preview shows every due date, each amount, total installments, actual down payment and principal. Exact integer-rial amounts sum to principal; distribute remainder deterministically to the final installment or another documented policy.
5. Persian month/date scheduling must define end-of-month behavior, leap years and timezone. Store due dates as local business dates and tested conversion; do not approximate a month as 30 days.
6. Manual payments record amount, paid_at, method (cash/card/bank/other), reference, actor and optional note; card entry is a reference, never sensitive card credentials. Receipts are idempotent. No gateway settlement is implied.
7. Allocate payments to schedule lines oldest due first by explicit policy; support partial payments. Lock affected agreement/rows to avoid overpayment under concurrency. Reject excess unless an explicit credit policy is later added.
8. Balance and PAID/PARTIAL/DUE/OVERDUE derive from schedule and valid allocations. Reversals restore balance with an audit trail. No double-entry accounting claim.
9. At most one active reminder policy per applicable line/channel; merchant controls days before/after, quiet hours and timezone. Explain delivery failure and credit shortage without deleting debt.
10. Void/replacement of linked invoices includes an explicit agreement settlement/transfer/cancellation process with preserved records. No unpaid obligations are dropped.

## 14. App navigation, PWA and offline

Routes include `/app/calculator`, `/app/invoices`, `/app/invoices/new`, `/app/invoices/{id}`, `/app/customers`, `/app/installments`, `/app/settings`; provider routes under `/provider`. Use client navigation, browser back/forward and deep links that work after refresh. Define shared navigation and pending/save states. Do not reload the entire page on routine module navigation.

- Manifest with name/short_name, versioned icons including maskable, start_url, scope and standalone display; HTTPS deployment documented. Provide an install action only when browser support permits; explain iOS installation as needed.
- Worker caches static app-shell assets and a controlled offline fallback. Never cache authenticated HTML/API, public invoices, OTP, mutable financial records or tokens with a generic cache-all strategy.
- Offline calculator uses locally available manual/last-known quote and exact arithmetic with `آفلاین؛ آخرین نرخ دریافت‌شده ...`. Never finalizes or sends SMS offline. If policy/rules are missing offline, explain limitations.
- Local draft storage is opt-in, minimal and tenant/user-scoped; avoid customer PII by default, with expiry and visible deletion. Clear on logout/tenant switch. If customers are needed, design explicit security/storage consent rather than silently persisting them.
- Draft synchronization after reconnect is explicit, version/conflict-aware and never auto-issues. Revalidate rates and effective rules before a review/finalization.
- Worker updates show a nonintrusive refresh prompt; never replace the page during unsaved calculation. Handle network retry without duplicate mutations.
- iOS/Android/desktop capability detection and progressive enhancement; no invented guarantee of unpublished 2027 standards. Web Push/native notifications are future options, not a Phase 1 deliverable.

### Performance-first delivery on weak internet

Treat `docs/PERFORMANCE_BUDGET.md` as the required performance contract, including compressed route/asset budgets, production-build checks, specified cold/warm network profiles and honest evidence reporting. Its numbers are project targets, not previously measured performance. Library choice remains pending the owner, and must then pass a representative production-slice measurement.

- Split routes and import only needed components/icons. Login/calculator must not eagerly fetch customer tables, provider screens, charts, heavyweight PDF code or entire UI/icon libraries.
- Use exact local preview so typing weight, changing purity and entering miscellaneous prices do not need a network round trip per keystroke. Keep server-authoritative financial commits and existing offline/security policies intact.
- Self-host essential fonts/assets; avoid blocking third-party CDNs, remote fonts or decorative media. Static caching/compression never means caching private invoices or auth data.
- Keep payloads small and paginated, poll only compact quotes with pause/backoff, and avoid broad prefetch on constrained networks. No artificial splash/animation delay or destructive refresh while editing.
- Public invoice is a lightweight server-rendered view independent of the merchant application bundle. Select host/CDN only after latency/reachability evidence from the intended market; no assumption that a named provider is always faster in Iran.
- Verify loading and usable input on the specified slow network/CPU profiles, not only localhost or a Lighthouse score. Record budget regressions; do not claim measured speed until tests are actually run.

## 15. UX requirements and provisional design boundary

Read `UI_UX_DISCOVERY_PROMPT.md`. Design around the merchant's transaction, not a generic KPI dashboard. The merchant home emphasizes starting a calculation, continuing a draft and finding a recent invoice; provider operational metrics belong in provider UI.

- Always explicit تومان/ریال, گرم, عیار and percent next to numeric inputs and totals.
- Persian/Arabic/Latin number entry, pasted thousand separators and Persian decimal separator supported through a strict parser. Preserve caret position; do not reformat every keystroke destructively. Ambiguous currency input requires explicit unit, not a guess.
- Progressive disclosure for less-common fields. Main path: calculate -> review -> finalize -> print/share. No mandatory customer registration for a simple cash invoice.
- Clear labels (`صدور فاکتور`, `پیش‌نویس`, `چاپ`, `کپی لینک`, `ثبت پرداخت`) instead of internal jargon. Inline help explains consequences and actual state.
- A large clear total and breakdown must remain readable. New feed quotes, unsaved work, quota limit and offline state have distinct copy/actions.
- Input errors preserve all fields; predictable numeric keypad, tab order and touch targets. Critical failures get persistent recoverable feedback, not toast-only messages.
- Show immutable-document consequences before issue and recoverable state on return. Confirmation is reserved for meaningful irreversible actions, not every click.
- Target accessible WCAG 2.2 AA interactions/contrast; actual browser/assistive tests required. Toolkit marketing is not accessibility evidence.

Follow `docs/prompts/UI_UX_RAPID_IMPLEMENTATION_PROMPT.md`: first prepare a concrete overall wireframe/visual draft with proposed colors, logo/wordmark, typography and toolkit; obtain explicit owner approval of that review package before detailed production UI. Proposed colors/logos are allowed in this preliminary stage, but are not final choices. Do not install an unselected toolkit/design skill or copy a dashboard starter. After consolidated approval, execute the remaining authorized UI work efficiently without asking again for routine per-page details. Uniqueness is in product-specific composition, typography, hierarchy and helpful behavior; changing library alone does not solve generic design.

## 16. Security, privacy and reliability

- Authorization for every action, tenant ownership at object boundaries, CSRF/XSS/injection defenses, escaped merchant text and constrained uploads. No arbitrary template HTML/CSS execution.
- Secure secrets, minimal public DTOs, cache/log redaction, encrypted sensitive integration credentials and appropriate protected storage. Never commit credentials or actual customer data.
- Audit who changed prices, tax profiles, permissions, subscriptions, finalized/voided invoices, shared/revoked links and recorded/reversed payments. Avoid logging private payloads/codes/tokens. Preserve append-only audit semantics; retention policy is configurable.
- Atomic transactions/locks for invoice numbering, finalization, quotas, SMS reservations and installment allocation. Prefer idempotent services and transactional outbox to brittle sequences.
- Document backups and a restore drill, key/asset handling, queue/scheduler operation, failed-job recovery and provider outage behavior. A backup checkbox without a tested recovery procedure is not enough.
- Define request validation, optimistic concurrency and structured error responses; merchant-friendly text, technical trace IDs for support. No stack traces in production.
- Basic health/metrics: quote freshness, queue lag, provider error rate, reminder backlog, reservations awaiting reconciliation and failed financial operations. Provider screens do not expose private merchant details unnecessarily.
- Asset retention must preserve referenced issued logos/templates; later file cleanup cannot break historical invoices.

## 17. Implementation milestones

### M0 — Decisions and skeleton

Inspect repo; document assumptions, dependency versions, ERD, module contracts, tenant strategy, numeric/rounding/currency conventions and UI-selection boundary. Set up app, migrations, dev fixtures, CI, exact arithmetic and framework checks. No unselected toolkit installation.

### M1 — SaaS identity and isolation

Tenant/membership, mobile OTP development and real adapter contract, optional Passkey enrollment/login/management with SMS recovery, roles/permissions, plans/features/quotas, provider administration foundations. Integration tests prove tenant isolation across HTTP, jobs and cache. Seed at least two demo tenants with distinct data.

### M2 — Quotes and calculator

Mock/live provider adapter path with explicit mode, freshness, overrides, effective tax rules, pure exact engine, fixtures and standalone calculator contract. Verify sample formulas and browser/server parity. Manual pricing works without market module/integration.

### M3 — Invoice vertical slice

Drafts with repeatable GOLD/MISC rows, per-row product name/description, editable gold purity and manual miscellaneous prices; mixed-type totals, review fingerprint, idempotent finalization/numbering, issued snapshots, history and void/replacement path, print layout, secure share token lifecycle and transactional link quota. Test rates/profile/plan changes do not rewrite issued content.

### M4 — Messaging and Professional workflows

SMS adapter/outbox/reservations/status, final-text preview, five free SMS per year, prepaid toman credit lots/ledger, Billing with MockGateway (plan purchase, SMS top-up, result page, reconcile), provider health; Party records, standalone and invoice-linked installments, exact schedules, partial payments/reversal, overdue and idempotent consent-aware reminders.

### M5 — Selected UI, PWA and release verification

After the owner supplies the chosen toolkit/design direction, create the final design contract and implement merchant/provider/public UI. Verify novice flows, phone/desktop RTL, printing, accessibility, offline, deep links, updates and the required low-bandwidth budgets/profiles. Write operations/runbook, integration setup and release checklist.

Each milestone ends with changed files, checks actually run, evidence, known limitations and next step. Update the checklist honestly. Do not mark live integration, user research or production readiness complete when only mocked.

## 18. Required test coverage and acceptance

Tests must verify behavior and invariants, not merely mirror methods:

- Exact calculation examples plus boundary/property tests, component discount reconciliation, line totals, purity and currency conversion; client/server fixtures identical.
- Repeatable GOLD/MISC rows, MISC-only and mixed invoices, editable 18K-default per-row purity, independent recalculation, manual final-row prices, conditional validation, type switching, add/remove recovery, stable ordering and name/description snapshots in print/public views. Verify MISC rows never enter gold taxable bases or gold-weight totals.
- Cross-tenant read/write/foreign-key attacks, public share lookup, staff permissions, provider boundary, queue/cache scopes.
- Mobile-first login/registration plus opt-in Passkey after verified SMS login; normalized unique phone identity, no duplicate user/tenant after verification retries, invitation/member authorization and safe return routing; login remains independent of tenant sharing credits. OTP expiry/reuse/brute-force/rate limiting and secret redaction.
- Passkey enrollment authorization, distinct one-use challenges, cryptographic assertion verification, origin/RP/ownership checks, replay/expiry, user verification, revocation, unsupported/cancelled flows and SMS recovery; virtual-authenticator tests distinguished from real fingerprint/face/PIN device checks.
- 180-second central/client gold-refresh cadence, foreground/reconnect refresh, single-flight provider calls, failed-fetch freshness and frozen transaction-rate behavior. New-invoice entry shows large current price and Start below it; changed-value acceptance, stale/manual and MISC-only paths work. Global quote remains unchanged by tenant edits.
- Effective tax-rule switching and immutable historical snapshots.
- Duplicate finalization/retry/concurrent numbering and forbidden mutations of issued inputs.
- Business-profile issuance guard, optional landline/business-mobile fallback and private login/customer distinction; both presets and Basic/Professional editor rights vs Free fixed layout, direct-API permission/tenant checks, protected fields/QR, mobile controls, local undo/reset/conflicts and logo validation. Shop/layout/asset/plan changes preserve historical renders; new downgraded invoices follow Free rules.
- Printed upper-left QR resolves the same issued snapshot; stable reprint, void/replacement/revocation, privacy, cross-tenant/invalid tokens and quota-independent verification checked. Actual PDF/paper scan and mobile responsive invoice evidence required; no draft/offline false validation.
- Customer-mobile issue-and-SMS vs only-issue, Persian-number normalization, missing/invalid destination, insufficient link/SMS quota, post-issue share/send failure and unknown reconciliation; repeated taps/network retries yield one invoice and one initial send intent.
- Billing per `../PAYMENTS_AND_SMS_CREDIT.md` §11: tampered amounts rejected, success/cancel/amount-mismatch/timeout/duplicate-callback scenarios, single fulfillment, result page from server state only, SMS credit per-segment pricing, FIFO expiry and Free month-end expiry.
- Quota races, period boundaries, trial grants, revocation/regeneration, downgrade and preserved existing repayment/read behavior.
- SMS Unicode/URL segment estimates, reservation failure/unknown reconciliation, idempotency/webhook duplicates, opted-out/paid/void reminders skipped.
- Installment remainder/month-end/partial payments/reversals/concurrent allocation and invoice-void linkage handling.
- Browser flows: login -> calculate -> review -> issue -> print/share; Professional customer -> schedule -> record payment; offline -> recovery without duplicate issue; direct URL and back/forward.
- RTL screenshots at 360/390/768/1280 widths, long Persian names, large values, 200% zoom and keyboard focus; multi-page print/PDF smoke check.
- Production-build route/transfer budgets, cold/warm weak-network profiles, exact local-preview responsiveness, critical third-party-domain independence and interrupted/chunk-retry journeys per `docs/PERFORMANCE_BUDGET.md`; speed targets remain pending until evidenced.

## 19. Deliverables and completion report

Deliver working application code within the authorized implementation scope, migrations/seeders, lockfiles, setup/environment docs, ERD/module map, ADRs, operations guidance, feature/quota definitions, exact fixtures, meaningful tests and a completed evidence-backed checklist.

Before final reporting run appropriate tests, lint/type/build checks and browser/print checks available in the environment. State missing credentials/browser/tool support plainly and distinguish mock from live providers. Summarize what works, how verified and what remains. Do not claim a legal Modian integration, a native app, a certified tax invoice or field-tested novice usability.

For the UI execution stage, use the rapid implementation prompt to prepare the overall draft and a consolidated owner decision on toolkit, colors, logo and structure. That approval precedes detailed UI coding; it does not certify mocks as real financial integrations.
