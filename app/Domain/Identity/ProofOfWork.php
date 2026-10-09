<?php

namespace App\Domain\Identity;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Hashcash-style challenge solved by the browser before any OTP SMS is sent. It makes
 * scripted SMS pumping/bombing expensive without a third-party captcha. Challenges are
 * single-use, bound to the requesting IP and expire quickly.
 */
final class ProofOfWork
{
    public function issue(string $ip): array
    {
        $challenge = Str::random(32);
        Cache::put('pow:'.$challenge, ['ip' => $ip, 'at' => now()->getTimestamp()], config('talata.pow.ttl_seconds'));

        return ['challenge' => $challenge, 'bits' => $this->bits(), 'issued_at' => now()->getTimestamp()];
    }

    public function bits(): int
    {
        return max(1, (int) config('talata.pow.bits'));
    }

    public function verify(?string $challenge, ?string $nonce, string $ip): bool
    {
        if (! is_string($challenge) || ! is_string($nonce) || ! preg_match('/^[A-Za-z0-9]{32}$/', $challenge) || ! preg_match('/^\d{1,12}$/', $nonce)) {
            return false;
        }
        $data = Cache::get('pow:'.$challenge);
        // Atomic single use: only the first request can claim the marker (parallel replays fail).
        if (! $data || ! Cache::add('pow:used:'.$challenge, 1, config('talata.pow.ttl_seconds'))) {
            return false;
        }
        Cache::forget('pow:'.$challenge);
        if ($data['ip'] !== $ip) {
            return false;
        }
        if (now()->getTimestamp() - $data['at'] < config('talata.pow.min_form_seconds')) {
            return false;
        }

        return self::leadingZeroBits(hash('sha256', $challenge.':'.$nonce, true)) >= $this->bits();
    }

    public static function leadingZeroBits(string $binary): int
    {
        $bits = 0;
        foreach (str_split($binary) as $byte) {
            $b = ord($byte);
            if ($b === 0) {
                $bits += 8;

                continue;
            }
            $bits += 7 - (int) floor(log($b, 2));
            break;
        }

        return $bits;
    }
}
