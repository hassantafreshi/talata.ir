<?php

namespace App\Domain\Affiliate;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\BillingOrder;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DbLock;
use App\Support\Digits;
use App\Support\TechLog;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Affiliate program (همکاری در فروش): codes, attribution of new shops, buyer discount,
 * commissions, approval and payouts. Rules: docs/AFFILIATE_PROGRAM.md.
 */
final class AffiliateService
{
    /** Unambiguous alphabet for generated codes (no 0/O, 1/I/L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function normalizeCode(?string $raw): ?string
    {
        $code = strtoupper(preg_replace('/[\s\-_]+/u', '', Digits::toLatin((string) $raw)) ?? '');

        return preg_match('/^[A-Z0-9]{4,16}$/', $code) ? $code : null;
    }

    public function generateCode(): string
    {
        do {
            $code = 'TL';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Affiliate::query()->where('code', $code)->exists());

        return $code;
    }

    public function findActive(?string $raw): ?Affiliate
    {
        $code = self::normalizeCode($raw);

        return $code ? Affiliate::query()->where('code', $code)->where('status', 'active')->first() : null;
    }

    public function referralFor(Tenant $tenant): ?AffiliateReferral
    {
        return AffiliateReferral::query()->where('tenant_id', $tenant->id)->first();
    }

    /** The affiliate (or anyone in the affiliate's shops) can never be their own customer. */
    public function isSelfReferral(Affiliate $affiliate, Tenant $tenant): bool
    {
        return Membership::query()->where('tenant_id', $tenant->id)->where('user_id', $affiliate->user_id)->where('status', '!=', 'removed')->exists()
            || $this->ownerMobile($tenant) === $affiliate->user?->mobile;
    }

    /** A code can attach only to a new shop: young, no paid order yet, not already attributed, not self. */
    public function canAttach(Affiliate $affiliate, Tenant $tenant, ?int $exceptOrderId = null): ?string
    {
        $existing = $this->referralFor($tenant);
        if ($existing) {
            return $existing->affiliate_id === $affiliate->id ? null : 'ALREADY_REFERRED';
        }
        if ($this->isSelfReferral($affiliate, $tenant)) {
            return 'SELF_REFERRAL';
        }
        if ($tenant->created_at->lt(now()->subDays(config('talata.affiliate.new_customer_days')))
            || BillingOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->whereIn('status', ['PAID', 'FULFILLED'])
                ->when($exceptOrderId, fn ($q) => $q->whereKeyNot($exceptOrderId))->exists()) {
            return 'NOT_NEW_CUSTOMER';
        }

        return null;
    }

    public function attach(Affiliate $affiliate, Tenant $tenant, string $source, ?int $exceptOrderId = null): ?AffiliateReferral
    {
        return DB::transaction(function () use ($affiliate, $tenant, $source, $exceptOrderId) {
            DbLock::key('affiliate-referral:'.$tenant->id);
            $existing = $this->referralFor($tenant);
            if ($existing) {
                return $existing->affiliate_id === $affiliate->id ? $existing : null; // attribution never changes
            }
            if ($this->canAttach($affiliate, $tenant, $exceptOrderId) !== null) {
                return null;
            }
            $ref = AffiliateReferral::create([
                'affiliate_id' => $affiliate->id, 'tenant_id' => $tenant->id, 'source' => $source,
                'buyer_mobile' => (string) $this->ownerMobile($tenant), 'attributed_at' => now(),
            ]);
            Audit::record('affiliate.referral_attached', $ref, ['affiliate' => $affiliate->id, 'source' => $source], $tenant->id, 'system');

            return $ref;
        });
    }

    /**
     * Price adjustment for a new order. Returns [affiliate|null, discount_irr, code|null].
     * Discount: plan purchases only, only on the shop's first paid plan order.
     *
     * @throws DomainError when an entered code is invalid or not usable by this shop
     */
    public function quote(Tenant $tenant, string $product, string $subtotalIrr, ?string $rawCode): array
    {
        $affiliate = null;
        $code = null;
        if ($rawCode !== null && trim($rawCode) !== '') {
            $affiliate = $this->findActive($rawCode);
            if (! $affiliate) {
                throw new DomainError('DISCOUNT_CODE_INVALID', 'این کد تخفیف معتبر نیست.', 422, ['errors' => ['discount_code' => ['این کد تخفیف معتبر نیست.']]]);
            }
            $why = $this->canAttach($affiliate, $tenant);
            if ($why !== null) {
                $msg = match ($why) {
                    'ALREADY_REFERRED' => 'این فروشگاه قبلاً با کد معرف دیگری ثبت شده است.',
                    'SELF_REFERRAL' => 'کد معرف شما برای فروشگاه خودتان قابل استفاده نیست.',
                    default => 'این کد فقط برای خرید اول فروشگاه‌های تازه‌ثبت‌نام است.',
                };
                throw new DomainError('DISCOUNT_CODE_NOT_ELIGIBLE', $msg, 422, ['errors' => ['discount_code' => [$msg]]]);
            }
            $code = $affiliate->code;
        } elseif ($ref = $this->referralFor($tenant)) {
            $affiliate = Affiliate::query()->whereKey($ref->affiliate_id)->where('status', 'active')->first();
            $code = $affiliate?->code;
        }

        $discount = '0';
        if ($affiliate && $product === 'PLAN' && BigDecimal::of($affiliate->discount_percent)->isPositive()
            && ! BillingOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('product', 'PLAN')->whereIn('status', ['PAID', 'FULFILLED'])->exists()) {
            $discount = (string) BigDecimal::of($subtotalIrr)->multipliedBy($affiliate->discount_percent)->dividedBy(100, 0, RoundingMode::HalfUp);
        }

        return [$affiliate, $discount, $code];
    }

