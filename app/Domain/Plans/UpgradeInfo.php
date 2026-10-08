<?php

namespace App\Domain\Plans;

use App\Support\Digits;
use App\Support\Money;

/**
 * What the «ارتقا» sheet says about a locked capability: which plans include it and the cheapest monthly
 * price among them, read from the active commercial config (no plan names in code).
 */
final class UpgradeInfo
{
    /** @return array{plans: string, price_fa: ?string} */
    public static function for(string $capability): array
    {
        $config = app(CommercialConfig::class);
        $labels = [];
        $cheapest = null;
        foreach ($config->planCodes() as $code) {
            $plan = $config->plan($code);
            if (! ($plan['capabilities'][$capability] ?? false)) {
                continue;
            }
            $labels[] = (string) ($plan['label_fa'] ?? $code);
            $monthly = (string) ($plan['price_toman']['monthly'] ?? '');
            if ($monthly !== '' && $monthly !== '0' && ($cheapest === null || (int) $monthly < (int) $cheapest)) {
                $cheapest = $monthly;
            }
        }

        return ['plans' => implode(' و ', $labels), 'price_fa' => $cheapest ? Money::toman((string) ((int) $cheapest * 10)) : null];
    }

    public static function plansFa(string $capability): string
    {
        return Digits::toPersian(self::for($capability)['plans'] ?: 'پلن‌های بالاتر');
    }
}
