<?php

namespace App\Domain\Tax;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Models\StaffUser;
use App\Models\TaxRule;
use App\Support\DbLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Versioned tax rules (docs/handoff/04_SCREENS_ADMIN.md A-08). A rule that has started is never
 * edited: a change is a new future-dated version; only a version that has not started yet can be
 * disabled. Issued invoices keep the rule in their snapshot.
 */
final class TaxRuleAdmin
{
    public const CATEGORIES_FA = [
        'GOLD_SERVICES' => 'اجرت، سود و حق‌العمل طلا',
        'MISC' => 'ردیف متفرقه (مالیات جدا ندارد)',
        'SILVER' => 'نقره (نسخه ۲)',
        'COIN' => 'سکه (نسخه ۲)',
        'MELTED_GOLD' => 'طلای آب‌شده (نسخه ۲)',
    ];

    /** Only categories whose base the calculator implements in Phase 1 can get new versions. */
    public const SCHEDULABLE = ['GOLD_SERVICES' => 'WAGE+PROFIT+COMMISSION'];

    public const BASES_FA = ['WAGE+PROFIT+COMMISSION' => 'اجرت + سود + حق‌العمل (بدون ارزش طلا)', 'NONE' => 'بدون مالیات جدا'];

    public function schedule(StaffUser $staff, string $category, string $ratePercent, CarbonImmutable $effectiveFrom, string $reference, bool $expertConfirmed): TaxRule
    {
        if (! array_key_exists($category, self::SCHEDULABLE)) {
            throw new DomainError('TAX_CATEGORY_LOCKED', 'برای این دسته در نسخه ۱ نسخه جدید ساخته نمی‌شود.', 422);
        }
        if (! preg_match('/^\d{1,2}(\.\d{1,4})?$/', $ratePercent)) {
            throw new DomainError('TAX_RATE_INVALID', 'نرخ باید عددی بین ۰ تا ۹۹ با حداکثر چهار رقم اعشار باشد.', 422, ['errors' => ['rate_percent' => ['نرخ را درست وارد کنید (مثلاً ۱۰).']]]);
        }
        if ($effectiveFrom->lte(now())) {
            throw new DomainError('TAX_DATE_PAST', 'تاریخ شروع باید در آینده باشد؛ قاعده‌ای که شروع شده تغییر نمی‌کند.', 422, ['errors' => ['effective_from' => ['تاریخ آینده انتخاب کنید.']]]);
        }

        return DB::transaction(function () use ($category, $ratePercent, $effectiveFrom, $reference, $expertConfirmed) {
            DbLock::key('tax_rules:'.$category);
            $version = (int) TaxRule::query()->where('category', $category)->max('version') + 1;
            $rule = TaxRule::query()->create([
                'category' => $category, 'version' => $version, 'rate_percent' => $ratePercent, 'base' => self::SCHEDULABLE[$category],
                'effective_from' => $effectiveFrom, 'status' => 'active', 'is_sample' => ! $expertConfirmed, 'source_reference' => $reference,
            ]);
            Audit::record('tax.rule_scheduled', $rule, [
                'category' => $category, 'version' => $version, 'rate_percent' => $ratePercent,
                'effective_from' => $effectiveFrom->toIso8601String(), 'expert_confirmed' => $expertConfirmed, 'reference' => $reference,
            ], null, 'staff');

            return $rule;
        });
    }

    public function disable(StaffUser $staff, TaxRule $rule, string $reason): TaxRule
    {
        return DB::transaction(function () use ($rule, $reason) {
            $rule = TaxRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            if ($rule->effective_from->lte(now())) {
                throw new DomainError('TAX_RULE_STARTED', 'این قاعده شروع شده و تغییر نمی‌کند؛ برای تغییر، نسخه جدید با تاریخ آینده بسازید.', 409);
            }
            if ($rule->status !== 'active') {
                throw new DomainError('TAX_RULE_DISABLED', 'این نسخه قبلاً غیرفعال شده است.', 409);
            }
            $rule->forceFill(['status' => 'disabled'])->save();
            Audit::record('tax.rule_disabled', $rule, ['category' => $rule->category, 'version' => $rule->version, 'reason' => $reason], null, 'staff');

            return $rule;
        });
    }

    /** active | scheduled | superseded | disabled, for the table. */
    public static function state(TaxRule $rule, ?int $currentId): string
    {
        if ($rule->status !== 'active') {
            return 'disabled';
        }
        if ($rule->effective_from->gt(now())) {
            return 'scheduled';
        }

        return $rule->id === $currentId ? 'active' : 'superseded';
    }
}
