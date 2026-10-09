<?php

namespace Tests\Feature;

use App\Domain\Market\QuoteProvider;
use App\Domain\Market\QuoteSchedule;
use App\Domain\Market\QuoteService;
use App\Models\PlatformSetting;
use App\Models\StaffUser;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Quote API cadence by Tehran time of day and the admin gold markup (owner decision 2026-10-09). */
class QuoteScheduleTest extends TestCase
{
    private function tehran(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-10 '.$time, 'Asia/Tehran');
    }

    public function test_interval_follows_the_tehran_time_windows(): void
    {
        $expect = [
            '00:00' => 180, '01:59' => 180, '02:00' => 1200, '05:59' => 1200, '06:00' => 300, '07:30' => 300,
            '08:00' => 180, '09:00' => 120, '10:00' => 60, '11:59' => 60, '12:00' => 60, '15:59' => 60,
            '16:00' => 60, '19:59' => 60, '20:00' => 120, '23:59' => 120,
        ];
        foreach ($expect as $time => $seconds) {
            $this->assertSame($seconds, QuoteSchedule::intervalSeconds($this->tehran($time)), $time);
        }
        // The server clock may be UTC: 09:30 UTC is 13:00 in Tehran (+03:30).
        $this->assertSame(60, QuoteSchedule::intervalSeconds(CarbonImmutable::parse('2026-10-10 09:30:00', 'UTC')));
    }

    public function test_the_requester_itself_decides_when_to_ask_the_api(): void
    {
        $provider = $this->provider();
        $service = new QuoteService($provider);

        $this->travelTo($this->tehran('03:00'));                 // every 20 minutes
        $this->assertTrue($service->refreshIfDue(true));
        $this->travel(19)->minutes();
        $this->assertFalse($service->refreshIfDue(true));
        $this->travel(1)->minutes();
        $this->assertTrue($service->refreshIfDue(true));
        $this->assertSame(2, $provider->calls);

        $this->travelTo($this->tehran('13:00'));                 // every minute
        $this->assertTrue($service->refreshIfDue(true));
        $this->travel(30)->seconds();
        $this->assertFalse($service->refreshIfDue(true));
        $this->travel(30)->seconds();
        $this->assertTrue($service->refreshIfDue(true));
        $this->assertSame(4, $provider->calls);

        // Price reads only trigger it where the host has no reliable scheduler.
        $this->travel(5)->minutes();
        config(['talata.quotes.refresh_on_read' => false]);
        $this->assertFalse($service->refreshIfDue());
        config(['talata.quotes.refresh_on_read' => true]);
        $this->assertTrue($service->refreshIfDue());
    }

    public function test_gold_quotes_get_the_markup_and_other_assets_do_not(): void
    {
        $service = new QuoteService($this->provider());
        config(['talata.quotes.gold_markup_toman' => 100000]);
        $this->assertTrue($service->refresh());
        // 10,100,000 toman from the API + 100,000 toman = 10,200,000 toman = 102,000,000 rial.
        $this->assertSame('102000000', (string) BigDecimal::of($service->feed('GOLD_18_SELL')->value)->toScale(0));
        $this->assertSame('1010000', (string) BigDecimal::of($service->feed('USD_IRR')->value)->toScale(0));

        PlatformSetting::put('quotes.gold_markup_toman', 250000, null);
        $this->travel(2)->minutes();
        $this->assertTrue($service->refresh());
        $this->assertSame('103500000', (string) BigDecimal::of($service->feed('GOLD_18_SELL')->value)->toScale(0));
    }

    public function test_admin_changes_the_markup_with_a_reason(): void
    {
        $this->asStaff(StaffUser::query()->create(['mobile' => '09120003001', 'name' => 'مدیر', 'role' => 'admin', 'active' => true]));
        $this->get(route('admin.quotes'))->assertOk()->assertSee('افزایش ثابت قیمت طلا');
        $this->postJson(route('admin.quotes.markup'), ['markup_toman' => '۱۵۰٬۰۰۰', 'reason' => 'تنظیم بازار', 'idempotency_key' => 'adm-'.bin2hex(random_bytes(8))])->assertOk();
        $this->assertSame(150000, QuoteService::goldMarkupToman());
        $this->postJson(route('admin.quotes.markup'), ['markup_toman' => 'abc', 'reason' => 'تنظیم بازار', 'idempotency_key' => 'adm-'.bin2hex(random_bytes(8))])->assertStatus(422);
    }

    private function provider(): QuoteProvider
    {
        return new class implements QuoteProvider
        {
            public int $calls = 0;

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

                return [
                    'GOLD_18_SELL' => ['value' => '101000000', 'unit' => 'IRR_PER_GRAM', 'quote_time' => null],
                    'USD_IRR' => ['value' => '1010000', 'unit' => 'IRR_PER_USD', 'quote_time' => null],
                ];
            }
        };
    }
}
