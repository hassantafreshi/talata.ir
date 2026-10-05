/**
 * Talata frontend adapter contracts (documentation-only, proposed).
 *
 * The UI talks to these interfaces only. Two implementations exist per adapter:
 *   - `mock`: deterministic sample data, visibly labelled «نمونه», never claims a real issue/send.
 *   - `live`: Inertia/HTTP calls to the Laravel modular monolith.
 * Money, weight and rates are decimal STRINGS (IRR / grams / percent), never JS numbers.
 * Every mutation that creates financial or messaging state carries an idempotency key.
 * Nothing here grants access: the server enforces tenant, capability and quota checks.
 */

export type DecimalString = string;          // "217620000" (IRR), "2.5" (g), "2" (%)
export type IsoDateTime = string;            // "2026-10-05T10:55:00Z"
export type LocalDateTime = string;          // "1405/07/13 14:25" (tenant timezone, Jalali)
export type DisplayCurrency = 'TOMAN' | 'RIAL';
export type AdapterMode = 'mock' | 'live';

export interface AdapterStatus {
  mode: AdapterMode;
  /** Shown in the UI header when mode === 'mock': «حالت نمونه». */
  label_fa: string;
}

/* ---------- Auth: mobile OTP first, optional passkey later ---------- */

export interface OtpRequestResult {
  challenge_id: string;
  resend_after_seconds: number;      // UI countdown; server also throttles
  expires_in_seconds: number;
  delivery_hint_fa: string;          // «پیامک معمولاً زیر ۳۰ ثانیه می‌رسد»
}

export interface OtpVerifyResult {
  ok: boolean;
  /** generic, enumeration-resistant reason */
  error?: 'INVALID' | 'EXPIRED' | 'TOO_MANY_ATTEMPTS' | 'NETWORK';
  attempts_left?: number;
  /** true only after the FIRST successful mobile login on this device: show the passkey offer once */
  offer_passkey?: boolean;
  /** validated in-app destination; never a raw return URL */
  next: 'rate' | 'complete_profile' | 'draft';
}

export interface AuthAdapter {
  requestOtp(mobile_raw: string): Promise<OtpRequestResult>;            // accepts Persian/Arabic/Latin digits, 09…/+98…
  verifyOtp(challenge_id: string, code_raw: string): Promise<OtpVerifyResult>;
  passkeySupported(): Promise<boolean>;                                  // feature-detect; false → hide the option
  passkeyRegister(label?: string): Promise<{ ok: boolean; credential_id?: string; error?: 'CANCELLED' | 'UNSUPPORTED' | 'NETWORK' }>;
  passkeyLogin(): Promise<{ ok: boolean; error?: 'CANCELLED' | 'NO_CREDENTIAL' | 'REVOKED' | 'NETWORK' }>;
  listPasskeys(): Promise<Array<{ credential_id: string; label: string; created_at: IsoDateTime; last_used_at?: IsoDateTime }>>;
  revokePasskey(credential_id: string): Promise<void>;                   // requires recent authentication server-side
  logout(): Promise<void>;
}

/* ---------- Market quote: central 180 s refresh, client polls compact value ---------- */

export type QuoteFreshness = 'FRESH' | 'STALE' | 'MANUAL' | 'OFFLINE' | 'ERROR';

export interface QuoteSnapshot {
  quote_id: string;                      // provenance captured at Start
  asset: 'GOLD_18' | 'GOLD_24' | 'USD_IRR';
  value_irr: DecimalString;              // per gram for gold; per USD for USD_IRR
  display_currency: DisplayCurrency;
  fetched_at: IsoDateTime;               // real server fetch time; failed polls never refresh it
  quote_time?: IsoDateTime;
  source_label_fa: string;               // «بازار (نمونه)»
  freshness: QuoteFreshness;
  stale_after_seconds: number;           // 240 default (180 + 60 grace), configurable
  tenant_override?: { value_irr: DecimalString; reason: string; actor: string; at: IsoDateTime };
}

export type BoardAsset = 'GOLD_18_BUY' | 'GOLD_18_SELL' | 'GOLD_24' | 'USD_IRR' | 'XAU_USD';
export interface QuoteBoardRow { asset: BoardAsset; value: DecimalString | null; unit_fa: string; change_vs_previous_pct?: DecimalString; freshness: QuoteFreshness; fetched_at: IsoDateTime; source_label_fa: string }
export interface QuoteBoardSnapshot { rows: QuoteBoardRow[]; fetched_at: IsoDateTime; freshness: QuoteFreshness; spread_18_irr?: DecimalString }

