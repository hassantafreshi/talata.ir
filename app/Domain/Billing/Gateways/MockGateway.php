<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\PaymentGateway;
use App\Support\Tokens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Test gateway: a local "bank page" lets the tester choose success / cancel / amount mismatch / timeout.
 * Refused in production unless explicitly allowed (AppServiceProvider). Results are visibly labelled «آزمایشی».
 */
final class MockGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'mock';
    }

    public function isMock(): bool
    {
        return true;
    }

    public function request(string $orderRef, string $amountIrr, string $callbackUrl, ?string $mobile): array
    {
        $authority = 'MOCK'.strtoupper(substr(Tokens::make(24), 0, 28));
        Cache::put('mockpay:'.$authority, ['amount_irr' => $amountIrr, 'ref' => $orderRef, 'decision' => null], 3600);

        return ['authority' => $authority, 'redirect_url' => route('pay.mock', $authority), 'method' => 'GET', 'fields' => []];
    }

    public function parseCallback(Request $request): array
    {
        $authority = (string) $request->input('Authority', '');
        $status = (string) $request->input('Status', '');

        return [
            'authority' => preg_match('/^MOCK[A-Z0-9_-]{10,40}$/', $authority) ? $authority : null,
            'status' => $status === 'OK' ? 'OK' : ($status === 'NOK' ? 'CANCELLED' : 'FAILED'),
            'bank_code' => $status === 'OK' ? null : '17',
            'raw' => ['Status' => mb_substr($status, 0, 10)],
        ];
    }

    public function verify(string $authority, string $amountIrr): array
    {
        $data = Cache::get('mockpay:'.$authority);
        if (! $data || ! $data['decision']) {
            return ['status' => 'FAILED', 'ref_id' => null, 'card_mask' => null, 'bank_code' => '-11', 'amount_irr' => null];
        }

        return match ($data['decision']) {
            'success' => ['status' => 'OK', 'ref_id' => (string) random_int(10000000, 99999999), 'card_mask' => '6037-99**-****-1234', 'bank_code' => '100', 'amount_irr' => $data['amount_irr']],
            'mismatch' => ['status' => 'OK', 'ref_id' => (string) random_int(10000000, 99999999), 'card_mask' => null, 'bank_code' => '100', 'amount_irr' => (string) max(0, (int) $data['amount_irr'] - 10000000)],
            'timeout' => ['status' => 'UNKNOWN', 'ref_id' => null, 'card_mask' => null, 'bank_code' => null, 'amount_irr' => null],
            default => ['status' => 'FAILED', 'ref_id' => null, 'card_mask' => null, 'bank_code' => '17', 'amount_irr' => null],
        };
    }

    /** Called by the mock bank page only. */
    public static function decide(string $authority, string $decision): bool
    {
        $data = Cache::get('mockpay:'.$authority);
        if (! $data || $data['decision']) {
            return false;
        }
        $data['decision'] = in_array($decision, ['success', 'cancel', 'mismatch', 'timeout'], true) ? $decision : 'cancel';
        Cache::put('mockpay:'.$authority, $data, 3600);

        return true;
    }

    public static function lookup(string $authority): ?array
    {
        return Cache::get('mockpay:'.$authority);
    }

    /** Lets a pending "timeout" order succeed on reconcile in tests/demo. */
    public static function settleTimeout(string $authority): void
    {
        $data = Cache::get('mockpay:'.$authority);
        if ($data && $data['decision'] === 'timeout') {
            $data['decision'] = 'success';
            Cache::put('mockpay:'.$authority, $data, 3600);
        }
    }
}
