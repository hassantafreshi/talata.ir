<?php

namespace Tests\Feature;

use App\Domain\Market\QuoteService;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Tests\TestCase;

/** Invoice entry details requested 2026-10-08: «روش تسویه» (نقد/چک/با طلا/قسطی) and «سکه و پلاک». */
class InvoiceEntryTest extends TestCase
{
    private function draft($user, array $rows, bool $saveCustomer = false): array
    {
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $res = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $id = $res->json('draft_id');
        $state = $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $res->json('version'), 'rows' => $rows, 'buyer' => ['name' => 'مشتری', 'mobile' => '09351234567']])->assertOk();
        $this->api('POST', "/api/invoices/drafts/{$id}/issue", [
            'mode' => 'ISSUE_ONLY', 'version' => $state->json('version'), 'idempotency_key' => 'k-'.bin2hex(random_bytes(8)),
            'buyer' => ['name' => 'مشتری', 'mobile' => '09351234567'], 'save_customer' => $saveCustomer,
        ])->assertCreated();

        return [$id, Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->firstOrFail()];
    }

    private function gold(array $over = []): array
    {
        return array_merge(['item_type' => 'GOLD', 'name' => 'انگشتر طلا', 'net_weight_g' => '3.5', 'purity_ppt' => '750', 'wage_percent' => '18', 'profit_percent' => '7'], $over);
    }

    public function test_cheque_and_instalment_are_recorded_and_printed_without_changing_the_money(): void
    {
        $user = $this->merchant();
        [$cashId, $cash] = $this->draft($user, [$this->gold()]);
        [$id, $invoice] = $this->draft($user, [$this->gold(['settlement' => 'CHEQUE']), $this->gold(['settlement' => 'INSTALLMENT', 'name' => 'گوشواره'])]);

        $items = InvoiceItem::withoutGlobalScope('tenant')->where('invoice_id', $invoice->id)->orderBy('position')->get();
        $this->assertSame('CHEQUE', $items[0]->item_attributes['pay_method']);
        $this->assertSame('INSTALLMENT', $items[1]->item_attributes['pay_method']);
        $this->assertArrayNotHasKey('settlement', $items[0]->item_attributes);
        $this->assertSame((string) ($cash->payable_irr * 2), (string) $invoice->payable_irr, 'cheque/instalment rows are priced like cash');

        $this->get("/invoices/{$id}/print")->assertOk()->assertSee('روش تسویه:')->assertSee('چک، قسطی');
        // A plain cash invoice prints no settlement line (older invoices never recorded «نقد»).
        $this->get("/invoices/{$cashId}/print")->assertOk()->assertDontSee('روش تسویه:');
        // Unknown values fall back to cash.
        [, $odd] = $this->draft($user, [$this->gold(['settlement' => 'BITCOIN'])]);
        $this->assertEmpty(InvoiceItem::withoutGlobalScope('tenant')->where('invoice_id', $odd->id)->first()->item_attributes ?? []);
    }

    public function test_instalment_invoice_offers_the_instalment_plan_on_professional_only(): void
    {
        $pro = $this->merchant('professional');
        [$id] = $this->draft($pro, [$this->gold(['settlement' => 'INSTALLMENT'])], saveCustomer: true);
        $this->get("/invoices/{$id}/issued")->assertOk()->assertSee('ثبت اقساط این فاکتور')->assertSee('invoice='.$id, false);

        $free = $this->merchant();
        [$freeId] = $this->draft($free, [$this->gold(['settlement' => 'INSTALLMENT'])], saveCustomer: true);
        $this->get("/invoices/{$freeId}/issued")->assertOk()->assertDontSee('ثبت اقساط این فاکتور');
    }

    public function test_coins_and_plaques_are_a_sold_gold_kind(): void
    {
        $user = $this->merchant();
        [, $invoice] = $this->draft($user, [$this->gold(['kind' => 'COIN', 'name' => 'سکه بهار آزادی', 'purity_ppt' => '900', 'wage_percent' => '0', 'profit_percent' => '2'])]);
        $item = InvoiceItem::withoutGlobalScope('tenant')->where('invoice_id', $invoice->id)->first();
        $this->assertSame('COIN', $item->item_attributes['kind']);
        $this->assertSame('900', (string) (float) $item->purity_ppt);
    }

    public function test_items_page_shows_the_new_settlement_and_kind_choices(): void
    {
        $user = $this->merchant();
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $id = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->json('draft_id');
        $this->get("/invoices/{$id}/items")->assertOk()->assertSee('روش تسویه')->assertDontSee('فلز طلا چطور تسویه شود')
            ->assertSee('value="CHEQUE"', false)->assertSee('value="INSTALLMENT"', false)->assertSee('سکه و پلاک');
    }
}
