# Talata project instructions

This repository currently contains planning documents. The owner requested prompts, not an application implementation in this documentation change.

For future implementation requests, read:

1. `docs/prompts/PHASE_1_MASTER_PROMPT.md`
2. `docs/prompts/UI_UX_DISCOVERY_PROMPT.md`
3. `docs/PHASE_1_CHECKLIST.md`
4. `docs/research/UI_UX_TOOL_SHORTLIST.md` when choosing design tools.

## Durable constraints

- Laravel modular monolith; true tenant isolation from the first migration.
- Persian-first, RTL, designed for very low digital literacy.
- Fast usable loading on weak/unreliable Iran internet is a core acceptance requirement. Read `docs/PERFORMANCE_BUDGET.md`; split routes/import only needed components, self-host critical assets and measure production cold/warm slow-network journeys. Do not claim a toolkit is lightweight or timings are achieved without evidence.
- Mobile number is the primary account identifier; first login/registration uses SMS OTP, without mandatory username/email/password. Afterwards users may opt into WebAuthn/Passkey login via fingerprint, face or device lock. Keep SMS recovery, verify assertions server-side, never collect biometrics, and preserve tenant authorization; login messages do not consume invoice-sharing trial credits.
- Decimal money/weight/rates, explicit units, authoritative server calculations.
- Snapshot finalized documents. Never silently recalculate historical invoices.
- Draft invoices support repeated GOLD/MISC rows and per-row product name/description. GOLD defaults to 18K/750 with editable purity and proportional pricing; MISC requires manual title and final row price and never inherits gold calculations/tax classification.
- Features and quotas enforced server-side; no business logic branching on plan names.
- Provider integrations behind adapters. Demo data must be visibly labelled.
- No full accounting, inventory, silver, melted-gold, Modian integration, or commerce in Phase 1.
- Existing specification assumptions must be documented and configurable, not presented as discovered business facts.
- UI toolkit, design skill and visual direction are PENDING OWNER SELECTION.
- Do not default to shadcn, a generic SaaS dashboard, or an unrelated React/Next stack.
- No third-party design skill or library installation until selected. Reading public documentation is allowed.
- Final UI implementation follows a later owner-selected design prompt. Backend foundations, domain logic, tests and neutral UX flows can proceed under a future implementation request.
- Never claim legal certification, live integration, usability testing, or passed checks without evidence.

Record progress and decisions so subsequent sessions continue rather than restart.
