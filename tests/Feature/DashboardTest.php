<?php

namespace Tests\Feature;

use App\Domain\Identity\LoginService;
use App\Domain\Market\QuoteService;
use App\Domain\Reports\DashboardService;
use App\Models\Invoice;
use App\Models\Membership;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sales dashboard: docs/GOLD_RECEIVED_AND_DASHBOARD.md §8–§12. */
class DashboardTest extends TestCase
{
    private function issueInvoice($user, array $rows): Invoice
    {
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $res = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $id = $res->json('draft_id');
        $save = $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $res->json('version'), 'rows' => $rows])->assertOk();
        $this->api('POST', "/api/invoices/drafts/{$id}/issue", ['mode' => 'ISSUE_ONLY', 'version' => $save->json('version'), 'idempotency_key' => 'k-'.bin2hex(random_bytes(8))])->assertCreated();

        return Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->first();
    }

    private function gold(string $w = '2'): array
    {
        return ['item_type' => 'GOLD', 'name' => 'انگشتر', 'net_weight_g' => $w, 'purity_ppt' => '750', 'wage_percent' => '10', 'profit_percent' => '5'];
    }

    public function test_professional_sees_every_metric_with_exact_server_totals_and_voids_excluded(): void
    {
        $user = $this->merchant('professional');
        $a = $this->issueInvoice($user, [$this->gold('2'), ['item_type' => 'GOLD_IN', 'kind' => 'OLD_GOLD', 'net_weight_g' => '1', 'purity_ppt' => '750', 'rate_basis' => 'BUY']]);
        $b = $this->issueInvoice($user, [$this->gold('3'), ['item_type' => 'MISC', 'name' => 'جعبه', 'manual_total_toman' => '50000']]);
        $void = $this->issueInvoice($user, [$this->gold('9')]);
        $this->api('POST', "/api/invoices/{$void->public_id}/void", ['reason' => 'DUPLICATE'])->assertOk();

        $res = $this->api('GET', '/api/dashboard?range=month')->assertOk();
        $this->assertSame(2, $res->json('invoices'));
        $this->assertSame(DashboardService::METRICS, array_keys($res->json('metrics')));
        $sum = fn (string $col) => (string) BigDecimal::of($a->{$col})->plus($b->{$col});
        $this->assertSame($sum('sales_total_irr'), $res->json('metrics.sales.irr'));
        $this->assertSame($sum('wage_irr'), $res->json('metrics.wage.irr'));
        $this->assertSame($sum('profit_irr'), $res->json('metrics.profit.irr'));
        $this->assertSame($sum('vat_irr'), $res->json('metrics.vat.irr'));
        $this->assertSame($a->gold_in_total_irr, $res->json('metrics.gold_in.irr'));
        $this->assertSame('5.000', $res->json('metrics.sales.g'), '750-equivalent grams sold, void excluded');
        $this->assertSame('1.000', $res->json('metrics.gold_in.g'));
        $wageG = BigDecimal::of($a->wage_irr)->dividedBy($a->accepted_rate_irr, 20, RoundingMode::HalfUp)
            ->plus(BigDecimal::of($b->wage_irr)->dividedBy($b->accepted_rate_irr, 20, RoundingMode::HalfUp))->toScale(3, RoundingMode::HalfUp);
        $this->assertSame((string) $wageG, $res->json('metrics.wage.g'));
        $this->assertArrayNotHasKey('g', $res->json('metrics.vat'), 'VAT is toman only');
        $this->assertSame((float) array_sum($res->json('metrics.sales.series_g')), 5.0);
        $this->assertSame($res->json('labels'), array_values($res->json('labels')));
        $this->assertSame([], $res->json('locked_metrics'));

        foreach (['day', 'week', 'quarter', 'year'] as $range) {
            $this->api('GET', "/api/dashboard?range={$range}")->assertOk()->assertJsonPath('metrics.sales.irr', $sum('sales_total_irr'));
        }
        $this->get('/dashboard')->assertOk()->assertSee('داشبورد فروش')->assertSee('سود فروش')->assertSee('بازه دلخواه');
    }

    public function test_free_plan_gets_sales_wage_and_gold_in_for_day_week_month_only(): void
    {
        $user = $this->merchant('free');
        $this->issueInvoice($user, [$this->gold()]);
        $res = $this->actingAs($user)->api('GET', '/api/dashboard?range=week')->assertOk();
        $this->assertSame(['sales', 'wage', 'gold_in'], array_keys($res->json('metrics')));
        $this->assertSame(['profit', 'vat'], $res->json('locked_metrics'));
        $this->assertArrayNotHasKey('delta_pct', $res->json('metrics.sales'), 'no previous-period comparison on Free');
        foreach (['quarter', 'year', 'custom'] as $range) {
            $this->api('GET', "/api/dashboard?range={$range}&from=1405/01/01&to=1405/02/01")->assertStatus(403)->assertJsonPath('code', 'FEATURE_LOCKED');
        }
        $page = $this->get('/dashboard')->assertOk();
        $page->assertSee('در پلن پایه و حرفه‌ای')->assertSee('اجرت دریافتی')->assertSee('طلای خریداری‌شده از مشتری');
    }

    public function test_custom_range_validation_and_tenant_isolation(): void
    {
        $other = $this->merchant('professional');
        $this->issueInvoice($other, [$this->gold('7')]);
        $user = $this->merchant('basic');
        $this->actingAs($user)->api('GET', '/api/dashboard?range=custom&from=1405/01/01')->assertStatus(422)->assertJsonPath('code', 'RANGE_INVALID');
        $this->api('GET', '/api/dashboard?range=custom&from=1400/01/01&to=1405/01/01')->assertStatus(422)->assertJsonPath('code', 'RANGE_TOO_LONG');
        $this->api('GET', '/api/dashboard?range=forever')->assertStatus(422);
        $res = $this->api('GET', '/api/dashboard?range=custom&from=۱۴۰۵/۰۱/۰۱&to=1405/12/29')->assertOk();
        $this->assertSame(0, $res->json('invoices'), 'another shop\'s invoices never count');
        $this->assertCount(12, $res->json('labels'), 'a long custom range is bucketed by Jalali month');
    }

    public function test_buckets_use_the_shop_timezone_and_jalali_week(): void
    {
        $user = $this->merchant('professional');
        $inv = $this->issueInvoice($user, [$this->gold()]);
        // 2026-10-05 21:30 UTC = 2026-10-06 01:00 Tehran (a Tuesday, ۱۴ مهر ۱۴۰۵).
        DB::table('invoices')->where('id', $inv->id)->update(['issued_at' => '2026-10-05 21:30:00']);
        $tenant = $this->tenantOf($user);
        $now = CarbonImmutable::parse('2026-10-06 12:00', 'Asia/Tehran');
        $svc = app(DashboardService::class);

        $day = $this->inTenant($user, fn () => $svc->report($tenant, 'day', null, null, $now));
        $this->assertCount(24, $day['labels']);
        $this->assertGreaterThan(0, $day['metrics']['sales']['series_toman'][1], 'counted in the 01:00 local hour, not the previous UTC day');

        $week = $this->inTenant($user, fn () => $svc->report($tenant, 'week', null, null, $now));
        $this->assertSame(['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'], $week['labels']);
        $this->assertGreaterThan(0, $week['metrics']['sales']['series_toman'][3]);

        $month = $this->inTenant($user, fn () => $svc->report($tenant, 'month', null, null, $now));
        $this->assertCount(30, $month['labels'], 'Mehr has 30 days');
        $this->assertSame('مهر ۱۴۰۵', $month['label_fa']);
        $this->assertGreaterThan(0, $month['metrics']['sales']['series_toman'][13]);
    }

    public function test_member_without_reports_permission_is_blocked(): void
    {
        $owner = $this->merchant('professional');
        $this->actingAs($owner)->api('POST', '/api/users/invite', ['mobile' => '09371112299', 'permissions' => ['invoice.issue']])->assertOk();
        $member = app(LoginService::class)->completeLogin('09371112299')['user'];
        $invite = Membership::query()->where('invited_mobile', '09371112299')->where('status', 'invited')->firstOrFail();
        $this->actingAs($member)->api('POST', "/api/invites/{$invite->id}/accept")->assertOk();
        $this->api('GET', '/api/dashboard?range=day')->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_home_screen_shows_todays_sales_with_the_same_plan_and_team_rules(): void
    {
        // Free: sales, wage and gold received open; profit and VAT locked behind the upgrade sheet.
        $free = $this->merchant();
        $this->issueInvoice($free, [$this->gold('2')]);
        $home = $this->actingAs($free)->get(route('invoices.new'))->assertOk();
        $home->assertSee('فروش امروز')->assertSee('گزارش کامل')->assertSee('۱ فاکتور صادرشده', false);
        $html = $home->getContent();
        $this->assertSame(2, substr_count($html, 'class="tile dash-tile is-locked"'), 'profit and VAT are locked on Free');

        // Professional: every card open, none locked.
        $pro = $this->merchant('professional');
        $this->issueInvoice($pro, [$this->gold('1')]);
        $html = $this->actingAs($pro)->get(route('invoices.new'))->assertOk()->assertSee('سود فروش')->getContent();
        $this->assertStringNotContainsString('dash-tile is-locked', $html);

        // A team member without «گزارش فروش» never sees the numbers on the home screen.
        $this->actingAs($pro)->api('POST', '/api/users/invite', ['mobile' => '09371112288', 'permissions' => ['invoice.issue']])->assertOk();
        $member = app(LoginService::class)->completeLogin('09371112288')['user'];
        $invite = Membership::query()->where('invited_mobile', '09371112288')->where('status', 'invited')->firstOrFail();
        $this->actingAs($member)->api('POST', "/api/invites/{$invite->id}/accept")->assertOk();
        $this->get(route('invoices.new'))->assertOk()->assertDontSee('فروش امروز');
    }
}
