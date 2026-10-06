<?php

namespace Tests\Feature;

use App\Domain\Billing\Gateways\MockGateway;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\PaymentGateways;
use App\Domain\Sms\SmsCredit;
use App\Models\BillingOrder;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Tests\TestCase;

class BillingTest extends TestCase
{
    private function order($user, array $payload)
    {
        return $this->actingAs($user)->api('POST', '/api/billing/orders', $payload + ['idempotency_key' => 'ord-'.bin2hex(random_bytes(8))]);
    }

    private function pay(string $redirect, string $decision)
    {
        $authority = basename(parse_url($redirect, PHP_URL_PATH));
        $this->get("/pay/mock/{$authority}")->assertOk()->assertSee('پرداخت آزمایشی');
        $back = $this->post("/pay/mock/{$authority}", ['decision' => $decision])->assertRedirect();

        return $this->get($back->headers->get('Location'));
    }

    public function test_sms_credit_purchase_adds_pre_vat_amount_and_shows_vat_separately(): void
    {
        $user = $this->merchant();
        $this->actingAs($user)->get('/settings/sms')->assertOk()->assertSee('مالیات بر ارزش افزوده');
        $res = $this->order($user, ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '400000'])->assertCreated();
        $order = BillingOrder::withoutGlobalScope('tenant')->where('public_id', $res->json('order_id'))->first();
        $this->assertSame('4000000', (string) $order->subtotal_irr);
        $this->assertSame('400000', (string) $order->vat_irr);
        $this->assertSame('4400000', (string) $order->amount_irr);
        $result = $this->pay($res->json('redirect.url'), 'success')->assertRedirect();
        $this->get($result->headers->get('Location'))->assertOk()->assertSee('پرداخت موفق');
        $this->assertSame('4000000', app(SmsCredit::class)->balance($order->tenant_id));
        $this->get(route('settings.receipt', $order->public_id))->assertOk()->assertSee($order->public_ref);
    }

    public function test_below_minimum_and_arbitrary_amounts_are_rejected(): void
    {
        $user = $this->merchant();
        $this->order($user, ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '100000'])->assertStatus(422)->assertJsonPath('code', 'BELOW_MINIMUM');
        $this->order($user, ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '1'])->assertStatus(422)->assertJsonPath('code', 'PACK_INVALID');
        $this->order($user, ['product' => 'PLAN', 'plan' => 'free', 'period' => 'monthly'])->assertStatus(422);
    }

    public function test_plan_purchase_activates_and_callback_replay_is_idempotent(): void
    {
        $user = $this->merchant();
        $this->actingAs($user)->get('/settings/plan')->assertOk()->assertSee('قابل پرداخت');
        $res = $this->order($user, ['product' => 'PLAN', 'plan' => 'basic', 'period' => 'monthly'])->assertCreated();
        $authority = basename(parse_url($res->json('redirect.url'), PHP_URL_PATH));
        $this->pay($res->json('redirect.url'), 'success');
        $this->get("/pay/callback/mock?Authority={$authority}&Status=OK")->assertRedirect();
        $this->assertSame(1, Subscription::withoutGlobalScope('tenant')->where('plan_code', 'basic')->count());
        $this->get('/settings/appearance')->assertOk()->assertDontSee('در پلن رایگان قالب ثابت');
    }

    public function test_amount_mismatch_and_cancel_never_fulfil(): void
    {
        $user = $this->merchant();
        $res = $this->order($user, ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '500000'])->assertCreated();
        $this->pay($res->json('redirect.url'), 'mismatch');
        $order = BillingOrder::withoutGlobalScope('tenant')->where('public_id', $res->json('order_id'))->first();
        $this->assertSame('FAILED', $order->status);
        $this->assertSame('AMOUNT_MISMATCH', $order->failure_code);
        $res2 = $this->order($user, ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '500000'])->assertCreated();
        $this->pay($res2->json('redirect.url'), 'cancel');
        $this->assertSame('0', app(SmsCredit::class)->balance($order->tenant_id));
    }

    public function test_result_page_needs_membership_or_signature(): void
    {
        $user = $this->merchant();
        $res = $this->order($user, ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '400000'])->assertCreated();
        auth()->logout();
        $this->get('/pay/result/'.$res->json('order_id'))->assertNotFound();
        $this->get('/pay/result/'.$res->json('order_id').'?s=forged')->assertNotFound();
        $this->get('/pay/callback/mock?Authority=MOCKDOESNOTEXIST123&Status=OK')->assertNotFound();
        $this->assertSame(0, PaymentAttempt::query()->where('status', 'PAID')->count());
    }

    public function test_same_idempotency_key_creates_one_order(): void
    {
        $user = $this->merchant();
        $payload = ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '400000', 'idempotency_key' => 'ord-fixed-12345'];
        $this->actingAs($user)->api('POST', '/api/billing/orders', $payload);
        $this->api('POST', '/api/billing/orders', $payload);
        $this->assertSame(1, BillingOrder::withoutGlobalScope('tenant')->count());
    }

    public function test_forged_nok_callback_does_not_block_the_real_payment(): void
    {
        $user = $this->merchant();
        $res = $this->order($user, ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '400000'])->assertCreated();
        $authority = basename(parse_url($res->json('redirect.url'), PHP_URL_PATH));
        auth()->logout();
        $this->get("/pay/callback/mock?Authority={$authority}&Status=NOK")->assertRedirect(); // attacker
        MockGateway::decide($authority, 'success');           // user pays at the bank
        $this->get("/pay/callback/mock?Authority={$authority}&Status=OK")->assertRedirect();
        $order = BillingOrder::withoutGlobalScope('tenant')->where('public_id', $res->json('order_id'))->first();
        $this->assertSame('FULFILLED', $order->status);
        $this->assertSame('4000000', app(SmsCredit::class)->balance($order->tenant_id));
    }

    public function test_gateway_exception_keeps_order_for_reconcile(): void
    {
        $user = $this->merchant();
        $res = $this->order($user, ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '400000'])->assertCreated();
        $authority = basename(parse_url($res->json('redirect.url'), PHP_URL_PATH));
        $real = app(PaymentGateway::class);
        app(PaymentGateways::class)->extend('mock', new class($real) implements PaymentGateway
        {
            public function __construct(private $real) {}

            public function code(): string
            {
                return $this->real->code();
            }

            public function isMock(): bool
            {
                return true;
            }

            public function request(string $r, string $a, string $c, ?string $m): array
            {
                return $this->real->request($r, $a, $c, $m);
            }

            public function redirectFor(string $authority): array
            {
                return $this->real->redirectFor($authority);
            }

            public function formActionHosts(): array
            {
                return [];
            }

            public function parseCallback(Request $r): array
            {
                return $this->real->parseCallback($r);
            }

            public function verify(string $authority, string $amountIrr): array
            {
                throw new \RuntimeException('PSP timeout');
            }
        });
        $this->get("/pay/callback/mock?Authority={$authority}&Status=OK")->assertRedirect();
        $order = BillingOrder::withoutGlobalScope('tenant')->where('public_id', $res->json('order_id'))->first();
        $this->assertSame('PENDING_VERIFICATION', $order->status);
    }
}
