# ADR 0001 — Blade + vanilla JS (AJAX) for the merchant web app

Date: 2026-10-06 · Status: accepted for Phase 1 implementation

## Context
The owner asked for a complete Laravel web app using AJAX where needed. CLAUDE.md forbids defaulting to shadcn/generic SaaS/unrelated React stacks and requires fast loading on weak Iranian internet (docs/PERFORMANCE_BUDGET.md). No UI toolkit has been selected by the owner.

## Decision
- Server-rendered Blade pages (Persian RTL) with small per-page ES modules loaded lazily by `body[data-page]` (`resources/js/app.js` + `resources/js/pages/*`).
- `fetch`-based JSON endpoints under `/api/*` (session + CSRF) for autosave, issue, SMS, sharing, billing, customers, settings.
- Plain CSS with the proposed design tokens (palette 1 working default); no third-party UI library.
- Exact money preview in the browser with BigInt (`resources/js/lib/pricing.js`) mirroring `app/Domain/Pricing/GoldIrV1.php`; parity proven by shared vectors.
- Strict CSP: no inline scripts/styles; boot data passed as `<script type="application/json">`.

## Consequences
- Built output: ~9 KB gzip shared JS + 1–4 KB per page; CSS ~6 KB gzip (measured by `npm run build`, not a field measurement).
- Swapping to Inertia/Vue later is possible because domain logic lives in `app/Domain/*` services and JSON endpoints.
- Visual direction is still a proposal; changing tokens in `resources/css/app.css` re-themes the app.
