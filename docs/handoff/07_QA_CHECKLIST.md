# QA and acceptance checklist

Tick each item with evidence (test name, screenshot path, command output). Anything not verified is listed as a known limitation, never as done.

## Per task

- [ ] All states listed for the task's screens render and have a Playwright screenshot at 390 and 1280 (admin: 1280 and 1024).
- [ ] Copy matches the spec / reference HTML; all strings come from `fa.ts`.
- [ ] Only `--t-*` tokens used; palette switch to 2 and 3 works without code changes.
- [ ] Server-side permission and tenant checks on every new endpoint, with cross-tenant tests.
- [ ] Money/weight/rate are decimals end-to-end; no float.
- [ ] Idempotency for every create/issue/pay/manual action; double-click and retry tests.
- [ ] Offline and error states handled; no blank screens.
- [ ] Accessibility: labels, focus order, 44 px targets, 200% zoom, keyboard-only path for the main flow.
- [ ] Bundle size of touched routes within `PERFORMANCE_BUDGET.md`.
- [ ] `docs/PHASE_1_CHECKLIST.md` updated; ADR added for new decisions.

## Release (v1)

- [ ] Main flow on a real phone over a throttled profile: login → rate → Start → 2 GOLD + 1 MISC rows → review → issue+SMS → print → scan QR → `/v` shows the same record.
- [ ] Calculation vectors pass in PHP and TS; printed totals equal API totals.
- [ ] Issued invoice unchanged after: rate change, tax-rule change, layout change, profile change, plan downgrade.
- [ ] Free plan caps: 50 invoices, 50 new customers, 10 links per month, 5 free SMS per year, current-month history only; notices shown; printing/مظنه/calculator never blocked.
- [ ] SMS credit: per-segment pricing by plan, VAT only at purchase, Free credit expiry at month end, unknown status reconcile.
- [ ] Payments (Mock): success, cancel, amount mismatch, timeout→reconcile, duplicate/concurrent callbacks, reopen result page; VAT shown on every price and receipt.
- [ ] Admin: staff passkey mandatory, permissions matrix enforced, every dangerous action audited with reason, no customer PII in admin responses.
- [ ] Verification page: valid/voided/replaced/invalid states; no buyer PII; QR prints upper-left on paper (physical test).
- [ ] Performance: measured cold/warm journeys on slow profiles recorded; public pages ≤ 20 KiB JS.
- [ ] Backups and a restore drill executed and documented; runbook for queues, schedulers, provider outages.
- [ ] Known limitations listed: mock gateway/SMS/quote providers, unapproved palette/logo/toolkit, sample tax rule, pending owner numbers.

## Affiliate program
- [x] Code discount on the first plan purchase only; VAT on the discounted amount; base, discount, VAT and payable shown separately.
- [x] Commission = % of the pre-VAT amount paid; FIRST_PAYMENT vs LIFETIME; SMS credit only when enabled; one commission per payment.
- [x] Attribution: link at new signup, code after the first successful payment; never changes; self-referral, old or paid shops and paused codes rejected.
- [x] Affiliate panel shows only masked buyer mobiles (first 3 + last 3) and income; non-affiliates get 404.
- [x] Pending → payable after the hold days; payout with bank reference; void with reason; support role read-only.
