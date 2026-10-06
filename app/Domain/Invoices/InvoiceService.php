<?php

namespace App\Domain\Invoices;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Market\QuoteService;
use App\Domain\Plans\CommercialConfig;
use App\Domain\Plans\Entitlements;
use App\Domain\Sms\SmsService;
use App\Domain\Tax\TaxRules;
use App\Models\Customer;
use App\Models\InstallmentAgreement;
use App\Models\Invoice;
use App\Models\InvoiceCounter;
use App\Models\InvoiceItem;
use App\Models\InvoiceLayout;
use App\Models\InvoiceShare;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Mobile;
use App\Support\Money;
use App\Support\Tokens;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InvoiceService
{
    /** Stored row columns the calculator reads (also copied to a replacement draft). */
    private const ROW_FIELDS = [
        'row_uid', 'position', 'item_type', 'formula_version', 'name', 'description', 'net_weight_g', 'purity_ppt',
        'wage_percent', 'profit_percent', 'discount_scope', 'discount_irr', 'manual_total_irr', 'item_attributes',
    ];

    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly Entitlements $entitlements,
        private readonly QuoteService $quotes,
        private readonly TaxRules $taxRules,
        private readonly SmsService $sms,
        private readonly CommercialConfig $config,
    ) {}

    /** Start: captures the displayed rate. A newer server value must be accepted explicitly. */
    public function createDraft(Tenant $tenant, User $user, array $rate): Invoice
    {
        $this->entitlements->assertCan($tenant, 'invoice.finalize', 'صدور فاکتور در این پلن فعال نیست.');
        $this->entitlements->assertQuota($tenant, 'invoices_per_month');

        $mode = in_array($rate['mode'] ?? '', ['MARKET', 'MANUAL', 'NONE'], true) ? $rate['mode'] : 'MARKET';
        $value = null;
        $buyValue = null;
        $fetchedAt = null;
        $reason = null;
        if ($mode === 'MARKET') {
            $latest = $this->quotes->latest('GOLD_18_SELL');
            if (! $latest || $this->quotes->freshness($latest) === 'ERROR') {
                throw new DomainError('RATE_UNAVAILABLE', 'نرخ بازار در دسترس نیست. نرخ دستی ثبت کنید یا فاکتور فقط متفرقه بسازید.', 409);
            }
            $latestValue = (string) BigDecimal::of($latest->value)->toScale(0, RoundingMode::HalfUp);
            if (($rate['value_irr'] ?? null) !== $latestValue) {
                throw new DomainError('RATE_CHANGED', 'نرخ تازه رسید؛ با کدام عدد ادامه دهیم؟', 409, [
                    'latest' => ['value_irr' => $latestValue, 'value_toman_fa' => Money::toman($latestValue), 'fetched_at_fa' => Jalali::time($latest->fetched_at, $tenant->timezone)],
                ]);
            }
            $value = $latestValue;
            $fetchedAt = $latest->fetched_at;
            $buyValue = $this->latestBuyRate();
        } elseif ($mode === 'MANUAL') {
            $value = Money::parseTomanToIrr((string) ($rate['value_toman'] ?? ''));
            $reason = in_array($rate['reason'] ?? '', ['MARKET_UNAVAILABLE', 'CUSTOMER_AGREEMENT', 'PEER_RATE'], true) ? $rate['reason'] : null;
            if (! $value || BigDecimal::of($value)->isGreaterThan(config('talata.invoices.max_amount_irr')) || ! $reason) {
                throw new DomainError('RATE_INVALID', 'نرخ دستی و دلیل آن را درست وارد کنید.', 422);
            }
        }

        return DB::transaction(function () use ($user, $mode, $value, $buyValue, $fetchedAt, $reason) {
            $invoice = Invoice::create([
                'status' => 'draft', 'rate_mode' => $mode, 'accepted_rate_irr' => $value, 'accepted_buy_rate_irr' => $buyValue, 'rate_fetched_at' => $fetchedAt,
                'rate_manual_reason' => $reason, 'created_by' => $user->id, 'version' => 1,
            ]);
            InvoiceItem::create($this->calculator->normalizeRow(
                $mode === 'NONE' ? ['item_type' => 'MISC'] : ['item_type' => 'GOLD', 'name' => 'طلای ۱۸ عیار', 'purity_ppt' => '750'], 1,
            ) + ['invoice_id' => $invoice->id]);

            return $invoice;
        });
    }

    /** Autosave with optimistic versioning. Returns the priced state. */
    public function saveDraft(Invoice $invoice, int $version, array $rows, array $buyer, bool $useLatestRate, Tenant $tenant): array
    {
        if (! $invoice->isDraft()) {
            throw new DomainError('NOT_DRAFT', 'این فاکتور صادر شده و قابل ویرایش نیست.', 409);
        }
        if (count($rows) > config('talata.invoices.max_rows')) {
            throw new DomainError('TOO_MANY_ROWS', 'تعداد ردیف‌ها بیش از حد مجاز است.', 422);
        }

        return DB::transaction(function () use ($invoice, $version, $rows, $buyer, $useLatestRate, $tenant) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->version !== $version) {
                throw new DomainError('DRAFT_CONFLICT', 'این پیش‌نویس در جای دیگری تغییر کرد. صفحه را دوباره باز کنید.', 409, ['version' => $invoice->version]);
            }
            if ($useLatestRate && $invoice->rate_mode !== 'NONE') {
                $latest = $this->quotes->latest('GOLD_18_SELL');
                if ($latest) {
                    $invoice->rate_mode = 'MARKET';
                    $invoice->accepted_rate_irr = (string) BigDecimal::of($latest->value)->toScale(0, RoundingMode::HalfUp);
                    $invoice->rate_fetched_at = $latest->fetched_at;
                    $invoice->rate_manual_reason = null;
                    $invoice->accepted_buy_rate_irr = $this->latestBuyRate();
                }
            }
            $normalized = [];
            $uids = [];
            foreach (array_values($rows) as $i => $raw) {
                $row = $this->calculator->normalizeRow((array) $raw, $i + 1);
                if (isset($uids[$row['row_uid']])) {
                    $row['row_uid'] = strtolower((string) Str::ulid());
                }
                $uids[$row['row_uid']] = true;
                $normalized[] = $row;
            }
            InvoiceItem::query()->where('invoice_id', $invoice->id)->delete();
            foreach ($normalized as $row) {
                InvoiceItem::create($row + ['invoice_id' => $invoice->id]);
            }
            $invoice->buyer_name = $this->cleanName($buyer['name'] ?? null);
            $raw = trim((string) ($buyer['mobile'] ?? ''));
            $invoice->buyer_mobile = $raw === '' ? null : Mobile::normalize($raw);
            $invoice->version++;
            $invoice->save();

            return $this->state($invoice, $tenant, $raw !== '' && ! $invoice->buyer_mobile);
        });
    }

    public function state(Invoice $invoice, Tenant $tenant, bool $buyerMobileInvalid = false): array
    {
        $rows = $invoice->items()->get()->map(fn (InvoiceItem $i) => $i->only(self::ROW_FIELDS))->all();
        $priced = $this->calculator->priceAll($rows, $invoice->accepted_rate_irr, $this->vatRate($tenant), $invoice->accepted_buy_rate_irr);

        return [
            'version' => $invoice->version,
            'valid' => $priced['valid'],
            'sale_required' => $priced['sale_required'],
            'rows' => array_map(fn ($r) => [
                'row_uid' => $r['row_uid'], 'ok' => $r['result']['ok'], 'errors' => $r['result']['errors'],
                'total_irr' => $r['result']['total'], 'total_fa' => $r['result']['total'] ? Money::toman($r['result']['total']) : null,
                'computed' => $r['result']['computed'],
            ], $priced['rows']),
            'totals' => [
                'gold_irr' => $priced['gold_total'], 'misc_irr' => $priced['misc_total'], 'payable_irr' => $priced['payable'],
                'gold_fa' => Money::toman($priced['gold_total']), 'misc_fa' => Money::toman($priced['misc_total']), 'payable_fa' => Money::toman($priced['payable']),
                'components' => array_map(fn ($v) => Money::toman($v), array_diff_key($priced['gold'], ['weight' => 1])),
                'sales_irr' => $priced['sales_total'], 'sales_fa' => Money::toman($priced['sales_total']),
                'gold_in_irr' => $priced['gold_in_total'], 'gold_in_fa' => Money::toman($priced['gold_in_total']),
                'has_gold_in' => collect($rows)->contains('item_type', 'GOLD_IN'),
                // Negative payable = the shop owes the customer the difference («مانده به نفع مشتری»).
                'customer_credit' => BigDecimal::of($priced['payable'])->isNegative(),
                'payable_abs_fa' => Money::toman((string) BigDecimal::of($priced['payable'])->abs()),
                'weights' => array_map(fn ($w) => InvoicePresenter::weight($w), $priced['weights']),
            ],
            'buyer_mobile_invalid' => $buyerMobileInvalid,
        ];
    }

    /** Market 18K buy rate («خرید از شما») used as the default value of gold received; null when unavailable. */
    private function latestBuyRate(): ?string
    {
        $buy = $this->quotes->latest('GOLD_18_BUY');
        if (! $buy || $this->quotes->freshness($buy) === 'ERROR') {
            return null;
        }

        return (string) BigDecimal::of($buy->value)->toScale(0, RoundingMode::HalfUp);
    }

    private function vatRate(Tenant $tenant): string
    {
        return (string) BigDecimal::of($this->taxRules->for('GOLD_SERVICES', now())->rate_percent)->strippedOfTrailingZeros();
    }

    private function cleanName(?string $name): ?string
    {
        $name = trim(strip_tags((string) $name));

        return $name === '' ? null : mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 80);
    }

    /**
     * Issues the draft exactly once. Same idempotency key returns the same result.
     * Quota counting, numbering and snapshot happen under the tenant row lock.
     */
    public function issue(Invoice $draft, Tenant $tenant, User $user, array $input): array
    {
        $key = (string) ($input['idempotency_key'] ?? '');
        if (! preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $key)) {
            throw new DomainError('IDEMPOTENCY_KEY', 'درخواست نامعتبر است. صفحه را دوباره باز کنید.', 422);
        }
        $mode = ($input['mode'] ?? '') === 'ISSUE_AND_SMS' ? 'ISSUE_AND_SMS' : 'ISSUE_ONLY';

        $existing = Invoice::query()->where('issue_key', $key)->first();
        if ($existing) {
            return ['invoice' => $existing, 'replayed' => true];
        }

        $invoice = DB::transaction(function () use ($draft, $tenant, $user, $input, $key, $mode) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            $invoice = Invoice::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if (! $invoice->isDraft()) {
                throw new DomainError('ALREADY_ISSUED', 'این فاکتور قبلاً صادر شده است.', 409, ['invoice' => $invoice->public_id]);
            }
            if ((int) ($input['version'] ?? -1) !== $invoice->version) {
                throw new DomainError('REVIEW_REQUIRED', 'فاکتور پس از مرور تغییر کرد. یک بار دیگر مرور کنید.', 409, ['reason' => 'CHANGED']);
            }
            $this->entitlements->assertCan($tenant, 'invoice.finalize', 'صدور فاکتور در این پلن فعال نیست.');
            $profile = $tenant->profile()->first();
            if (! $profile?->isComplete()) {
                throw new DomainError('PROFILE_INCOMPLETE', 'برای صدور اولین فاکتور، نام، موبایل و آدرس کسب‌وکار لازم است.', 409, ['missing' => $profile?->missing() ?? ['name', 'business_mobile', 'address'], 'redirect' => route('settings.business', ['return' => $invoice->public_id])]);
            }
            $this->entitlements->assertQuota($tenant, 'invoices_per_month');

            $buyerName = $this->cleanName($input['buyer']['name'] ?? $invoice->buyer_name);
            $rawMobile = trim((string) ($input['buyer']['mobile'] ?? $invoice->buyer_mobile ?? ''));
            $buyerMobile = $rawMobile === '' ? null : Mobile::normalize($rawMobile);
            if ($rawMobile !== '' && ! $buyerMobile) {
                throw new DomainError('BUYER_MOBILE_INVALID', 'شماره موبایل مشتری درست نیست.', 422, ['errors' => ['buyer_mobile' => ['شماره موبایل مشتری درست نیست.']]]);
            }
            if ($mode === 'ISSUE_AND_SMS') {
                if (! $buyerMobile) {
                    throw new DomainError('BUYER_MOBILE_REQUIRED', 'برای ارسال پیامکی، شماره موبایل مشتری لازم است.', 422, ['errors' => ['buyer_mobile' => ['برای ارسال پیامکی، شماره موبایل مشتری لازم است.']]]);
                }
                if (! InvoiceShare::query()->where('invoice_id', $invoice->id)->whereNull('revoked_at')->exists()) {
                    $this->entitlements->assertQuota($tenant, 'links_per_month');
                }
            }

            $rows = $invoice->items()->get();
            $rule = $this->taxRules->for('GOLD_SERVICES', now());
            $vat = (string) BigDecimal::of($rule->rate_percent)->strippedOfTrailingZeros();
            $priced = $this->calculator->priceAll($rows->map->only(self::ROW_FIELDS)->all(), $invoice->accepted_rate_irr, $vat, $invoice->accepted_buy_rate_irr);
            if ($priced['sale_required']) {
                throw new DomainError('ROWS_SALE_REQUIRED', 'فاکتور فروش دست‌کم یک ردیف فروش (طلا یا متفرقه) لازم دارد. طلای دریافتی به‌تنهایی فاکتور فروش نیست.', 422);
            }
            if (! $priced['valid']) {
                throw new DomainError('ROWS_INVALID', 'بعضی ردیف‌ها کامل یا درست نیستند. آن‌ها را اصلاح کنید.', 422);
            }

            $customerId = $invoice->customer_id;
            if ($buyerMobile) {
                $customer = Customer::query()->where('mobile', $buyerMobile)->first();
                if (! $customer && ! empty($input['save_customer'])) {
                    $this->entitlements->assertQuota($tenant, 'new_customers_per_month');
                    $customer = Customer::create(['name' => $buyerName ?: 'مشتری', 'mobile' => $buyerMobile, 'created_by' => $user->id]);
                    Audit::record('customer.created', $customer, ['via' => 'invoice']);
                }
                $customerId = $customer?->id;
            }

            $year = Jalali::year(now(), $tenant->timezone);
            $counter = InvoiceCounter::query()->where('jalali_year', $year)->lockForUpdate()->first()
                ?? InvoiceCounter::create(['jalali_year' => $year, 'last_seq' => 0]);
            $counter->last_seq++;
            $counter->save();
            $seq = $counter->last_seq;

            foreach ($priced['rows'] as $r) {
                InvoiceItem::query()->where('invoice_id', $invoice->id)->where('row_uid', $r['row_uid'])
                    ->update(['computed' => json_encode($r['result']['computed']), 'row_total_irr' => $r['result']['total']]);
            }

            $invoice->forceFill([
                'status' => 'issued', 'number' => sprintf('%d-%04d', $year, $seq), 'jalali_year' => $year, 'seq' => $seq,
                'buyer_name' => $buyerName, 'buyer_mobile' => $buyerMobile, 'customer_id' => $customerId,
                'gold_total_irr' => $priced['gold_total'], 'misc_total_irr' => $priced['misc_total'], 'payable_irr' => $priced['payable'],
                // Denormalized report columns (dashboard); the snapshot below stays the legal record.
                'sales_total_irr' => $priced['sales_total'], 'gold_in_total_irr' => $priced['gold_in_total'],
                'wage_irr' => $priced['gold']['W'], 'profit_irr' => $priced['gold']['P'], 'vat_irr' => $priced['gold']['V'],
                'gold_out_weight_750' => $priced['weights']['out_750'], 'gold_in_weight_750' => $priced['weights']['in_750'],
                'verify_token' => $vt = Tokens::make(), 'verify_token_hash' => Tokens::hash($vt), 'issue_mode' => $mode, 'issue_key' => $key, 'issued_at' => now(), 'issued_by' => $user->id,
            ]);
            $invoice->snapshot = $this->snapshot($invoice, $tenant, $user, $priced, $rule);
            $invoice->save();
            Audit::record('invoice.issued', $invoice, ['number' => $invoice->number, 'mode' => $mode, 'payable_irr' => $invoice->payable_irr]);

            return $invoice;
        });

        $sms = null;
        if ($mode === 'ISSUE_AND_SMS') {
            try {
                $share = $this->ensureShare($invoice, $tenant, $user);
                $sms = $this->sms->queueInvoiceSms($tenant, $invoice, $this->shareUrl($share), $user->id, false);
            } catch (DomainError $e) {
                // Issuance stays committed; SMS problems never roll it back.
                Audit::record('sms.not_sent_after_issue', $invoice, ['code' => $e->codeName]);
                $sms = $e;
            }
        }

        return ['invoice' => $invoice->fresh(), 'replayed' => false, 'sms' => $sms];
    }

    private function snapshot(Invoice $invoice, Tenant $tenant, User $user, array $priced, $rule): array
    {
        $profile = $tenant->profile()->first();
        $canCustomize = $this->entitlements->can($tenant, 'invoice.customize');
        $canLogo = $this->entitlements->can($tenant, 'invoice.shop_logo');
        $layoutRow = InvoiceLayout::query()->first();
        $layout = LayoutSettings::effective($layoutRow?->settings ?? [], $canCustomize);

        return [
            'schema' => 1,
            'number' => $invoice->number,
            'issued_at' => $invoice->issued_at->toIso8601String(),
            'timezone' => $tenant->timezone,
            'issuer' => ['name' => $user->name ?: Mobile::mask($user->mobile)],
            'shop' => [
                'name' => $profile->name, 'business_mobile' => $profile->business_mobile, 'landline' => $profile->landline,
                'address' => $profile->address, 'website' => $profile->website, 'socials' => $profile->socials ?? [],
                'license_union' => $profile->license_union, 'license_online' => $profile->license_online,
                'logo' => $canLogo && $profile->logo_path ? ['tenant' => $tenant->public_id, 'version' => $profile->logo_version] : null,
            ],
            'buyer' => ['name' => $invoice->buyer_name, 'mobile' => $invoice->buyer_mobile],
            'rate' => [
                'mode' => $invoice->rate_mode, 'value_irr' => $invoice->accepted_rate_irr, 'buy_value_irr' => $invoice->accepted_buy_rate_irr,
                'fetched_at' => $invoice->rate_fetched_at?->toIso8601String(), 'manual_reason' => $invoice->rate_manual_reason,
            ],
            'tax' => ['category' => 'GOLD_SERVICES', 'rule_id' => $rule->id, 'version' => $rule->version, 'rate_percent' => (string) $rule->rate_percent, 'is_sample' => (bool) $rule->is_sample],
            'rounding_policy' => 'IRR_LINE_HALF_UP_V1',
            'rows' => array_map(fn ($r) => array_diff_key($r, ['result' => 1]) + ['computed' => $r['result']['computed'], 'total_irr' => $r['result']['total']], $priced['rows']),
            'totals' => [
                'gold_irr' => $priced['gold_total'], 'misc_irr' => $priced['misc_total'], 'payable_irr' => $priced['payable'], 'gold_components' => $priced['gold'],
                'sales_irr' => $priced['sales_total'], 'gold_in_irr' => $priced['gold_in_total'], 'gold_in' => $priced['gold_in'], 'weights' => $priced['weights'],
            ],
            'layout' => $layout,
            'branding' => ['show_talata_mark' => ! $this->entitlements->can($tenant, 'invoice.hide_provider_brand')],
            'pricing_version' => $this->config->version(),
        ];
    }

    /** Returns the active share for the invoice, creating one (and consuming link quota) once. */
    public function ensureShare(Invoice $invoice, Tenant $tenant, User $user): InvoiceShare
    {
        return DB::transaction(function () use ($invoice, $tenant, $user) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            if (! Invoice::query()->whereKey($invoice->id)->where('status', 'issued')->exists()) {
                throw new DomainError('SHARE_NOT_ALLOWED', 'برای فاکتور باطل‌شده یا پیش‌نویس لینک ساخته نمی‌شود.', 409);
            }
            $share = InvoiceShare::query()->where('invoice_id', $invoice->id)->whereNull('revoked_at')->first();
            if ($share) {
                return $share;
            }
            $this->entitlements->assertQuota($tenant, 'links_per_month');
            $share = InvoiceShare::create(['invoice_id' => $invoice->id, 'token' => $st = Tokens::make(), 'token_hash' => Tokens::hash($st), 'created_by' => $user->id]);
            Audit::record('invoice.share_created', $invoice);

            return $share;
        });
    }

    public function shareUrl(InvoiceShare $share): string
    {
        return config('talata.public_url').'/i/'.$share->token;
    }

    public function verifyUrl(Invoice $invoice): string
    {
        return config('talata.public_url').'/v/'.$invoice->verify_token;
    }

    public function void(Invoice $invoice, string $reason, ?string $note): Invoice
    {
        $reasons = ['WRONG_WEIGHT', 'CUSTOMER_CANCELLED', 'DUPLICATE', 'OTHER'];
        if (! in_array($reason, $reasons, true)) {
            throw new DomainError('VOID_REASON', 'دلیل ابطال را انتخاب کنید.', 422);
        }

        return DB::transaction(function () use ($invoice, $reason, $note) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! $invoice->isIssued()) {
                throw new DomainError('NOT_ISSUED', 'فقط فاکتور قطعی قابل ابطال است.', 409);
            }
            if (InstallmentAgreement::query()->where('invoice_id', $invoice->id)->where('status', 'active')->exists()) {
                throw new DomainError('HAS_INSTALLMENTS', 'این فاکتور قرارداد اقساط فعال دارد. ابتدا تکلیف اقساط را در صفحه مشتری مشخص کنید.', 409);
            }
            $invoice->forceFill([
                'status' => 'void', 'voided_at' => now(), 'void_reason' => $reason,
                'void_note' => $note ? mb_substr(strip_tags($note), 0, 250) : null, 'voided_by' => auth()->id(),
            ])->save();
            SmsMessage::query()->where('invoice_id', $invoice->id)->where('status', 'AWAITING_CREDIT')->update(['status' => 'CANCELLED']);
            Audit::record('invoice.voided', $invoice, ['reason' => $reason]);

            return $invoice;
        });
    }

    public function replace(Invoice $original, Tenant $tenant, User $user): Invoice
    {
        if (! $original->isVoid()) {
            throw new DomainError('REPLACE_REQUIRES_VOID', 'برای ساخت فاکتور جایگزین، ابتدا فاکتور فعلی را باطل کنید.', 409);
        }
        $this->entitlements->assertQuota($tenant, 'invoices_per_month');

        return DB::transaction(function () use ($original, $user) {
            $existing = Invoice::query()->where('replaces_invoice_id', $original->id)->first();
            if ($existing) {
                return $existing;
            }
            $draft = Invoice::create([
                'status' => 'draft', 'rate_mode' => $original->rate_mode, 'accepted_rate_irr' => $original->accepted_rate_irr, 'accepted_buy_rate_irr' => $original->accepted_buy_rate_irr,
                'rate_fetched_at' => $original->rate_fetched_at, 'rate_manual_reason' => $original->rate_manual_reason,
                'buyer_name' => $original->buyer_name, 'buyer_mobile' => $original->buyer_mobile, 'customer_id' => $original->customer_id,
                'replaces_invoice_id' => $original->id, 'created_by' => $user->id,
            ]);
            foreach ($original->items()->get() as $item) {
                InvoiceItem::create($item->only(self::ROW_FIELDS) + ['invoice_id' => $draft->id]);
            }
            Audit::record('invoice.replacement_started', $original, ['draft' => $draft->public_id]);

            return $draft;
        });
    }

    public function revokeShare(Invoice $invoice): void
    {
        InvoiceShare::query()->where('invoice_id', $invoice->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        Audit::record('invoice.share_revoked', $invoice);
    }
}
