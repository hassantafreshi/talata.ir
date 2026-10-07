<?php

namespace Tests\Feature;

use App\Domain\Sms\SmsCredit;
use App\Models\AuditEvent;
use App\Models\BillingOrder;
use App\Models\PaymentAttempt;
use App\Models\PromoCode;
use App\Models\StaffUser;
use App\Models\Subscription;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

/** Staff discount codes; a 100% code completes a purchase without the bank (owner test plan 2026-10-07). */
class PromoCodeTest extends TestCase
{
    private function admin(string $role = 'admin'): StaffUser
    {
        $staff = StaffUser::query()->create(['mobile' => '09120003'.random_int(100, 999), 'name' => 'مدیر', 'role' => $role, 'active' => true]);
        $this->asStaff($staff);

        return $staff;
    }

    private function makeCode(array $over = []): void
    {
        $this->admin();
        $this->postJson('/admin/api/promo-codes', $over + ['code' => 'test-100', 'percent' => '100', 'product_plan' => true, 'product_sms' => true,
            'max_uses' => 2, 'days' => 30, 'reason' => 'آزمایش خرید پیش از درگاه', 'idempotency_key' => 'adm-'.bin2hex(random_bytes(8))])->assertCreated()->assertJsonPath('code', strtoupper(str_replace('-', '', $over['code'] ?? 'test-100')));
    }

    private function order($user, array $payload)
    {
        return $this->actingAs($user)->api('POST', '/api/billing/orders', $payload + ['idempotency_key' => 'ord-'.bin2hex(random_bytes(8))]);
    }

    public function test_a_100_percent_code_activates_a_plan_without_the_bank(): void
    {
        $this->makeCode();
        $user = $this->merchant();
        $this->actingAs($user)->api('POST', '/api/billing/discount', ['code' => 'test100', 'plan' => 'professional', 'period' => 'monthly'])
            ->assertOk()->assertJsonPath('applied', true)->assertJsonPath('total_fa', '۰');

        $res = $this->order($user, ['product' => 'PLAN', 'plan' => 'professional', 'period' => 'monthly', 'discount_code' => 'TEST100'])->assertCreated();
        $this->assertStringContainsString('/pay/result/', $res->json('redirect.url'));
        $order = BillingOrder::withoutGlobalScope('tenant')->latest('id')->firstOrFail();
        $this->assertSame(['FULFILLED', 'FREE', '0', '0'], [$order->status, $order->channel, (string) $order->amount_irr, (string) $order->vat_irr]);
        $this->assertSame('free', PaymentAttempt::query()->where('order_id', $order->id)->value('gateway'));
        $this->assertSame(1, Subscription::withoutGlobalScope('tenant')->where('plan_code', 'professional')->count());
        $this->get(parse_url($res->json('redirect.url'), PHP_URL_PATH).'?'.parse_url($res->json('redirect.url'), PHP_URL_QUERY))->assertOk();

        // One use per shop.
        $this->order($user, ['product' => 'PLAN', 'plan' => 'basic', 'period' => 'monthly', 'discount_code' => 'TEST100'])->assertStatus(422)->assertJsonPath('code', 'DISCOUNT_CODE_NOT_ELIGIBLE');
    }

    public function test_sms_credit_limits_expiry_and_deactivation(): void
    {
        $this->makeCode();
        $a = $this->merchant('basic');
        $this->order($a, ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '100000', 'discount_code' => 'TEST100'])->assertCreated();
        $this->assertSame('1000000', app(SmsCredit::class)->balance($this->tenantOf($a)->id));

        $this->order($this->merchant('basic'), ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '100000', 'discount_code' => 'TEST100'])->assertCreated();
        // max_uses = 2: the third shop is refused.
        $this->order($this->merchant('basic'), ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '100000', 'discount_code' => 'TEST100'])->assertStatus(422)->assertJsonPath('message_fa', 'ظرفیت این کد تخفیف تمام شده است.');

        // A partial code still goes to the bank; an unknown code is still an error.
        $this->makeCode(['code' => 'HALF50', 'percent' => '50', 'product_sms' => false, 'max_uses' => null]);
        $res = $this->order($this->merchant(), ['product' => 'PLAN', 'plan' => 'basic', 'period' => 'monthly', 'discount_code' => 'HALF50'])->assertCreated();
        $this->assertStringNotContainsString('/pay/result/', $res->json('redirect.url'));
        $this->order($this->merchant(), ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '100000', 'discount_code' => 'HALF50'])->assertStatus(422);

        $this->travel(31)->days();
        $this->order($this->merchant(), ['product' => 'PLAN', 'plan' => 'basic', 'period' => 'monthly', 'discount_code' => 'HALF50'])->assertStatus(422)->assertJsonPath('message_fa', 'مهلت این کد تخفیف تمام شده است.');
        $this->assertSame(2, AuditEvent::query()->where('event', 'billing.promo_created')->count());
    }

    public function test_only_pricing_managers_create_codes(): void
    {
        $this->admin('support');
        $this->postJson('/admin/api/promo-codes', ['code' => 'NOPE1', 'percent' => '100', 'product_plan' => true, 'reason' => 'آزمون دسترسی', 'idempotency_key' => 'adm-x1234567'])->assertForbidden();
        $this->admin();
        $this->postJson('/admin/api/promo-codes', ['code' => 'BAD1', 'percent' => '150', 'product_plan' => true, 'reason' => 'آزمون درصد', 'idempotency_key' => 'adm-x7654321'])->assertStatus(422);
    }

    public function test_the_seeded_owner_code_htdc00_works_only_for_the_owner_and_can_be_reused(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class); // idempotent: still one code

        $owner = $this->merchant('free', true, '09396727215');
        $this->actingAs($owner)->api('POST', '/api/billing/discount', ['code' => 'htdc00', 'plan' => 'basic', 'period' => 'monthly'])->assertOk()->assertJsonPath('total_fa', '۰');
        $this->order($owner, ['product' => 'PLAN', 'plan' => 'basic', 'period' => 'monthly', 'discount_code' => 'htdc00'])->assertCreated();
        // Reusable for testing: the same shop can switch plans again with it.
        $this->order($owner, ['product' => 'PLAN', 'plan' => 'professional', 'period' => 'yearly', 'discount_code' => 'HTDC00'])->assertCreated();
        $this->assertSame(2, BillingOrder::withoutGlobalScope('tenant')->where('status', 'FULFILLED')->where('amount_irr', '0')->count());

        // Anyone else is refused, and it never applies to SMS credit.
        $other = $this->merchant();
        $this->order($other, ['product' => 'PLAN', 'plan' => 'basic', 'period' => 'monthly', 'discount_code' => 'htdc00'])->assertStatus(422)->assertJsonPath('errors.discount_code.0', 'این کد تخفیف برای حساب شما نیست.');
        $this->order($owner, ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '100000', 'discount_code' => 'htdc00'])->assertStatus(422);
        $this->assertSame(1, PromoCode::query()->where('code', 'HTDC00')->count());
    }
}
