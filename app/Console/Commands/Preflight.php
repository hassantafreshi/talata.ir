<?php

namespace App\Console\Commands;

use App\Domain\Billing\PaymentGateways;
use App\Domain\Sms\Gateways\KavenegarSmsGateway;
use App\Domain\Sms\SmsGateway;
use App\Models\StaffUser;
use App\Support\ScheduleMonitor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Release gate (docs/DEPLOYMENT.md): checks the settings that make production safe and exits
 * non-zero when any blocking check fails. Run after `config:cache` on every deploy.
 */
class Preflight extends Command
{
    protected $signature = 'talata:preflight
        {--allow-warnings : exit 0 even when non-blocking warnings exist}
        {--live : also contact providers without side effects (Kavenegar account/info, ZarinPal reachability) and check the scheduler heartbeat}';

    protected $description = 'Check production settings before go-live (blocking errors exit with code 1)';

    /** @var list<array{0:string,1:string,2:string}> level, check, detail */
    private array $rows = [];

    public function handle(PaymentGateways $gateways): int
    {
        $prod = app()->environment('production');
        $url = (string) config('app.url');
        $public = (string) config('talata.public_url');

        $this->check(! $prod ? 'warn' : 'ok', 'APP_ENV', $prod ? 'production' : 'not production: '.app()->environment());
        $this->check(config('app.debug') ? 'fail' : 'ok', 'APP_DEBUG', config('app.debug') ? 'must be false (stack traces leak data)' : 'off');
        $this->check(strlen((string) config('app.key')) >= 32 ? 'ok' : 'fail', 'APP_KEY', 'set; back it up — invoice QR tokens are encrypted with it');
        $this->check(str_starts_with($url, 'https://') ? 'ok' : 'fail', 'APP_URL', $url ?: 'missing');
        $this->check(str_starts_with($public, 'https://') ? 'ok' : 'fail', 'TALATA_PUBLIC_URL', ($public ?: 'missing').' (printed QR links; never change without redirects)');
        $this->check(config('session.secure') === true ? 'ok' : 'fail', 'SESSION_SECURE_COOKIE', 'must be true (cookies only over HTTPS)');
        $this->check(config('session.http_only') ? 'ok' : 'fail', 'SESSION_HTTP_ONLY', 'must be true');
        $this->check(in_array(config('session.same_site'), ['lax', 'strict'], true) ? 'ok' : 'fail', 'SESSION_SAME_SITE', (string) config('session.same_site'));
        $this->check(in_array(config('session.driver'), ['database', 'redis'], true) ? 'ok' : 'warn', 'SESSION_DRIVER', (string) config('session.driver'));
        $this->check(config('queue.default') !== 'sync' ? 'ok' : 'fail', 'QUEUE_CONNECTION', 'must not be sync (SMS and payments run in the worker)');
        $this->check(! in_array(config('cache.default'), ['array', 'null'], true) ? 'ok' : 'fail', 'CACHE_STORE', 'rate limits and the quote lock need a shared cache');
        $proxies = (string) config('talata.trusted_proxies');
        // «*» lets anyone who reaches the app directly forge X-Forwarded-For and dodge every per-IP limit
        // and the admin IP allowlist: list the load balancer addresses instead.
        $this->check(str_contains($proxies, '*') ? 'fail' : 'ok', 'TRUSTED_PROXIES', $proxies.(str_contains($proxies, '*') ? ' — never «*»; list the proxy/LB addresses' : ' (client IP only through these proxies)'));
        $stack = implode(',', (array) config('logging.channels.stack.channels'));
        $this->check(str_contains($stack, 'errors_db') ? 'ok' : 'warn', 'LOG_STACK', $stack.' (errors_db shows errors in the admin console)');

        try {
            $gateway = $gateways->default();
            $this->check($gateway->isMock() ? 'fail' : 'ok', 'TALATA_PAYMENT_DRIVER', $gateway->code().($gateway->isMock() ? ' is the mock gateway' : ''));
            if ($gateway->code() === 'zarinpal') {
                $this->check(config('talata.payments.zarinpal.sandbox') ? ($prod ? 'fail' : 'warn') : 'ok', 'TALATA_ZARINPAL_SANDBOX', config('talata.payments.zarinpal.sandbox') ? 'sandbox' : 'live');
                $this->check('ok', 'ZarinPal callback URL', route('pay.callback', 'zarinpal').' (its domain must match the one registered in the ZarinPal panel)');
                if ($this->option('live')) {
                    $this->zarinpalReachable();
                }
            }
        } catch (Throwable $e) {
            $this->check('fail', 'TALATA_PAYMENT_DRIVER', mb_substr($e->getMessage(), 0, 160));
        }
        $sms = (string) config('talata.drivers.sms');
        $this->check(in_array($sms, ['log', 'fake'], true) ? 'fail' : 'ok', 'TALATA_SMS_DRIVER', $sms.(in_array($sms, ['log', 'fake'], true) ? ': no real SMS, nobody can sign in' : ''));
        if ($sms === 'kavenegar') {
            $this->check(config('services.kavenegar.api_key') ? 'ok' : 'fail', 'KAVENEGAR_API_KEY', config('services.kavenegar.api_key') ? 'set' : 'missing');
            $this->check(config('services.kavenegar.otp_template') ? 'ok' : 'warn', 'KAVENEGAR_OTP_TEMPLATE', config('services.kavenegar.otp_template') ? 'set' : 'unset: login codes use sms/send');
            $this->check(config('services.kavenegar.otp_template_mobile_change') ? 'ok' : 'warn', 'KAVENEGAR_OTP_TEMPLATE_MOBILE_CHANGE', config('services.kavenegar.otp_template_mobile_change') ? 'set' : 'unset: number-change codes use sms/send with Zarlio wording');
            $this->check(config('services.kavenegar.sender') ? 'ok' : 'warn', 'KAVENEGAR_SENDER', config('services.kavenegar.sender') ? 'set' : 'unset: invoice SMS use the account default line');
            if ($this->option('live') && config('services.kavenegar.api_key')) {
                $this->kavenegarKey();
            }
        }
        $quotes = (string) config('talata.drivers.quotes');
        $this->check($quotes === 'demo' ? 'warn' : 'ok', 'TALATA_QUOTE_DRIVER', $quotes === 'demo' ? 'demo numbers (labelled «نمونه») until a real provider is connected' : $quotes);
        $this->check(config('talata.webauthn.rp_id') ? 'ok' : 'warn', 'TALATA_WEBAUTHN_RP_ID', config('talata.webauthn.rp_id') ?: 'unset: falls back to the APP_URL host');
        $this->check(config('talata.admin.require_passkey') ? 'ok' : 'fail', 'TALATA_ADMIN_REQUIRE_PASSKEY', config('talata.admin.require_passkey') ? 'staff must add a passkey' : 'off: the admin console accepts SMS-only sign-in');
        $this->check(config('talata.payments.mock_allowed_in_production') ? 'fail' : 'ok', 'TALATA_ALLOW_MOCK_PAYMENTS_IN_PRODUCTION', 'must be false');
        $this->check(config('talata.sms_dev_driver_allowed_in_production') ? 'fail' : 'ok', 'TALATA_ALLOW_DEV_SMS_IN_PRODUCTION', 'must be false');
        $hb = (string) config('talata.ops.backup_heartbeat_file');
        $this->check($hb !== '' ? 'ok' : 'warn', 'TALATA_BACKUP_HEARTBEAT_FILE', $hb !== '' ? $hb : 'unset: the admin console cannot show the last database backup');

        try {
            $admins = StaffUser::query()->where('role', 'admin')->where('active', true)->count();
            $this->check($admins > 0 ? 'ok' : 'fail', 'Admin staff', $admins.' active admin(s); create one with php artisan talata:staff');
            $migrator = app('migrator');
            $pending = array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $migrator->getRepository()->getRan());
            $this->check($pending ? 'fail' : 'ok', 'Migrations', $pending ? count($pending).' pending: php artisan migrate --force' : 'up to date');
        } catch (Throwable $e) {
            $this->check('fail', 'Database', mb_substr($e->getMessage(), 0, 160));
        }
        foreach ([storage_path('app'), storage_path('framework'), storage_path('logs'), base_path('bootstrap/cache')] as $dir) {
            $this->check(is_writable($dir) ? 'ok' : 'fail', 'Writable '.str_replace(base_path().'/', '', $dir), is_writable($dir) ? 'yes' : 'no');
        }
        $this->check(is_file(public_path('build/manifest.json')) ? 'ok' : 'fail', 'Front-end build', is_file(public_path('build/manifest.json')) ? 'public/build present' : 'run npm ci && npm run build');
        $this->check(! is_file(public_path('hot')) ? 'ok' : 'fail', 'Vite dev server', is_file(public_path('hot')) ? 'public/hot exists: remove it' : 'not in use');

