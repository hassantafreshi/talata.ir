<?php

namespace Tests\Feature;

use App\Domain\Customers\InstallmentService;
use App\Domain\Market\QuoteService;
use App\Domain\Sms\SmsService;
use App\Models\Customer;
use App\Models\InstallmentAgreement;
use App\Models\InstallmentLine;
use App\Models\InstallmentPayment;
use App\Models\SmsCreditLot;
use App\Models\SmsMessage;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Exact schedules, month-end rules, payments and reversals, reminder suppression (docs/PHASE_1_CHECKLIST.md M4). */
class InstallmentScheduleTest extends TestCase
{
    private function jalaliStart(int $jy, int $jm, int $jd): CarbonImmutable
    {
        [$gy, $gm, $gd] = Jalali::toGregorian($jy, $jm, $jd);

        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, 'Asia/Tehran');
    }

    public function test_monthly_schedule_clamps_to_month_end_and_keeps_round_amounts(): void
    {
        $s = app(InstallmentService::class);
        // 10,000,000 toman in 3 from 31 Shahrivar: Mehr and Aban have 30 days.
        $lines = $s->schedule('100000000', 3, 'monthly', $this->jalaliStart(1405, 6, 31), 'Asia/Tehran');
        $this->assertSame(['33330000', '33330000', '33340000'], array_column($lines, 'amount'));   // round 1,000 toman, remainder last
        $this->assertSame(['۱۴۰۵/۰۶/۳۱', '۱۴۰۵/۰۷/۳۰', '۱۴۰۵/۰۸/۳۰'], array_map(fn ($l) => Jalali::date($l['due'], 'Asia/Tehran'), $lines));
        $this->assertSame('100000000', (string) array_sum(array_map('intval', array_column($lines, 'amount'))));

        // From 30 Bahman: Esfand 1405 has 29 days (not a leap year).
        $lines = $s->schedule('20000000', 2, 'monthly', $this->jalaliStart(1405, 11, 30), 'Asia/Tehran');
        $this->assertSame('۱۴۰۵/۱۲/۲۹', Jalali::date($lines[1]['due'], 'Asia/Tehran'));

        // Weekly: exactly 7 days apart.
        $lines = $s->schedule('30000000', 3, 'weekly', $this->jalaliStart(1405, 7, 1), 'Asia/Tehran');
        $this->assertSame(7, (int) $lines[0]['due']->diffInDays($lines[1]['due']));
    }

    public function test_payment_spanning_lines_completes_and_a_reversal_reopens_exactly(): void
    {
        $user = $this->merchant('professional');
        $customer = $this->inTenant($user, fn () => Customer::create(['name' => 'مشتری قسطی']));
        $this->actingAs($user)->api('POST', "/api/customers/{$customer->public_id}/agreements", [
            'principal_toman' => '3000000', 'count' => 3, 'frequency' => 'monthly', 'first_due' => Jalali::date(now()->addMonth(), 'Asia/Tehran'),
        ])->assertCreated();
        $a = $this->inTenant($user, fn () => InstallmentAgreement::firstOrFail());
        $today = Jalali::date(now(), 'Asia/Tehran');

        // 1,500,000 covers line 1 and half of line 2 (oldest first).
        $this->api('POST', "/api/agreements/{$a->public_id}/payments", ['amount_toman' => '1500000', 'method' => 'cash', 'paid_on' => $today, 'idempotency_key' => 'p1-000001'])->assertOk();
        $paid = $this->inTenant($user, fn () => InstallmentLine::orderBy('number')->pluck('paid_irr')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(['10000000', '5000000', '0'], $paid);

        $this->api('POST', "/api/agreements/{$a->public_id}/payments", ['amount_toman' => '1500000', 'method' => 'pos', 'paid_on' => $today, 'idempotency_key' => 'p2-000001'])->assertOk();
        $this->assertSame('completed', $this->inTenant($user, fn () => $a->fresh()->status));

        // Reversing the second payment reopens the agreement and restores exactly what it had paid.
        $p2 = $this->inTenant($user, fn () => InstallmentPayment::orderByDesc('id')->firstOrFail());
        $this->api('POST', "/api/payments/{$p2->public_id}/reverse", ['reason' => 'چک برگشت خورد'])->assertOk();
        $this->assertSame('active', $this->inTenant($user, fn () => $a->fresh()->status));
        $paid = $this->inTenant($user, fn () => InstallmentLine::orderBy('number')->pluck('paid_irr')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(['10000000', '5000000', '0'], $paid);
        // Reversing twice changes nothing.
        $this->api('POST', "/api/payments/{$p2->public_id}/reverse", ['reason' => 'دوباره'])->assertOk();
        $this->assertSame(['10000000', '5000000', '0'], $this->inTenant($user, fn () => InstallmentLine::orderBy('number')->pluck('paid_irr')->map(fn ($v) => (string) $v)->all()));
    }

    public function test_reminders_respect_quiet_hours_and_skip_paid_lines(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'Asia/Tehran'));
        $user = $this->merchant('professional');
        SmsCreditLot::withoutGlobalScope('tenant')->forceCreate(['tenant_id' => $this->tenantOf($user)->id, 'amount_irr' => '1000000', 'remaining_irr' => '1000000', 'source' => 'PROVIDER_ADJUST', 'carries_over' => true, 'expires_at' => null, 'plan_at_purchase' => 'professional']);
        $this->actingAs($user);
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $d = $this->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate]);
        $s = $this->api('PUT', '/api/invoices/drafts/'.$d->json('draft_id'), ['version' => $d->json('version'), 'rows' => [['row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'النگو', 'net_weight_g' => '1', 'purity_ppt' => '750']], 'buyer' => ['name' => 'رضا', 'mobile' => '09351234511']]);
        $this->api('POST', '/api/invoices/drafts/'.$d->json('draft_id').'/issue', ['mode' => 'ISSUE_ONLY', 'version' => $s->json('version'), 'idempotency_key' => 'k-rem-quiet', 'buyer' => ['name' => 'رضا', 'mobile' => '09351234511'], 'save_customer' => true])->assertCreated();
        $cid = Customer::withoutGlobalScope('tenant')->value('public_id');
        $this->api('POST', "/api/customers/{$cid}/agreements", ['invoice_id' => $d->json('draft_id'), 'count' => 2, 'frequency' => 'weekly', 'first_due' => Jalali::date(now()->addDay(), 'Asia/Tehran'), 'reminders' => true])->assertCreated();
        $service = app(InstallmentService::class);
        $sms = app(SmsService::class);

        foreach (['2026-10-06 22:30', '2026-10-07 08:30'] as $quiet) {
            $this->travelTo(CarbonImmutable::parse($quiet, 'Asia/Tehran'));
            $service->sendReminders($sms);
            $this->assertSame(0, SmsMessage::query()->where('purpose', 'REMINDER')->count(), "quiet hours: {$quiet}");
        }

        // Line 1 paid in full before the reminder window opens: no reminder for it.
        $line1 = $this->inTenant($user, fn () => InstallmentLine::orderBy('number')->firstOrFail());
        $this->inTenant($user, fn () => $line1->update(['paid_irr' => $line1->amount_irr]));
        $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', 'Asia/Tehran'));
        $service->sendReminders($sms);
        $this->assertSame(0, SmsMessage::query()->where('purpose', 'REMINDER')->where('schedule_line_id', $line1->id)->count());
    }
}
