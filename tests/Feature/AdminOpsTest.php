<?php

namespace Tests\Feature;

use App\Domain\Billing\Gateways\MockGateway;
use App\Domain\Market\QuoteService;
use App\Domain\Plans\CommercialConfig;
use App\Domain\Plans\Entitlements;
use App\Domain\Sms\SmsCredit;
use App\Domain\Tax\TaxRules;
use App\Models\AdminAction;
use App\Models\AuditEvent;
use App\Models\BillingOrder;
use App\Models\Invoice;
use App\Models\Passkey;
use App\Models\ShopProfile;
use App\Models\SmsMessage;
use App\Models\StaffUser;
use App\Models\Subscription;
use App\Models\TaxRule;
use App\Support\Jalali;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Admin console v1 operations (docs/handoff/04_SCREENS_ADMIN.md A-01…A-12). */
class AdminOpsTest extends TestCase
{
    private int $staffSeq = 0;

    private function staff(string $role = 'admin', int $authAgo = 0): StaffUser
    {
        $staff = StaffUser::query()->create(['mobile' => '0912000'.str_pad((string) (2000 + $this->staffSeq++), 4, '0', STR_PAD_LEFT), 'name' => 'کارمند '.$role, 'role' => $role, 'active' => true]);
        $this->asStaff($staff, $authAgo);

        return $staff;
    }

    private function key(): string
    {
        return 'adm-'.bin2hex(random_bytes(8));
    }

    private function basicPriceToman(string $period = 'monthly'): string
    {
        return (string) app(CommercialConfig::class)->plan('basic')['price_toman'][$period];
    }

    public function test_manual_plan_activation_uses_normal_fulfilment_once_and_is_audited(): void
    {
        $merchant = $this->merchant();
        $tenant = $this->tenantOf($merchant);
        $finance = $this->staff('finance');
        $received = (string) ((int) $this->basicPriceToman() * 11 / 10);
        $payload = ['plan' => 'basic', 'period' => 'monthly', 'received_toman' => $received, 'reference' => 'TR-12345', 'reason' => 'واریز کارت به کارت مالک', 'idempotency_key' => $this->key()];

        $res = $this->postJson("/admin/api/tenants/{$tenant->id}/activation", $payload)->assertCreated();
        $this->assertSame('FULFILLED', $res->json('status'));
        $sub = Subscription::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('status', 'active')->firstOrFail();
        $this->assertSame(['basic', 'PROVIDER'], [$sub->plan_code, $sub->activated_by]);
        $order = BillingOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(['MANUAL', 'FULFILLED', $received.'0', 'TR-12345', $finance->id], [$order->channel, $order->status, (string) $order->amount_irr, $order->manual_reference, $order->staff_id]);
        // Base + VAT add up to exactly what was received.
        $this->assertSame((string) $order->amount_irr, (string) ((int) $order->subtotal_irr + (int) $order->vat_irr));
        $this->assertSame(1, AuditEvent::query()->where('event', 'billing.manual_activation')->where('staff_id', $finance->id)->count());

        // Same key again (double click / retry): replayed, never applied twice.
        $this->postJson("/admin/api/tenants/{$tenant->id}/activation", $payload)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertSame(1, BillingOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, Subscription::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, AdminAction::query()->count());

        // Reason is required, in Persian.
        $bad = $this->postJson("/admin/api/tenants/{$tenant->id}/activation", ['reason' => ''] + $payload)->assertStatus(422);
        $this->assertSame('دلیل را وارد کنید.', $bad->json('errors.reason.0'));
        $this->assertSame('VALIDATION', $bad->json('code'));

        // Support may not; a stale sign-in must re-authenticate first.
        $this->staff('support');
        $this->postJson("/admin/api/tenants/{$tenant->id}/activation", ['idempotency_key' => $this->key()] + $payload)->assertForbidden()->assertJsonPath('code', 'STAFF_FORBIDDEN');
        $this->staff('finance', 3600);
        $this->postJson("/admin/api/tenants/{$tenant->id}/activation", ['idempotency_key' => $this->key()] + $payload)->assertForbidden()->assertJsonPath('code', 'REAUTH_REQUIRED');
    }

