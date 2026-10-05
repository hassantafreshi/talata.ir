# Talata — low-bandwidth performance contract

Owner priority: fast loading on weak/unreliable internet in Iran. Status: implementation targets, not measured results. These are project budgets; they are not claims about all Iranian networks or guaranteed timings on every device.

## Measurement and budgets

Measure production builds, not Vite development/HMR. Count every resource needed until the essential screen is usable, including shared/vendor/route JS, CSS, fonts, HTML/JSON and visible images. KiB = 1024 bytes. Use gzip-equivalent asset sizes for reproducible CI comparison, plus actual browser transferred bytes/encoding. A single small entry file does not pass if it imports large dependent chunks.

| Critical screen | Initial JS budget, gzip | Total essential first-load transfer budget | Essential behavior |
| --- | ---: | ---: | --- |
| Mobile/SMS login | <=150 KiB | <=300 KiB | mobile input and request-code action usable |
| New-invoice price/Start entry | <=150 KiB | <=300 KiB | large current 18K price/status and Start action; manual/MISC recovery if quote missing |
| Gold calculator/invoice draft composer | <=220 KiB | <=400 KiB | input, exact local preview and repeatable GOLD/MISC rows usable without waiting for live quotes |
| Public customer invoice | <=20 KiB | <=180 KiB | readable server-rendered document and browser print action |

Additional targets: essential CSS <=30 KiB; essential Persian font downloads combined <=80 KiB; normal paginated data response <=50 KiB (large multi-item documents measured separately); no more than 8 essential requests for login and 12 for calculator. Reference list pagination starts at 25 items with an explicit maximum. Avoid many tiny serial chunks that amplify high-latency round trips.

Budgets are release gates. If a measured requirement legitimately exceeds one, first reduce unnecessary dependencies, props and assets; document the reason, trace and proposed adjustment instead of silently raising the limit. Accessibility, correct arithmetic and security must not be removed to make a bundle smaller.

## Reproducible network/device profiles

Use a supported browser and a documented lower-end mobile/emulation CPU setup, with fixed fixtures and production deployment/build. Record tool, version, bandwidth, RTT, CPU multiplier, device, cache state, compression and geography/test location. Throttling is repeatable test coverage; it is not a substitute for eventual real-device tests from the intended market.

| Profile | Download/upload | RTT | CPU slowdown | Initial screen-usable targets |
| --- | --- | --- | --- | --- |
| A — weak mobile baseline | 1.6/0.75 Mbps | 300ms | 4x | login <=4s; calculator <=5s; public invoice <=3s |
| B — severe constraint | 0.4/0.2 Mbps | 800ms | 6x | login <=10s; calculator <=12s; public invoice <=8s; no empty/crashed screen |
| C — disconnected/interrupted | offline / interruption during request | n/a | lower-end mobile | already available calculator/manual draft inputs remain usable; no false issued/sent status |

Run at least five cold and five warm journeys for each baseline comparison, report median and worst case and retain traces; do not claim population percentiles from that sample. On profile A with cached static assets, target a repeat calculator start <=1.5s when essential app/auth responses are healthy. Network wait for SMS delivery is separate from page-loading time; show honest delivery/wait status.

Track LCP, interaction responsiveness and layout shifts in lab and, after release with privacy-safe instrumentation, real-user measurements. Use current Core Web Vitals guidance: p75 LCP <=2.5s, INP <=200ms, CLS <=0.1 across relevant device groups. These field targets and the custom throttled screen-usable timings are different measures; passing a Lighthouse score alone does not prove the merchant journey works on weak networks. Local numeric preview should respond within 100ms at p95 on the specified reference setup without a server request per keystroke.

## Implementation rules

