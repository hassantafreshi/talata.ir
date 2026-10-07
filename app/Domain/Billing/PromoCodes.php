<?php

namespace App\Domain\Billing;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Models\BillingOrder;
use App\Models\PromoCode;
use App\Models\StaffUser;
use App\Models\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Staff discount codes (docs/PAYMENTS_AND_SMS_CREDIT.md): percent of the pre-VAT price, chosen products, optional
 * use limit and expiry, one use per shop. Taken before affiliate codes; a 100% code makes a zero order that is
 * fulfilled without the bank.
 */
final class PromoCodes
{
    public const PRODUCTS = ['PLAN' => 'خرید پلن', 'SMS_CREDIT' => 'شارژ پیامک'];

    public static function normalize(?string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $raw) ?? '');
    }

    public function find(?string $raw): ?PromoCode
    {
        $code = self::normalize($raw);

        return $code === '' ? null : PromoCode::query()->where('code', $code)->first();
    }

    /** Uses that count: orders not failed or expired. */
    private function used(PromoCode $promo, ?int $tenantId = null): int
    {
        return BillingOrder::withoutGlobalScope('tenant')->where('promo_code_id', $promo->id)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', ['FAILED', 'EXPIRED'])->count();
    }

    /** Discount in IRR for this shop, product and pre-VAT subtotal; throws a merchant-readable reason. */
    public function discount(PromoCode $promo, Tenant $tenant, string $product, string $subtotalIrr): string
    {
        $reason = match (true) {
            ! $promo->active => 'این کد تخفیف غیرفعال شده است.',
            $promo->expires_at && $promo->expires_at->isPast() => 'مهلت این کد تخفیف تمام شده است.',
            ! in_array($product, (array) $promo->products, true) => 'این کد برای '.(self::PRODUCTS[$product] ?? 'این خرید').' نیست.',
            $promo->max_uses !== null && $this->used($promo) >= $promo->max_uses => 'ظرفیت این کد تخفیف تمام شده است.',
            $this->used($promo, $tenant->id) > 0 => 'این فروشگاه قبلاً از این کد استفاده کرده است.',
            default => null,
        };
        if ($reason) {
            throw new DomainError('DISCOUNT_CODE_NOT_ELIGIBLE', $reason, 422, ['errors' => ['discount_code' => [$reason]]]);
        }

        return (string) BigDecimal::of($subtotalIrr)->multipliedBy($promo->percent)->dividedBy(100, 0, RoundingMode::HalfUp);
    }

    public function create(StaffUser $staff, array $data, string $reason): PromoCode
    {
        $code = self::normalize($data['code'] ?? '');
        if (strlen($code) < 4 || strlen($code) > 20) {
            throw new DomainError('VALIDATION', 'کد ۴ تا ۲۰ حرف و رقم انگلیسی باشد.', 422, ['errors' => ['code' => ['کد ۴ تا ۲۰ حرف و رقم انگلیسی باشد.']]]);
        }
        $percent = BigDecimal::of((string) ($data['percent'] ?? '0'));
        if ($percent->isLessThanOrEqualTo(0) || $percent->isGreaterThan(100)) {
            throw new DomainError('VALIDATION', 'درصد تخفیف بین ۱ تا ۱۰۰ باشد.', 422, ['errors' => ['percent' => ['بین ۱ تا ۱۰۰']]]);
        }
        $products = array_values(array_intersect(array_keys(self::PRODUCTS), (array) ($data['products'] ?? [])));
        if (! $products) {
            throw new DomainError('VALIDATION', 'دست‌کم یک نوع خرید را انتخاب کنید.', 422, ['errors' => ['products' => ['یکی را انتخاب کنید.']]]);
        }
        if (PromoCode::query()->where('code', $code)->exists()) {
            throw new DomainError('VALIDATION', 'این کد قبلاً ساخته شده است.', 422, ['errors' => ['code' => ['تکراری است.']]]);
        }

        return DB::transaction(function () use ($staff, $data, $reason, $code, $percent, $products) {
            $promo = PromoCode::create([
                'code' => $code, 'percent' => (string) $percent, 'products' => $products,
                'max_uses' => ($data['max_uses'] ?? '') === '' || $data['max_uses'] === null ? null : max(1, (int) $data['max_uses']),
                'expires_at' => ! empty($data['days']) ? now()->addDays((int) $data['days']) : null,
                'active' => true, 'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 200) ?: null, 'created_by' => $staff->id,
            ]);
            Audit::record('billing.promo_created', $promo, ['code' => $code, 'percent' => (string) $percent, 'products' => $products, 'max_uses' => $promo->max_uses, 'reason' => $reason], null, 'staff');

            return $promo;
        });
    }

    public function deactivate(PromoCode $promo, string $reason): void
    {
        $promo->update(['active' => false]);
        Audit::record('billing.promo_deactivated', $promo, ['code' => $promo->code, 'reason' => $reason], null, 'staff');
    }
}
