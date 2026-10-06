<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\PaymentGatewayError;
use App\Support\TechLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ZarinPal payment gateway, REST API v4 (docs/PAYMENTS_AND_SMS_CREDIT.md §Zarinpal).
 *
 *   request  POST {base}/pg/v4/payment/request.json  {merchant_id, amount, currency, description, callback_url, metadata{order_id[,mobile]}}
 *            → data.code 100 + data.authority
 *   redirect GET  {base}/pg/StartPay/{authority}
 *   callback GET  ?Authority=…&Status=OK|NOK          (never trusted: only an Authority to look up)
 *   verify   POST {base}/pg/v4/payment/verify.json   {merchant_id, amount, authority}
 *            → data.code 100 (paid) | 101 (already verified) + ref_id, card_pan; errors.code < 0 otherwise
 *
 * base = https://payment.zarinpal.com, or https://sandbox.zarinpal.com when TALATA_ZARINPAL_SANDBOX=true.
 * Amounts are sent in IRR with currency "IRR". ZarinPal checks the paid amount against the verify amount
 * (code -50 on a mismatch), so a successful verify confirms exactly the stored order amount.
 */
final class ZarinpalGateway implements PaymentGateway
{
    public const CODE = 'zarinpal';

    /** PSP codes → Persian, for the result page and logs. Codes not listed fall back to a generic message. */
    public const CODES_FA = [
        '100' => 'پرداخت موفق', '101' => 'پرداخت قبلاً تأیید شده است',
        '-9' => 'خطای اعتبارسنجی اطلاعات ارسالی به درگاه', '-10' => 'آی‌پی یا کد پذیرنده درگاه درست نیست', '-11' => 'کد پذیرنده درگاه فعال نیست',
        '-12' => 'تلاش بیش از حد در زمان کوتاه؛ کمی بعد دوباره امتحان کنید', '-15' => 'درگاه پذیرنده تعلیق شده است', '-16' => 'سطح تأیید پذیرنده کافی نیست',
        '-50' => 'مبلغ پرداخت‌شده با مبلغ سفارش یکسان نیست', '-51' => 'پرداخت ناموفق بود', '-52' => 'خطای غیرمنتظره درگاه؛ بعداً بررسی می‌شود',
        '-53' => 'این پرداخت متعلق به این پذیرنده نیست', '-54' => 'شناسه پرداخت نامعتبر است',
    ];

    /** Codes after which asking again later may succeed (temporary PSP-side problems). */
    private const RETRYABLE = ['-12', '-52'];

    public function __construct(private readonly array $config = [])
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) ($this->config['merchant_id'] ?? ''))) {
            throw new RuntimeException('ZarinPal merchant id is missing or malformed. Set TALATA_ZARINPAL_MERCHANT_ID (36-character id from the ZarinPal panel).');
        }
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function isMock(): bool
    {
        return false;
    }

    private function base(): string
    {
        return ($this->config['sandbox'] ?? false) ? 'https://sandbox.zarinpal.com/pg/' : 'https://payment.zarinpal.com/pg/';
    }

    private function http()
    {
        return Http::acceptJson()->asJson()
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->withUserAgent('Zarlio/1.0');
    }

    public function request(string $orderRef, string $amountIrr, string $callbackUrl, ?string $mobile): array
    {
        $metadata = ['order_id' => $orderRef];
        if (($this->config['send_mobile'] ?? false) && $mobile) {
            $metadata['mobile'] = $mobile; // opt-in: lets ZarinPal offer the payer's saved cards
        }
        try {
            $res = $this->http()->post($this->base().'v4/payment/request.json', [
                'merchant_id' => $this->config['merchant_id'],
                'amount' => (int) $amountIrr,
                'currency' => 'IRR',
                'description' => mb_substr(($this->config['description'] ?? 'خرید از زرلیو').' · '.$orderRef, 0, 250),
                'callback_url' => $callbackUrl,
                'metadata' => $metadata,
            ]);
        } catch (ConnectionException $e) {
            TechLog::error('payments', 'zarinpal request unreachable', ['order' => $orderRef]);
            throw new PaymentGatewayError('NETWORK', 'payment gateway unreachable');
        }
        $data = $res->json('data');
        $authority = is_array($data) ? (string) ($data['authority'] ?? '') : '';
        if ($res->successful() && is_array($data) && (int) ($data['code'] ?? 0) === 100 && $this->validAuthority($authority)) {
            return ['authority' => $authority, 'method' => 'GET', 'fields' => []] + $this->redirectFor($authority);
        }
        $code = (string) ($res->json('errors.code') ?? ('HTTP'.$res->status()));
        TechLog::error('payments', 'zarinpal request refused', ['order' => $orderRef, 'code' => $code, 'http' => $res->status()]);
        throw new PaymentGatewayError($code, self::CODES_FA[$code] ?? 'payment gateway refused');
    }

    public function redirectFor(string $authority): array
    {
        return ['redirect_url' => $this->base().'StartPay/'.$authority, 'method' => 'GET', 'fields' => []];
    }

    public function parseCallback(Request $request): array
    {
        $authority = (string) $request->query('Authority', $request->input('Authority', ''));
        $status = strtoupper((string) $request->query('Status', $request->input('Status', '')));

        return [
            'authority' => $this->validAuthority($authority) ? $authority : null,
            // Unauthenticated hint only: BillingService always verifies server-side.
            'status' => $status === 'OK' ? 'OK' : ($status === 'NOK' ? 'CANCELLED' : 'FAILED'),
            'bank_code' => $status === 'OK' ? null : '17',
            'raw' => ['Status' => mb_substr($status, 0, 10)],
        ];
    }

    public function verify(string $authority, string $amountIrr): array
    {
        $unknown = ['status' => 'UNKNOWN', 'ref_id' => null, 'card_mask' => null, 'bank_code' => null, 'amount_irr' => null];
        try {
            $res = $this->http()->post($this->base().'v4/payment/verify.json', [
                'merchant_id' => $this->config['merchant_id'], 'amount' => (int) $amountIrr, 'authority' => $authority,
            ]);
        } catch (ConnectionException) {
            return $unknown;
        }
        if ($res->serverError() || ! is_array($res->json())) {
            return $unknown;
        }
        $data = $res->json('data');
        $code = is_array($data) && isset($data['code']) ? (string) $data['code'] : (string) ($res->json('errors.code') ?? '');
        if (in_array($code, ['100', '101'], true)) {
            return [
                'status' => 'OK', 'ref_id' => isset($data['ref_id']) ? (string) $data['ref_id'] : null,
                'card_mask' => isset($data['card_pan']) ? mb_substr(preg_replace('/[^0-9*\-]/', '', (string) $data['card_pan']), 0, 30) : null,
                // ZarinPal verified the payment against the amount we sent (else -50), so it is the stored amount.
                'bank_code' => $code, 'amount_irr' => $amountIrr,
            ];
        }
        if ($code === '' || in_array($code, self::RETRYABLE, true)) {
            return array_merge($unknown, ['bank_code' => $code ?: null]);
        }

        return ['status' => 'FAILED', 'ref_id' => null, 'card_mask' => null, 'bank_code' => $code === '-50' ? 'AMOUNT_MISMATCH' : $code, 'amount_irr' => null];
    }

    public function formActionHosts(): array
    {
        return []; // GET redirect to StartPay
    }

    private function validAuthority(string $authority): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{10,64}$/', $authority);
    }
}
