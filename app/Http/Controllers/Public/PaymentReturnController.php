<?php

namespace App\Http\Controllers\Public;

use App\Domain\Billing\BillingService;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Sms\SmsCredit;
use App\Http\Controllers\Controller;
use App\Models\BillingOrder;
use App\Models\Membership;
use App\Models\Subscription;
use App\Support\Jalali;
use App\Support\Money;
use Illuminate\Http\Request;

/** Bank return. The result is read from server state only, never from gateway query parameters. */
class PaymentReturnController extends Controller
{
    public function callback(Request $request, string $gateway, BillingService $billing)
    {
        $order = $billing->handleCallback($gateway, $request);
        if (! $order) {
            return response()->view('public.pay-not-found', [], 404);
        }

        return redirect()->route('pay.result', [$order->public_id, 's' => $billing->resultSignature($order)]);
    }

    private function find(string $publicId): ?BillingOrder
    {
        return preg_match('/^[0-9a-z]{26}$/', $publicId) ? BillingOrder::withoutGlobalScope('tenant')->where('public_id', $publicId)->first() : null;
    }

    /** Full details only for a logged-in member of the order's tenant; otherwise status + reference with a valid signature. */
    private function access(Request $request, BillingOrder $order, BillingService $billing): string
    {
        $user = $request->user();
        if ($user && Membership::query()->where('user_id', $user->id)->where('tenant_id', $order->tenant_id)->where('status', 'active')->exists()) {
            return 'full';
        }
        if (hash_equals($billing->resultSignature($order), (string) $request->query('s'))) {
            return 'limited';
        }

        return 'none';
    }

    public function show(Request $request, string $order, BillingService $billing, PaymentGateway $gateway, SmsCredit $credit)
    {
        $o = $this->find($order);
        if (! $o || ($access = $this->access($request, $o, $billing)) === 'none') {
            return response()->view('public.pay-not-found', [], 404);
        }
        $tz = config('talata.timezone');
        $attempt = $o->attempts()->latest('id')->first();
        $sub = $o->product === 'PLAN' ? Subscription::withoutGlobalScope('tenant')->where('source_order_id', $o->id)->first() : null;
        $next = match ($o->return_to['route'] ?? null) {
            'review' => route('invoices.review', $o->return_to['id']),
            'invoice' => route('invoices.show', $o->return_to['id']),
            'customers' => route('customers.index'),
            default => $o->product === 'PLAN' ? route('settings.plan') : route('settings.sms'),
        };

        return response()->view('public.pay-result', [
            'o' => $o, 'attempt' => $attempt, 'access' => $access, 'mock' => ($attempt?->gateway ?? $gateway->code()) === 'mock', 'sub' => $sub, 'tz' => $tz,
            'balanceFa' => $access === 'full' ? Money::toman($credit->balance($o->tenant_id)) : null,
            'next' => $next, 'sig' => $billing->resultSignature($o),
            'paidFa' => $o->paid_at ? Jalali::date($o->paid_at, $tz, true) : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, string $order, BillingService $billing)
    {
        $o = $this->find($order);
        if (! $o || $this->access($request, $o, $billing) === 'none') {
            return response()->json(['code' => 'NOT_FOUND'], 404);
        }

        return response()->json(['status' => $o->status, 'final' => $o->isFinal()]);
    }
}
