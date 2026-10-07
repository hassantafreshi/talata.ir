<?php

namespace App\Domain\Sms;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Models\BillingOrder;
use App\Models\SmsCreditEntry;
use App\Models\SmsCreditLot;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Support\Jalali;
use App\Support\Money;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

/**
 * Prepaid toman credit (stored IRR) as lots + append-only entries. Callers hold the tenant
 * row lock; lots are additionally locked FOR UPDATE so balances never go negative.
 */
final class SmsCredit
{
    public function balance(int $tenantId): string
    {
        $sum = SmsCreditLot::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->sum('remaining_irr');

        return (string) BigInteger::of((string) ($sum ?: '0'));
    }

    public function expiringBalance(int $tenantId): array
    {
        $lots = SmsCreditLot::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereNotNull('expires_at')
            ->where('expires_at', '>', now())->where('remaining_irr', '>', 0)->orderBy('expires_at')->get();

        return ['amount_irr' => (string) $lots->sum('remaining_irr'), 'expires_at' => $lots->first()?->expires_at];
    }

    /** Reserves $amountIrr across lots (soonest expiry first). Returns false if insufficient. */
    public function reserve(int $tenantId, string $amountIrr, SmsMessage $message): bool
    {
        $need = BigInteger::of($amountIrr);
        $lots = SmsCreditLot::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->where('remaining_irr', '>', 0)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('expires_at IS NULL, expires_at ASC, id ASC')->lockForUpdate()->get();
        $available = $lots->reduce(fn ($c, $l) => $c->plus($l->remaining_irr), BigInteger::zero());
        if ($available->isLessThan($need)) {
            return false;
        }
        foreach ($lots as $lot) {
            if ($need->isZero()) {
                break;
            }
            $take = BigInteger::min($need, BigInteger::of($lot->remaining_irr));
            $lot->remaining_irr = (string) BigInteger::of($lot->remaining_irr)->minus($take);
            $lot->save();
            $this->entry($tenantId, $lot->id, 'RESERVE', (string) $take->negated(), $message);
            $need = $need->minus($take);
        }

        return true;
    }

    /** Gives a failed message's reservation back to the same lots. */
    public function release(SmsMessage $message): void
    {
        $reserves = SmsCreditEntry::withoutGlobalScope('tenant')->where('sms_message_id', $message->id)->where('type', 'RESERVE')->get();
        $released = SmsCreditEntry::withoutGlobalScope('tenant')->where('sms_message_id', $message->id)->where('type', 'RELEASE')->exists();
        if ($released) {
            return;
        }
        foreach ($reserves as $r) {
            $lot = SmsCreditLot::withoutGlobalScope('tenant')->lockForUpdate()->find($r->lot_id);
            if (! $lot) {
                continue;
            }
            $amount = BigInteger::of($r->amount_irr)->negated();
            $lot->remaining_irr = (string) BigInteger::of($lot->remaining_irr)->plus($amount);
            $lot->save();
            $this->entry($message->tenant_id, $lot->id, 'RELEASE', (string) $amount, $message);
        }
    }

    public function capture(SmsMessage $message): void
    {
        if (! SmsCreditEntry::withoutGlobalScope('tenant')->where('sms_message_id', $message->id)->whereIn('type', ['CAPTURE', 'RELEASE'])->exists()) {
            $this->entry($message->tenant_id, null, 'CAPTURE', '0', $message);
        }
    }

    public function creditFromOrder(BillingOrder $order, string $planCode, bool $carriesOver, Tenant $tenant): SmsCreditLot
    {
        $expires = null;
        if (! $carriesOver) {
            [, $end] = Jalali::monthBounds(now(), $tenant->timezone);
            $expires = $end;
        }
        // Credit = the pack bought (pre-VAT list amount); a discount code lowers what is paid, not the credit.
        $amount = (string) ($order->list_subtotal_irr ?: $order->subtotal_irr);
        $lot = SmsCreditLot::withoutGlobalScope('tenant')->create([
            'tenant_id' => $order->tenant_id, 'source' => 'PURCHASE', 'source_order_id' => $order->id,
            'amount_irr' => $amount, 'remaining_irr' => $amount,
            'carries_over' => $carriesOver, 'expires_at' => $expires, 'plan_at_purchase' => $planCode,
        ]);
        $this->entry($order->tenant_id, $lot->id, 'CREDIT', $amount, null);

        return $lot;
    }

