# API examples (JSON)

All amounts are **IRR decimal strings** (toman × 10). Errors use `{ "code": "…", "message_fa": "…", "trace_id": "…" }` with an HTTP status. Authenticated merchant endpoints are scoped to the session tenant; never send `tenant_id` from the client. Types: `docs/design/contracts/frontend-adapters.ts`.

## Auth

```http
POST /api/auth/otp/request
{ "mobile": "۰۹۱۲۳۴۵۶۷۸۹" }
→ 200 { "challenge_id": "otp_01J9…", "masked_mobile": "0912***6789", "resend_after_seconds": 90 }
→ 429 { "code": "RATE_LIMITED", "message_fa": "تعداد درخواست‌ها زیاد شد. ۵ دقیقه دیگر دوباره امتحان کنید.", "retry_after_seconds": 300 }

POST /api/auth/otp/verify
{ "challenge_id": "otp_01J9…", "code": "482913" }
→ 200 { "status": "OK", "is_new_tenant": true, "next": "/login/passkey-offer" }
→ 422 { "code": "WRONG_CODE", "message_fa": "کد درست نیست…", "attempts_left": 2 }
```

## Entitlements

```http
GET /api/entitlements
→ 200 {
  "plan": { "id": "basic", "label_fa": "پایه", "period": "monthly", "ends_at_local": "1405-08-01T00:00:00+03:30" },
  "capabilities": { "invoice.finalize": true, "invoice.customize": true, "installments.manage": false, "mazneh.view": true, "calculator.use": true },
  "quotas": {
    "invoices_per_month":      { "used": 48, "limit": 500, "resets_at_local": "1405-08-01T00:00:00+03:30" },
    "new_customers_per_month": { "used": 12, "limit": 500 },
    "links_per_month":         { "used": 98, "limit": 100 }
  },
  "history": "all",
  "sms": { "free_yearly_remaining": 1, "balance_irr": "420000", "per_segment_irr": "5000", "approx_segments": 84, "carries_over": true }
}
```

## Quotes

```http
GET /api/quotes/latest
→ 200 { "asset": "GOLD_18_SELL", "value_irr": "100000000", "unit": "IRR_PER_GRAM", "fetched_at": "2026-10-05T10:51:04Z", "freshness": "FRESH", "source_label_fa": "بازار (نمونه)", "is_demo": true }

GET /api/quotes/board
→ 200 { "fetched_at": "2026-10-05T10:51:04Z", "freshness": "FRESH", "spread_18_irr": "1000000", "rows": [
  { "asset": "GOLD_18_BUY",  "value": "99000000",  "unit_fa": "تومان / گرم", "change_vs_previous_pct": "0.3",  "freshness": "FRESH", "fetched_at": "…", "source_label_fa": "بازار (نمونه)" },
  { "asset": "GOLD_18_SELL", "value": "100000000", "unit_fa": "تومان / گرم", "change_vs_previous_pct": "0.3",  "freshness": "FRESH", "fetched_at": "…", "source_label_fa": "بازار (نمونه)" },
  { "asset": "GOLD_24",      "value": "133330000", "unit_fa": "تومان / گرم", "change_vs_previous_pct": "0.3",  "freshness": "FRESH", "fetched_at": "…", "source_label_fa": "بازار (نمونه)" },
  { "asset": "USD_IRR",      "value": "1000000",   "unit_fa": "تومان",        "change_vs_previous_pct": "0",    "freshness": "FRESH", "fetched_at": "…", "source_label_fa": "بازار (نمونه)" },
  { "asset": "XAU_USD",      "value": "2650",      "unit_fa": "دلار / اونس",  "change_vs_previous_pct": "-0.2", "freshness": "STALE", "fetched_at": "…", "source_label_fa": "جهانی (نمونه)" } ] }
```

## Drafts and issue

