<?php

namespace App\Domain\Plans;

use App\Models\PricingVersion;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Active versioned commercial configuration (prices, quotas, capabilities, VAT, SMS credit). */
final class CommercialConfig
{
    private ?array $payload = null;

    private ?int $version = null;

    public function payload(): array
    {
        if ($this->payload === null) {
            $row = Cache::remember('talata.pricing.active', 60, fn () => PricingVersion::query()
                ->where('status', 'published')->where('effective_from', '<=', now())
                ->orderByDesc('effective_from')->orderByDesc('version')->first()?->only(['version', 'payload']));
            if (! $row) {
                throw new RuntimeException('No published pricing version. Run the database seeder.');
            }
            $this->payload = $row['payload'];
            $this->version = (int) $row['version'];
        }

        return $this->payload;
    }

    public function version(): int
    {
        $this->payload();

        return (int) $this->version;
    }

    public function plan(string $code): array
    {
        return $this->payload()['plans'][$code] ?? throw new RuntimeException("Unknown plan {$code}");
    }

    /** @return list<string> */
    public function planCodes(): array
    {
        return array_keys($this->payload()['plans']);
    }

    public function vatRatePercent(): string
    {
        return (string) $this->payload()['tax']['vat_rate_percent'];
    }

    public function sms(): array
    {
        return $this->payload()['sms_credit'];
    }

    public function forget(): void
    {
        $this->payload = null;
        Cache::forget('talata.pricing.active');
    }
}
