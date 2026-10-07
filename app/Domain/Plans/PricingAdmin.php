<?php

namespace App\Domain\Plans;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Models\PricingVersion;
use App\Support\Digits;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Admin console price editor (docs/ADMIN_PRICING.md). Every change publishes a NEW immutable pricing
 * version copied from the active one; nothing is edited in place, so paid orders (which keep their own
 * amounts and pricing_version) and issued invoices are never rewritten. Free stays 0 by definition.
 */
final class PricingAdmin
{
    /** Plans with a price the admin may change, and the SMS per-segment price per plan. */
    public const PRICED_PLANS = ['basic', 'professional'];

    public const MAX_PLAN_TOMAN = 1_000_000_000;

    public const MAX_SEGMENT_TOMAN = 100_000;

    /** A change above this fraction asks for an explicit confirmation (typo guard). */
    public const LARGE_CHANGE = 0.5;

    public function __construct(private readonly CommercialConfig $config) {}

    /** Editable fields of the active version, as toman integer strings. */
    public function current(): array
    {
        $payload = $this->config->payload();
        $out = ['version' => $this->config->version(), 'plans' => [], 'sms' => []];
        foreach (self::PRICED_PLANS as $code) {
            $out['plans'][$code] = [
                'label_fa' => $payload['plans'][$code]['label_fa'],
                'monthly' => (string) $payload['plans'][$code]['price_toman']['monthly'],
                'yearly' => (string) $payload['plans'][$code]['price_toman']['yearly'],
            ];
        }
        foreach (array_keys($payload['plans']) as $code) {
            $out['sms'][$code] = ['label_fa' => $payload['plans'][$code]['label_fa'], 'per_segment' => (string) $payload['sms_credit']['per_segment_toman'][$code]];
        }

        return $out;
    }

    /**
     * Validates and publishes a new version. $input: plans[code][monthly|yearly], sms[code], note, confirm_large.
     *
     * @return array{version:PricingVersion,changes:list<array>}
     */
    public function publish(array $input, int $staffId): array
    {
        $current = $this->current();
        $errors = [];
        $changes = [];
        $next = $this->config->payload();
        foreach (self::PRICED_PLANS as $code) {
            foreach (['monthly', 'yearly'] as $period) {
                $field = "plans.{$code}.{$period}";
                $value = $this->amount($input['plans'][$code][$period] ?? null, 1, self::MAX_PLAN_TOMAN);
                if ($value === null) {
                    $errors[$field] = ['قیمت را به تومان و بدون اعشار وارد کنید (بیشتر از صفر).'];

                    continue;
                }
                $old = $current['plans'][$code][$period];
                if ($value !== $old) {
                    $changes[] = ['field' => $field, 'label_fa' => $current['plans'][$code]['label_fa'].' · '.($period === 'monthly' ? 'ماهانه' : 'سالانه'), 'old' => $old, 'new' => $value];
                    $next['plans'][$code]['price_toman'][$period] = $value;
                }
            }
            if (! isset($errors["plans.{$code}.monthly"]) && ! isset($errors["plans.{$code}.yearly"])
                && (int) $next['plans'][$code]['price_toman']['yearly'] > 12 * (int) $next['plans'][$code]['price_toman']['monthly']) {
                $errors["plans.{$code}.yearly"] = ['قیمت سالانه نباید از ۱۲ برابر ماهانه بیشتر باشد.'];
            }
        }
        foreach (array_keys($current['sms']) as $code) {
            $field = "sms.{$code}";
            $value = $this->amount($input['sms'][$code] ?? null, 1, self::MAX_SEGMENT_TOMAN);
            if ($value === null) {
                $errors[$field] = ['قیمت هر بخش پیامک را به تومان وارد کنید (بیشتر از صفر).'];

                continue;
            }
            $old = $current['sms'][$code]['per_segment'];
            if ($value !== $old) {
                $changes[] = ['field' => $field, 'label_fa' => 'پیامک · '.$current['sms'][$code]['label_fa'], 'old' => $old, 'new' => $value];
                $next['sms_credit']['per_segment_toman'][$code] = $value;
            }
        }
        if ($errors) {
            throw new DomainError('VALIDATION', 'بعضی قیمت‌ها درست نیستند.', 422, ['errors' => $errors]);
        }
        if (! $changes) {
            throw new DomainError('NO_CHANGES', 'قیمتی تغییر نکرده است.', 422);
        }
        $large = array_values(array_filter($changes, fn ($c) => abs((int) $c['new'] - (int) $c['old']) > self::LARGE_CHANGE * max(1, (int) $c['old'])));
        if ($large && empty($input['confirm_large'])) {
            throw new DomainError('CONFIRM_LARGE_CHANGE', 'تغییر بیش از ۵۰٪ است. اگر مطمئن هستید دوباره تأیید کنید.', 409, ['changes' => self::describe($large)]);
        }
        $note = mb_substr(trim(strip_tags((string) ($input['note'] ?? ''))), 0, 250) ?: null;

        $version = DB::transaction(function () use ($next, $note, $staffId) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE pricing_versions IN SHARE ROW EXCLUSIVE MODE'); // SQLite: IMMEDIATE transactions already serialize
            }
            $number = (int) PricingVersion::query()->max('version') + 1;

            return PricingVersion::create([
                'version' => $number, 'payload' => $next, 'effective_from' => now()->startOfSecond(), 'status' => 'published', // the column has no fractions: never round into the future
                'note' => $note ?? 'تغییر قیمت از پنل مدیریت', 'created_by_staff' => $staffId,
            ]);
        });
        $this->config->forget();
        Audit::record('pricing.published', $version, ['version' => $version->version, 'changes' => $changes], null, 'staff');

        return ['version' => $version, 'changes' => self::describe($changes)];
    }

    /** Republishes an older version's prices as a new version (one-click undo). */
    public function restore(PricingVersion $old, int $staffId): array
    {
        $input = ['confirm_large' => true, 'note' => 'بازگرداندن قیمت‌های نسخه '.$old->version];
        foreach (self::PRICED_PLANS as $code) {
            $input['plans'][$code] = $old->payload['plans'][$code]['price_toman'] ?? [];
        }
        $input['sms'] = $old->payload['sms_credit']['per_segment_toman'] ?? [];

        return $this->publish($input, $staffId);
    }

    private function amount(mixed $raw, int $min, int $max): ?string
    {
        $v = str_replace([',', '٬', ' '], '', Digits::toLatin((string) ($raw ?? '')));
        if (! preg_match('/^\d{1,12}$/', $v) || (int) $v < $min || (int) $v > $max) {
            return null;
        }

        return (string) (int) $v;
    }

    private static function describe(array $changes): array
    {
        return array_map(fn ($c) => $c + ['old_fa' => Money::toman((int) $c['old'] * 10), 'new_fa' => Money::toman((int) $c['new'] * 10)], $changes);
    }
}