```http
POST /api/invoices/drafts
{ "accepted_rate": { "asset": "GOLD_18_SELL", "value_irr": "100000000", "fetched_at": "2026-10-05T10:51:04Z", "mode": "MARKET" }, "idempotency_key": "d-7f3a…" }
→ 201 { "draft_id": "drf_01J9…", "version": 1 }
→ 409 { "code": "RATE_CHANGED", "message_fa": "نرخ تازه رسید…", "latest": { "value_irr": "100500000", "fetched_at": "…" } }

PATCH /api/invoices/drafts/drf_01J9…
{ "version": 3, "rows": [
  { "row_id": "r1", "item_type": "GOLD", "name": "النگو ۱۸ عیار", "description": null, "net_weight_g": "2", "purity_ppt": "750", "wage_percent": "2", "profit_percent": "5", "discount": null },
  { "row_id": "r2", "item_type": "MISC", "name": "جعبه هدیه", "row_total_irr": "2000000" } ] }
→ 200 { "version": 4, "preview": { "gold_rows_total": "215620000", "misc_rows_total": "2000000", "payable_total": "217620000" } }

POST /api/invoices/drafts/drf_01J9…/issue
{ "mode": "ISSUE_AND_SMS", "buyer": { "name": "خانم رضایی", "mobile_raw": "۰۹۱۲ ۰۰۰ ۰۰۰۰" }, "save_customer": false, "review_fingerprint": "sha256:…", "idempotency_key": "iss-2c91…" }
→ 201 { "status": "ISSUED", "invoice_id": "inv_01J9…", "number": "1405-0012", "verification_url": "https://talata.ir/v/…", "share": { "url": "https://talata.ir/i/…", "links_remaining": 1 }, "sms": { "status": "QUEUED", "segments": 1, "charged_from": "FREE_YEARLY" } }
→ 200 { "status": "REVIEW_REQUIRED", "reason": "PROFILE_INCOMPLETE", "missing": ["address"] }
```

## Billing

```http
GET /api/billing/offers
→ 200 { "vat_rate_percent": "10", "prices_exclude_vat": true, "gateway_mode": "mock",
  "plans": [ { "id": "basic", "price_toman": { "monthly": "790000", "yearly": "7900000" } }, { "id": "professional", "price_toman": { "monthly": "1900000", "yearly": "10900000" } } ],
  "sms": { "per_segment_irr": "5000", "min_purchase_irr": "1000000", "pack_amounts_irr": ["1000000","2000000","3000000","5000000","10000000"], "carries_over": true, "balance_irr": "420000", "free_yearly_remaining": 1 } }

POST /api/billing/orders
{ "product": "SMS_CREDIT", "pack_amount_toman": "200000", "return_to": { "route": "review", "draft_id": "drf_01J9…" }, "idempotency_key": "ord-a0b4…" }
→ 201 { "order_id": "01J9ORD…", "redirect": { "url": "https://gateway.example/start/A000…412", "method": "GET" } }
→ 422 { "code": "BELOW_MINIMUM", "message_fa": "حداقل شارژ برای پلن رایگان ۴۰۰ هزار تومان است." }

GET /api/billing/orders/01J9ORD…
→ 200 { "order_id": "01J9ORD…", "public_ref": "TL-SMS-1405-0021", "product": "SMS_CREDIT", "status": "FULFILLED",
  "subtotal_irr": "2000000", "vat_rate_percent": "10", "vat_irr": "200000", "amount_irr": "2200000",
  "bank_ref_id": "12345678", "paid_at": "2026-10-05T11:01:00Z",
  "sms": { "added_irr": "2000000", "balance_irr": "2420000", "approx_segments": 484, "carries_over": true },
  "return_to": { "route": "review", "draft_id": "drf_01J9…" }, "gateway_mode": "mock" }
```

## Admin

