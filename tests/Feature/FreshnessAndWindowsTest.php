<?php

namespace Tests\Feature;

use App\Domain\Market\QuoteService;
use App\Domain\Plans\Entitlements;
use App\Models\MarketQuote;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Assumptions with clocks (docs/ASSUMPTIONS.md): quote freshness and the yearly free-SMS window. */
class FreshnessAndWindowsTest extends TestCase
{
    public function test_quote_is_fresh_then_stale_after_240_seconds_then_error_when_refresh_fails(): void
    {
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
}