1. **Small initial app:** route-level splitting, individual component/icon imports and bundle inspection. Calculator does not import provider administration, rich tables, charts, PDF engines or entire icon sets. Load customer management, installments and uncommon editors on navigation/action. Do not eagerly bundle every Inertia page.
2. **Selected toolkit:** one primary component toolkit. PrimeVue supports component-level imports, but Unstyled does not automatically remove component JavaScript or guarantee a small bundle. Reka/Nuxt UI also require measurements. The chosen representative login/calculator/mixed-invoice production slice must pass budgets before the toolkit is locked; do not claim a library is fastest from package download/install size. Design Skills used in development must not become browser runtime dependencies.
3. **Responsive local transaction:** exact decimal preview on-device for weight/purity/discount and MISC price. Debounce/cancel redundant server validations; request authoritative review/finalization at meaningful steps. Persist permitted drafts according to the existing opt-in/minimal-PII policy. Never simulate successful server issue/payment/SMS offline.
4. **Lean data:** route-specific DTOs, selected columns, indexed/scoped queries, eager loading where appropriate, pagination and authorized partial reloads of only needed props on the same Inertia page. Do not attach all customers, invoices, plan definitions or large nested relations to global shared props. Public invoice view is a lightweight server-rendered read model, independent of the merchant app bundle.
5. **Quotes and polling:** owner-required central gold refresh and visible-client compact quote updates every 180 seconds; fetch the latest normalized server value on entry/foreground/reconnect. Pause client polling while hidden/offline, consolidate multi-tab requests where practical, use backoff and avoid parallel duplicate provider calls. Poll quote data, not the entire page. Background quote changes never mutate accepted transaction inputs. The current-price/Start screen must not eagerly download the full editor until needed, and must display actual freshness metadata rather than resetting timestamps on failed polls.
6. **Static assets:** self-host licensed Persian WOFF2/fonts, icons and needed styles on a reliably reachable first-party path. No blocking Google Fonts, third-party script CDN, external icon runtime or analytics prerequisite. Use font-display/fallbacks with stable layout; include necessary Persian/Arabic/Latin digits, glyphs and print shaping. Optimize logos/images, reserve dimensions and avoid decorative media in the working app. Do not preload every font weight or future route.
7. **Delivery and caching:** compression and correct HTTP caching for content-hashed public static assets. Cache only the approved static PWA shell/assets; financial/authenticated/private responses, OTP/WebAuthn data and public invoice responses follow the existing private/no-store rules. Queue/scheduler and market-provider outages must not block basic screen rendering. Static cache recovery does not bypass authentication or tenant checks.
8. **Navigation:** preserve input/focus while an on-demand module loads, use clear low-cost progress feedback and accessible lazy-load errors with retry. Avoid blocking splash screens, artificial animation delays, heavy effects, large table DOMs and eager offscreen rendering. Restrict speculative prefetch on constrained/data-saving conditions; feature-detect network hints but remain functional when the browser does not expose them. Client navigation still may need data/network; do not promise every uncached screen opens instantly.
9. **Deployment and reachability:** evaluate backend/static-file latency and availability from representative Iranian networks before selecting hosting/CDN. Do not assume a famous external CDN or a domestic host is reachable/faster everywhere. Keep essential paths first-party and document integration timeout/fallbacks; do not introduce a deployment-provider change without evidence. Measure HTTP round trips as well as bytes.
10. **Correct status and retries:** SMS/Passkey cancellation, network interruption, unknown issue result and failed module chunks have distinct recoverable states. Retry uses existing idempotency contracts. Updates never discard an unsaved invoice. Fast UI feedback must not report a financial mutation as committed before server confirmation.

## Required evidence

- Build/bundle report: per-route critical JS, CSS, fonts, transferred bytes and dependency graph; verify unused modules/icons are excluded.
- Cold/warm profile A/B traces for mobile login, calculator, mixed GOLD/MISC draft -> review, and public invoice; measure both initial display and actual input usability.
- Interrupted/offline/reconnect journey and chunk-load retry preserve inputs, prevent duplicate issue and correctly label stale quotes.
- Real Persian text/font/print/accessibility checks on a lower-end supported phone; no font or critical function blocked when third-party domains are unavailable.
- Regression gate in CI for compressed critical assets and route payload budgets, with a documented human-readable comparison. Runtime and field results recorded separately.
- Known limitations and pending real Iran-network/device measurements are reported honestly; no speed certification based on this planning document.

## Primary implementation references

- https://inertiajs.com/code-splitting
- https://inertiajs.com/partial-reloads
- https://primevue.dev/laravel/
- https://web.dev/articles/vitals

Verify the API against the pinned implementation versions; the budgets/profile choices above are Talata-specific decisions.