        if ($this->option('live')) {
            $this->schedulerHeartbeat();
        }

        $this->table(['', 'Check', 'Detail'], array_map(fn ($r) => [['ok' => 'ok', 'warn' => 'warn', 'fail' => 'FAIL'][$r[0]], $r[1], $r[2]], $this->rows));
        $fails = count(array_filter($this->rows, fn ($r) => $r[0] === 'fail'));
        $warns = count(array_filter($this->rows, fn ($r) => $r[0] === 'warn'));
        $this->line("{$fails} blocking, {$warns} warning(s).");

        return $fails > 0 || ($warns > 0 && ! $this->option('allow-warnings')) ? self::FAILURE : self::SUCCESS;
    }

    /** account/info.json: validates the key and shows the credit; sends no SMS. */
    private function kavenegarKey(): void
    {
        try {
            $gateway = app(SmsGateway::class);
            if (! $gateway instanceof KavenegarSmsGateway) {
                return;
            }
            $info = $gateway->accountInfo();
            $this->check($info['ok'] ? 'ok' : 'fail', 'Kavenegar (live)', $info['ok']
                ? 'key valid; credit '.number_format((int) $info['credit_irr']).' rial'
                : 'key rejected or unreachable: '.$info['error']);
        } catch (Throwable $e) {
            $this->check('fail', 'Kavenegar (live)', mb_substr($e->getMessage(), 0, 160));
        }
    }

    /** Only reachability (any HTTP answer). A payment request would create a real authority, so none is made. */
    private function zarinpalReachable(): void
    {
        $host = config('talata.payments.zarinpal.sandbox') ? 'https://sandbox.zarinpal.com/' : 'https://payment.zarinpal.com/';
        try {
            $status = Http::connectTimeout(5)->timeout(10)->get($host)->status();
            $this->check('ok', 'ZarinPal (live)', "{$host} reachable (HTTP {$status})");
        } catch (Throwable $e) {
            $this->check('fail', 'ZarinPal (live)', "{$host} unreachable from this server: ".mb_substr($e->getMessage(), 0, 120));
        }
    }

    /** Payments/SMS reconciliation and the quote feed depend on the scheduler (cron every minute). */
    private function schedulerHeartbeat(): void
    {
        $last = ScheduleMonitor::last('payments-reconcile')['at'];
        $fresh = $last && now()->diffInSeconds(Carbon::parse($last)) <= 300;
        $this->check($fresh ? 'ok' : 'fail', 'Scheduler (live)', $last ? "payments-reconcile last ran {$last}" : 'never ran: add the cron entry «* * * * * php artisan schedule:run»');
    }

    private function check(string $level, string $name, string $detail): void
    {
        $this->rows[] = [$level, $name, $detail];
    }
}
