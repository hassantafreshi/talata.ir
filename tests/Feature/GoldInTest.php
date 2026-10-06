<?php

namespace Tests\Feature;

use App\Domain\Market\QuoteService;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Tests\TestCase;

/** Gold received from the customer instead of money (GOLD_IN rows): docs/GOLD_RECEIVED_AND_DASHBOARD.md. */
class GoldInTest extends TestCase
{
    private function start($user): array
    {
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $res = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();

        return [$res->json('draft_id'), $res->json('version')];
    }

    private function save(string $id, int $version, array $rows)
    {
        return $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $version, 'rows' => $rows, 'buyer' => ['name' => 'مشتری', 'mobile' => '09351234567']])->assertOk();
    }

    private function issue(string $id, int $version)
    {
        return $this->api('POST', "/api/invoices/drafts/{$id}/issue", [
            'mode' => 'ISSUE_ONLY', 'version' => $version, 'idempotency_key' => 'k-'.bin2hex(random_bytes(8)),
            'buyer' => ['name' => 'مشتری', 'mobile' => '09351234567'],
        ]);
    }

    private function gold(array $over = []): array
    {
        return array_merge(['item_type' => 'GOLD', 'name' => 'النگو', 'net_weight_g' => '10.16', 'purity_ppt' => '750', 'wage_percent' => '7', 'profit_percent' => '7'], $over);
    }

    private function goldIn(array $over = []): array
    {
        return array_merge(['item_type' => 'GOLD_IN', 'kind' => 'OLD_GOLD', 'name' => '', 'net_weight_g' => '2.03', 'purity_ppt' => '750', 'rate_basis' => 'BUY', 'deduction_percent' => '0'], $over);
    }

    public function test_gold_received_reduces_payable_and_is_snapshotted_with_750_weights(): void
    {
        $user = $this->merchant();
        [$id, $v] = $this->start($user);
        $draft = Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->first();
        $buy = (string) BigDecimal::of(app(QuoteService::class)->latest('GOLD_18_BUY')->value)->toScale(0, RoundingMode::HalfUp);
        $this->assertSame($buy, $draft->accepted_buy_rate_irr, 'Start captures the market buy rate for gold received');

        $state = $this->save($id, $v, [$this->gold(), $this->goldIn(['kind' => 'COIN', 'name' => 'سکه امامی', 'net_weight_g' => '8.13', 'purity_ppt' => '900'])]);
        $this->assertTrue($state->json('valid'));
        $coin = $state->json('rows.1.computed');
        $this->assertSame('GOLD_IN_V1', $coin['formula_version']);
        $this->assertSame('9.756', $coin['weight_750']);
        $this->assertSame($buy, $coin['rate_irr_per_g']);
        $this->assertSame((string) BigDecimal::of('9.756')->multipliedBy($buy)->toScale(0, RoundingMode::HalfUp), $coin['T']);
        $sales = $state->json('totals.sales_irr');
        $this->assertSame((string) BigInteger::of($sales)->minus($coin['T']), $state->json('totals.payable_irr'));
        $this->assertSame($coin['T'], $state->json('totals.gold_in_irr'));

        $this->get("/invoices/{$id}/review")->assertOk()->assertSee('دریافتی')->assertSee('ارزش طلای دریافتی از مشتری');
        $issued = $this->issue($id, $state->json('version'))->assertCreated();

        $inv = Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->first();
        $this->assertSame($coin['T'], $inv->gold_in_total_irr);
        $this->assertSame($sales, $inv->sales_total_irr);
        $this->assertSame('10.160', $inv->gold_out_weight_750);
        $this->assertSame('9.756', $inv->gold_in_weight_750);
        $this->assertNotSame('0', $inv->wage_irr);
        $this->assertSame('COIN', $inv->snapshot['rows'][1]['item_attributes']['kind']);
        $this->assertSame('0.404', $inv->snapshot['totals']['weights']['net_750']);

        // Tahesab-style print: 750-equivalent weight column, received rows and the gold/money split.
        $this->get("/invoices/{$id}/print")->assertOk()
            ->assertSee('وزن ۷۵۰')->assertSee('تفکیک طلایی')->assertSee('تفکیک مبلغ')->assertSee('سکه امامی')->assertSee('۹.۷۵۶');
        $this->get("/invoices/{$id}")->assertOk()->assertSee('ارزش طلای دریافتی');
    }

    public function test_manual_rate_with_melting_deduction_matches_the_shared_vector(): void
    {
        $user = $this->merchant();
        [$id, $v] = $this->start($user);
        // Vector in_melted_deduction: 10.5 g at 705.5, 98,765,432 IRR/g, 1.5% deduction => T 960,873,579, D 14,632,593.
        $state = $this->save($id, $v, [$this->gold(), $this->goldIn([
            'kind' => 'MELTED', 'net_weight_g' => '۱۰٫۵', 'purity_ppt' => '705.5', 'rate_basis' => 'MANUAL', 'rate_toman' => '9876543.2',
            'deduction_percent' => '1.5', 'assay_ref' => 'A-1405-77',
        ])]);
        $c = $state->json('rows.1.computed');
        $this->assertSame('960873579', $c['T']);
        $this->assertSame('14632593', $c['D']);
        $item = InvoiceItem::withoutGlobalScope('tenant')->where('row_uid', $state->json('rows.1.row_uid'))->first();
        $this->assertEquals(['kind' => 'MELTED', 'rate_basis' => 'MANUAL', 'deduction_percent' => '1.5', 'rate_irr_per_g' => '98765432', 'assay_ref' => 'A-1405-77'], $item->item_attributes);
    }

    public function test_gold_received_above_the_sale_is_a_balance_owed_to_the_customer(): void
    {
        $user = $this->merchant();
        [$id, $v] = $this->start($user);
        $state = $this->save($id, $v, [
            ['item_type' => 'MISC', 'name' => 'جعبه', 'manual_total_toman' => '100000'],
            $this->goldIn(['net_weight_g' => '5']),
        ]);
        $this->assertTrue($state->json('valid'));
        $this->assertTrue($state->json('totals.customer_credit'));
        $this->assertStringStartsWith('-', $state->json('totals.payable_irr'));
        $this->get("/invoices/{$id}/review")->assertOk()->assertSee('مانده به نفع مشتری');

        $this->issue($id, $state->json('version'))->assertCreated();
        $inv = Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->first();
        $this->assertTrue(BigDecimal::of($inv->payable_irr)->isNegative());
        $this->get("/invoices/{$id}/print")->assertOk()->assertSee('مانده به نفع مشتری');
        $this->get("/invoices/{$id}/issued")->assertOk()->assertSee('مانده به نفع مشتری');
    }

    public function test_gold_received_alone_is_not_a_sales_invoice(): void
    {
        $user = $this->merchant();
        [$id, $v] = $this->start($user);
        $state = $this->save($id, $v, [$this->goldIn()]);
        $this->assertFalse($state->json('valid'));
        $this->assertTrue($state->json('sale_required'));
        $this->issue($id, $state->json('version'))->assertStatus(422)->assertJsonPath('code', 'ROWS_SALE_REQUIRED');
    }

    public function test_invalid_gold_in_fields_are_reported_per_field(): void
    {
        $user = $this->merchant();
        [$id, $v] = $this->start($user);
        $state = $this->save($id, $v, [$this->gold(), $this->goldIn(['deduction_percent' => '60']), $this->goldIn(['rate_basis' => 'MANUAL', 'rate_toman' => ''])]);
        $this->assertFalse($state->json('valid'));
        $this->assertArrayHasKey('deduction_percent', $state->json('rows.1.errors'));
        $this->assertArrayHasKey('rate_toman', $state->json('rows.2.errors'));
    }

    public function test_replacement_draft_keeps_gold_received_rows(): void
    {
        $user = $this->merchant();
        [$id, $v] = $this->start($user);
        $state = $this->save($id, $v, [$this->gold(), $this->goldIn(['kind' => 'COIN', 'purity_ppt' => '900'])]);
        $this->issue($id, $state->json('version'))->assertCreated();
        $this->api('POST', "/api/invoices/{$id}/void", ['reason' => 'WRONG_WEIGHT'])->assertOk();
        $replacement = $this->api('POST', "/api/invoices/{$id}/replace")->assertSuccessful();
        $draft = Invoice::withoutGlobalScope('tenant')->where('replaces_invoice_id', Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->value('id'))->first();
        $this->assertNotNull($draft->accepted_buy_rate_irr);
        $copied = InvoiceItem::withoutGlobalScope('tenant')->where('invoice_id', $draft->id)->where('item_type', 'GOLD_IN')->first();
        $this->assertSame('COIN', $copied->item_attributes['kind']);
    }
}
