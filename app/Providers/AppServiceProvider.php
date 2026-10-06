<?php

namespace App\Providers;

use App\Domain\Billing\Gateways\MockGateway;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Market\DemoQuoteProvider;
use App\Domain\Market\QuoteProvider;
use App\Domain\Plans\CommercialConfig;
use App\Domain\Pricing\PolicyRegistry;
use App\Domain\Sms\Gateways\FakeSmsGateway;
use App\Domain\Sms\Gateways\LogSmsGateway;
use App\Domain\Sms\SmsGateway;
use App\Tenancy\TenantContext;
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

        $this->app->singleton(SmsGateway::class, fn () => match (config('talata.drivers.sms')) {
            'log' => new LogSmsGateway,
            'fake' => new FakeSmsGateway,
            default => throw new RuntimeException('Unknown SMS driver'),
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
        RouteLimits::register();
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
        View::composer('*', function ($view) {
            $view->with('tenantContext', app(TenantContext::class));
        });
    }
}
