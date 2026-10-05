# Global states, layout patterns and shared components

## 1. Visual language (v3 «برگه یکپارچه»)

Use only tokens from `docs/design/tokens/talata-tokens.css` (`--t-*`). Palette 1 «شب و طلا» is the working default; palettes 2/3 switch via `<html data-palette="royal_emerald|burgundy_gold">` and must work without code changes.

- White page (`--t-page`); content sits in borderless ivory **bands** (`--t-band`, radius 20); emphasis band `--t-band-emphasis` with `inset 0 0 0 2px var(--t-gold-action)`.
- One dark **hero** per screen at most (price card, result summary): `--t-chrome`, radius 24 (desktop 26), `--t-shadow-hero`, numbers in `--t-gold-bright`.
- Buttons and segmented controls are **pills** (radius 999). Primary = gold (`--t-gold-action`, text `--t-text-on-gold`); secondary = dark or white outline; danger = red outline. Primary actions ≥ 56 px tall on mobile.
- Inputs are **filled**: white background, no border except a 2 px bottom line `#C9C3B6`, radius `12px 12px 0 0`; label above; unit fixed beside the value; error text below in `--t-error-fg` with an icon.
- Hairline separators `#E6E1D6`; dashed borders only for placeholders/previews.
- Status always icon + label (`StatusBadge`): success, warning, error, info, offline. Never colour alone; change indicators (↑ ↓) in neutral ink.
- Icons: inline SVG line icons 20–24 px, stroke 2–2.2, always with a text label in the main path.

## 2. Shells

| Shell | Used by | Structure |
| --- | --- | --- |
| `MerchantMobileShell` | merchant < 768 px | top app bar (title, back/close, context badge) · scroll content · sticky action bar (optional) · bottom tabs (M-11) with safe-area padding |
| `MerchantDesktopShell` | merchant ≥ 1024 px | dark top bar (logo, shop, 6 nav items, plan/credit strip) · content max-width 1280 · settings pages add right sidebar |
| `PublicShell` | `/i`, `/v`, `/pay/result` logged-out | no nav; shop or Talata header; footer; server-rendered |
| `PrintLayout` | `/invoices/{id}/print` | A4 page box, print CSS, no app chrome |
| `AdminShell` | `/provider/*` | dark right sidebar 232 px · header row (title, description, actions, env badge) · content |

## 3. Global states (every screen)

| State | Behaviour |
| --- | --- |
| Initial loading | Skeleton bands matching the final layout (no spinners on full page). Hero price shows the last cached value with «در حال به‌روزرسانی…» if available. |
| Route chunk failed to load | `ChunkRetry`: «بخشی از برنامه دریافت نشد. اینترنت را بررسی و دوباره تلاش کنید.» + «تلاش دوباره»; retry with backoff; never a blank page. |
| Offline | `OfflineBanner` at top: «اینترنت قطع است. آخرین اطلاعات {time} نمایش داده می‌شود.»; disable server-only actions (issue, send, pay) with reason; drafts and calculator keep working. |
| Session expired | Modal «برای ادامه دوباره وارد شوید» → login with return URL; unsaved draft kept locally. |
| Forbidden (permission) | Inline `ErrorState` «دسترسی این کار را ندارید. از مالک فروشگاه بخواهید.»; hide destructive buttons the user can't use. |
| Capability not in plan | `QuotaNotice` (inline/banner/sheet) per trigger; never a red error. |
| Server error | Inline `ErrorState` with message_fa and «کد پیگیری: {trace_id}» + retry. |
| Validation error | Field-level messages; first invalid field focused; summary at top only for long forms. |
| Empty | `EmptyState` with one sentence and the primary action. |
| Demo / sample data | Badge «نمونه» / «داده نمونه» / «عدد نمونه» wherever sample values appear. |
| Mock integration | Badge «حالت آزمایشی» on payment results/receipts and admin integration cards. |
| Saving | Inline «در حال ذخیره…» → «ذخیره شد · {time}»; failure keeps local copy and retries. |
| Toasts | Bottom (mobile) / bottom-left (desktop), 4–6 s, never the only place for errors; undo toasts for deletes. |