export interface QuoteAdapter {
  /** مظنه: every row of docs/MAZNEH_AND_CALCULATOR.md; a missing buy price is null, never derived. */
  getBoard(): Promise<QuoteBoardSnapshot>;
  subscribeBoard(cb: (b: QuoteBoardSnapshot) => void, opts: { interval_seconds: number; clock?: () => number }): () => void;
  /** Latest normalized server value; call on page entry, foreground and reconnect. */
  getLatest(assets?: Array<QuoteSnapshot['asset']>): Promise<QuoteSnapshot[]>;
  /**
   * Compact polling every `interval_seconds` (180). Pauses while hidden/offline; coalesces tabs when possible.
   * The callback never mutates an accepted transaction rate; the page decides to show «استفاده از نرخ جدید».
   */
  subscribe(cb: (q: QuoteSnapshot[]) => void, opts: { interval_seconds: number; clock?: () => number }): () => void;
}

/* ---------- Calculator preview (pure, local, exact) ---------- */

export type ItemType = 'GOLD' | 'MISC';
export type DiscountScope = 'TAXABLE_COMPONENTS' | 'WAGE' | 'PROFIT';

export interface GoldRowInput {
  row_id: string; item_type: 'GOLD';
  name: string; description?: string;
  net_weight_g: DecimalString;
  purity_ppt: DecimalString;              // 750 default; karat presets map to ppt with full precision
  wage_percent: DecimalString;
  profit_percent: DecimalString;
  discount?: { scope: DiscountScope; amount_irr: DecimalString };
}
export interface MiscRowInput {
  row_id: string; item_type: 'MISC';
  name: string; description?: string;     // name required
  manual_price_irr: DecimalString;        // final price for the whole row (price_basis ROW_TOTAL)
}
export type RowInput = GoldRowInput | MiscRowInput;

export interface GoldRowPreview {
  row_id: string; item_type: 'GOLD';
  effective_rate_irr_per_g: DecimalString;
  M: DecimalString; W0: DecimalString; P0: DecimalString; C0: DecimalString;
  allocation: { wage: DecimalString; profit: DecimalString; commission: DecimalString };
  W: DecimalString; P: DecimalString; C: DecimalString; B: DecimalString; V: DecimalString; T: DecimalString;
  vat_rule: { id: string; version: number; rate_percent: DecimalString };
  state: 'OK' | 'INVALID';               // INVALID → show field errors, never a misleading 0
  errors?: Array<{ field: string; code: string; message_fa: string }>;
}
export interface MiscRowPreview {
  row_id: string; item_type: 'MISC'; T: DecimalString; state: 'OK' | 'INVALID';
  errors?: Array<{ field: string; code: string; message_fa: string }>;
}

export interface InvoicePreview {
  rows: Array<GoldRowPreview | MiscRowPreview>;
  gold_metal: DecimalString; gold_wage: DecimalString; gold_profit: DecimalString; gold_vat: DecimalString;
  gold_rows_total: DecimalString; misc_rows_total: DecimalString; payable_total: DecimalString;
  formula_version: 'GOLD_IR_V1'; rounding_policy: 'IRR_LINE_HALF_UP_V1';
  /** hash of inputs + accepted rate + rule versions; must match the server's reviewed fingerprint at issue */
  fingerprint: string;
}

/** Pure function; must pass docs/design/contracts/calculation-vectors.json. Responds < 100 ms p95 locally. */
export type PreviewFn = (rows: RowInput[], acceptedRate: { price18_irr_per_g: DecimalString; quote_id: string }, vatRule: { id: string; version: number; rate_percent: DecimalString }) => InvoicePreview;

/* ---------- Drafts ---------- */

export interface DraftCustomer { name?: string; mobile_raw?: string; save_to_customers?: boolean }

export interface Draft {
  draft_id: string; version: number;        // optimistic locking
  accepted_rate: { price18_irr_per_g: DecimalString; quote_id: string; accepted_at: IsoDateTime; manual?: boolean };
  rows: RowInput[];
  customer: DraftCustomer;
  display_currency: DisplayCurrency;
  saved_at?: IsoDateTime;                   // «پیش‌نویس ذخیره شد ۱۴:۲۳» / «هنوز ذخیره نشده»
}

