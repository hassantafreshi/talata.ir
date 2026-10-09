<?php

namespace App\Providers;

use App\Domain\Billing\Gateways\ZarinpalGateway;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\PaymentGateways;
use App\Domain\Market\BrsApiQuoteProvider;
use App\Domain\Market\DemoQuoteProvider;
use App\Domain\Market\QuoteProvider;
use App\Domain\Plans\CommercialConfig;
use App\Domain\Pricing\PolicyRegistry;
use App\Domain\Sms\Gateways\FakeSmsGateway;
use App\Domain\Sms\Gateways\KavenegarSmsGateway;
use App\Domain\Sms\Gateways\LogSmsGateway;
use App\Domain\Sms\SmsGateway;
use App\Support\TechLog;
use App\Tenancy\TenantContext;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(CommercialConfig::class);
        $this->app->singleton(PolicyRegistry::class, fn () => PolicyRegistry::default());

        $this->app->singleton(QuoteProvider::class, fn () => match (config('talata.drivers.quotes')) {
            'demo' => new DemoQuoteProvider,
            'brsapi' => new BrsApiQuoteProvider((string) config('services.brsapi.key'), (string) config('services.brsapi.url'), (int) config('services.brsapi.timeout', 10)),
            default => throw new RuntimeException('Unknown quote driver'),
        });

        $this->app->singleton(SmsGateway::class, function () {
            $driver = config('talata.drivers.sms');
            if (in_array($driver, ['log', 'fake'], true) && $this->app->isProduction() && ! config('talata.sms_dev_driver_allowed_in_production')) {
                // A log/fake driver in production would silently drop every login code.
                throw new RuntimeException('TALATA_SMS_DRIVER must be a real provider (kavenegar) in production');
            }

            return match ($driver) {
                'log' => new LogSmsGateway,
                'fake' => new FakeSmsGateway,
                'kavenegar' => new KavenegarSmsGateway(
                    (string) config('services.kavenegar.api_key'), config('services.kavenegar.sender') ?: null,
                    config('services.kavenegar.otp_template') ?: null, (string) config('services.kavenegar.base_url'), (int) config('services.kavenegar.timeout'),
                    config('services.kavenegar.otp_template_mobile_change') ?: null,
                ),
                default => throw new RuntimeException('Unknown SMS driver'),
            };
        });

        // PSP adapters: registry by code; new payments use TALATA_PAYMENT_DRIVER (docs/PAYMENTS_AND_SMS_CREDIT.md).
        $this->app->singleton(PaymentGateways::class, fn () => new PaymentGateways(
            config('talata.payments.gateways'), (string) config('talata.drivers.payment'),
            $this->app->isProduction(), (bool) config('talata.payments.mock_allowed_in_production'),
        ));
        $this->app->bind(PaymentGateway::class, fn () => $this->app->make(PaymentGateways::class)->default());
        $this->app->bind(ZarinpalGateway::class, fn () => new ZarinpalGateway(config('talata.payments.zarinpal')));
    }

    public function boot(): void
    {
        // Failed queue jobs are visible per service in the admin technical log.
        Queue::failing(function (JobFailed $e) {
            TechLog::error('queue', 'job failed: '.$e->job->resolveName(), ['queue' => $e->job->getQueue(), 'error' => mb_substr($e->exception->getMessage(), 0, 300)]);
        });
        RouteLimits::register();
        // Overrides the bootstrap default with the cached config value (env() is empty after config:cache).
        TrustProxies::at(config('talata.trusted_proxies'));
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
            // Cookies only over HTTPS unless explicitly configured (talata:preflight fails on false).
            if (config('session.secure') === null) {
                config(['session.secure' => true]);
            }
        }
        View::composer('*', function ($view) {
            $view->with('tenantContext', app(TenantContext::class));
        });
    }
}
