<?php

namespace Tests\Feature;

use App\Domain\Customers\InstallmentService;
use App\Domain\Market\QuoteService;
use App\Domain\Sms\SmsCredit;
use App\Domain\Sms\SmsGateway;
use App\Domain\Sms\SmsService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ShopProfile;
use App\Models\SmsCreditLot;
use App\Models\SmsMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Every route by which a merchant could make the platform send unwanted SMS — from the Free
 * plan or a paid one — must be bounded server-side.
 */
class SmsAbuseTest extends TestCase
{
    private function issueTo(User $user, string $mobile, string $mode = 'ISSUE_AND_SMS'): array
    {
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $d = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $s = $this->api('PUT', '/api/invoices/drafts/'.$d->json('draft_id'), ['version' => $d->json('version'), 'rows' => [[
            'row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'النگو', 'net_weight_g' => '1', 'purity_ppt' => '750', 'wage_percent' => '0', 'profit_percent' => '0',
        ]], 'buyer' => ['mobile' => $mobile]])->assertOk();
        $r = $this->api('POST', '/api/invoices/drafts/'.$d->json('draft_id').'/issue', [
            'mode' => $mode, 'version' => $s->json('version'), 'idempotency_key' => 'k-'.bin2hex(random_bytes(8)), 'buyer' => ['mobile' => $mobile],
        ])->assertCreated();

        return ['id' => $d->json('draft_id'), 'sms' => $r->json('sms')];
    }

    private function sent(): int
    {
        return count(app(SmsGateway::class)->sent);
    }