export interface DraftAdapter {
  create(accepted: Draft['accepted_rate']): Promise<Draft>;
  load(draft_id: string): Promise<Draft>;
  save(draft: Draft): Promise<{ version: number; saved_at: IsoDateTime } | { conflict: true; server: Draft }>;
  listOpen(): Promise<Array<{ draft_id: string; rows: number; saved_at: IsoDateTime; customer_name?: string }>>;
  /** local opt-in storage is minimal and tenant/user scoped; cleared on logout */
}

/* ---------- Issue / share / SMS ---------- */

export type IssueMode = 'ISSUE_ONLY' | 'ISSUE_AND_SMS';

export interface IssueRequest {
  draft_id: string; draft_version: number; fingerprint: string;
  mode: IssueMode;
  customer: DraftCustomer;                  // mobile required for ISSUE_AND_SMS (server validates/normalizes)
  idempotency_key: string;                  // same key on retry after network loss → same invoice, one send intent
  acknowledge_stale_rate?: boolean;         // when policy allows issuing with an older/manual rate
}

export type IssueResult =
  | { status: 'ISSUED'; invoice_id: string; number: string; issued_at: IsoDateTime; verification_url: string; share?: { url: string; links_left: number }; sms?: SmsStatus }
  | { status: 'REVIEW_REQUIRED'; reason: 'RATE_CHANGED' | 'RULE_CHANGED' | 'INPUT_CHANGED' | 'PROFILE_INCOMPLETE'; new_rate?: QuoteSnapshot; missing_profile_fields?: string[] }
  | { status: 'ERROR'; code: string; message_fa: string };

export type SmsStatus =
  | { state: 'QUEUED'; segments: number; cost_label_fa: string }
  | { state: 'DELIVERED'; at: IsoDateTime }
  | { state: 'FAILED'; reason_fa: string; can_retry: true }
  | { state: 'UNKNOWN'; reconciling: true }         // never blind-retry
  | { state: 'NOT_REQUESTED' };

export interface IssueAdapter {
  preflightSms(draft_id: string, mobile_raw: string): Promise<{ ok: boolean; normalized?: string; segments?: number; cost_label_fa?: string; trial_left?: number; links_left?: number; reason_fa?: string }>;
  issue(req: IssueRequest): Promise<IssueResult>;
  smsStatus(invoice_id: string): Promise<SmsStatus>;
  resendSms(invoice_id: string, mobile_raw: string): Promise<SmsStatus>;   // only after a definite FAILED
  createShareLink(invoice_id: string): Promise<{ url: string; links_left: number } | { quota_exhausted: true; message_fa: string }>;
  printUrl(invoice_id: string): string;      // print never depends on link quota
}

/* ---------- Public: share view and verification ---------- */

export interface VerificationDTO {
  status: 'FINALIZED' | 'VOIDED' | 'REPLACED' | 'INVALID' | 'REVOKED';
  number?: string; issued_at_local?: LocalDateTime;
  shop?: { name: string; address: string; contact: string };
  items?: Array<{ name: string; description?: string; item_type: ItemType; net_weight_g?: DecimalString; karat_label?: string; row_total_irr: DecimalString; components?: { metal: DecimalString; wage: DecimalString; profit: DecimalString; vat: DecimalString } }>;
  totals?: { gold_rows_total: DecimalString; misc_rows_total: DecimalString; payable_total: DecimalString; display_currency: DisplayCurrency };
  /** NEVER: customer name/mobile, internal notes, history, tenant ids */
}

/* ---------- Shop profile and invoice layout ---------- */

export interface ShopProfile {
  name: string; business_mobile: string; address: string;            // required before first issue
  landline?: string; website?: string;
  socials: Array<{ id: string; network: 'instagram' | 'telegram' | 'whatsapp' | 'other'; handle: string }>;
  license_union?: string; license_online?: string;
  logo?: { asset_id: string; version: number; width: number; height: number } | null;
  completeness: { ok: boolean; missing: Array<'name' | 'business_mobile' | 'address'> };
}

/** Validated against docs/design/invoice-templates/invoice-layout.schema.json */
export type InvoiceLayoutSettings = Record<string, unknown>;