    /** Called inside order fulfilment (same transaction). Attaches the shop (if a code was used) and records the commission. */
    public function onOrderFulfilled(BillingOrder $order, Tenant $tenant): ?AffiliateCommission
    {
        $affiliate = $order->affiliate_id ? Affiliate::query()->find($order->affiliate_id) : null;
        $referral = $this->referralFor($tenant);
        if (! $referral && $affiliate && $order->discount_code) {
            $referral = $this->attach($affiliate, $tenant, 'CODE', $order->id); // this payment itself is the first one
        }
        if (! $referral) {
            return null;
        }
        $affiliate = Affiliate::query()->find($referral->affiliate_id);
        if (! $affiliate?->isActive() || $this->isSelfReferral($affiliate, $tenant)) {
            return null;
        }
        if ($order->product === 'SMS_CREDIT' && ! $affiliate->include_sms_credit) {
            return null;
        }
        if ($affiliate->commission_mode === 'FIRST_PAYMENT'
            && AffiliateCommission::query()->where('referral_id', $referral->id)->where('status', '!=', 'VOID')->exists()) {
            return null;
        }
        if (AffiliateCommission::query()->where('order_id', $order->id)->exists()) {
            return null; // idempotent
        }
        $amount = (string) BigDecimal::of($order->subtotal_irr)->multipliedBy($affiliate->commission_percent)->dividedBy(100, 0, RoundingMode::HalfUp);
        if (BigDecimal::of($amount)->isZero()) {
            return null;
        }
        $c = AffiliateCommission::create([
            'affiliate_id' => $affiliate->id, 'referral_id' => $referral->id, 'tenant_id' => $tenant->id, 'order_id' => $order->id,
            'product' => $order->product, 'base_irr' => (string) $order->subtotal_irr, 'percent' => $affiliate->commission_percent,
            'mode' => $affiliate->commission_mode, 'amount_irr' => $amount, 'status' => 'PENDING',
            'approve_after' => now()->addDays(config('talata.affiliate.hold_days')),
        ]);
        Audit::record('affiliate.commission_created', $c, ['affiliate' => $affiliate->id, 'amount_irr' => $amount, 'order' => $order->public_ref], $tenant->id, 'system');
        TechLog::info('affiliate', 'commission created', ['affiliate' => $affiliate->id, 'order' => $order->public_ref, 'amount_irr' => $amount]);

        return $c;
    }

    /** Pending commissions past the hold period become payable. */
    public function approveDue(): int
    {
        return AffiliateCommission::query()->where('status', 'PENDING')->where('approve_after', '<=', now())
            ->update(['status' => 'APPROVED', 'approved_at' => now(), 'updated_at' => now()]);
    }

