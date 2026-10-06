<?php

namespace Tests\Feature;

use App\Domain\Affiliate\AffiliateService;
use App\Domain\DomainError;
use App\Domain\Identity\ProofOfWork;
use App\Domain\Sms\SmsGateway;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\BillingOrder;
use App\Models\ShopProfile;
use App\Models\StaffUser;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class AffiliateTest extends TestCase
{
    private function enroll(User $user, array $over = []): Affiliate
    {
        return app(AffiliateService::class)->enroll($user, $over + ['commission_percent' => '10', 'commission_mode' => 'FIRST_PAYMENT', 'discount_percent' => '20'], null);
    }

    /** Buys a plan through the mock bank as $user; returns the order. */
    private function buyPlan(User $user, ?string $code = null, string $plan = 'basic'): BillingOrder
    {
        $res = $this->actingAs($user)->api('POST', '/api/billing/orders', ['product' => 'PLAN', 'plan' => $plan, 'period' => 'monthly', 'idempotency_key' => 'ord-'.bin2hex(random_bytes(6))] + ($code ? ['discount_code' => $code] : []))->assertCreated();
        $authority = basename(parse_url($res->json('redirect.url'), PHP_URL_PATH));
        $back = $this->post("/pay/mock/{$authority}", ['decision' => 'success'])->assertRedirect();
        $this->get($back->headers->get('Location'));

        return BillingOrder::withoutGlobalScope('tenant')->where('public_id', $res->json('order_id'))->first();
    }

    private function buySms(User $user): BillingOrder
    {
        $res = $this->actingAs($user)->api('POST', '/api/billing/orders', ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '100000', 'idempotency_key' => 'ord-'.bin2hex(random_bytes(6))])->assertCreated();
        $authority = basename(parse_url($res->json('redirect.url'), PHP_URL_PATH));
        $back = $this->post("/pay/mock/{$authority}", ['decision' => 'success']);
        $this->get($back->headers->get('Location'));

        return BillingOrder::withoutGlobalScope('tenant')->where('public_id', $res->json('order_id'))->first();
    }

    public function test_code_gives_first_plan_discount_and_records_commission_on_pre_vat_amount(): void
    {
        $affiliate = $this->enroll($this->merchant('basic'));
        $buyer = $this->merchant(mobile: '09351234567');

        $preview = $this->actingAs($buyer)->api('POST', '/api/billing/discount', ['code' => strtolower($affiliate->code), 'plan' => 'basic', 'period' => 'monthly'])->assertOk();
        $this->assertTrue($preview->json('applied'));

        $order = $this->buyPlan($buyer, $affiliate->code);
        // Basic monthly 790,000 toman → 7,900,000 IRR; 20% off → 6,320,000; VAT 10% on the discounted amount.
        $this->assertSame('7900000', (string) $order->list_subtotal_irr);
        $this->assertSame('1580000', (string) $order->discount_irr);
        $this->assertSame('6320000', (string) $order->subtotal_irr);
        $this->assertSame('632000', (string) $order->vat_irr);
        $this->assertSame('FULFILLED', $order->status);

        $ref = AffiliateReferral::query()->firstOrFail();
        $this->assertSame('CODE', $ref->source);
        $c = AffiliateCommission::query()->firstOrFail();
        $this->assertSame('632000', (string) $c->amount_irr, '10% of the pre-VAT amount actually paid');
        $this->assertSame('PENDING', $c->status);

        // FIRST_PAYMENT mode: a second payment earns nothing and gets no discount.
        $second = $this->buyPlan($buyer, null, 'professional');
        $this->assertSame('0', (string) $second->discount_irr);
        $this->assertSame(1, AffiliateCommission::query()->count());
    }

    public function test_lifetime_mode_and_sms_credit_option(): void
    {
        $affiliate = $this->enroll($this->merchant(), ['commission_mode' => 'LIFETIME', 'include_sms_credit' => true]);
        $buyer = $this->merchant(mobile: '09351234568');
        $this->buyPlan($buyer, $affiliate->code);
        $this->buyPlan($buyer, null, 'professional');
        $this->buySms($buyer);
        $this->assertSame(3, AffiliateCommission::query()->count());
        $this->assertSame(['PLAN', 'PLAN', 'SMS_CREDIT'], AffiliateCommission::query()->orderBy('id')->pluck('product')->all());
    }

    public function test_sms_credit_earns_nothing_unless_enabled(): void
    {
        $affiliate = $this->enroll($this->merchant(), ['commission_mode' => 'LIFETIME']);
        $buyer = $this->merchant(mobile: '09351234569');
        $this->buyPlan($buyer, $affiliate->code);
        $this->buySms($buyer);
        $this->assertSame(['PLAN'], AffiliateCommission::query()->pluck('product')->all());
    }

    public function test_code_rules_self_old_shop_already_referred_paused(): void
    {
        $affUser = $this->merchant();
        $affiliate = $this->enroll($affUser);
        $other = $this->enroll($this->merchant(), ['code' => 'OTHER1']);
        $preview = fn (User $u, string $code) => $this->actingAs($u)->api('POST', '/api/billing/discount', ['code' => $code, 'plan' => 'basic', 'period' => 'monthly']);

        $preview($affUser, $affiliate->code)->assertStatus(422)->assertJsonPath('code', 'DISCOUNT_CODE_NOT_ELIGIBLE'); // self
        $preview($affUser, 'NOPE1234')->assertStatus(422)->assertJsonPath('code', 'DISCOUNT_CODE_INVALID');

        $old = $this->merchant();
        Tenant::query()->whereKey($this->tenantOf($old)->id)->update(['created_at' => now()->subDays(61)]);
        $preview($old, $affiliate->code)->assertStatus(422);

        $buyer = $this->merchant();
        $this->buyPlan($buyer, $affiliate->code);
        $preview($buyer, $other->code)->assertStatus(422); // attribution never changes
        $this->assertSame(0, AffiliateCommission::query()->where('affiliate_id', $other->id)->count());

        $other->update(['status' => 'paused']);
        $preview($this->merchant(), 'OTHER1')->assertStatus(422)->assertJsonPath('code', 'DISCOUNT_CODE_INVALID');
    }

    public function test_referral_link_attributes_a_new_signup_only(): void
    {
        $affiliate = $this->enroll($this->merchant());
        $this->get('/r/'.strtolower($affiliate->code))->assertRedirect(route('login'))->assertCookie('talata_ref');

        // Sign up through the normal OTP flow carrying the cookie.
        $c = $this->getJson('/api/auth/pow')->json();
        $nonce = 0;
        while (ProofOfWork::leadingZeroBits(hash('sha256', $c['challenge'].':'.$nonce, true)) < $c['bits']) {
            $nonce++;
        }
        $this->travel(3)->seconds();
        $this->withCredentials()->withCookie('talata_ref', $affiliate->code)->postJson('/api/auth/otp/request', ['mobile' => '09359876543', 'pow_challenge' => $c['challenge'], 'pow_nonce' => (string) $nonce])->assertOk();
        $sent = app(SmsGateway::class)->sent;
        preg_match('/(\d{6})/', end($sent)['body'], $m);
        $this->withCredentials()->withCookie('talata_ref', $affiliate->code)->postJson('/api/auth/otp/verify', ['code' => $m[1]])->assertOk();

        $ref = AffiliateReferral::query()->firstOrFail();
        $this->assertSame('LINK', $ref->source);
        $this->assertSame('09359876543', $ref->buyer_mobile);
        // The referred shop gets the discount automatically on its first plan purchase.
        $order = $this->buyPlan(User::query()->where('mobile', '09359876543')->first());
        $this->assertSame('1580000', (string) $order->discount_irr);
        $this->assertSame(1, AffiliateCommission::query()->count());
    }

    public function test_affiliate_panel_shows_masked_buyers_and_income_only(): void
    {
        $affUser = $this->merchant();
        $affiliate = $this->enroll($affUser);
        $this->actingAs($affUser)->get('/settings')->assertOk()->assertSee('همکاری در فروش');
        $buyer = $this->merchant(mobile: '09351112233');
        ShopProfile::withoutGlobalScope('tenant')->where('tenant_id', $this->tenantOf($buyer)->id)->update(['name' => 'گالری خریدار محرمانه']);
        $this->buyPlan($buyer, $affiliate->code);

        $page = $this->actingAs($affUser)->get('/affiliate')->assertOk();
        $page->assertSee('۰۹۳*****۲۳۳')->assertSee('۶۳٬۲۰۰')->assertSee($affiliate->code)->assertSee($affiliate->link());
        $page->assertDontSee('09351112233')->assertDontSee('۰۹۳۵۱۱۱۲۲۳۳')->assertDontSee('گالری خریدار محرمانه');
        // A merchant who is not an affiliate has no such page.
        $this->actingAs($buyer)->get('/affiliate')->assertNotFound();
    }

    public function test_approval_payout_and_void(): void
    {
        $affiliate = $this->enroll($this->merchant(), ['commission_mode' => 'LIFETIME']);
        $buyer = $this->merchant();
        $this->buyPlan($buyer, $affiliate->code);
        $this->buyPlan($buyer, null, 'professional');
        $service = app(AffiliateService::class);
        $this->assertSame(0, $service->approveDue());
        $this->travel(8)->days();
        $this->assertSame(2, $service->approveDue());
        $service->void(AffiliateCommission::query()->orderByDesc('id')->first(), 'refund test');
        $payout = $service->payout($affiliate, 'BANK-998877', null);
        $this->assertSame((string) AffiliateCommission::query()->where('status', 'PAID')->sum('amount_irr'), (string) $payout->amount_irr);
        $this->expectException(DomainError::class);
        $service->payout($affiliate, 'BANK-1', null);
    }

    public function test_admin_enrols_with_code_and_link_and_support_is_read_only(): void
    {
        $merchant = $this->merchant(mobile: '09121110000');
        $admin = StaffUser::query()->create(['mobile' => '09120000009', 'name' => 'مدیر', 'role' => 'admin', 'active' => true]);
        $this->asStaff($admin);
        $this->postJson('/admin/api/affiliates', ['mobile' => '09129999990', 'commission_percent' => '10', 'commission_mode' => 'LIFETIME'])->assertStatus(422); // no shop panel
        $this->postJson('/admin/api/affiliates', ['mobile' => '۰۹۱۲۱۱۱۰۰۰۰', 'commission_percent' => '۱۲.۵', 'commission_mode' => 'LIFETIME', 'discount_percent' => '5', 'code' => 'negin-10'])->assertCreated();
        $aff = Affiliate::query()->firstOrFail();
        $this->assertSame(['NEGIN10', '12.50', $merchant->id], [$aff->code, $aff->commission_percent, $aff->user_id]);
        $this->get('/admin/affiliates')->assertOk()->assertSee('NEGIN10');
        $this->get('/admin/affiliates/'.$aff->id)->assertOk()->assertSee($aff->link());
        $this->putJson('/admin/api/affiliates/'.$aff->id, ['commission_percent' => '60', 'commission_mode' => 'LIFETIME', 'status' => 'active'])->assertStatus(422);

        $support = StaffUser::query()->create(['mobile' => '09120000008', 'name' => 'پشتیبان', 'role' => 'support', 'active' => true]);
        $this->asStaff($support);
        $this->get('/admin/affiliates/'.$aff->id)->assertOk();
        $this->putJson('/admin/api/affiliates/'.$aff->id, ['commission_percent' => '50', 'commission_mode' => 'LIFETIME', 'status' => 'active'])->assertForbidden();
    }
}
