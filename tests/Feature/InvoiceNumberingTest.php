<?php

namespace Tests\Feature;

use App\Domain\Market\QuoteService;
use App\Models\Invoice;
use App\Support\Jalali;
use Tests\TestCase;

/** Configurable invoice numbering: docs/INVOICE_NUMBERING.md. */
class InvoiceNumberingTest extends TestCase
{
    private function issue($user): string
    {
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $res = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $id = $res->json('draft_id');
        $v = $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $res->json('version'), 'rows' => [['item_type' => 'GOLD', 'name' => 'انگشتر', 'net_weight_g' => '1', 'purity_ppt' => '750']]])->json('version');
        $this->api('POST', "/api/invoices/drafts/{$id}/issue", ['mode' => 'ISSUE_ONLY', 'version' => $v, 'idempotency_key' => 'k-'.bin2hex(random_bytes(8))])->assertCreated();

        return Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->value('number');
    }

    private function save(array $settings, int $version, ?string $next = null)
    {
        return $this->api('PUT', '/api/settings/numbering', ['settings' => $settings, 'next' => $next, 'version' => $version]);
    }

    private function ym(): array
    {
        $local = now()->setTimezone('Asia/Tehran');

        return Jalali::fromGregorian($local->year, $local->month, $local->day);
    }

    public function test_default_is_year_dash_four_digits_and_settings_page_shows_presets(): void
    {
        $user = $this->merchant();
        [$jy] = $this->ym();
        $this->assertSame("{$jy}-0001", $this->issue($user));
        $this->assertSame("{$jy}-0002", $this->issue($user));
        $this->get('/settings/numbering')->assertOk()->assertSee('شماره فاکتور بعدی')->assertSee('فقط شماره پیوسته')->assertSee('سال / ماه / شماره');
    }

    public function test_continuous_numbering_continues_a_paper_invoice_book(): void
    {
        $user = $this->merchant();
        $this->actingAs($user);
        $this->save(['prefix' => '', 'year' => 'none', 'month' => false, 'separator' => '-', 'digits' => 1, 'reset' => 'never'], 0, '۲۵۰۱')
            ->assertOk()->assertJsonPath('preview.0', '2501');
        $this->assertSame('2501', $this->issue($user));
        $this->assertSame('2502', $this->issue($user));
        // Going back would repeat a printed number.
        $this->save(['prefix' => '', 'year' => 'none', 'month' => false, 'separator' => '-', 'digits' => 1, 'reset' => 'never'], 1, '2400')
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION');
        $this->save(['prefix' => '', 'year' => 'none', 'month' => false, 'separator' => '-', 'digits' => 1, 'reset' => 'never'], 0)->assertStatus(409)->assertJsonPath('code', 'SETTINGS_CONFLICT');
    }

    public function test_monthly_with_prefix_and_invalid_combinations(): void
    {
        $user = $this->merchant();
        $this->actingAs($user);
        [$jy, $jm] = $this->ym();
        $this->save(['prefix' => 'ط', 'year' => 'none', 'month' => false, 'separator' => '-', 'digits' => 4, 'reset' => 'yearly'], 0)
            ->assertStatus(422)->assertJsonStructure(['errors' => ['year']]);
        $this->save(['prefix' => 'ط', 'year' => 'full', 'month' => false, 'separator' => '/', 'digits' => 3, 'reset' => 'monthly'], 0)
            ->assertStatus(422)->assertJsonStructure(['errors' => ['month']]);
        $this->save(['prefix' => 'ط ط', 'year' => 'full', 'month' => true, 'separator' => '/', 'digits' => 3, 'reset' => 'monthly'], 0)
            ->assertStatus(422)->assertJsonStructure(['errors' => ['prefix']]);
        $this->save(['prefix' => 'ط', 'year' => 'full', 'month' => true, 'separator' => '/', 'digits' => 3, 'reset' => 'monthly'], 0)->assertOk();
        $this->assertSame(sprintf('ط/%d/%02d/001', $jy, $jm), $this->issue($user));
        $this->get('/invoices')->assertOk();
    }

    public function test_switching_formats_never_reuses_an_issued_number(): void
    {
        $user = $this->merchant();
        [$jy] = $this->ym();
        $this->assertSame("{$jy}-0001", $this->issue($user));
        // A new continuous scheme whose first number would be "1405-0001" again skips to the next free one.
        $this->actingAs($user);
        $this->save(['prefix' => (string) $jy, 'year' => 'none', 'month' => false, 'separator' => '-', 'digits' => 4, 'reset' => 'never'], 0)->assertOk();
        $this->assertSame("{$jy}-0002", $this->issue($user));
        $this->assertSame(1, Invoice::query()->withoutGlobalScope('tenant')->where('number', "{$jy}-0001")->count());
    }
}
