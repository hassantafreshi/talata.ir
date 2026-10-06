# ADR 0003 — Tenant isolation strategy

Date: 2026-10-11 · Status: accepted (records the design built since 2026-10-06; constraint from CLAUDE.md "true tenant isolation from the first migration")

## Context
One PostgreSQL database serves every shop (tenant). A merchant must never read or change another shop's data, including through IDs placed in a URL or a payload. The app also has cross-tenant surfaces by design: public invoice/verification links, the service admin console, scheduled jobs and the payment callback.

## Decisions
- **Row-level tenancy.** Every merchant-owned table has a non-null `tenant_id`. The schema is in `docs/ERD.md` (generated with `php artisan talata:erd`).
- **Fail-closed global scope.** Merchant models use `App\Tenancy\BelongsToTenant`. Inside a request the scope filters by the current tenant. With no tenant context, a query returns nothing (`1 = 0`) instead of everything. Creating a row without a context throws. A write whose `tenant_id` differs from the context throws. `tenant_id` can never be changed after creation.
- **Tenant context from the session, checked against membership.** `ResolveTenant` reads the chosen shop from the session and requires an *active* membership for the signed-in user. Suspended shops are refused with `TENANT_SUSPENDED`. Removing a member deletes that person's sessions at once.
- **Opaque public ids.** URLs and payloads carry ULID `public_id`s, never sequential ids. Route-model binding goes through the scoped query, so another shop's id resolves to 404. The same holds for ids inside payloads: `invoice_id` in an installment agreement, or the buyer's customer looked up by mobile, are both looked up through the scope (`tests/Feature/QuotaAndIsolationTest.php`).
- **Single-column foreign keys, not composite tenant keys.** A row can only reference another row it was able to look up, and every lookup is scoped. So a cross-tenant reference cannot be formed through the app, and composite `(tenant_id, id)` keys would add little at real cost (wider indexes, every relation declared twice). Planning documents that mention composite keys are superseded by this ADR.
- **Explicit, reviewed opt-outs.** `withoutGlobalScope('tenant')` is used only where cross-tenant access is intended:
  - public token pages, which look up by hash of a high-entropy token and expose only the public view;
  - the admin console, behind the `staff` guard, mandatory passkey and audit;
  - scheduled jobs and queue workers, which filter by `tenant_id` explicitly;
  - quota counting;
  - the payment callback, matched by order and gateway authority.

  Each use filters by `tenant_id` or a token. New uses are a review item.
- **Locks per tenant for counters.** Quota checks, invoice numbering, SMS credit and invites run under a lock on the tenant row, so parallel requests cannot exceed a cap or duplicate a number.
- **Files** are stored per tenant (`logos/{tenant public_id}/v{n}.png`) and served only by that path. Old versions are kept so issued invoices keep their snapshot logo.

## Consequences
- A missed scope fails closed (empty result or exception), not open.
- Each new merchant model must use `BelongsToTenant`. Each new `withoutGlobalScope('tenant')` must state why in code and be covered by a test.
- Evidence: `tests/Feature/TenantIsolationTest.php`, `QuotaAndIsolationTest.php`, `TeamPermissionsTest.php`, `PasskeyTest::test_passkey_never_opens_a_removed_membership_or_a_suspended_shop`.
