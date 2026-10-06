<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingService;
use App\Domain\Billing\Gateways\ZarinpalGateway;
use App\Domain\Billing\PaymentGateways;
use App\Models\BillingOrder;
use App\Models\PaymentAttempt;
use App\Models\SmsCreditLot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** ZarinPal v4 adapter and the PSP registry (docs/PAYMENTS_AND_SMS_CREDIT.md §PSP adapters). HTTP is faked. */
class ZarinpalGatewayTest extends TestCase
{
    private const MERCHANT = '1344b5d4-0048-11e8-94db-005056a205be';

    private const AUTHORITY = 'A0000000000000000000000000000wwOGYpd';

    /** Next responses of the faked PSP (one Http::fake for the whole test: stubs registered later never override earlier ones). */
    private array $requestQueue = [];

    private array $verifyQueue = [];

    private bool $verifyDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useZarinpal();
        Http::fake(function (HttpRequest $r) {
            if (str_ends_with($r->url(), '/pg/v4/payment/request.json')) {
                return Http::response(array_shift($this->requestQueue) ?? ['data' => ['code' => 100, 'message' => 'Success', 'authority' => self::AUTHORITY, 'fee_type' => 'Merchant', 'fee' => 100], 'errors' => []]);
            }
            if (str_ends_with($r->url(), '/pg/v4/payment/verify.json')) {
                if ($this->verifyDown) {
                    throw new ConnectionException('timed out');
                }

                return Http::response(array_shift($this->verifyQueue) ?? self::error(-51)['body']);
            }

            return Http::response('not found', 404);
        });
    }

    private function useZarinpal(bool $sandbox = false): void
    {
        config(['talata.drivers.payment' => 'zarinpal', 'talata.payments.zarinpal.merchant_id' => self::MERCHANT, 'talata.payments.zarinpal.sandbox' => $sandbox]);
        $this->app->forgetInstance(PaymentGateways::class);
    }

    private function fakeRequestOk(?string $authority = null): void
    {
        $this->requestQueue[] = ['data' => ['code' => 100, 'message' => 'Success', 'authority' => $authority ?? self::AUTHORITY, 'fee_type' => 'Merchant', 'fee' => 100], 'errors' => []];
    }

    private function buySms($user): BillingOrder
    {
        $res = $this->actingAs($user)->api('POST', '/api/billing/orders', ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '400000', 'idempotency_key' => 'ord-'.bin2hex(random_bytes(8))])->assertCreated();
        $this->assertSame('https://payment.zarinpal.com/pg/StartPay/'.self::AUTHORITY, $res->json('redirect.url'));
        $this->assertSame('GET', $res->json('redirect.method'));

        return BillingOrder::withoutGlobalScope('tenant')->where('public_id', $res->json('order_id'))->firstOrFail();
    }

    private function verifyReturns(array ...$responses): void
    {
        $this->verifyDown = false;
        $this->verifyQueue = array_map(fn ($r) => $r['body'], $responses);
    }

    private static function paid(int $code = 100): array
    {
        return ['body' => ['data' => ['code' => $code, 'message' => 'Paid', 'card_hash' => '1EBE3EBEBE35C7EC0F8D6EE4F2F859107A87822CA179BC9528767EA7B5489B69', 'card_pan' => '502229******5995', 'ref_id' => 201, 'fee_type' => 'Merchant', 'fee' => 0], 'errors' => []]];
    }

    private static function error(int $code): array
    {
        return ['body' => ['data' => [], 'errors' => ['code' => $code, 'message' => 'error', 'validations' => []]]];
    }

    public function test_request_sends_the_stored_irr_amount_and_redirects_to_start_pay(): void
    {
        $this->fakeRequestOk();
        $user = $this->merchant();
        $order = $this->buySms($user);

        Http::assertSent(function (HttpRequest $r) use ($order) {
            return $r->url() === 'https://payment.zarinpal.com/pg/v4/payment/request.json'
                && $r['merchant_id'] === self::MERCHANT && $r['amount'] === (int) $order->amount_irr && $r['currency'] === 'IRR'
                && $r['callback_url'] === route('pay.callback', 'zarinpal') && $r['metadata']['order_id'] === $order->public_ref
                && ! isset($r['metadata']['mobile']); // data minimisation: mobile only when opted in
        });
        $attempt = PaymentAttempt::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(['zarinpal', self::AUTHORITY], [$attempt->gateway, $attempt->authority]);
        // The purchase page may only POST to the active PSP's hosts (none for ZarinPal's GET redirect).
        $this->get('/settings/sms')->assertOk()->assertHeader('Content-Security-Policy');
        $this->assertStringContainsString("form-action 'self';", $this->get('/settings/sms')->headers->get('Content-Security-Policy'));
    }

    public function test_successful_payment_is_verified_server_side_and_fulfilled_exactly_once(): void
    {
        $this->fakeRequestOk();
        $user = $this->merchant();
        $order = $this->buySms($user);
        $this->verifyReturns(self::paid(100), self::paid(101));

        auth()->logout();
        $this->get('/pay/callback/zarinpal?Authority='.self::AUTHORITY.'&Status=OK')->assertRedirect();
        $order->refresh();
        $this->assertSame('FULFILLED', $order->status);
        $attempt = PaymentAttempt::query()->where('order_id', $order->id)->first();
        $this->assertSame(['201', '502229******5995', 'PAID'], [$attempt->ref_id, $attempt->card_mask, $attempt->status]);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/verify.json') && $r['amount'] === (int) $order->amount_irr && $r['authority'] === self::AUTHORITY && $r['merchant_id'] === self::MERCHANT);

        // A replayed callback (refresh/back button) never credits twice.
        $this->get('/pay/callback/zarinpal?Authority='.self::AUTHORITY.'&Status=OK')->assertRedirect();
        $this->assertSame(1, SmsCreditLot::withoutGlobalScope('tenant')->where('source_order_id', $order->id)->count());
    }

    public function test_refused_request_creates_no_order_and_reports_unavailable(): void
    {
        $this->requestQueue[] = ['data' => [], 'errors' => ['code' => -10, 'message' => 'Terminal is not valid', 'validations' => []]];
        $user = $this->merchant();
        $this->actingAs($user)->api('POST', '/api/billing/orders', ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '400000', 'idempotency_key' => 'ord-'.bin2hex(random_bytes(8))])
            ->assertStatus(503)->assertJsonPath('code', 'GATEWAY_UNAVAILABLE');
        $this->assertSame(0, BillingOrder::withoutGlobalScope('tenant')->count());
    }

    public function test_cancelled_failed_and_mismatched_payments_never_fulfil(): void
    {
        $user = $this->merchant();
        foreach ([[-51, '-51'], [-50, 'AMOUNT_MISMATCH']] as [$psp, $stored]) {
            $this->fakeRequestOk();
            BillingOrder::withoutGlobalScope('tenant')->delete();
            PaymentAttempt::query()->delete();
            $order = $this->buySms($user);
            $this->verifyReturns(self::error($psp));
            $this->get('/pay/callback/zarinpal?Authority='.self::AUTHORITY.'&Status=OK')->assertRedirect();
            $order->refresh();
            $this->assertSame(['FAILED', $stored], [$order->status, $order->failure_code], "code {$psp}");
        }
        // Status=NOK is only a hint; the order stays reopenable until it expires (a later verified callback wins).
        $this->fakeRequestOk();
        BillingOrder::withoutGlobalScope('tenant')->delete();
        PaymentAttempt::query()->delete();
        $order = $this->buySms($user);
        $this->get('/pay/callback/zarinpal?Authority='.self::AUTHORITY.'&Status=NOK')->assertRedirect();
        $this->assertSame('FAILED', $order->fresh()->status);
        $this->verifyReturns(self::paid());
        $this->get('/pay/callback/zarinpal?Authority='.self::AUTHORITY.'&Status=OK')->assertRedirect();
        $this->assertSame('FULFILLED', $order->fresh()->status);
    }

    public function test_network_error_waits_for_reconcile_and_a_payer_who_never_returned_is_still_fulfilled(): void
    {
        $this->fakeRequestOk();
        $user = $this->merchant();
        $order = $this->buySms($user);
        $this->verifyDown = true;
        $this->get('/pay/callback/zarinpal?Authority='.self::AUTHORITY.'&Status=OK')->assertRedirect();
        $this->assertSame('PENDING_VERIFICATION', $order->fresh()->status);

        $this->verifyReturns(self::paid(101));
        PaymentAttempt::query()->update(['next_reconcile_at' => now()->subMinute()]);
        app(BillingService::class)->reconcile();
        $this->assertSame('FULFILLED', $order->fresh()->status);

        // Second order: paid at ZarinPal but the browser never came back; on expiry the PSP is asked first.
        $this->fakeRequestOk('A00000000000000000000000000000000002');
        $res = $this->actingAs($user)->api('POST', '/api/billing/orders', ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '400000', 'idempotency_key' => 'ord-'.bin2hex(random_bytes(8))])->assertCreated();
        $second = BillingOrder::withoutGlobalScope('tenant')->where('public_id', $res->json('order_id'))->first();
        $second->update(['expires_at' => now()->subMinute()]);
        $this->verifyReturns(self::paid());
        app(BillingService::class)->reconcile();
        $this->assertSame('FULFILLED', $second->fresh()->status);

        // Third order: abandoned and unpaid (-51) → expired, nothing credited.
        $this->fakeRequestOk('A00000000000000000000000000000000003');
        $res = $this->actingAs($user)->api('POST', '/api/billing/orders', ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '400000', 'idempotency_key' => 'ord-'.bin2hex(random_bytes(8))])->assertCreated();
        $third = BillingOrder::withoutGlobalScope('tenant')->where('public_id', $res->json('order_id'))->first();
        $third->update(['expires_at' => now()->subMinute()]);
        $this->verifyReturns(self::error(-51));
        app(BillingService::class)->reconcile();
        $this->assertSame('EXPIRED', $third->fresh()->status);
    }

    public function test_switching_psp_keeps_in_flight_payments_with_their_own_gateway(): void
    {
        $this->fakeRequestOk();
        $user = $this->merchant();
        $order = $this->buySms($user);

        // Operator switches new payments to another PSP (here: mock) while this payment is at ZarinPal.
        config(['talata.drivers.payment' => 'mock']);
        $this->app->forgetInstance(PaymentGateways::class);
        $this->verifyReturns(self::paid());
        $this->get('/pay/callback/zarinpal?Authority='.self::AUTHORITY.'&Status=OK')->assertRedirect();
        $this->assertSame('FULFILLED', $order->fresh()->status);
        $this->get('/pay/callback/unknown-psp?Authority='.self::AUTHORITY.'&Status=OK')->assertNotFound();
        $this->get('/pay/callback/zarinpal?Authority=../../etc&Status=OK')->assertNotFound();
    }

    public function test_sandbox_host_and_merchant_id_validation(): void
    {
        $this->useZarinpal(sandbox: true);
        $user = $this->merchant();
        $res = $this->actingAs($user)->api('POST', '/api/billing/orders', ['product' => 'SMS_CREDIT', 'pack_amount_toman' => '400000', 'idempotency_key' => 'ord-'.bin2hex(random_bytes(8))])->assertCreated();
        $this->assertSame('https://sandbox.zarinpal.com/pg/StartPay/'.self::AUTHORITY, $res->json('redirect.url'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://sandbox.zarinpal.com/pg/v4/payment/request.json');

        $this->expectException(RuntimeException::class);
        new ZarinpalGateway(['merchant_id' => 'not-a-merchant-id']);
    }
}
