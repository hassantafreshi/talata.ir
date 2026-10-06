<?php

namespace App\Domain\Tax;

use App\Domain\DomainError;
use App\Models\TaxRule;
use DateTimeInterface;

/** Versioned, effective-dated tax rules per category. No silent fallback when none applies. */
final class TaxRules
{
    public function for(string $category, DateTimeInterface $at): TaxRule
    {
        $rule = TaxRule::query()->where('category', $category)->where('status', 'active')
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->orderByDesc('effective_from')->orderByDesc('version')->first();

        return $rule ?? throw new DomainError('NO_TAX_RULE', 'قاعده مالیاتی معتبری برای این تاریخ تعریف نشده است. با پشتیبانی طلاتا تماس بگیرید.', 503);
    }
}