    /** Records a manual bank transfer covering all payable commissions of the affiliate. */
    public function payout(Affiliate $affiliate, string $reference, ?int $staffId, ?string $note = null): AffiliatePayout
    {
        return DB::transaction(function () use ($affiliate, $reference, $staffId, $note) {
            $rows = AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->where('status', 'APPROVED')->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw new DomainError('NOTHING_TO_PAY', 'کمیسیون قابل پرداختی وجود ندارد.', 422);
            }
            $total = $rows->reduce(fn ($sum, $r) => $sum->plus($r->amount_irr), BigDecimal::zero());
            $payout = AffiliatePayout::create([
                'affiliate_id' => $affiliate->id, 'amount_irr' => (string) $total, 'reference' => mb_substr(trim($reference), 0, 80),
                'staff_id' => $staffId, 'note' => $note ? mb_substr($note, 0, 250) : null, 'paid_at' => now(),
            ]);
            AffiliateCommission::query()->whereIn('id', $rows->pluck('id'))->update(['status' => 'PAID', 'payout_id' => $payout->id, 'updated_at' => now()]);
            Audit::record('affiliate.payout_recorded', $payout, ['affiliate' => $affiliate->id, 'amount_irr' => (string) $total, 'count' => $rows->count()], null, 'staff');

            return $payout;
        });
    }

    public function void(AffiliateCommission $c, string $reason): void
    {
        if (! in_array($c->status, ['PENDING', 'APPROVED'], true)) {
            throw new DomainError('COMMISSION_FINAL', 'کمیسیون پرداخت‌شده یا لغوشده قابل تغییر نیست.', 422);
        }
        $c->update(['status' => 'VOID', 'void_reason' => mb_substr($reason, 0, 250)]);
        Audit::record('affiliate.commission_voided', $c, ['reason' => $reason], $c->tenant_id, 'staff');
    }

    /** Totals in IRR by status for the affiliate dashboard. */
    public function totals(Affiliate $affiliate): array
    {
        $sums = AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->selectRaw('status, sum(amount_irr) s, count(*) c')->groupBy('status')->get()->keyBy('status');
        $get = fn ($s) => (string) BigDecimal::of((string) ($sums[$s]->s ?? '0'))->toScale(0);

        return ['PENDING' => $get('PENDING'), 'APPROVED' => $get('APPROVED'), 'PAID' => $get('PAID'),
            'TOTAL' => (string) BigDecimal::of($get('PENDING'))->plus($get('APPROVED'))->plus($get('PAID')),
            'referrals' => AffiliateReferral::query()->where('affiliate_id', $affiliate->id)->count()];
    }

    /** "۰۹۱*****۵۶۷": first three and last three digits only. */
    public static function maskBuyer(?string $mobile): string
    {
        $m = (string) $mobile;

        return strlen($m) === 11 ? Digits::toPersian(substr($m, 0, 3)).'*****'.Digits::toPersian(substr($m, -3)) : '***';
    }

    public function enroll(User $user, array $input, ?int $staffId): Affiliate
    {
        $data = $this->validated($input, null);
        if (Affiliate::query()->where('user_id', $user->id)->exists()) {
            throw new DomainError('AFFILIATE_EXISTS', 'این شماره قبلاً همکار فروش است.', 409);
        }
        $affiliate = Affiliate::create(array_merge($data, [
            'user_id' => $user->id, 'code' => $data['code'] ?? $this->generateCode(), 'status' => 'active', 'created_by_staff' => $staffId,
        ]));
        Audit::record('affiliate.enrolled', $affiliate, ['user' => $user->id, 'code' => $affiliate->code, 'percent' => $affiliate->commission_percent, 'mode' => $affiliate->commission_mode], null, 'staff');

        return $affiliate;
    }

    public function update(Affiliate $affiliate, array $input): Affiliate
    {
        $data = $this->validated($input, $affiliate);
        $affiliate->update(array_filter($data, fn ($v) => $v !== null) + ['include_sms_credit' => $data['include_sms_credit'], 'note' => $data['note']]);
        Audit::record('affiliate.updated', $affiliate, array_intersect_key($affiliate->getChanges(), array_flip(['code', 'commission_percent', 'commission_mode', 'discount_percent', 'include_sms_credit', 'status'])), null, 'staff');

        return $affiliate;
    }

    private function validated(array $in, ?Affiliate $current): array
    {
        $errors = [];
        $pct = fn ($v) => preg_match('/^\d{1,2}(\.\d{1,2})?$/', Digits::toLatin((string) $v)) ? Digits::toLatin((string) $v) : null;
        $commission = $pct($in['commission_percent'] ?? '');
        if ($commission === null || (float) $commission <= 0 || (float) $commission > config('talata.affiliate.max_commission_percent')) {
            $errors['commission_percent'] = ['درصد کمیسیون بین ۰ تا '.Digits::toPersian((string) config('talata.affiliate.max_commission_percent')).' باشد.'];
        }
        $discount = $pct(($in['discount_percent'] ?? '') === '' ? '0' : $in['discount_percent']);
        if ($discount === null || (float) $discount > config('talata.affiliate.max_discount_percent')) {
            $errors['discount_percent'] = ['درصد تخفیف خریدار بین ۰ تا '.Digits::toPersian((string) config('talata.affiliate.max_discount_percent')).' باشد.'];
        }
        $mode = in_array($in['commission_mode'] ?? '', array_keys(Affiliate::MODES), true) ? $in['commission_mode'] : null;
        if (! $mode) {
            $errors['commission_mode'] = ['نوع کمیسیون را انتخاب کنید.'];
        }
        $code = null;
        if (trim((string) ($in['code'] ?? '')) !== '') {
            $code = self::normalizeCode($in['code']);
            if (! $code) {
                $errors['code'] = ['کد فقط حروف انگلیسی و عدد، ۴ تا ۱۶ نویسه.'];
            } elseif (Affiliate::query()->where('code', $code)->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
                $errors['code'] = ['این کد قبلاً استفاده شده است.'];
            }
        }
        $status = in_array($in['status'] ?? '', ['active', 'paused'], true) ? $in['status'] : null;
        if ($errors) {
            throw new DomainError('VALIDATION', 'چند مورد را اصلاح کنید.', 422, ['errors' => $errors]);
        }

        return [
            'commission_percent' => $commission, 'commission_mode' => $mode, 'discount_percent' => $discount, 'code' => $code,
            'include_sms_credit' => filter_var($in['include_sms_credit'] ?? false, FILTER_VALIDATE_BOOL),
            'status' => $status, 'note' => isset($in['note']) ? mb_substr(trim(strip_tags((string) $in['note'])), 0, 250) ?: null : null,
        ];
    }

    private function ownerMobile(Tenant $tenant): ?string
    {
        return Membership::query()->where('tenant_id', $tenant->id)->where('role', 'owner')->with('user')->first()?->user?->mobile;
    }
}
