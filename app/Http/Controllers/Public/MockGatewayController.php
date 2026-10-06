<?php

namespace App\Http\Controllers\Public;

use App\Domain\Billing\Gateways\MockGateway;
use App\Domain\Billing\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\BillingOrder;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;

/** Local stand-in for the bank page. Exists only while the mock driver is active. */
class MockGatewayController extends Controller
{
    private function guard(PaymentGateway $gateway, string $authority): PaymentAttempt
    {
        abort_unless($gateway->isMock() && preg_match('/^MOCK[A-Z0-9_-]{10,40}$/', $authority), 404);

        return PaymentAttempt::query()->where('gateway', 'mock')->where('authority', $authority)->firstOrFail();
    }

    public function show(PaymentGateway $gateway, string $authority)
    {
        $attempt = $this->guard($gateway, $authority);

        // Public route: no tenant context, so read the order explicitly (the tenant scope fails closed).
        $order = BillingOrder::withoutGlobalScope('tenant')->findOrFail($attempt->order_id);

        return view('public.mock-bank', ['attempt' => $attempt, 'authority' => $authority, 'order' => $order]);
    }

    public function decide(Request $request, PaymentGateway $gateway, string $authority)
    {
        $this->guard($gateway, $authority);
        $decision = (string) $request->input('decision');
        MockGateway::decide($authority, $decision);

        return redirect()->route('pay.callback', ['gateway' => 'mock', 'Authority' => $authority, 'Status' => $decision === 'cancel' ? 'NOK' : 'OK']);
    }
}
