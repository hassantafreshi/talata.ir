<?php

namespace App\Domain\Billing;

use RuntimeException;

/**
 * Registry of PSP adapters (config('talata.payments.gateways'): code => class). New payments use the default
 * driver (TALATA_PAYMENT_DRIVER); callbacks, verification and reconciliation always use the adapter that opened
 * the payment (PaymentAttempt.gateway), so switching PSP never strands a payment that is still in flight.
 */
final class PaymentGateways
{
    /** @var array<string,PaymentGateway> */
    private array $made = [];

    public function __construct(private array $classes, private readonly string $default, private readonly bool $production, private readonly bool $mockInProduction) {}

    public function default(): PaymentGateway
    {
        return $this->for($this->default) ?? throw new RuntimeException("Unknown payment driver [{$this->default}]");
    }

    public function for(string $code): ?PaymentGateway
    {
        if (! isset($this->classes[$code])) {
            return null;
        }
        if (! isset($this->made[$code])) {
            $gateway = app($this->classes[$code]);
            if ($gateway->isMock() && $this->production && ! $this->mockInProduction) {
                throw new RuntimeException('Mock payment gateway is disabled in production. Set TALATA_PAYMENT_DRIVER=zarinpal (or another real PSP).');
            }
            $this->made[$code] = $gateway;
        }

        return $this->made[$code];
    }

    /** Registers a ready adapter instance under a code (tests, or a runtime-configured PSP). */
    public function extend(string $code, PaymentGateway $gateway): void
    {
        $this->classes[$code] ??= $gateway::class;
        $this->made[$code] = $gateway;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->classes);
    }
}