    private function credit(User $user, int $toman): void
    {
        $tenant = $this->tenantOf($user);
        SmsCreditLot::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $tenant->id, 'amount_irr' => (string) ($toman * 10), 'remaining_irr' => (string) ($toman * 10), 'source' => 'PROVIDER_ADJUST', 'carries_over' => true, 'expires_at' => null, 'plan_at_purchase' => 'basic',
        ]);
    }

    public function test_free_plan_gets_two_free_sms_per_day_then_waits_for_credit(): void
    {
        $user = $this->merchant();
        $this->assertSame('QUEUED', $this->issueTo($user, '09350000001')['sms']['status'] === 'QUEUED' ? 'QUEUED' : 'SENT');
        $this->issueTo($user, '09350000002');
        $third = $this->issueTo($user, '09350000003');
        // 2/day free cap: third is not sent and nothing is charged.
        $this->assertSame('AWAITING_CREDIT', $third['sms']['status']);
        $this->assertSame(2, $this->sent());
    }

    public function test_free_allowance_is_five_per_year(): void
    {
        $user = $this->merchant();
        for ($i = 1; $i <= 7; $i++) {
            $this->issueTo($user, '0935000010'.$i);
            $this->travel(1)->days();
        }
        $this->assertSame(5, SmsMessage::query()->where('charge_source', 'FREE_YEARLY')->count());
        $this->assertSame(5, $this->sent());
    }

    public function test_initial_send_is_idempotent_and_resend_only_after_failure(): void
    {
        $user = $this->merchant();
        $inv = $this->issueTo($user, '09350000001');
        $this->api('POST', "/api/invoices/{$inv['id']}/sms")->assertStatus(409)->assertJsonPath('code', 'SMS_ALREADY_SENT');
        $this->assertSame(1, $this->sent());
    }

    public function test_failed_send_resend_has_cooldown_and_per_invoice_cap(): void
    {
        $user = $this->merchant('basic');
        $this->credit($user, 100000);
        app(SmsGateway::class)->nextStatus = 'FAILED';
        $inv = $this->issueTo($user, '09350000001');
        $this->api('POST', "/api/invoices/{$inv['id']}/sms")->assertStatus(429)->assertJsonPath('code', 'SMS_RESEND_COOLDOWN');
        $this->travel(11)->minutes();
        $this->api('POST', "/api/invoices/{$inv['id']}/sms")->assertOk();
        $this->travel(11)->minutes();
        $this->api('POST', "/api/invoices/{$inv['id']}/sms")->assertOk();
        $this->travel(11)->minutes();
        $this->api('POST', "/api/invoices/{$inv['id']}/sms")->assertStatus(429)->assertJsonPath('code', 'SMS_INVOICE_LIMIT');
        $this->assertSame(3, $this->sent());
        // Failed sends are not charged: credit fully released.
        $this->assertSame('1000000', app(SmsCredit::class)->balance($this->tenantOf($user)->id));
    }

    public function test_per_recipient_daily_cap_within_a_paid_tenant(): void
    {
        $user = $this->merchant('professional');
        $this->credit($user, 100000);
        for ($i = 0; $i < 3; $i++) {
            $this->issueTo($user, '09350000009');
        }
        // 4th invoice to the same number today: issued, but SMS refused (issuance never rolls back).
        $r = $this->issueTo($user, '09350000009');
        $this->assertSame('NOT_SENT', $r['sms']['status']);
        $this->assertSame(3, $this->sent());
        $this->assertSame(4, Invoice::withoutGlobalScope('tenant')->where('status', 'issued')->count());
    }

    public function test_many_free_accounts_cannot_flood_one_victim(): void
    {
        // Attacker registers several free shops and targets the same number.
        for ($i = 0; $i < 4; $i++) {
            $this->issueTo($this->merchant(), '09359999999');
        }
        $this->assertSame(config('talata.sms.per_recipient_global_free_daily'), $this->sent());
    }

    public function test_paid_tenant_without_credit_sends_nothing_and_is_not_charged(): void
    {
        $user = $this->merchant('basic');
        $r = $this->issueTo($user, '09350000001');
        $this->assertSame('AWAITING_CREDIT', $r['sms']['status']);
        $this->assertSame(0, $this->sent());
    }

    public function test_paid_credit_is_captured_per_segment(): void
    {
        $user = $this->merchant('basic');
        $this->credit($user, 10000);
        $this->issueTo($user, '09350000001');
        $msg = SmsMessage::query()->first();
        $this->assertSame('CREDIT', $msg->charge_source);
        $expected = 100000 - $msg->segments * 5000; // IRR: 10,000 toman − segments × 500 toman
        $this->assertSame((string) $expected, app(SmsCredit::class)->balance($this->tenantOf($user)->id));
    }

    public function test_tenant_hourly_cap_counts_failed_attempts(): void
    {
        config(['talata.sms.tenant_hourly_cap' => 2]);
        $user = $this->merchant('professional');
        $this->credit($user, 100000);
        app(SmsGateway::class)->nextStatus = 'FAILED';
        $this->issueTo($user, '09350000001');
        $this->issueTo($user, '09350000002');
        $this->assertSame('NOT_SENT', $this->issueTo($user, '09350000003')['sms']['status']);
    }

    public function test_drafts_void_and_other_tenants_invoices_cannot_be_sent(): void
    {
        $owner = $this->merchant();
        $inv = $this->issueTo($owner, '09350000001', 'ISSUE_ONLY');
        $attacker = $this->merchant();
        $this->actingAs($attacker)->api('POST', "/api/invoices/{$inv['id']}/sms")->assertNotFound();
        $this->actingAs($owner)->api('POST', "/api/invoices/{$inv['id']}/void", ['reason' => 'DUPLICATE'])->assertOk();
        $this->api('POST', "/api/invoices/{$inv['id']}/sms")->assertStatus(409);
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $draft = $this->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->json('draft_id');
        $this->api('POST', "/api/invoices/{$draft}/sms")->assertStatus(302);
        $this->assertSame(0, $this->sent());
    }

    public function test_sms_content_cannot_carry_links_phones_or_impersonation(): void
    {
        $user = $this->merchant('basic');
        $this->actingAs($user);
        $this->api('PUT', '/api/settings/sms-template', ['template' => 'تخفیف ویژه {invoice_number} در www.spam.ir {invoice_link}'])->assertStatus(422)->assertJsonPath('code', 'TEMPLATE_FORBIDDEN_CONTENT');
        $this->api('PUT', '/api/settings/sms-template', ['template' => 'تماس ۰۹۱۲ ۳۴۵ ۶۷ ۸۹ {invoice_number} {invoice_link}'])->assertStatus(422);
        $this->api('PUT', '/api/settings/sms-template', ['template' => 'بدون لینک فاکتور {invoice_number}'])->assertStatus(422)->assertJsonPath('code', 'TEMPLATE_LINK_REQUIRED');
        $this->api('PUT', '/api/settings/sms-template', ['template' => '{shop_name}: {customer_secret} {invoice_number} {invoice_link}'])->assertStatus(422)->assertJsonPath('code', 'TEMPLATE_PLACEHOLDER');
        $this->api('PUT', '/api/settings/sms-template', ['template' => '{shop_name}: حساب بانکی شما مسدود شد {invoice_number} {invoice_link}'])->assertStatus(422)->assertJsonPath('code', 'TEMPLATE_IMPERSONATION');
        $this->api('PUT', '/api/settings/sms-template', ['template' => '{shop_name}: تخفیف ویژه {invoice_link}'])->assertStatus(422)->assertJsonPath('code', 'TEMPLATE_NUMBER_REQUIRED');
        $this->api('PUT', '/api/settings/sms-template', ['template' => '{shop_name}: فاکتور {invoice_number} — {invoice_link}'])->assertOk();
        $base = ['business_mobile' => '09121112233', 'address' => 'تهران'];
        $this->api('POST', '/api/settings/business', ['name' => 'طلا t.me/spam'] + $base)->assertStatus(422);
        $this->api('POST', '/api/settings/business', ['name' => 'بانک ملت'] + $base)->assertStatus(422);
        $this->api('POST', '/api/settings/business', ['name' => 'طلافروشی نگین'] + $base)->assertOk();
        // Free plan cannot edit the template at all.
        $free = $this->merchant();
        $this->actingAs($free)->api('PUT', '/api/settings/sms-template', ['template' => '{shop_name} {invoice_link}'])->assertStatus(403);
    }

    public function test_template_shop_name_and_numbering_cannot_be_combined_into_phishing(): void
    {
        $user = $this->merchant('basic');
        $this->credit($user, 100000);
        $this->actingAs($user);
        $base = ['business_mobile' => '09121112233', 'address' => 'تهران'];
        // Each piece is checked, Latin brand words inside other words too…
        $this->api('POST', '/api/settings/business', ['name' => 'mellatbank'] + $base)->assertStatus(422);
        $this->api('POST', '/api/settings/business', ['name' => 'Zarlio Gold'] + $base)->assertStatus(422);
        // …placeholders cannot be glued into a web address…
        $this->api('PUT', '/api/settings/sms-template', ['template' => '{shop_name}.ir/pay فاکتور {invoice_number} {invoice_link}'])->assertStatus(422)->assertJsonPath('code', 'TEMPLATE_PLACEHOLDER_GLUED');
        // …and a numbering scheme that prints phone-number-shaped invoice numbers is refused.
        $this->api('PUT', '/api/settings/numbering', ['settings' => ['prefix' => '0912', 'year' => 'none', 'month' => false, 'separator' => '-', 'digits' => 7, 'reset' => 'never'], 'next' => '3456789', 'version' => 0])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['next']]);

        // A name saved before these checks still never goes out: the final text is checked before sending.
        ShopProfile::withoutGlobalScope('tenant')->where('tenant_id', $this->tenantOf($user)->id)->update(['name' => 'mellat']);
        $this->api('PUT', '/api/settings/sms-template', ['template' => '{shop_name} .ir/pay فاکتور {invoice_number} {invoice_link}'])->assertOk();
        $before = $this->sent();
        $issued = $this->issueTo($user, '09351234567');
        $this->assertSame('NOT_SENT', $issued['sms']['status']);
        $this->assertSame($before, $this->sent());
        $this->assertSame('issued', Invoice::withoutGlobalScope('tenant')->where('public_id', $issued['id'])->value('status'), 'issuing never depends on the SMS');
    }

    public function test_installment_reminders_are_at_most_two_per_installment(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'Asia/Tehran'));
        $user = $this->merchant('professional');
        $this->credit($user, 100000);
        $this->actingAs($user);
        // Reminders need an invoice issued to the customer's own mobile.
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $d = $this->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate]);
        $s = $this->api('PUT', '/api/invoices/drafts/'.$d->json('draft_id'), ['version' => $d->json('version'), 'rows' => [['row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'النگو', 'net_weight_g' => '1', 'purity_ppt' => '750']], 'buyer' => ['name' => 'رضا', 'mobile' => '09351234500']]);
        $this->api('POST', '/api/invoices/drafts/'.$d->json('draft_id').'/issue', ['mode' => 'ISSUE_ONLY', 'version' => $s->json('version'), 'idempotency_key' => 'k-rem-0001', 'buyer' => ['name' => 'رضا', 'mobile' => '09351234500'], 'save_customer' => true])->assertCreated();
        $cid = Customer::withoutGlobalScope('tenant')->value('public_id');
        $inv = $d->json('draft_id');
        // Manual amount (no invoice) or a past first due date cannot carry SMS reminders.
        $this->api('POST', "/api/customers/{$cid}/agreements", ['principal_toman' => '3000000', 'count' => 3, 'frequency' => 'weekly', 'first_due' => jymd(now()->addDays(2)), 'reminders' => true])->assertStatus(422)->assertJsonStructure(['errors' => ['reminders']]);
        $this->api('POST', "/api/customers/{$cid}/agreements", ['invoice_id' => $inv, 'count' => 3, 'frequency' => 'weekly', 'first_due' => jymd(now()->subDays(20)), 'reminders' => true])->assertStatus(422)->assertJsonStructure(['errors' => ['first_due']]);
        $this->api('POST', "/api/customers/{$cid}/agreements", ['invoice_id' => $inv, 'count' => 3, 'frequency' => 'weekly', 'first_due' => jymd(now()->addDays(2)), 'reminders' => true])->assertCreated();
        // The customer's mobile is now locked, so reminders cannot be redirected to another number.
        $this->api('PUT', "/api/customers/{$cid}", ['name' => 'رضا', 'mobile' => '09359999999'])->assertStatus(422)->assertJsonPath('code', 'MOBILE_LOCKED');
        $service = app(InstallmentService::class);
        $sms = app(SmsService::class);
        for ($day = 0; $day < 45; $day++) {
            $service->sendReminders($sms);
            $service->sendReminders($sms); // scheduler running twice must not duplicate
            $this->travel(1)->days();
        }
        $perLine = SmsMessage::query()->where('purpose', 'REMINDER')->get()->groupBy('schedule_line_id')->map->count();
        $this->assertCount(3, $perLine);
        $this->assertTrue($perLine->every(fn ($n) => $n <= 2), json_encode($perLine));
        // Opted-out customer gets nothing more.
        Customer::withoutGlobalScope('tenant')->update(['sms_opt_out' => true]);
        $before = SmsMessage::query()->count();
        $this->travel(-30)->days();
        $service->sendReminders($sms);
        $this->assertSame($before, SmsMessage::query()->count());
    }

    public function test_waiting_invoice_sms_go_out_when_credit_arrives_but_never_for_a_voided_invoice(): void
    {
        $user = $this->merchant('basic');
        $kept = $this->issueTo($user, '09350000011');
        $voided = $this->issueTo($user, '09350000012');
        $this->assertSame('AWAITING_CREDIT', $kept['sms']['status']);
        $this->api('POST', "/api/invoices/{$voided['id']}/void", ['reason' => 'WRONG_WEIGHT'])->assertOk();
        $this->assertSame(0, $this->sent());

        $this->credit($user, 10000);
        $queued = app(SmsService::class)->releaseAwaitingCredit($this->tenantOf($user));

        $this->assertSame(1, $queued);
        $this->assertSame(1, $this->sent());
        $this->assertSame('09350000011', app(SmsGateway::class)->sent[0]['to']);
        $this->assertSame('CREDIT', SmsMessage::query()->where('recipient', '09350000011')->latest('id')->value('charge_source'));
        // The voided invoice's message was cancelled (void cancels waiting sends), never announced.
        $this->assertNotSame('SENT', SmsMessage::query()->where('recipient', '09350000012')->latest('id')->value('status'));
    }

    public function test_no_resend_while_the_previous_outcome_is_unknown(): void
    {
        $user = $this->merchant('professional');
        $this->credit($user, 100000);
        app(SmsGateway::class)->nextStatus = 'UNKNOWN';
        $r = $this->issueTo($user, '09350000021');
        $this->travel(15)->minutes();
        $this->api('POST', "/api/invoices/{$r['id']}/sms")->assertStatus(409)->assertJsonPath('code', 'SMS_STATUS_UNKNOWN');
        $this->assertSame(1, SmsMessage::query()->where('recipient', '09350000021')->count());
    }

    public function test_review_preview_counts_the_same_segments_as_the_real_message(): void
    {
        $user = $this->merchant('professional');
        $this->credit($user, 100000);
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $d = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $s = $this->api('PUT', '/api/invoices/drafts/'.$d->json('draft_id'), ['version' => $d->json('version'), 'rows' => [[
            'row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'النگو', 'net_weight_g' => '1', 'purity_ppt' => '750', 'wage_percent' => '0', 'profit_percent' => '0',
        ]], 'buyer' => ['mobile' => '09350000031']])->assertOk();
        $preview = $this->inTenant($user, fn ($t) => app(SmsService::class)->previewFor(\App\Models\Invoice::where('public_id', $d->json('draft_id'))->first(), $t));
        $this->api('POST', '/api/invoices/drafts/'.$d->json('draft_id').'/issue', [
            'mode' => 'ISSUE_AND_SMS', 'version' => $s->json('version'), 'idempotency_key' => 'k-'.bin2hex(random_bytes(8)), 'buyer' => ['mobile' => '09350000031'],
        ])->assertCreated();
        $real = SmsMessage::query()->where('recipient', '09350000031')->firstOrFail();
        $this->assertSame($real->segments, $preview['segments']);
        $this->assertSame(mb_strlen($real->body), mb_strlen($preview['body']));
    }

    public function test_reconcile_picks_up_stuck_messages_without_blind_resends(): void
    {
        $user = $this->merchant('professional');
        $this->credit($user, 100000);
        app(SmsGateway::class)->nextStatus = 'UNKNOWN';
        $this->issueTo($user, '09350000041');
        $stuck = SmsMessage::query()->where('recipient', '09350000041')->firstOrFail();
        SmsMessage::query()->whereKey($stuck->id)->update(['status' => 'SENDING', 'updated_at' => now()->subMinutes(11)]);
        SmsMessage::query()->create(['tenant_id' => null, 'purpose' => 'OTP', 'recipient' => '09350000042', 'body' => 'x', 'payload' => ['code' => '123456'], 'segments' => 1, 'cost_irr' => '0', 'charge_source' => 'OPERATIONAL', 'status' => 'QUEUED', 'idempotency_key' => 'otp:stale']);
        SmsMessage::query()->where('idempotency_key', 'otp:stale')->update(['updated_at' => now()->subMinutes(11)]);
        $before = $this->sent();

        $this->artisan('talata:sms-reconcile')->assertSuccessful();

        $this->assertSame('UNKNOWN', $stuck->fresh()->status);           // ambiguous: looked up later, never resent blindly
        $this->assertSame('CANCELLED', SmsMessage::query()->where('idempotency_key', 'otp:stale')->value('status')); // expired code not sent
        $this->assertSame($before, $this->sent());
    }
}
