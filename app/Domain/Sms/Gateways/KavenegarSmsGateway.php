<?php

namespace App\Domain\Sms\Gateways;

use App\Domain\Sms\SmsGateway;
use App\Support\TechLog;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Kavenegar (kavenegar.com) REST adapter.
 *  - sms/send.json         invoice SMS and reminders
 *  - verify/lookup.json    login codes through an approved template (%token), when configured
 *  - sms/status.json       delivery reconciliation
 * Ambiguous outcomes (network errors, 5xx) are reported as UNKNOWN and never retried blindly.
 */
final class KavenegarSmsGateway implements SmsGateway
{
    /** Return codes that mean "definitely not sent" (request/account/recipient problems). */
    private const DEFINITE_FAILURE = [400, 401, 402, 403, 404, 405, 406, 407, 409, 411, 412, 413, 414, 415, 416, 417, 418, 419, 420, 422, 424, 426, 427, 428, 431, 432, 451, 501];

    /** Message entry status → ours. 1 queue, 2 scheduled, 4/5 sent to operator, 6 failed, 10 delivered, 11 undelivered, 13 cancelled, 14 blocked by recipient, 100 unknown id. */
    private const ENTRY_STATUS = [1 => 'SENT', 2 => 'SENT', 4 => 'SENT', 5 => 'SENT', 6 => 'FAILED', 10 => 'DELIVERED', 11 => 'UNDELIVERED', 13 => 'FAILED', 14 => 'UNDELIVERED', 100 => 'UNKNOWN'];

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $sender,
        private readonly ?string $otpTemplate,
        private readonly string $baseUrl = 'https://api.kavenegar.com/v1',
        private readonly int $timeout = 10,
        private readonly ?string $mobileChangeTemplate = null,
    ) {
        if ($apiKey === '') {
            throw new \RuntimeException('KAVENEGAR_API_KEY is not set');
        }
    }

    public function name(): string
    {
        return 'kavenegar';
    }

    public function send(string $recipient, string $body, ?string $localId = null): array
    {
        return $this->call('sms/send.json', array_filter(['receptor' => $recipient, 'sender' => $this->sender, 'message' => $body, 'localid' => $localId]), 'send');
    }

    public function sendOtp(string $recipient, string $code, string $body, string $kind = 'login'): array
    {
        // Never fall back to the login template for another kind: its text says «کد ورود».
        $template = $kind === 'login' ? $this->otpTemplate : ($kind === 'mobile_change' ? $this->mobileChangeTemplate : null);
        if (! $template) {
            return $this->send($recipient, $body);
        }

        return $this->call('verify/lookup.json', ['receptor' => $recipient, 'token' => $code, 'template' => $template], 'lookup');
    }

    public function status(string $providerId): string
    {
        $r = $this->request('sms/status.json', ['messageid' => $providerId], 'status');
        if ($r['http_ok'] && ($r['json']['return']['status'] ?? null) === 200) {
            $code = (int) ($r['json']['entries'][0]['status'] ?? 100);

            return self::ENTRY_STATUS[$code] ?? 'UNKNOWN';
        }

        return 'UNKNOWN';
    }

    /**
     * Kavenegar's own delivery code and Persian status text per message id (diagnostics only; read-only).
     *
     * @return array<string, array{status:int, text:string}>
     */
    public function rawStatus(array $providerIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter($providerIds))), 200) as $chunk) {
            $r = $this->request('sms/status.json', ['messageid' => implode(',', $chunk)], 'status');
            foreach ((array) ($r['json']['entries'] ?? []) as $e) {
                if (isset($e['messageid'])) {
                    $out[(string) $e['messageid']] = ['status' => (int) ($e['status'] ?? 100), 'text' => (string) ($e['statustext'] ?? '')];
                }
            }
        }

        return $out;
    }

    public function statusMany(array $providerIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($providerIds)), 200) as $chunk) {
            $r = $this->request('sms/status.json', ['messageid' => implode(',', $chunk)], 'status');
            foreach ($chunk as $id) {
                $out[$id] = 'UNKNOWN';
            }
            if ($r['http_ok'] && ($r['json']['return']['status'] ?? null) === 200) {
                foreach ((array) ($r['json']['entries'] ?? []) as $e) {
                    if (isset($e['messageid'])) {
                        $out[(string) $e['messageid']] = self::ENTRY_STATUS[(int) ($e['status'] ?? 100)] ?? 'UNKNOWN';
                    }
                }
            }
        }

        return $out;
    }

    public function lookupLocal(string $localId): ?array
    {
        $r = $this->request('sms/statuslocalmessageid.json', ['localid' => $localId], 'status-local');
        $e = $r['json']['entries'][0] ?? null;
        if (! $r['http_ok'] || ($r['json']['return']['status'] ?? null) !== 200 || ! isset($e['messageid']) || (int) ($e['status'] ?? 100) === 100) {
            return null;
        }

        return ['status' => self::ENTRY_STATUS[(int) $e['status']] ?? 'UNKNOWN', 'provider_id' => (string) $e['messageid']];
    }

    /**
     * Key check for `talata:preflight --live`: account/info.json sends no SMS and costs nothing. Returns the
     * remaining credit (rial) when the key is valid.
     *
     * @return array{ok:bool,credit_irr:?int,expires:?int,error:?string}
     */
    public function accountInfo(): array
    {
        $r = $this->request('account/info.json', [], 'account-info');
        $code = (int) ($r['json']['return']['status'] ?? 0);
        if ($r['http_ok'] && $code === 200) {
            $e = $r['json']['entries'] ?? [];

            return ['ok' => true, 'credit_irr' => isset($e['remaincredit']) ? (int) $e['remaincredit'] : null, 'expires' => isset($e['expiredate']) ? (int) $e['expiredate'] : null, 'error' => null];
        }

        return ['ok' => false, 'credit_irr' => null, 'expires' => null, 'error' => $r['error'] ?? ('kavenegar '.$code.': '.mb_substr((string) ($r['json']['return']['message'] ?? ''), 0, 120))];
    }

    private function call(string $path, array $params, string $op): array
    {
        $r = $this->request($path, $params, $op);
        if (! $r['http_ok'] && $r['json'] === null) {
            return ['status' => 'UNKNOWN', 'provider_id' => null, 'error' => $r['error'] ?? 'kavenegar: no response'];
        }
        $code = (int) ($r['json']['return']['status'] ?? 0);
        $message = (string) ($r['json']['return']['message'] ?? '');
        if ($code === 200) {
            $entry = $r['json']['entries'][0] ?? [];
            $id = isset($entry['messageid']) ? (string) $entry['messageid'] : null;
            $status = self::ENTRY_STATUS[(int) ($entry['status'] ?? 1)] ?? 'SENT';
            if (in_array($status, ['DELIVERED', 'UNDELIVERED', 'UNKNOWN'], true)) {
                $status = 'SENT';
            }

            return ['status' => $id ? $status : 'UNKNOWN', 'provider_id' => $id, 'error' => $status === 'FAILED' ? 'kavenegar entry status '.($entry['status'] ?? '?') : null];
        }
        if (in_array($code, self::DEFINITE_FAILURE, true)) {
            return ['status' => 'FAILED', 'provider_id' => null, 'error' => "kavenegar {$code}: ".mb_substr($message, 0, 120)];
        }

        return ['status' => 'UNKNOWN', 'provider_id' => null, 'error' => "kavenegar {$code}: ".mb_substr($message, 0, 120)];
    }

    /** @return array{http_ok:bool,json:?array,error:?string} */
    private function request(string $path, array $params, string $op): array
    {
        $url = rtrim($this->baseUrl, '/').'/'.$this->apiKey.'/'.$path;
        $started = microtime(true);
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout($this->timeout)->post($url, $params);
            $json = $response->json();
            $ms = (int) ((microtime(true) - $started) * 1000);
            TechLog::write($response->successful() ? 'info' : 'warning', 'kavenegar', "kavenegar {$op}", [
                'http' => $response->status(), 'return' => $json['return']['status'] ?? null, 'ms' => $ms,
                'receptor' => isset($params['receptor']) ? TechLog::scrub($params['receptor']) : null,
                'messageid' => $json['entries'][0]['messageid'] ?? ($params['messageid'] ?? null),
            ]);

            return ['http_ok' => $response->successful(), 'json' => is_array($json) ? $json : null, 'error' => null];
        } catch (Throwable $e) {
            $error = TechLog::scrub(str_replace($this->apiKey, '[redacted]', $e->getMessage()));
            TechLog::error('kavenegar', "kavenegar {$op} failed", ['error' => mb_substr($error, 0, 300), 'ms' => (int) ((microtime(true) - $started) * 1000)]);

            return ['http_ok' => false, 'json' => null, 'error' => 'kavenegar network: '.mb_substr($error, 0, 150)];
        }
    }
}