```http
POST /provider/api/tenants/ten_01J9…/manual-activation
{ "plan": "professional", "period": "yearly", "starts_at_local": "1405-07-13", "payment_reference": "کارت‌به‌کارت ۴۵۸۲۱۹", "reason": "پرداخت حضوری", "idempotency_key": "man-51de…" }
→ 201 { "subscription_id": "sub_…", "ends_at_local": "1406-07-13", "subtotal_irr": "109000000", "vat_irr": "10900000", "amount_irr": "119900000", "audit_id": "aud_…" }
→ 403 { "code": "REAUTH_REQUIRED", "message_fa": "برای این کار دوباره با Passkey تأیید کنید." }

POST /provider/api/payments/01J9ORD…/manual-confirm
{ "bank_ref_id": "87654321", "reason": "تأیید تلفنی بانک", "idempotency_key": "mc-9ab0…" }
→ 200 { "status": "FULFILLED", "audit_id": "aud_…" }
```

## Affiliate program

```http
POST /api/billing/discount
{ "code": "tala2026", "plan": "basic", "period": "monthly" }
→ 200 { "code": "TALA2026", "applied": true, "message_fa": "کد TALA2026 اعمال شد: ۲۰٪ تخفیف روی خرید اول پلن.",
        "list_fa": "۷۹۰٬۰۰۰", "discount_fa": "۱۵۸٬۰۰۰", "subtotal_fa": "۶۳۲٬۰۰۰", "vat_fa": "۶۳٬۲۰۰", "total_fa": "۶۹۵٬۲۰۰" }
→ 422 { "code": "DISCOUNT_CODE_NOT_ELIGIBLE", "message_fa": "این فروشگاه قبلاً با کد معرف دیگری ثبت شده است.", "errors": { "discount_code": ["…"] } }

POST /admin/api/affiliates
{ "mobile": "09121110000", "commission_percent": "12.5", "commission_mode": "LIFETIME", "discount_percent": "10", "code": "negin-10" }
→ 201 { "id": 7, "next": "/admin/affiliates/7" }      // code stored as NEGIN10, link {public_url}/r/NEGIN10

POST /admin/api/affiliates/7/payouts   { "reference": "BANK-998877" }   → 200 { "ok": true, "amount_irr": "1264000" }
POST /admin/api/affiliate-commissions/42/void   { "reason": "بازگشت وجه" }   → 200 { "ok": true }
```

## داشبورد فروش

```http
GET /api/dashboard?range=month
→ 200 {"range":"month","label_fa":"مهر ۱۴۰۵","invoices":22,"labels":["۱","۲",…],"titles":["۱۴۰۵/۰۷/۰۱",…],
       "metrics":{"sales":{"irr":"22994604794","toman_fa":"۲٬۲۹۹٬۴۶۰٬۴۷۹","g":"189.590","g_fa":"۱۸۹.۵۹ گرم","series_toman":[…],"series_g":[…],"delta_pct":"12"},
                  "wage":{…},"profit":{…},"gold_in":{…},"vat":{"irr":"…","toman_fa":"…","series_toman":[…]}},
       "locked_metrics":[],"access":{"full":true,"ranges":[…],"metrics":[…]},"empty":false}
GET /api/dashboard?range=custom&from=1405/01/01&to=1405/06/31   (Basic/Pro)
GET /api/dashboard?range=year   (Free) → 403 {"code":"FEATURE_LOCKED"}
```

## ردیف طلای دریافتی در ذخیره پیش‌نویس

```json
{"item_type":"GOLD_IN","kind":"COIN","name":"سکه امامی","net_weight_g":"8.13","purity_ppt":"900",
 "rate_basis":"BUY","deduction_percent":"0"}
```
`rate_basis`: `BUY` | `SELL` | `MANUAL` (+ `rate_toman`)؛ `kind=MELTED` می‌تواند `assay_ref` داشته باشد. پاسخ وضعیت: `totals.sales_irr`، `totals.gold_in_irr`، `totals.payable_irr` (ممکن است منفی باشد)، `totals.customer_credit`، `sale_required`.
