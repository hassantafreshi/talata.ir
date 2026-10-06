<?php

namespace App\Providers;

use App\Domain\Billing\Gateways\MockGateway;
use App\Domain\Billing\PaymentGateway;
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
                ),
                default => throw new RuntimeException('Unknown SMS driver'),
            };
        });

        $this->app->singleton(PaymentGateway::class, function () {
            $driver = config('talata.drivers.payment');
            if ($driver === 'mock') {
                if ($this->app->isProduction() && ! config('talata.payments.mock_allowed_in_production')) {
                    throw new RuntimeException('Mock payment gateway is disabled in production. Configure a real PSP adapter.');
                }

                return new MockGateway;
            }
            throw new RuntimeException('Unknown payment driver');
        });
    }

    public function boot(): void
    {
        // Failed queue jobs are visible per service in the admin technical log.
        Queue::failing(function (JobFailed $e) {
            TechLog::error('queue', 'job failed: '.$e->job->resolveName(), ['queue' => $e->job->getQueue(), 'error' => mb_substr($e->exception->getMessage(), 0, 300)]);
        });
        RouteLimits::register();
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
        View::composer('*', function ($view) {
            $view->with('tenantContext', app(TenantContext::class));
        });
    }
}
