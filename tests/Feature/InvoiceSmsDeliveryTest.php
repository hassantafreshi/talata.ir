<?php

namespace Tests\Feature;

use App\Domain\Identity\LoginService;
use App\Domain\Market\QuoteService;
use App\Domain\Sms\SmsGateway;
use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\SettingsBackup;
use App\Models\SmsCreditLot;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Invoice SMS delivery (docs/INVOICE_DELIVERY_AND_VERIFICATION.md, owner decision 2026-10-07): automatic SMS
 * after issuance with a per-shop switch, «ارسال دوباره به مشتری» and «ارسال به شماره دیگر».
 */
class InvoiceSmsDeliveryTest extends TestCase
{
    private function credit(User $user, int $toman): void
    {
        SmsCreditLot::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $this->tenantOf($user)->id, 'amount_irr' => (string) ($toman * 10), 'remaining_irr' => (string) ($toman * 10),
            'source' => 'PROVIDER_ADJUST', 'carries_over' => true, 'expires_at' => null, 'plan_at_purchase' => 'basic',
        ]);
    }

    /** @return array{id: string, res: TestResponse} */
    private function issue(User $user, ?string $mobile, string $mode = 'AUTO'): array
    {
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $d = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $s = $this->api('PUT', '/api/invoices/drafts/'.$d->json('draft_id'), ['version' => $d->json('version'), 'rows' => [[
            'row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'النگو', 'net_weight_g' => '1', 'purity_ppt' => '750',
        ]], 'buyer' => ['mobile' => $mobile]])->assertOk();
        $res = $this->api('POST', '/api/invoices/drafts/'.$d->json('draft_id').'/issue', [
            'mode' => $mode, 'version' => $s->json('version'), 'idempotency_key' => 'k-'.bin2hex(random_bytes(8)), 'buyer' => ['mobile' => $mobile],
        ])->assertCreated();

        return ['id' => $d->json('draft_id'), 'res' => $res];
    }

    private function sent(): array
    {
        return array_column(app(SmsGateway::class)->sent, 'to');
    }

    public function test_the_invoice_sms_goes_out_automatically_after_issuance_unless_the_shop_turns_it_off(): void
    {
        $user = $this->merchant('basic');
        $this->credit($user, 100000);

        // On by default: the main button sends when a mobile is entered…
        $a = $this->issue($user, '09350000001');
        $this->assertSame('ISSUE_AND_SMS', Invoice::where('public_id', $a['id'])->value('issue_mode'));
        $this->assertSame(['09350000001'], $this->sent());
        // …and simply issues when there is none.
        $b = $this->issue($user, null);
        $this->assertNull($b['res']->json('sms'));
        $this->assertCount(1, $this->sent());
        // «صدور بدون پیامک» for this one invoice.
        $this->issue($user, '09350000002', 'ISSUE_ONLY');
        $this->assertCount(1, $this->sent());

        // Switched off in settings: the same button only issues; explicit «صدور و ارسال پیامکی» still sends.
        $this->api('PUT', '/api/settings/sms-auto', ['enabled' => false])->assertOk();
        $this->assertSame(1, AuditEvent::query()->where('event', 'sms.auto_send_changed')->count());
        $c = $this->issue($user, '09350000003');
        $this->assertSame('ISSUE_ONLY', Invoice::where('public_id', $c['id'])->value('issue_mode'));
        $this->assertCount(1, $this->sent());
        [$id] = [$this->issue($user, '09350000004', 'ISSUE_AND_SMS')['id']];
        $this->assertSame(['09350000001', '09350000004'], $this->sent());
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $d = $this->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $this->api('PUT', '/api/invoices/drafts/'.$d->json('draft_id'), ['version' => $d->json('version'), 'rows' => [['row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'x', 'net_weight_g' => '1', 'purity_ppt' => '750']], 'buyer' => []])->assertOk();
        $this->get('/invoices/'.$d->json('draft_id').'/review')->assertOk()->assertSee('صدور و ارسال پیامکی')->assertSee('ارسال خودکار پیامک خاموش است');
        $this->get('/settings')->assertOk()->assertSee('ارسال خودکار: خاموش');

        // The switch is part of the settings backups (Basic/Pro) and can be restored.
        $backup = SettingsBackup::withoutGlobalScope('tenant')->where('tenant_id', $this->tenantOf($user)->id)->latest('id')->firstOrFail();
        $this->assertFalse($backup->payload['sms_auto']);
    }

    public function test_automatic_sending_never_blocks_issuance(): void
    {
        // Free plan, link quota used up: the invoice is issued and the merchant is told why no SMS went out.
        $user = $this->merchant('free');
        $tenant = $this->tenantOf($user);
        $first = $this->issue($user, null)['id'];
        $invoiceId = Invoice::where('public_id', $first)->value('id');
        for ($i = 0; $i < 10; $i++) {
            DB::table('invoice_shares')->insert(['tenant_id' => $tenant->id, 'invoice_id' => $invoiceId, 'token_hash' => hash('sha256', 'q'.$i), 'token' => 'enc'.$i, 'created_at' => now(), 'updated_at' => now(), 'revoked_at' => now()]);
        }
        $r = $this->issue($user, '09350000011')['res'];
        $this->assertSame('NOT_SENT', $r->json('sms.status'));
        $this->assertStringContainsString('لینک‌های فاکتور این ماه تمام شده', $r->json('sms.message_fa'));
        $this->assertSame([], $this->sent());
    }

    public function test_only_settings_managers_can_switch_automatic_sending(): void
    {
        $owner = $this->merchant('basic');
        $this->actingAs($owner)->api('POST', '/api/users/invite', ['mobile' => '09371230088', 'permissions' => ['invoice.issue', 'invoices.view']])->assertOk();
        $seller = app(LoginService::class)->completeLogin('09371230088')['user'];
        $invite = Membership::query()->where('invited_mobile', '09371230088')->where('status', 'invited')->firstOrFail();
        $this->actingAs($seller)->api('POST', "/api/invites/{$invite->id}/accept")->assertOk();
        $this->api('PUT', '/api/settings/sms-auto', ['enabled' => false])->assertForbidden();
    }

    public function test_send_to_other_numbers_parses_any_form_and_keeps_every_cap(): void
    {
        $user = $this->merchant('basic');
        $this->credit($user, 100000);
        $id = $this->issue($user, '09350000001')['id'];
        $this->assertSame(['09350000001'], $this->sent());

        // Two numbers typed together with no separator, Persian digits and +98: both found, both sent.
        $res = $this->api('POST', "/api/invoices/{$id}/sms/copies", ['mobiles' => '۰۹۱۲۰۰۰۰۰۰۱+989120000002', 'idempotency_key' => 'copy-0001'])->assertOk();
        $this->assertSame(2, $res->json('queued'));
        $this->assertSame(['09350000001', '09120000001', '09120000002'], $this->sent());
        // A double tap replays, never sends twice.
        $this->api('POST', "/api/invoices/{$id}/sms/copies", ['mobiles' => '۰۹۱۲۰۰۰۰۰۰۱+989120000002', 'idempotency_key' => 'copy-0001'])->assertOk();
        $this->assertCount(3, $this->sent());

        // The customer's own number, and a number that already got it, are skipped with a reason.
        $res = $this->api('POST', "/api/invoices/{$id}/sms/copies", ['mobiles' => '09350000001, 09120000001', 'idempotency_key' => 'copy-0002'])->assertOk();
        $this->assertSame(['SMS_IS_CUSTOMER', 'SMS_ALREADY_SENT'], array_column($res->json('results'), 'code'));
        $this->assertSame(0, $res->json('queued'));

        // Leftover digits are an error (a mistyped number is never silently dropped); too many numbers too.
        $this->api('POST', "/api/invoices/{$id}/sms/copies", ['mobiles' => '091200000039', 'idempotency_key' => 'copy-0003'])->assertStatus(422)->assertJsonPath('code', 'MOBILES_INVALID');
        $this->api('POST', "/api/invoices/{$id}/sms/copies", ['mobiles' => 'سلام', 'idempotency_key' => 'copy-0004'])->assertStatus(422)->assertJsonPath('code', 'MOBILES_INVALID');
        $this->api('POST', "/api/invoices/{$id}/sms/copies", ['mobiles' => '09120000003 09120000004 09120000005 09120000006', 'idempotency_key' => 'copy-0005'])->assertStatus(422)->assertJsonPath('code', 'SMS_TOO_MANY_RECIPIENTS');

        // At most three other numbers per invoice.
        $res = $this->api('POST', "/api/invoices/{$id}/sms/copies", ['mobiles' => '09120000003 09120000004', 'idempotency_key' => 'copy-0006'])->assertOk();
        $this->assertSame(['QUEUED', 'NOT_SENT'], array_map(fn ($r) => $r['status'] === 'QUEUED' || in_array($r['status'], ['SENT', 'DELIVERED'], true) ? 'QUEUED' : $r['status'], $res->json('results')));
        $this->assertSame('SMS_COPY_LIMIT', $res->json('results.1.code'));
        $this->assertCount(4, $this->sent());

        // Copies never change what the invoice page shows for the customer's own SMS (newer copies are «sent»).
        SmsMessage::query()->where('purpose', 'INVOICE')->update(['status' => 'FAILED']);
        $this->api('GET', "/api/invoices/{$id}/status")->assertOk()->assertJsonPath('sms.status', 'FAILED');
        $this->assertSame(3, SmsMessage::query()->where('purpose', 'INVOICE_COPY')->count());
    }

    public function test_copies_never_wait_for_credit_and_never_reach_another_shops_invoice(): void
    {
        $user = $this->merchant('professional');
        $id = $this->issue($user, '09350000001', 'ISSUE_ONLY')['id'];
        $res = $this->api('POST', "/api/invoices/{$id}/sms/copies", ['mobiles' => '09120000001', 'idempotency_key' => 'copy-0101'])->assertOk();
        $this->assertSame('SMS_NO_CREDIT', $res->json('results.0.code'));
        $this->assertSame(0, SmsMessage::query()->where('status', 'AWAITING_CREDIT')->count());
        $this->assertSame([], $this->sent());

        // A voided invoice cannot be sent to anyone.
        $this->api('POST', "/api/invoices/{$id}/void", ['reason' => 'WRONG_WEIGHT'])->assertOk();
        $this->api('POST', "/api/invoices/{$id}/sms/copies", ['mobiles' => '09120000001', 'idempotency_key' => 'copy-0103'])->assertStatus(422)->assertJsonPath('code', 'SMS_INVOICE_NOT_ISSUED');

        $this->actingAs($this->merchant('professional'))->api('POST', "/api/invoices/{$id}/sms/copies", ['mobiles' => '09120000001', 'idempotency_key' => 'copy-0102'])->assertNotFound();
    }
}
