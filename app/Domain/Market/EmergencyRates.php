<?php

namespace App\Domain\Market;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Models\EmergencyRate;
use App\Models\StaffUser;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Emergency 18K sell rate announced by staff while the quote feed is down or wrong
 * (docs/handoff/04_SCREENS_ADMIN.md A-07). Merchants see «نرخ اعلامی زرلیو (دستی)» until it is
 * cancelled or its validity ends; shops can still enter their own manual rate.
 */
final class EmergencyRates
{
    /** Validity choices in minutes; null = until cancelled. */
    public const VALIDITY_FA = ['30' => '۳۰ دقیقه', '60' => 'یک ساعت', '240' => 'چهار ساعت', 'until_cancelled' => 'تا لغو دستی'];

    /** A difference from the last feed value above this percentage needs a second confirmation (typo guard). */
    public const LARGE_CHANGE_PERCENT = 20;

    public function __construct(private readonly QuoteService $quotes) {}

    public function announce(StaffUser $staff, string $valueIrr, string $validity, string $reason, bool $confirmLarge): EmergencyRate
    {
        if (! array_key_exists($validity, self::VALIDITY_FA)) {
            throw new DomainError('VALIDITY_INVALID', 'مدت اعتبار را انتخاب کنید.', 422);
        }
        $value = BigDecimal::of($valueIrr);
        $feed = $this->quotes->feed('GOLD_18_SELL');
        if ($feed && ! $confirmLarge && ! BigDecimal::of($feed->value)->isZero()) {
            $diff = $value->minus($feed->value)->abs()->multipliedBy(100)->dividedBy($feed->value, 2, RoundingMode::HalfUp);
            if ($diff->isGreaterThan(self::LARGE_CHANGE_PERCENT)) {
                throw new DomainError('CONFIRM_LARGE_CHANGE', 'این نرخ با آخرین نرخ سرویس ('.Money::toman((string) BigDecimal::of($feed->value)->toScale(0, RoundingMode::HalfUp)).' تومان) بیش از '.self::LARGE_CHANGE_PERCENT.'٪ فاصله دارد. اگر مطمئن هستید دوباره تأیید کنید.', 409);
            }
        }

        return DB::transaction(function () use ($staff, $valueIrr, $validity, $reason) {
            EmergencyRate::query()->active()->lockForUpdate()->get()
                ->each(fn (EmergencyRate $old) => $old->forceFill(['cancelled_at' => now(), 'cancelled_by_staff' => $staff->id])->save());
            $rate = EmergencyRate::query()->create([
                'asset' => 'GOLD_18_SELL', 'value_irr' => $valueIrr, 'starts_at' => now()->startOfSecond(),
                'ends_at' => $validity === 'until_cancelled' ? null : now()->addMinutes((int) $validity),
                'reason' => $reason, 'created_by_staff' => $staff->id,
            ]);
            Audit::record('quotes.emergency_set', $rate, ['value_irr' => $valueIrr, 'validity' => $validity, 'reason' => $reason], null, 'staff');

            return $rate;
        });
    }

    public function cancel(StaffUser $staff, string $reason): int
    {
        return DB::transaction(function () use ($staff, $reason) {
            $active = EmergencyRate::query()->active()->lockForUpdate()->get();
            if ($active->isEmpty()) {
                throw new DomainError('NO_EMERGENCY_RATE', 'نرخ اعلامی فعالی وجود ندارد.', 409);
            }
            $active->each(fn (EmergencyRate $r) => $r->forceFill(['cancelled_at' => now(), 'cancelled_by_staff' => $staff->id])->save());
            Audit::record('quotes.emergency_cancelled', $active->first(), ['reason' => $reason], null, 'staff');

            return $active->count();
        });
    }
}