    public function test_sms_credit_adjustment_adds_and_deducts_but_never_below_zero(): void
    {
        $merchant = $this->merchant('basic');
        $tenant = $this->tenantOf($merchant);
        $this->staff('finance');
        $url = "/admin/api/tenants/{$tenant->id}/sms-credit";

        $this->postJson($url, ['direction' => 'add', 'amount_toman' => '۵۰٬۰۰۰', 'carries_over' => true, 'reason' => 'جبران قطعی ارسال', 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertSame('500000', app(SmsCredit::class)->balance($tenant->id));
        $this->postJson($url, ['direction' => 'deduct', 'amount_toman' => '60000', 'reason' => 'اصلاح ثبت اشتباه', 'idempotency_key' => $this->key()])
            ->assertStatus(422)->assertJsonPath('code', 'CREDIT_INSUFFICIENT');
        $this->postJson($url, ['direction' => 'deduct', 'amount_toman' => '20000', 'reason' => 'اصلاح ثبت اشتباه', 'idempotency_key' => $this->key()])->assertCreated();
        $this->get("/admin/tenants/{$tenant->id}")->assertOk()->assertSee('جبران قطعی ارسال')->assertSee('۳۰٬۰۰۰ تومان');
        $this->assertSame('300000', app(SmsCredit::class)->balance($tenant->id));
        $this->assertSame(2, DB::table('sms_credit_entries')->where('tenant_id', $tenant->id)->where('type', 'ADJUST')->count());
        $this->assertSame(2, AuditEvent::query()->where('event', 'sms_credit.adjusted')->where('tenant_id', $tenant->id)->count());
    }

    public function test_feature_override_turns_a_capability_on_until_it_ends(): void
    {
        $merchant = $this->merchant();
        $tenant = $this->tenantOf($merchant);
        $ent = app(Entitlements::class);
        $this->assertFalse($ent->can($tenant, 'installments.manage'));
        $this->staff('finance');

        $res = $this->postJson("/admin/api/tenants/{$tenant->id}/overrides", ['key' => 'installments.manage', 'enabled' => '1', 'days' => '30', 'reason' => 'آزمایش اقساط برای مالک', 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertTrue($ent->can($tenant, 'installments.manage'));
        $this->postJson("/admin/api/tenants/{$tenant->id}/overrides/{$res->json('id')}/end", ['reason' => 'پایان دوره آزمایشی'])->assertOk();
        $this->assertFalse($ent->can($tenant, 'installments.manage'));

        // Always-on entries (مظنه, calculator) can never be switched off per shop.
        $this->postJson("/admin/api/tenants/{$tenant->id}/overrides", ['key' => 'mazneh.view', 'enabled' => '0', 'days' => '7', 'reason' => 'نباید ممکن باشد', 'idempotency_key' => $this->key()])
            ->assertStatus(422)->assertJsonPath('code', 'CAPABILITY_INVALID');
    }

    public function test_suspension_blocks_the_merchant_panel_and_is_reversible(): void
    {
        $merchant = $this->merchant();
        $tenant = $this->tenantOf($merchant);
        $this->actingAs($merchant)->get('/invoices/new')->assertOk();

        $this->staff('finance');
        $this->postJson("/admin/api/tenants/{$tenant->id}/suspend", ['reason' => 'گزارش سوءاستفاده'])->assertForbidden(); // owner-only permission
        $admin = $this->staff();
        $this->postJson("/admin/api/tenants/{$tenant->id}/suspend", ['reason' => 'گزارش سوءاستفاده'])->assertOk();
        $this->assertSame('suspended', $tenant->fresh()->status);
        $this->actingAs($merchant)->get('/invoices/new')->assertForbidden()->assertSee('این فروشگاه موقتاً غیرفعال است');
        $this->api('GET', '/api/quotes/latest')->assertForbidden()->assertJsonPath('code', 'TENANT_SUSPENDED');

        $this->asStaff($admin);
        $this->postJson("/admin/api/tenants/{$tenant->id}/suspend", ['reason' => 'دوباره'])->assertStatus(409);
        $this->postJson("/admin/api/tenants/{$tenant->id}/unsuspend", ['reason' => 'رفع مشکل با مالک'])->assertOk();
        $this->actingAs($merchant)->get('/invoices/new')->assertOk();
        $this->assertSame(1, AuditEvent::query()->where('event', 'tenant.suspended')->where('staff_id', $admin->id)->count());
    }

    public function test_payment_review_inquire_mark_failed_and_manual_confirm(): void
    {
        $merchant = $this->merchant();
        $tenant = $this->tenantOf($merchant);
        $order = fn () => $this->actingAs($merchant)->api('POST', '/api/billing/orders', ['product' => 'PLAN', 'plan' => 'basic', 'period' => 'monthly', 'idempotency_key' => 'ord-'.bin2hex(random_bytes(8))])->assertCreated();

        // 1) The payer paid but never came back: inquiry asks the bank and fulfils.
        $order();
        $first = BillingOrder::withoutGlobalScope('tenant')->latest('id')->first();
        MockGateway::decide($first->attempts()->first()->authority, 'success');
        $this->staff('support');
        $this->postJson("/admin/api/payments/{$first->id}/inquire")->assertOk()->assertJsonPath('result', 'OK')->assertJsonPath('status', 'FULFILLED');

        // 2) Finance closes an abandoned order, then confirms it from the bank statement.
        $order();
        $second = BillingOrder::withoutGlobalScope('tenant')->latest('id')->first();
        $finance = $this->staff('finance');
        $this->postJson("/admin/api/payments/{$second->id}/mark-failed", ['reason' => 'مشتری پرداخت نکرد', 'idempotency_key' => $this->key()])->assertOk()->assertJsonPath('status', 'FAILED');
        $this->assertSame('MANUAL_FAILED', $second->fresh()->failure_code);
        $this->postJson("/admin/api/payments/{$second->id}/mark-failed", ['reason' => 'دوباره', 'idempotency_key' => $this->key()])->assertStatus(409);
        $this->postJson("/admin/api/payments/{$second->id}/manual-confirm", ['bank_reference' => '۹۸۷۶۵۴۳۲', 'reason' => 'واریز در صورت‌حساب دیده شد', 'idempotency_key' => $this->key()])
            ->assertOk()->assertJsonPath('status', 'FULFILLED');
        $this->assertSame(['98765432', $finance->id], [$second->fresh()->manual_reference, $second->fresh()->staff_id]);
        $this->postJson("/admin/api/payments/{$second->id}/manual-confirm", ['bank_reference' => '11112222', 'reason' => 'تکرار', 'idempotency_key' => $this->key()])
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_ALREADY_FULFILLED');
        $this->assertSame(2, Subscription::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());

        // Pages and the monthly finance export (formula-like shop names are neutralised).
        ShopProfile::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->update(['name' => '=HYPERLINK("http://x")']);
        $this->get('/admin/payments')->assertOk()->assertSee($second->public_ref);
        $this->get('/admin/payments?f=done')->assertOk();
        $this->get("/admin/payments/{$second->id}")->assertOk()->assertSee('98765432');
        [$jy, $jm] = Jalali::fromGregorian(now('Asia/Tehran')->year, now('Asia/Tehran')->month, now('Asia/Tehran')->day);
        $csv = $this->get('/admin/payments/export.csv?month='.$jy.'-'.$jm)->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString($second->public_ref, $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->staff('support');
        $this->get('/admin/payments/export.csv')->assertForbidden();
    }

    public function test_emergency_rate_replaces_the_feed_for_merchants_until_cancelled(): void
    {
        $quotes = app(QuoteService::class);
        $feed = (string) $quotes->feed('GOLD_18_SELL')->value;
        $value = (int) round((float) $feed / 10 * 1.05); // toman, 5% above the feed
        $this->staff('ops');
        $this->postJson('/admin/api/quotes/emergency', ['value_toman' => (string) ($value * 2), 'validity' => '30', 'reason' => 'سرویس نرخ عدد اشتباه می‌دهد', 'idempotency_key' => $this->key()])
            ->assertStatus(409)->assertJsonPath('code', 'CONFIRM_LARGE_CHANGE');
        $this->postJson('/admin/api/quotes/emergency', ['value_toman' => (string) $value, 'validity' => '30', 'reason' => 'سرویس نرخ عدد اشتباه می‌دهد', 'idempotency_key' => $this->key()])->assertCreated();

        $latest = $quotes->latest('GOLD_18_SELL');
        $this->assertTrue($latest->isEmergency);
        $this->assertSame($value * 10, (int) $latest->value);
        $this->assertSame('FRESH', $quotes->freshness($latest));

        $merchant = $this->merchant();
        $dto = $this->actingAs($merchant)->getJson('/api/quotes/latest')->assertOk();
        $this->assertTrue($dto->json('is_emergency'));
        $this->assertSame(QuoteService::EMERGENCY_SOURCE_FA, $dto->json('source_fa'));
        $draft = $this->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => (string) ($value * 10)])->assertCreated();
        $this->assertSame('EMERGENCY', Invoice::withoutGlobalScope('tenant')->where('public_id', $draft->json('draft_id'))->value('rate_source'));
        $this->get('/invoices/new')->assertOk()->assertSee('نرخ اعلامی زرلیو (دستی)');

        // It ends on its own after its validity…
        $this->travel(31)->minutes();
        $this->assertFalse($quotes->latest('GOLD_18_SELL')->isEmergency);
        $this->travelBack();
        // …or when staff cancel it.
        $this->staff('ops');
        $this->postJson('/admin/api/quotes/emergency/cancel', ['reason' => 'سرویس نرخ درست شد'])->assertOk();
        $this->assertFalse($quotes->latest('GOLD_18_SELL')->isEmergency);
        $this->postJson('/admin/api/quotes/emergency/cancel', ['reason' => 'دوباره لغو'])->assertStatus(409);
        $this->get('/admin/quotes')->assertOk()->assertSee('سابقه نرخ‌های اعلامی');
    }

    public function test_tax_rules_change_only_by_future_dated_versions(): void
    {
        $this->staff('finance');
        $tz = 'Asia/Tehran';
        $tomorrow = now($tz)->addDays(2);
        [$jy, $jm, $jd] = Jalali::fromGregorian($tomorrow->year, $tomorrow->month, $tomorrow->day);
        $date = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);

        $res = $this->postJson('/admin/api/tax-rules', ['category' => 'GOLD_SERVICES', 'rate_percent' => '۹', 'effective_from' => $date, 'reference' => 'بخشنامه نمونه شماره ۱', 'idempotency_key' => $this->key()])->assertCreated();
        $rule = TaxRule::query()->findOrFail($res->json('id'));
        $this->assertSame([2, '9.0000', true], [$rule->version, (string) $rule->rate_percent, $rule->is_sample]);
        $rules = app(TaxRules::class);
        $this->assertSame(1, $rules->for('GOLD_SERVICES', now())->version);
        $this->assertSame(2, $rules->for('GOLD_SERVICES', now()->addDays(3))->version);

        $yesterday = now($tz)->subDay();
        [$py, $pm, $pd] = Jalali::fromGregorian($yesterday->year, $yesterday->month, $yesterday->day);
        $this->postJson('/admin/api/tax-rules', ['category' => 'GOLD_SERVICES', 'rate_percent' => '8', 'effective_from' => sprintf('%04d/%02d/%02d', $py, $pm, $pd), 'reference' => 'تاریخ گذشته', 'idempotency_key' => $this->key()])
            ->assertStatus(422)->assertJsonPath('code', 'TAX_DATE_PAST');
        $this->postJson('/admin/api/tax-rules', ['category' => 'MISC', 'rate_percent' => '5', 'effective_from' => $date, 'reference' => 'متفرقه مالیات ندارد', 'idempotency_key' => $this->key()])
            ->assertStatus(422)->assertJsonPath('code', 'TAX_CATEGORY_LOCKED');

        $active = TaxRule::query()->where('category', 'GOLD_SERVICES')->where('version', 1)->firstOrFail();
        $this->postJson("/admin/api/tax-rules/{$active->id}/disable", ['reason' => 'نباید ممکن باشد'])->assertStatus(409)->assertJsonPath('code', 'TAX_RULE_STARTED');
        $this->postJson("/admin/api/tax-rules/{$rule->id}/disable", ['reason' => 'نرخ اشتباه وارد شد'])->assertOk();
        $this->assertSame(1, $rules->for('GOLD_SERVICES', now()->addDays(3))->version);
        $this->get('/admin/tax-rules')->assertOk()->assertSee('غیرفعال');
    }

    public function test_staff_management_never_locks_the_console_out(): void
    {
        $admin = $this->staff();
        $this->postJson('/admin/api/staff', ['name' => 'پشتیبان تازه', 'mobile' => '۰۹۱۲۳۳۳۴۴۵۵', 'role' => 'support'])->assertCreated();
        $new = StaffUser::query()->where('mobile', '09123334455')->firstOrFail();
        $this->postJson('/admin/api/staff', ['name' => 'تکراری', 'mobile' => '09123334455', 'role' => 'support'])->assertStatus(422)->assertJsonPath('code', 'STAFF_EXISTS');
        $this->putJson("/admin/api/staff/{$admin->id}", ['name' => $admin->name, 'role' => 'support', 'active' => true])->assertStatus(422)->assertJsonPath('code', 'STAFF_SELF_CHANGE');

        $other = StaffUser::query()->create(['mobile' => '09129998877', 'name' => 'مدیر دوم', 'role' => 'admin', 'active' => true]);
        $this->putJson("/admin/api/staff/{$other->id}", ['name' => 'مدیر دوم', 'role' => 'admin', 'active' => false])->assertOk();
        // Deactivation applies on the very next request of that person.
        $this->asStaff($other);
        $this->getJson('/admin/staff')->assertStatus(401);

        $this->asStaff($new);
        $this->putJson("/admin/api/staff/{$admin->id}", ['name' => $admin->name, 'role' => 'support', 'active' => true])->assertForbidden();
        $this->asStaff($admin);
        $this->get('/admin/staff')->assertOk()->assertSee('جدول اجازه‌ها')->assertSee('پشتیبان تازه');
    }

    public function test_passkey_is_mandatory_for_everything_but_dashboard_and_account(): void
    {
        config(['talata.admin.require_passkey' => true]);
        $staff = $this->staff();
        $this->get('/admin')->assertOk()->assertSee('افزودن کلید عبور');
        $this->get('/admin/payments')->assertRedirect('/admin/account');
        $this->postJson('/admin/api/sms/test')->assertForbidden()->assertJsonPath('code', 'PASSKEY_REQUIRED');
        $this->get('/admin/account')->assertOk();

        Passkey::query()->create(['owner_type' => 'staff', 'owner_id' => $staff->id, 'credential_id' => 'cred-'.bin2hex(random_bytes(6)), 'public_key_pem' => 'x', 'alg' => -7, 'name' => 'لپ‌تاپ']);
        $this->get('/admin/payments')->assertOk();
    }

    public function test_every_admin_page_renders_for_every_role_without_customer_data(): void
    {
        $merchant = $this->merchant('basic');
        $tenant = $this->tenantOf($merchant);
        SmsMessage::create(['tenant_id' => $tenant->id, 'purpose' => 'INVOICE', 'recipient' => '09351112233', 'body' => 'متن محرمانه پیامک', 'segments' => 1, 'cost_irr' => '5000',
            'charge_source' => 'CREDIT', 'status' => 'UNKNOWN', 'idempotency_key' => 'x-'.bin2hex(random_bytes(4)), 'provider_message_id' => '123']);
        DB::table('failed_jobs')->insert(['uuid' => '0b7f2c51-6a1e-4d55-9c0f-6f9a3f1a2b3c', 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'X', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]), 'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now()]);
        $pages = ['/admin', '/admin/tenants', '/admin/tenants?quota=at_cap', '/admin/tenants?plan=basic&status=active&period=soon', "/admin/tenants/{$tenant->id}", '/admin/payments', '/admin/sms', '/admin/sms?f=all',
            '/admin/quotes', '/admin/tax-rules', '/admin/integrations', '/admin/staff', '/admin/system', '/admin/pricing', '/admin/affiliates', '/admin/activity', '/admin/account'];
        foreach (['admin', 'finance', 'ops', 'support'] as $role) {
            $this->staff($role);
            foreach ($pages as $page) {
                $this->get($page)->assertOk();
            }
        }
        $sms = $this->get('/admin/sms?f=all')->assertOk()->assertDontSee('متن محرمانه پیامک')->assertDontSee('09351112233');
        $sms->assertSee('۰۹۳۵•••۲۲۳۳');

        // Failed job retry (ops) moves it back to the queue, once.
        $this->staff('ops');
        $this->postJson('/admin/api/system/failed-jobs/0b7f2c51-6a1e-4d55-9c0f-6f9a3f1a2b3c/retry')->assertOk();
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->postJson('/admin/api/system/failed-jobs/0b7f2c51-6a1e-4d55-9c0f-6f9a3f1a2b3c/retry')->assertNotFound();

        // Tenants CSV (finance/support): shop data only.
        $this->staff('support');
        $csv = $this->get('/admin/tenants/export.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('طلافروشی آزمون', $csv);
        $this->staff('ops');
        $this->get('/admin/tenants/export.csv')->assertForbidden();
    }
}