export interface SettingsAdapter {
  getProfile(): Promise<ShopProfile>;
  saveProfile(p: ShopProfile): Promise<ShopProfile>;
  uploadLogo(file: File): Promise<ShopProfile['logo']>;             // server validates raster type/size/dimensions
  getLayout(): Promise<{ settings: InvoiceLayoutSettings; version: number; can_customize: boolean }>;
  saveLayout(settings: InvoiceLayoutSettings, version: number): Promise<{ version: number } | { conflict: true } | { forbidden: true; message_fa: string }>;
  entitlements(): Promise<{ capabilities: Record<string, boolean>; quotas: Record<string, { used: number; limit: number; period_label_fa?: string; resets_at_local?: LocalDateTime }>; plan: { id: PlanId; label_fa: string; period?: BillingPeriod; ends_at_local?: LocalDateTime; pending_change?: { to: PlanId; period: BillingPeriod; status: 'PENDING_ACTIVATION' } }; trial_sms_left: number; sms_balance_segments: number }>;
  /** Prices and plan features are provider configuration (docs/design/contracts/plans-pricing.json), never constants in the UI. */
  plans(): Promise<{ plans: PlanOffer[]; addons: AddonOffer[]; activation_note_fa: string }>;
  requestPlanChange(input: { to: PlanId; period: BillingPeriod; idempotency_key: string }): Promise<{ request_id: string; status: 'PENDING_ACTIVATION'; amount_toman: DecimalString; payment_guide_fa: string } | { error: string; message_fa: string }>;
  purchaseSmsPack(input: { addon_id: string; idempotency_key: string }): Promise<{ request_id: string; status: 'PENDING_ACTIVATION'; amount_toman: DecimalString; payment_guide_fa: string } | { error: string; message_fa: string }>;
}

export type PlanId = 'free' | 'basic' | 'professional';
export type BillingPeriod = 'monthly' | 'yearly';
export interface PlanOffer { id: PlanId; label_fa: string; price_toman: Record<BillingPeriod, DecimalString>; highlights_fa: string[]; not_included_fa: string[]; recommended?: boolean }
export interface AddonOffer { id: string; label_fa: string; sms_count: number; price_toman: DecimalString; available_on: PlanId[] }

/** Quota notices only reflect server entitlements; they never block invoice create/finalize/print or the verification QR. */
export type QuotaTrigger = 'invoices_exhausted' | 'customers_exhausted' | 'links_exhausted' | 'near_limit' | 'history_restricted' | 'installments_professional_only' | 'sms_trial_exhausted' | 'sms_balance_low' | 'sms_balance_expiring';
/** Prepaid SMS credit (docs/PLANS_AND_QUOTAS.md §3): toman balance charged per segment at the current plan price. */
export interface SmsCredit { balance_irr: DecimalString; per_segment_irr: DecimalString; approx_segments: number; min_purchase_irr: DecimalString; pack_amounts_irr: DecimalString[]; carries_over: boolean; expires_at?: IsoDateTime; free_yearly_remaining: number }

/* ---------- Customers and installments (Professional) ---------- */

export interface CustomerSummary { party_id: string; name: string; mobile: string; last_invoice_at?: LocalDateTime; invoices: number; balance_irr: DecimalString; overdue?: { amount_irr: DecimalString; due_local: LocalDateTime } }

export interface CustomersAdapter {
  search(q: string, page: number, page_size: 25): Promise<{ items: CustomerSummary[]; total: number }>;   // debounced, not per keystroke
  create(input: { name: string; mobile_raw: string; note?: string }): Promise<{ party_id: string; duplicates?: CustomerSummary[] }>; // suggests, never auto-merges
  get(party_id: string): Promise<CustomerSummary & { agreements: InstallmentAgreement[]; invoices: Array<{ invoice_id: string; number: string; issued_at_local: LocalDateTime; payable_total_irr: DecimalString; status: string }> }>;
}

export interface InstallmentAgreement {
  agreement_id: string; invoice_number?: string; principal_irr: DecimalString; down_payment_irr: DecimalString;
  lines: Array<{ seq: number; due_local: LocalDateTime; amount_irr: DecimalString; paid_irr: DecimalString; status: 'PAID' | 'DUE' | 'OVERDUE' | 'PENDING'; reminder?: { state: 'SENT' | 'SCHEDULED' | 'OFF'; at?: LocalDateTime } }>;
  balance_irr: DecimalString;
}

export interface InstallmentsAdapter {
  recordPayment(agreement_id: string, input: { amount_irr: DecimalString; method: 'CASH' | 'CARD' | 'TRANSFER' | 'OTHER'; paid_at_local: LocalDateTime; reference?: string; idempotency_key: string }): Promise<InstallmentAgreement>;
  reversePayment(payment_id: string, reason: string): Promise<InstallmentAgreement>;
  setReminders(agreement_id: string, enabled: boolean): Promise<void>;
}
