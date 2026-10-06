<?php

namespace App\Domain\Identity;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Sms\SmsService;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\Digits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Login OTP with layered abuse controls: proof-of-work, honeypot, Iranian-mobile-only,
 * per-mobile cooldown/hour/day, per-IP and per-subnet hourly limits, a global daily SMS budget
 * (circuit breaker) and a lockout after repeated wrong codes. Codes are stored as HMACs only.
 */
final class OtpService
{
    public function __construct(private readonly SmsService $sms) {}

    /** @return array{challenge_id:string,resend_after_seconds:int} */
    public function request(string $mobile, string $ip): array
    {
        $cfg = config('talata.otp');

        $code = str_pad((string) random_int(0, 10 ** $cfg['length'] - 1), $cfg['length'], '0', STR_PAD_LEFT);

        // Serialize per mobile so parallel requests cannot all pass the cooldown, and count
        // hit-first (atomic increment) so parallel requests cannot all pass the rate limits.
        // Rejections are returned, not thrown, so the counted attempts are committed.
        $result = DB::transaction(function () use ($mobile, $ip, $code, $cfg) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['otp:'.$mobile]);

            if (Cache::get('otp:lock:'.$mobile)) {
                return new DomainError('OTP_LOCKED', 'به‌خاطر تلاش‌های ناموفق، ورود این شماره چند دقیقه قفل شد. کمی بعد دوباره امتحان کنید.', 429);
            }
            $last = OtpChallenge::query()->where('mobile', $mobile)->latest('created_at')->first();
            if ($last && $last->created_at->gt(now()->subSeconds($cfg['resend_cooldown_seconds']))) {
                $wait = (int) max(1, $cfg['resend_cooldown_seconds'] - $last->created_at->diffInSeconds(now()));

                return new DomainError('OTP_COOLDOWN', 'کد قبلی تازه فرستاده شده است. '.Digits::toPersian((string) $wait).' ثانیه دیگر دوباره امتحان کنید.', 429, ['retry_after_seconds' => $wait]);
            }
            $checks = [
                ['otp:m:h:'.$mobile, $cfg['per_mobile_hour'], 3600],
                ['otp:m:d:'.$mobile, $cfg['per_mobile_day'], 86400],
                ['otp:ip:h:'.$ip, $cfg['per_ip_hour'], 3600],
                ['otp:net:h:'.self::subnet($ip), $cfg['per_subnet_hour'], 3600],
            ];
            foreach ($checks as [$key, $max, $decay]) {
                if (RateLimiter::hit($key, $decay) > $max) {
                    return new DomainError('OTP_RATE_LIMITED', 'تعداد درخواست کد زیاد شد. '.Digits::toPersian((string) max(1, (int) ceil(RateLimiter::availableIn($key) / 60))).' دقیقه دیگر دوباره امتحان کنید.', 429);
                }
            }

            // Existing users have their own reserved budget so a flood of new numbers cannot lock shops out.
            $existing = User::query()->where('mobile', $mobile)->exists();
            $budget = $existing ? $cfg['existing_users_daily_budget'] : $cfg['global_daily_budget'];
            $budgetKey = 'otp:budget:'.($existing ? 'existing:' : 'new:').now()->format('Ymd');
            Cache::add($budgetKey, 0, 90000);
            if (Cache::increment($budgetKey) > $budget) {
                Log::critical('OTP daily budget exhausted', ['pool' => $existing ? 'existing' : 'new', 'budget' => $budget]);

                return new DomainError('OTP_BUDGET', 'ارسال کد موقتاً ممکن نیست. چند دقیقه دیگر دوباره امتحان کنید.', 503);
            }

            OtpChallenge::query()->where('mobile', $mobile)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $c = OtpChallenge::create([
                'mobile' => $mobile, 'code_hash' => 'pending', 'ip' => $ip,
                'expires_at' => now()->addSeconds($cfg['ttl_seconds']), 'created_at' => now(),
            ]);
            $c->code_hash = self::hash($c->id, $code);
            $c->save();

            return $c;
        });
        if ($result instanceof DomainError) {
            throw $result;
        }
        $challenge = $result;

        $this->sms->queueOtp($mobile, $code, $challenge->id);
        Audit::record('auth.otp_requested', null, ['mobile_tail' => substr($mobile, -4)], null, 'system');

        return ['challenge_id' => $challenge->id, 'resend_after_seconds' => $cfg['resend_cooldown_seconds']];
    }

    /** Returns the verified mobile, or throws. Attempts are counted atomically. */
    public function verify(string $challengeId, string $code, string $ip): string
    {
        $cfg = config('talata.otp');
        if (RateLimiter::tooManyAttempts('otp:verify:'.$ip, $cfg['verify_per_ip_minute'])) {
            throw new DomainError('OTP_VERIFY_RATE', 'تعداد تلاش زیاد است. یک دقیقه صبر کنید.', 429);
        }
        RateLimiter::hit('otp:verify:'.$ip, 60);

        $code = Digits::toLatin($code);
        if (! preg_match('/^\d{'.$cfg['length'].'}$/', $code) || ! preg_match('/^[0-9a-z]{26}$/i', $challengeId)) {
            throw new DomainError('OTP_WRONG', 'کد درست نیست. یک بار دیگر به پیامک نگاه کنید.', 422);
        }

        // Decide inside the lock, commit, then throw: an exception inside the transaction would
        // roll back the attempt counter and lockout, leaving the code open to brute force.
        $outcome = DB::transaction(function () use ($challengeId, $code, $cfg) {
            $c = OtpChallenge::query()->whereKey($challengeId)->lockForUpdate()->first();
            if ($c && Cache::get('otp:lock:'.$c->mobile)) {
                return ['LOCKED'];
            }
            if (! $c || $c->consumed_at || $c->expires_at->isPast()) {
                return ['EXPIRED'];
            }
            $c->attempts++;
            if (! hash_equals($c->code_hash, self::hash($c->id, $code))) {
                if ($c->attempts >= $cfg['max_attempts']) {
                    $c->consumed_at = now();
                    $c->save();
                    Cache::put('otp:lock:'.$c->mobile, true, $cfg['lockout_minutes'] * 60);

                    return ['LOCKED'];
                }
                $c->save();

                return ['WRONG', $cfg['max_attempts'] - $c->attempts];
            }
            $c->consumed_at = now();
            $c->save();

            return ['OK', $c->mobile];
        });

        if ($outcome[0] !== 'OK') {
            Audit::record($outcome[0] === 'LOCKED' ? 'auth.otp_locked' : 'auth.otp_wrong', null, ['outcome' => strtolower($outcome[0])], null, 'system');
        }

        return match ($outcome[0]) {
            'OK' => $outcome[1],
            'EXPIRED' => throw new DomainError('OTP_EXPIRED', 'این کد دیگر معتبر نیست. کد تازه بگیرید.', 422),
            'LOCKED' => throw new DomainError('OTP_LOCKED', 'تلاش‌های ناموفق زیاد شد. '.Digits::toPersian((string) $cfg['lockout_minutes']).' دقیقه دیگر دوباره امتحان کنید.', 429),
            default => throw new DomainError('OTP_WRONG', 'کد درست نیست. یک بار دیگر به پیامک نگاه کنید ('.Digits::toPersian((string) $outcome[1]).' تلاش دیگر).', 422, ['attempts_left' => $outcome[1]]),
        };
    }

    public static function hash(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$code, (string) config('app.key'));
    }

    public static function subnet(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_slice(explode('.', $ip), 0, 3)).'.0/24';
        }
        $packed = @inet_pton($ip);

        return $packed ? bin2hex(substr($packed, 0, 6)).'::/48' : 'unknown';
    }
}
