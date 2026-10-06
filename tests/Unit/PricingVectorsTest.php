<?php

namespace Tests\Unit;

use App\Domain\Pricing\GoldIrV1;
use App\Domain\Pricing\ManualLineV1;
use App\Domain\Pricing\PricingError;
use App\Support\Digits;
use App\Support\Money;
use Brick\Math\BigInteger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every vector in docs/design/contracts/calculation-vectors.json must pass (browser has the same test). */
class PricingVectorsTest extends TestCase
{
    public static function vectors(): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/../../docs/design/contracts/calculation-vectors.json'), true);
        $out = [];
        foreach ($json['vectors'] as $vector) {
            $out[$vector['id']] = [$vector, $json['vectors']];
        }

        return $out;
    }

    #[DataProvider('vectors')]
    public function test_vector(array $vector, array $all): void
    {
        $in = $vector['input'];
        $expected = $vector['expected'];
        $policy = new GoldIrV1;

        if (isset($in['rows'])) {
            $gold = '0';
            $misc = '0';
            $vat = '0';
            foreach ($in['rows'] as $row) {
                if (isset($row['ref'])) {
                    $ref = collect($all)->firstWhere('id', $row['ref']);
                    $r = $policy->price($ref['input']);
                    $gold = bcadd_safe($gold, $r['T']);
                    $vat = bcadd_safe($vat, $r['V']);
                } else {
                    $r = (new ManualLineV1)->price(['manual_total_irr' => $row['manual_price_irr']]);
                    $misc = bcadd_safe($misc, $r['T']);
                }
            }
            $this->assertSame($expected['gold_rows_total'], $gold);
            $this->assertSame($expected['misc_rows_total'], $misc);
            $this->assertSame($expected['payable_total'], bcadd_safe($gold, $misc));
            $this->assertSame($expected['gold_vat_total'], $vat);

            return;
        }

        if (isset($in['display_currency'])) {
            $this->assertSame($expected['stored_price18_irr_per_g'], Money::parseTomanToIrr($in['displayed_price_per_g']));

            return;
        }

        if (isset($in['raw_weight'])) {
            $this->assertSame($expected['net_weight_g'], Digits::toLatin($in['raw_weight']));
            $this->assertSame($expected['displayed_price_per_g'], Digits::toLatin($in['raw_price']));

            return;
        }

        if (isset($expected['error'])) {
            try {
                $policy->price($in);
                $this->fail('Expected '.$expected['error']);
            } catch (PricingError $e) {
                $this->assertSame($expected['error'], $e->codeName);
                $this->assertSame($expected['eligible_irr'], $e->context['eligible_irr']);
            }

            return;
        }

        $result = $policy->price($in);
        foreach ($expected as $key => $value) {
            if ($key === 'effective_rate_irr_per_g') {
                $this->assertSame(rtrim(rtrim($value, '0'), '.'), rtrim(rtrim(substr($result[$key], 0, strlen($value)), '0'), '.'), $key);

                continue;
            }
            $this->assertSame($value, $result[$key], $vector['id'].' '.$key);
        }
        // T always equals the sum of posted components.
        $this->assertSame($result['T'], bcadd_safe(bcadd_safe($result['M'], $result['B']), $result['V']));
    }

    public function test_two_gold_rows_different_purities(): void
    {
        $p = new GoldIrV1;
        $a = $p->price(['net_weight_g' => '1', 'purity_ppt' => '750', 'price18_irr_per_g' => '100000000', 'wage_percent' => '0', 'profit_percent' => '0', 'discount' => null, 'vat_rate_percent' => '10']);
        $b = $p->price(['net_weight_g' => '1', 'purity_ppt' => '1000', 'price18_irr_per_g' => '100000000', 'wage_percent' => '0', 'profit_percent' => '0', 'discount' => null, 'vat_rate_percent' => '10']);
        $this->assertSame('100000000', $a['M']);
        $this->assertSame('133333333', $b['M']);
        $this->assertSame('233333333', bcadd_safe($a['T'], $b['T']));
    }

    public function test_rejects_invalid_inputs(): void
    {
        $p = new GoldIrV1;
        foreach ([['net_weight_g' => '0'], ['net_weight_g' => '-1'], ['purity_ppt' => '1001'], ['net_weight_g' => '1e3'], ['price18_irr_per_g' => '1.5']] as $bad) {
            $in = array_merge(['net_weight_g' => '2', 'purity_ppt' => '750', 'price18_irr_per_g' => '100000000', 'wage_percent' => '2', 'profit_percent' => '5', 'discount' => null, 'vat_rate_percent' => '10'], $bad);
            try {
                $p->price($in);
                $this->fail('accepted '.json_encode($bad));
            } catch (PricingError) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

function bcadd_safe(string $a, string $b): string
{
    return (string) BigInteger::of($a)->plus($b);
}
