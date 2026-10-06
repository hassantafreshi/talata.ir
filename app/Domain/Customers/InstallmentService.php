<?php

namespace App\Domain\Customers;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Plans\Entitlements;
use App\Domain\Sms\SmsService;
use App\Models\Customer;
use App\Models\InstallmentAgreement;
use App\Models\InstallmentLine;
use App\Models\InstallmentPayment;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Professional installments: schedules, manual payments, reversals, reminders. No interest engine in Phase 1. */
final class InstallmentService
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /** @return list<array{number:int,due:CarbonImmutable,amount:string}> */
    public function schedule(string $principalIrr, int $count, string $frequency, CarbonImmutable $first, string $tz): array
    {
        $principal = BigInteger::of($principalIrr);
        $base = $principal->quotient($count);
        // Keep instalments round to 1,000 toman where possible; remainder goes to the last one.
        $rounded = $base->quotient(10000)->multipliedBy(10000);
        if ($rounded->isZero()) {
            $rounded = $base;
        }
        [$jy, $jm, $jd] = Jalali::fromGregorian($first->year, $first->month, $first->day);
        $out = [];
        $sum = BigInteger::zero();
        for ($i = 0; $i < $count; $i++) {
            if ($frequency === 'weekly') {
                $due = $first->addDays(7 * $i);
            } else {
                $m = $jm - 1 + $i;
                $y = $jy + intdiv($m, 12);
                $mm = $m % 12 + 1;
                $d = min($jd, Jalali::monthLength($y, $mm));
                [$gy, $gm, $gd] = Jalali::toGregorian($y, $mm, $d);
                $due = CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, $tz);
            }
            $amount = $i === $count - 1 ? $principal->minus($sum) : $rounded;
            $sum = $sum->plus($amount);
            $out[] = ['number' => $i + 1, 'due' => $due, 'amount' => (string) $amount];
        }

        return $out;
    }

    public function create(Tenant $tenant, User $user, Customer $customer, array $input): InstallmentAgreement
    {
        $this->entitlements->assertCan($tenant, 'installments.manage', 'اقساط و یادآوری پیامکی در پلن حرفه‌ای است. ثبت مشتری و فاکتور آزاد است.');
        $count = (int) ($input['count'] ?? 0);
        $frequency = ($input['frequency'] ?? 'monthly') === 'weekly' ? 'weekly' : 'monthly';
        $down = Money::parseTomanToIrr((string) ($input['down_payment_toman'] ?? '0'), true) ?? 'x';
        $first = Jalali::parse((string) ($input['first_due'] ?? ''), $tenant->timezone);
        $errors = [];
        if ($count < 1 || $count > 60) {
            $errors['count'] = ['تعداد اقساط بین ۱ تا ۶۰ باشد.'];
        }
        if ($down === 'x') {
            $errors['down_payment_toman'] = ['مبلغ پیش‌پرداخت درست نیست.'];
        }
        if (! $first) {
            $errors['first_due'] = ['تاریخ اولین سررسید را انتخاب کنید.'];
        }
        $invoice = null;
        if (! empty($input['invoice_id'])) {
            $invoice = Invoice::query()->where('public_id', $input['invoice_id'])->where('status', 'issued')->first();
            if (! $invoice) {
                $errors['invoice_id'] = ['فاکتور قطعی پیدا نشد.'];
            }
            $total = $invoice?->payable_irr;
        } else {
            $total = Money::parseTomanToIrr((string) ($input['principal_toman'] ?? ''));
            if (! $total) {
                $errors['principal_toman'] = ['مبلغ را وارد کنید.'];
            }
        }
        if ($errors) {
            throw new DomainError('VALIDATION', 'اطلاعات قرارداد کامل نیست.', 422, ['errors' => $errors]);
        }
        $principal = BigInteger::of($total)->minus($down);
        if ($principal->isLessThanOrEqualTo(0)) {
            throw new DomainError('PRINCIPAL_ZERO', 'پیش‌پرداخت نباید از کل مبلغ بیشتر یا مساوی باشد.', 422, ['errors' => ['down_payment_toman' => ['پیش‌پرداخت نباید از کل مبلغ بیشتر یا مساوی باشد.']]]);
        }

        return DB::transaction(function () use ($tenant, $user, $customer, $invoice, $principal, $down, $count, $frequency, $first, $input) {
            if ($invoice && InstallmentAgreement::query()->where('invoice_id', $invoice->id)->where('status', 'active')->lockForUpdate()->exists()) {
                throw new DomainError('AGREEMENT_EXISTS', 'برای این فاکتور قرارداد اقساط فعال وجود دارد.', 409);
            }
            $agreement = InstallmentAgreement::create([
                'customer_id' => $customer->id, 'invoice_id' => $invoice?->id, 'principal_irr' => (string) $principal,
                'down_payment_irr' => $down, 'count' => $count, 'frequency' => $frequency,
                'reminders_enabled' => (bool) ($input['reminders'] ?? true), 'status' => 'active', 'created_by' => $user->id,
            ]);
            foreach ($this->schedule((string) $principal, $count, $frequency, $first, $tenant->timezone) as $line) {
                InstallmentLine::create(['agreement_id' => $agreement->id, 'number' => $line['number'], 'due_date' => $line['due']->toDateString(), 'amount_irr' => $line['amount'], 'paid_irr' => '0']);
            }
            Audit::record('installment.created', $agreement, ['principal_irr' => (string) $principal, 'count' => $count]);

            return $agreement;
        });
    }

    public function pay(InstallmentAgreement $agreement, User $user, array $input, string $tz): InstallmentPayment
    {
        $amount = Money::parseTomanToIrr((string) ($input['amount_toman'] ?? ''));
        $method = in_array($input['method'] ?? '', ['cash', 'pos', 'card_transfer', 'other'], true) ? $input['method'] : null;
        $paidOn = Jalali::parse((string) ($input['paid_on'] ?? ''), $tz);
        $key = (string) ($input['idempotency_key'] ?? '');
        $errors = array_filter([
            'amount_toman' => $amount ? null : ['مبلغ دریافتی را وارد کنید.'],
            'method' => $method ? null : ['روش دریافت را انتخاب کنید.'],
            'paid_on' => $paidOn ? null : ['تاریخ دریافت را انتخاب کنید.'],
        ]);
        if ($errors || ! preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $key)) {
            throw new DomainError('VALIDATION', 'اطلاعات پرداخت کامل نیست.', 422, ['errors' => $errors]);
        }

        return DB::transaction(function () use ($agreement, $user, $amount, $method, $paidOn, $key, $input) {
            $existing = InstallmentPayment::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $agreement = InstallmentAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            if ($agreement->status !== 'active') {
                throw new DomainError('AGREEMENT_CLOSED', 'این قرارداد فعال نیست.', 409);
            }
            $lines = InstallmentLine::query()->where('agreement_id', $agreement->id)->orderBy('number')->lockForUpdate()->get();
            $remaining = $lines->reduce(fn ($c, $l) => $c->plus($l->remaining()), BigInteger::zero());
            $left = BigInteger::of($amount);
            if ($left->isGreaterThan($remaining)) {
                throw new DomainError('OVERPAYMENT', 'مبلغ از مانده قرارداد ('.Money::toman((string) $remaining).' تومان) بیشتر است.', 422, ['errors' => ['amount_toman' => ['مبلغ از مانده قرارداد بیشتر است.']]]);
            }
            $alloc = [];
            foreach ($lines as $line) {
                if ($left->isZero()) {
                    break;
                }
                $take = BigInteger::min($left, BigInteger::of($line->remaining()));
                if ($take->isZero()) {
                    continue;
                }
                $line->paid_irr = (string) BigInteger::of($line->paid_irr)->plus($take);
                $line->save();
                $alloc[] = ['line' => $line->number, 'amount_irr' => (string) $take];
                $left = $left->minus($take);
            }
            $payment = InstallmentPayment::create([
                'agreement_id' => $agreement->id, 'amount_irr' => $amount, 'method' => $method, 'paid_on' => $paidOn->toDateString(),
                'reference' => isset($input['reference']) ? mb_substr(strip_tags((string) $input['reference']), 0, 60) : null,
                'allocations' => $alloc, 'recorded_by' => $user->id, 'idempotency_key' => $key,
            ]);
            if ($lines->every(fn ($l) => BigInteger::of($l->remaining())->isZero())) {
                $agreement->update(['status' => 'completed']);
            }
            Audit::record('installment.payment_recorded', $payment, ['amount_irr' => $amount]);

            return $payment;
        });
    }

    public function reverse(InstallmentPayment $payment, string $reason): void
    {
        $reason = trim(strip_tags($reason));
        if ($reason === '') {
            throw new DomainError('VALIDATION', 'دلیل برگشت را بنویسید.', 422, ['errors' => ['reason' => ['دلیل برگشت را بنویسید.']]]);
        }
        DB::transaction(function () use ($payment, $reason) {
            $payment = InstallmentPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->reversed_at) {
                return;
            }
            foreach ($payment->allocations as $a) {
                $line = InstallmentLine::query()->where('agreement_id', $payment->agreement_id)->where('number', $a['line'])->lockForUpdate()->first();
                $line->paid_irr = (string) BigInteger::of($line->paid_irr)->minus($a['amount_irr']);
                $line->save();
            }
            $payment->update(['reversed_at' => now(), 'reversal_reason' => mb_substr($reason, 0, 250)]);
            InstallmentAgreement::query()->whereKey($payment->agreement_id)->where('status', 'completed')->update(['status' => 'active']);
            Audit::record('installment.payment_reversed', $payment, ['reason' => $reason]);
        });
    }

    /** Scheduler: reminders 2 days before due and on overdue days, Pro only, opt-out and quiet hours respected. */
    public function sendReminders(SmsService $sms): int
    {
        $sent = 0;
        $context = app(TenantContext::class);
        $lines = InstallmentLine::withoutGlobalScope('tenant')
            ->whereColumn('paid_irr', '<', 'amount_irr')
            ->where('due_date', '<=', now()->addDays(2)->toDateString())->where('due_date', '>=', now()->subDays(30)->toDateString())
            ->with(['agreement' => fn ($q) => $q->withoutGlobalScope('tenant')])->limit(2000)->get();
        foreach ($lines as $line) {
            $agreement = $line->agreement;
            if (! $agreement || $agreement->status !== 'active' || ! $agreement->reminders_enabled) {
                continue;
            }
            $tenant = Tenant::query()->find($line->tenant_id);
            if (! $tenant?->isActive() || ! $this->entitlements->can($tenant, 'installments.sms_remind')) {
                continue;
            }
            $hour = (int) now()->setTimezone($tenant->timezone)->format('G');
            [$quietFrom, $quietTo] = config('talata.sms.reminder_quiet_hours');
            if ($hour >= $quietFrom || $hour < $quietTo) {
                continue;
            }
            $context->runAs($tenant, function () use ($sms, $tenant, $line, $agreement, &$sent) {
                $customer = Customer::query()->find($agreement->customer_id);
                if (! $customer?->mobile || $customer->sms_opt_out) {
                    return;
                }
                $shop = $tenant->profile?->name ?: 'فروشگاه';
                $body = "{$shop}: یادآوری قسط ".Digits::toPersian((string) $line->number).' به مبلغ '.Money::toman($line->remaining()).' تومان، سررسید '.Jalali::date($line->due_date, $tenant->timezone).'.';
                // At most two reminders per installment, ever: one just before due, one if overdue.
                $today = now()->setTimezone($tenant->timezone)->startOfDay();
                $due = CarbonImmutable::parse($line->due_date->toDateString(), $tenant->timezone);
                $daysUntil = (int) $today->diffInDays($due, false);
                $kind = match (true) {
                    $daysUntil >= 0 && $daysUntil <= 1 => 'pre',
                    $daysUntil <= -3 && $daysUntil >= -30 => 'late',
                    default => null,
                };
                if ($kind === null) {
                    return;
                }
                if ($sms->queueReminder($tenant, $line, $body, $customer->mobile, $kind)) {
                    $sent++;
                }
            });
        }

        return $sent;
    }
}
