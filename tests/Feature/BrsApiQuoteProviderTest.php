<?php

namespace Tests\Feature;

use App\Domain\Market\BrsApiQuoteProvider;
use App\Domain\Market\QuoteService;
use App\Models\MarketQuote;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The owner's real BrsApi response shape (tests/fixtures/brsapi-gold-currency.json), served by a fake. */
class BrsApiQuoteProviderTest extends TestCase
{
    private function provider(): BrsApiQuoteProvider
    {
        return new BrsApiQuoteProvider('test-key', 'https://api.brsapi.ir/Market/Gold_Currency.php');
    }

    public function test_maps_toman_prices_to_our_assets_and_never_invents_a_buy_price(): void
    {
        Http::fake(['api.brsapi.ir/*' => Http::response(file_get_contents(base_path('tests/fixtures/brsapi-gold-currency.json')))]);
        $q = $this->provider()->fetch();

        $this->assertSame(['GOLD_18_SELL', 'GOLD_24', 'XAU_USD', 'USD_IRR'], array_keys($q));
        $this->assertSame(['26738300', 'TOMAN_PER_GRAM'], [$q['GOLD_18_SELL']['value'], $q['GOLD_18_SELL']['unit']]);
        $this->assertSame(1791304194, $q['GOLD_18_SELL']['quote_time']->getTimestamp());
        $this->assertArrayNotHasKey('GOLD_18_BUY', $q);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'key=test-key'));

        // Through the central refresh: stored as IRR per gram (toman × 10), labelled live, not demo.
        $this->assertTrue((new QuoteService($this->provider()))->refresh());
        $sell = MarketQuote::query()->where('asset', 'GOLD_18_SELL')->latest('id')->first();
        $this->assertTrue(BigDecimal::of($sell->value)->isEqualTo('267383000'));
        $this->assertSame('BrsApi', $sell->source);
        $this->assertFalse((bool) $sell->is_demo);
        $this->assertTrue(BigDecimal::of(MarketQuote::query()->where('asset', 'USD_IRR')->latest('id')->value('value'))->isEqualTo('2688200'));
        $this->assertTrue(BigDecimal::of(MarketQuote::query()->where('asset', 'XAU_USD')->latest('id')->value('value'))->isEqualTo('4147'));
    }

    public function test_errors_keep_the_last_good_price_and_never_leak_the_key(): void
    {
        Http::fake(['api.brsapi.ir/*' => Http::response('Forbidden', 403)]);
        try {
            $this->provider()->fetch();
            $this->fail('expected an error');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('test-key', $e->getMessage());
        }
        $this->assertFalse((new QuoteService($this->provider()))->refresh());

        Http::fake(['api.brsapi.ir/*' => Http::response(['gold' => [], 'currency' => []])]);
        $this->expectException(\RuntimeException::class);
        $this->provider()->fetch();
    }
}
