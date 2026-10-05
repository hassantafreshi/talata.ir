# Talata project instructions

This repository currently contains planning documents. The owner requested prompts, not an application implementation in this documentation change.

For future implementation requests, read:

1. `docs/prompts/PHASE_1_MASTER_PROMPT.md`
2. `docs/prompts/UI_UX_DISCOVERY_PROMPT.md`
3. `docs/PHASE_1_CHECKLIST.md`
4. `docs/research/UI_UX_TOOL_SHORTLIST.md` when choosing design tools.
5. `docs/INVOICE_DELIVERY_AND_VERIFICATION.md` for invoice QR verification, responsive invoice views and customer-mobile SMS at issuance.
6. `docs/INVOICE_CUSTOMIZATION.md` for mandatory business profile, contact fallback, two preset invoices and novice-friendly layout editing on Basic AND Professional.
7. `docs/design/README.md` and `docs/design/UI_APPROVED_DECISIONS.md` for the Stage A proposal status, owner-stated UI requirements and which design decisions are actually approved (brand spelling: طلاتا / Talata).
8. `docs/prompts/UI_UX_RAPID_IMPLEMENTATION_PROMPT.md` for UI execution: prepare an overall wireframe/visual draft, get consolidated owner approval of colors/logo/font/toolkit, then implement details rapidly.

## Durable constraints

- Laravel modular monolith; true tenant isolation from the first migration.
- Persian-first, RTL, designed for very low digital literacy.
- Fast usable loading on weak/unreliable Iran internet is a core acceptance requirement. Read `docs/PERFORMANCE_BUDGET.md`; split routes/import only needed components, self-host critical assets and measure production cold/warm slow-network journeys. Do not claim a toolkit is lightweight or timings are achieved without evidence.
- Mobile number is the primary account identifier; first login/registration uses SMS OTP, without mandatory username/email/password. Afterwards users may opt into WebAuthn/Passkey login via fingerprint, face or device lock. Keep SMS recovery, verify assertions server-side, never collect biometrics, and preserve tenant authorization; login messages do not consume invoice-sharing trial credits.
- Decimal money/weight/rates, explicit units, authoritative server calculations.
- Snapshot finalized documents. Never silently recalculate historical invoices.
- Issued sales invoices have a stable secure verification QR at the physical upper-left, preserved in print/PDF, plus a responsive mobile invoice view. A minimal verification token is separate from quota-controlled InvoiceShare; verify the issued record and current void/replacement status without exposing customer PII.
- Review includes customer mobile and explicit صدور و ارسال پیامکی / فقط صدور actions. SMS shares the same issued invoice via outbox; failures/unknown sends do not roll back or duplicate issuance. Customer login/OTP or Professional customer management is not required.
- Draft invoices support repeated GOLD/MISC rows and per-row product name/description. GOLD defaults to 18K/750 with editable purity and proportional pricing; MISC requires manual title and final row price and never inherits gold calculations/tax classification.
- Features and quotas enforced server-side; no business logic branching on plan names.
- Require shop name, business mobile and address before first invoice issuance; landline if available, with business mobile as fallback. Website/social accounts/licenses/logo are optional profile fields. Keep public business contact distinct from login and customer mobile.
- Both Basic and Professional have invoice.customize: two presets, live print/mobile preview, simple header/footer and right/center/left controls for business blocks/logo, optional-field visibility and guarded appearance/column settings. Free uses a fixed layout. Protect required invoice data and upper-left QR; snapshot the layout/assets so later edits/downgrades never rewrite issued invoices.
- Provider integrations behind adapters. Demo data must be visibly labelled.
- Gold feed refreshes centrally every 180 seconds. New-invoice entry shows the current 18K price prominently with شروع immediately below; Start captures the displayed accepted rate, and background updates never silently reprice the transaction.
- No full accounting, inventory, silver, melted-gold, Modian integration, or commerce in Phase 1.
- Existing specification assumptions must be documented and configurable, not presented as discovered business facts.
- UI toolkit, design skill and visual direction are PENDING OWNER SELECTION.
- Do not default to shadcn, a generic SaaS dashboard, or an unrelated React/Next stack.
- No third-party design skill or library installation until selected. Reading public documentation is allowed.
- UI execution follows the prepared rapid implementation prompt. The owner explicitly requires the overall draft and colors/logo to be approved before detailed production UI. Propose a concrete review package first; after approval proceed without repeated per-page confirmation. Backend foundations, domain logic, tests and neutral UX flows can proceed under a future implementation request.
- Never claim legal certification, live integration, usability testing, or passed checks without evidence.

Record progress and decisions so subsequent sessions continue rather than restart.