## 4. Shared components (build these; no external UI kit)

Merchant/public (detailed props in `UI_BUILD_SPEC.md` §4): `TButton`, `TField`, `MobileInput`, `OtpInput`, `MoneyInput`, `WeightInput`, `PercentInput`, `PuritySelect`, `SegmentedChoice`, `StatusBadge`, `RateCard`, `RateStatusBar`, `GoldRowCard`, `MiscRowCard`, `AddRowButton`, `TotalSummary`, `CustomerFields`, `SmsPreview`, `StickyActionBar`, `AppNavigation`, `QuoteBoard`, `GoldCalculator`, `SmsCreditPanel`, `AmountTiles` (radio tiles for SMS packs), `PriceBreakdown` (base / VAT / payable), `PaymentResult` (success/failed/pending), `OfflineBanner`, `ErrorState`, `EmptyState`, `ChunkRetry`, `QrVerificationBlock`, `InvoiceRenderer`, `LayoutEditor` family, `PlanCard`, `UsageMeter`, `QuotaNotice`, `BottomSheet`, `Dialog`, `Toast`.

Admin: `AdminShell`, `StatTile` (label, value, sub-line, optional `StatusBadge`; text only, no charts), `FilterBar`, `QuickViews` (segmented with counts), `DataTable` (sortable header, numeric columns LTR-aligned left, row selection, pagination 25/50, CSV export hook), `KeyValueList`, `Tabs`, `DetailPanel`, `DangerActionDialog` (effect summary + reason* + passkey re-auth), `PermissionMatrix`, `VersionedConfigBar` (active/draft/effective date/publish).

## 5. Numbers, dates and text

- Display Persian digits (`۰–۹`) with `٬` thousands separator and `٫` decimal; inputs accept Persian, Arabic-Indic and Latin digits and normalize.
- Money: IRR in API/DB, toman in UI (÷10, exact). Always show «تومان». Large hero numbers use tabular figures.
- Weight: grams, up to 3 visible decimals; purity as «۱۸ عیار (۷۵۰)».
- Phone numbers, URLs, codes, references (`TL-…`), authorities: wrap in `dir="ltr"` + `unicode-bidi: isolate`, `white-space: nowrap`.
- Dates: Jalali display (`۱۴۰۵/۰۷/۱۳`), 24 h time (`۱۴:۲۱`), tenant timezone (default Asia/Tehran); store UTC.
- Copy: short sentences, no jargon (no "ledger", "token"); every limit message says what still works.

## 6. Accessibility and input

- `lang="fa" dir="rtl"`; logical CSS properties; focus ring `--t-focus-ring`; focus order = visual order; skip link on desktop.
- Touch targets ≥ 44 px (primary ≥ 56 px); spacing between adjacent targets ≥ 8 px.
- Mobile keyboards: `tel` for phone, `numeric` + `one-time-code` for OTP, `decimal` for weight/percent/money.
- Sticky bars must not cover focused inputs (scroll-padding, `visualViewport` handling).
- `role="status"`/`aria-live="polite"` for save state, rate updates (throttled), payment and issue results; `role="radiogroup"` for segmented choices and amount tiles.
- Respect `prefers-reduced-motion`; no decorative animation in work flows.
- Test at 360, 390, 768, 1024, 1280 px and 200% zoom.

## 7. Performance rules (see `docs/PERFORMANCE_BUDGET.md`)

- Route-level lazy chunks; the rate page must not import composer, editor, tables or QR code libraries.
- Public pages: server-rendered, ≤ 20 KiB JS. Admin chunk never loaded for merchants.
- Self-host fonts (two subsets), no third-party runtime requests, SVG icons inline or local sprite.
- Poll politely: quotes every 180 s only when visible and online; payment result every 5 s up to 2 min; SMS status every 5 s up to 2 min.
