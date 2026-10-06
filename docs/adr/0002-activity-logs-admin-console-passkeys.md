# ADR 0002 — Activity/technical logs, admin console, passkeys, Kavenegar

Date: 2026-10-06 · Status: accepted (owner request)

## Decisions
- **Activity log** reuses the append-only `audit_events` table, extended with `service`, `staff_id`, `request_id`, `user_agent`. Service is derived from the event prefix (`Audit::SERVICES`), so new events need no schema change.
- **Technical log** is a Monolog handler writing to `system_logs` through a second connection (`pgsql_log`) so entries survive request-transaction rollbacks. Channel `tech` (per service via `TechLog::*`) and `errors_db` (warnings+ of the whole app). Secrets and mobiles are redacted before storage. Retention is enforced by a scheduled prune.
- **Admin console** (`/admin`) is a separate surface: `staff` guard + `staff_users` table, its own session cookie scoped to `/admin`, idle timeout, optional IP allowlist, roles admin/support. Logs are readable only there; merchants have no log routes.
- **Passkeys**: own minimal WebAuthn implementation (CBOR + COSE ES256/RS256, attestation none, UV required) instead of a third-party package, to keep the dependency surface small; covered by a software-authenticator test suite and a Chromium virtual-authenticator E2E run. One `passkeys` table serves merchants and staff (`owner_type`).
- **Kavenegar** behind the existing `SmsGateway` adapter; login codes via Verify Lookup when a template is configured. OTP codes are stored only encrypted and erased after the send attempt.

## Consequences
- Admin role management is CLI-only (`talata:staff`) until a staff-management UI is specified.
- Kavenegar integration is verified against a faked HTTP API only; a live send needs the owner's API key, sender line and approved OTP template.