    /**
     * Provider adjustment recorded by staff with a reason (compensation, correction, goodwill).
     * Positive: a PROVIDER_ADJUST lot (month-end expiry unless it carries over). Negative: taken from
     * the soonest-expiring lots; the balance never goes below zero. Caller holds the tenant row lock.
     *
     * @return array{balance_irr:string,lots:list<int>}
     */
    public function adjust(Tenant $tenant, string $amountIrr, bool $carriesOver, string $note, int $staffId, string $planCode): array
    {
        $amount = BigInteger::of($amountIrr);
        if ($amount->isZero()) {
            throw new DomainError('AMOUNT_ZERO', 'مبلغ تغییر اعتبار نمی‌تواند صفر باشد.', 422);
        }
        $lots = [];
        if ($amount->isPositive()) {
            $lot = SmsCreditLot::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenant->id, 'source' => 'PROVIDER_ADJUST', 'amount_irr' => (string) $amount, 'remaining_irr' => (string) $amount,
                'carries_over' => $carriesOver, 'expires_at' => $carriesOver ? null : Jalali::monthBounds(now(), $tenant->timezone)[1],
                'plan_at_purchase' => $planCode, 'created_by_staff' => $staffId, 'note' => mb_substr($note, 0, 250),
            ]);
            $this->entry($tenant->id, $lot->id, 'ADJUST', (string) $amount, null);
            $lots[] = $lot->id;
        } else {
            $need = $amount->negated();
            $open = SmsCreditLot::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)
                ->where('remaining_irr', '>', 0)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByRaw('expires_at IS NULL, expires_at ASC, id ASC')->lockForUpdate()->get();
            $available = $open->reduce(fn ($c, $l) => $c->plus($l->remaining_irr), BigInteger::zero());
            if ($available->isLessThan($need)) {
                throw new DomainError('CREDIT_INSUFFICIENT', 'اعتبار فعلی این فروشگاه '.Money::toman((string) $available).' تومان است؛ بیش از آن کسر نمی‌شود.', 422);
            }
            foreach ($open as $lot) {
                if ($need->isZero()) {
                    break;
                }
                $take = BigInteger::min($need, BigInteger::of($lot->remaining_irr));
                $lot->remaining_irr = (string) BigInteger::of($lot->remaining_irr)->minus($take);
                $lot->save();
                $this->entry($tenant->id, $lot->id, 'ADJUST', (string) $take->negated(), null);
                $lots[] = $lot->id;
                $need = $need->minus($take);
            }
        }

        return ['balance_irr' => $this->balance($tenant->id), 'lots' => $lots];
    }

    /** Month-end expiry of non-carry-over credit (Free plan purchases). */
    public function expireDue(): int
    {
        $count = 0;
        SmsCreditLot::withoutGlobalScope('tenant')->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->where('remaining_irr', '>', 0)->orderBy('id')->each(function (SmsCreditLot $lot) use (&$count) {
                DB::transaction(function () use ($lot, &$count) {
                    $lot = SmsCreditLot::withoutGlobalScope('tenant')->lockForUpdate()->find($lot->id);
                    if (BigInteger::of($lot->remaining_irr)->isZero()) {
                        return;
                    }
                    $amount = $lot->remaining_irr;
                    $lot->remaining_irr = '0';
                    $lot->save();
                    $this->entry($lot->tenant_id, $lot->id, 'EXPIRE', (string) BigInteger::of($amount)->negated(), null);
                    Audit::record('sms_credit.expired', $lot, ['amount_irr' => $amount], $lot->tenant_id, 'system');
                    $count++;
                });
            });

        return $count;
    }

    private function entry(int $tenantId, ?int $lotId, string $type, string $amount, ?SmsMessage $message): void
    {
        SmsCreditEntry::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenantId, 'lot_id' => $lotId, 'type' => $type, 'amount_irr' => $amount,
            'sms_message_id' => $message?->id, 'segments' => $message?->segments,
            'per_segment_irr' => $message && $message->segments ? (string) BigInteger::of($message->cost_irr ?: '0')->quotient(max(1, $message->segments)) : null,
            'created_at' => now(),
        ]);
    }
}
