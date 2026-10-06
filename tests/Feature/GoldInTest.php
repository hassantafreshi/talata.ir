<?php

namespace Tests\Feature;

use App\Domain\Market\QuoteService;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Jalali;
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

    public function test_reference_invoice_settled_by_weight_on_the_ledger_template(): void
    {
        // The reference (Tahesab) document: 10.16 g sold, settled with an Emami coin and old gold by weight.
        $user = $this->merchant('basic');
        $this->actingAs($user)->api('PUT', '/api/settings/appearance', ['settings' => ['template_id' => 'ledger'], 'version' => 1])->assertOk();
        [$id, $v] = $this->start($user);
        $state = $this->save($id, $v, [
            $this->gold(['settlement' => 'WEIGHT']),
            $this->goldIn(['kind' => 'COIN', 'name' => 'سکه امامی', 'net_weight_g' => '8.13', 'purity_ppt' => '900', 'rate_basis' => 'WEIGHT']),
            $this->goldIn(['name' => 'ورود متفرقه', 'net_weight_g' => '2.03', 'rate_basis' => 'WEIGHT']),
        ]);
        $this->assertTrue($state->json('valid'));
        $sale = $state->json('rows.0.computed');
        $this->assertSame('WEIGHT', $sale['settlement']);
        $this->assertSame('10.160', $sale['debit_750']);
        $this->assertSame((string) BigInteger::of($sale['B'])->plus($sale['V']), $state->json('rows.0.total_irr'), 'only wage, profit and VAT are money');
        $this->assertSame('9.756', $state->json('rows.1.computed.credit_750'));
        $this->assertSame('0', $state->json('rows.1.total_irr'));
        $this->assertSame(['gold_debit_750' => '10.160', 'gold_credit_750' => '11.786', 'gold_balance_750' => '-1.626'], array_intersect_key($state->json('totals.ledger'), array_flip(['gold_debit_750', 'gold_credit_750', 'gold_balance_750'])));
        $this->assertSame($state->json('totals.payable_irr'), $state->json('totals.ledger.money_balance_irr'));
        $this->assertSame('CREDIT', $state->json('totals.gold_balance_side'));

        $this->issue($id, $state->json('version'))->assertCreated();
        $inv = Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->first();
        $this->assertSame('-1.626', $inv->snapshot['totals']['ledger']['gold_balance_750']);
        $this->assertSame('ledger', $inv->snapshot['layout']['template_id']);
        $print = $this->get("/invoices/{$id}/print")->assertOk();
        $print->assertSee('طلا (گرم ۷۵۰) بد/بس')->assertSee('مبلغ (تومان) بد/بس')->assertSee('مانده سند')
            ->assertSee('۱۰.۱۶ بد')->assertSee('۹.۷۵۶ بس')->assertSee('۲.۰۳ بس')->assertSee('بستانکار ۱.۶۲۶ گرم طلای ۱۸ عیار')->assertSee($inv->issued_at ? Jalali::date($inv->issued_at, 'Asia/Tehran', true) : '')->assertSee('تسویه وزنی؛ اجرت، سود و مالیات نقدی')->assertSee('حساب وزنی (بدون تبدیل به پول)')
            ->assertSee('اشتباه از طرفین قابل برگشت است.')->assertSee('گیرنده سند');
    }

    public function test_weight_settlement_shows_the_debit_credit_table_even_on_the_free_layout(): void
    {
        $user = $this->merchant('free');
        [$id, $v] = $this->start($user);
        $state = $this->save($id, $v, [$this->gold(['settlement' => 'WEIGHT', 'net_weight_g' => '3']), $this->goldIn(['net_weight_g' => '3', 'rate_basis' => 'WEIGHT'])]);
        $this->assertSame('0.000', $state->json('totals.ledger.gold_balance_750'));
        $this->issue($id, $state->json('version'))->assertCreated();
        $this->get("/invoices/{$id}/print")->assertOk()->assertSee('مانده سند')->assertSee('تسویه');
    }

    public function test_melted_gold_sale_by_weight_against_melted_gold_received(): void
    {
        // «فاکتور آب‌شده»: the shop sells assayed melted gold and takes melted gold back, both by weight.
        $user = $this->merchant('professional');
        [$id, $v] = $this->start($user);
        $state = $this->save($id, $v, [
            ['item_type' => 'GOLD', 'kind' => 'MELTED', 'name' => '', 'assay_ref' => 'ع-۱۲۳', 'net_weight_g' => '50', 'purity_ppt' => '995', 'wage_percent' => '0', 'profit_percent' => '0.5', 'settlement' => 'WEIGHT'],
            $this->goldIn(['kind' => 'MELTED', 'net_weight_g' => '60', 'purity_ppt' => '705.5', 'deduction_percent' => '0.5', 'assay_ref' => 'ع-۱۲۴', 'rate_basis' => 'WEIGHT']),
        ]);
        $this->assertTrue($state->json('valid'));
        $this->assertSame('66.333', $state->json('rows.0.computed.debit_750'));      // 50 × 995 / 750
        $this->assertSame('56.158', $state->json('rows.1.computed.credit_750'));     // 60 × 705.5 / 750 = 56.44 × 0.995 = 56.1578
        $this->assertSame('10.175', $state->json('totals.ledger.gold_balance_750')); // customer owes 10.175 g of 750
        $this->issue($id, $state->json('version'))->assertCreated();
        $this->get("/invoices/{$id}/print")->assertOk()
            ->assertSee('فروش طلای آب‌شده')->assertSee('برگه عیارسنجی ع-۱۲۳')->assertSee('۶۶.۳۳۳ بد')->assertSee('۵۶.۱۵۸ بس')
            ->assertSee('بدهکار ۱۰.۱۷۵ گرم طلای ۱۸ عیار')->assertSee('مانده سند');
    }
}
