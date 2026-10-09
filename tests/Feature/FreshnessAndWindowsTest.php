<?php

namespace Tests\Feature;

use App\Domain\Market\QuoteProvider;
use App\Domain\Market\QuoteService;
use App\Domain\Plans\Entitlements;
use App\Models\MarketQuote;
use App\Models\SmsMessage;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Assumptions with clocks (docs/ASSUMPTIONS.md): quote freshness and the yearly free-SMS window. */
class FreshnessAndWindowsTest extends TestCase
{
    public function test_quote_is_fresh_then_stale_after_240_seconds_then_error_when_refresh_fails(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 13:00:00', 'Asia/Tehran')); // a 1-minute window
        $quotes = app(QuoteService::class);
        $q = MarketQuote::query()->create(['asset' => 'GOLD_18_SELL', 'value' => '100000000', 'unit' => 'IRR_PER_GRAM', 'source' => 'test', 'is_demo' => true, 'fetched_at' => now()]);
        $this->assertSame('FRESH', $quotes->freshness($q));
        $this->travel(239)->seconds();
        $this->assertSame('FRESH', $quotes->freshness($q->fresh()));
        $this->travel(2)->seconds();
        $this->assertSame('STALE', $quotes->freshness($q->fresh()));
        Cache::put('talata.quotes.last_error', now()->toIso8601String(), 600);
        $this->assertSame('ERROR', $quotes->freshness($q->fresh()));
        $this->assertSame('ERROR', $quotes->freshness(null));
    }

    public function test_free_sms_year_renews_on_the_registration_anniversary(): void
    {
        $user = $this->merchant();
        $tenant = $this->tenantOf($user);
        $ent = app(Entitlements::class);
        $this->travel(10)->days();
        SmsMessage::query()->create(['tenant_id' => $tenant->id, 'purpose' => 'INVOICE', 'recipient' => '09351234567', 'body' => 'x', 'segments' => 1, 'cost_irr' => '0', 'charge_source' => 'FREE_YEARLY', 'status' => 'SENT', 'idempotency_key' => 'free-1']);
        $this->assertSame(4, $ent->freeSmsRemaining($tenant));
        $this->travel(353)->days();                     // day 363 since sign-up: same year
        $this->assertSame(4, $ent->freeSmsRemaining($tenant));
        $this->travel(3)->days();                       // day 366: a new year, full allowance
        $this->assertSame(5, $ent->freeSmsRemaining($tenant));
    }

    private function provider(bool $fail = false): QuoteProvider
    {
        return new class($fail) implements QuoteProvider
        {
            public int $calls = 0;

            public function __construct(private bool $fail) {}

            public function name(): string
            {
                return 'test-feed';
            }

            public function isDemo(): bool
            {
                return true;
            }

            public function fetch(): array
            {
                $this->calls++;
                if ($this->fail) {
                    throw new \RuntimeException('provider down');
                }

                return ['GOLD_18_SELL' => ['value' => '101000000', 'unit' => 'IRR_PER_GRAM', 'quote_time' => null]];
            }
        };
    }

    public function test_central_refresh_is_single_flight_and_a_failure_never_fakes_a_fresh_timestamp(): void
    {
        $ok = $this->provider();
        $service = new QuoteService($ok);
        // Another worker holds the refresh: this one does not call the provider at all.
        $lock = Cache::lock('talata.quotes.fetch', 60);
        $this->assertTrue($lock->get());
        $this->assertFalse($service->refresh());
        $this->assertSame(0, $ok->calls);
        $lock->release();
        $this->assertTrue($service->refresh());
        $fetched = MarketQuote::query()->where('source', 'test-feed')->latest('id')->firstOrFail()->fetched_at;

        // Provider down 5 minutes later: nothing new is written, the old timestamp stays, status says ERROR.
        $this->travel(5)->minutes();
        $down = new QuoteService($this->provider(fail: true));
        $this->assertFalse($down->refresh());
        $latest = MarketQuote::query()->where('asset', 'GOLD_18_SELL')->latest('fetched_at')->firstOrFail();
        $this->assertTrue($latest->fetched_at->equalTo($fetched));
        $this->assertSame('ERROR', $down->freshness($latest));
    }

    public function test_the_feed_requester_is_woken_every_minute_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'talata:quotes'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression); // the interval itself is decided by QuoteSchedule
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_provider_units_are_normalized_and_unknown_units_are_refused(): void
    {
        $this->assertSame('100000000', QuoteService::normalize('GOLD_18_SELL', '100000000', 'IRR_PER_GRAM'));
        $this->assertSame('100000000', QuoteService::normalize('GOLD_18_SELL', '10000000', 'toman_per_gram'));
        $this->assertSame('100000000', QuoteService::normalize('GOLD_18_SELL', '460800000', 'IRR_PER_MITHQAL'));
        $this->assertSame('100000000', QuoteService::normalize('GOLD_18_SELL', '46080000', 'TOMAN_PER_MITHQAL'));
        $this->assertSame('1000000', QuoteService::normalize('USD_IRR', '100000', 'TOMAN_PER_USD'));
        $this->assertSame('2650.5', QuoteService::normalize('XAU_USD', '2650.5', 'USD_PER_OUNCE'));
        $this->assertNull(QuoteService::normalize('GOLD_18_SELL', '2650', 'USD_PER_OUNCE'));
        $this->assertNull(QuoteService::normalize('GOLD_18_SELL', '100', 'IRR_PER_KG'));
        $this->assertNull(QuoteService::normalize('GOLD_18_SELL', '0', 'IRR_PER_GRAM'));
        $this->assertNull(QuoteService::normalize('GOLD_18_SELL', 'abc', 'IRR_PER_GRAM'));

        // Through the central refresh: a toman feed is stored as IRR; a mislabelled asset is skipped, not stored.
        $feed = new class implements QuoteProvider
        {
            public function name(): string
            {
                return 'unit-feed';
            }

            public function isDemo(): bool
            {
                return true;
            }

            public function fetch(): array
            {
                return [
                    'GOLD_18_SELL' => ['value' => '10500000', 'unit' => 'TOMAN_PER_GRAM', 'quote_time' => null],
                    'GOLD_24' => ['value' => '3000', 'unit' => 'USD_PER_OUNCE', 'quote_time' => null],
                ];
            }
        };
        $before = MarketQuote::query()->where('asset', 'GOLD_24')->count();
        $this->assertTrue((new QuoteService($feed))->refresh());
        $sell = MarketQuote::query()->where('asset', 'GOLD_18_SELL')->latest('id')->first();
        $this->assertTrue(BigDecimal::of($sell->value)->isEqualTo('105000000'), (string) $sell->value);
        $this->assertSame('IRR_PER_GRAM', $sell->unit);
        $this->assertSame($before, MarketQuote::query()->where('asset', 'GOLD_24')->count());
    }
}
