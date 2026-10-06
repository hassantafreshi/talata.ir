<?php

namespace Tests\Feature;

use App\Domain\Market\QuoteService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\Jalali;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Free-plan caps (docs/PLANS_AND_QUOTAS.md) and payload ids that point into another shop. */
class QuotaAndIsolationTest extends TestCase
{
    private function draft($user): array
    {
        $irr = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $res = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $irr])->assertCreated();
        $row = ['row_uid' => 'r'.bin2hex(random_bytes(6)), 'item_type' => 'GOLD', 'name' => 'انگشتر', 'description' => '', 'net_weight_g' => '2', 'purity_ppt' => '750',
            'wage_percent' => '2', 'profit_percent' => '5', 'discount_toman' => '', 'discount_scope' => 'TAXABLE_COMPONENTS', 'manual_total_toman' => ''];
        $save = $this->api('PUT', '/api/invoices/drafts/'.$res->json('draft_id'), ['version' => $res->json('version'), 'rows' => [$row], 'buyer' => ['name' => 'مشتری', 'mobile' => '09351234567']])->assertOk();

        return [$res->json('draft_id'), $save->json('version')];
    }

    private function issue($user, string $id, int $version, string $mobile = '09351234567')
    {
        return $this->actingAs($user)->api('POST', "/api/invoices/drafts/{$id}/issue", [
            'mode' => 'ISSUE_ONLY', 'version' => $version, 'idempotency_key' => 'k-'.bin2hex(random_bytes(8)), 'buyer' => ['name' => 'مشتری', 'mobile' => $mobile],
        ]);
    }

    /** Issued invoices already counted this month (rows only; numbering is irrelevant to the cap). */
    private function fillInvoices(Tenant $tenant, int $n): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['public_id' => strtolower((string) Str::ulid()), 'tenant_id' => $tenant->id, 'status' => 'issued', 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('invoices')->insert($rows);
    }

    public function test_free_invoice_cap_blocks_start_and_issue_but_never_print_mazneh_or_calculator_and_resets_next_month(): void
    {
        $user = $this->merchant();
        $tenant = $this->tenantOf($user);
        [$printed, $pv] = $this->draft($user);
        $this->issue($user, $printed, $pv)->assertCreated();
        [$pending, $v] = $this->draft($user);           // started before the cap was reached
        $this->fillInvoices($tenant, 49);                 // 50 issued this month

        $irr = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $this->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $irr])->assertStatus(409)->assertJsonPath('code', 'QUOTA_INVOICES_PER_MONTH');
        $this->issue($user, $pending, $v)->assertStatus(409)->assertJsonPath('code', 'QUOTA_INVOICES_PER_MONTH');

        // Quota notices never block printing issued invoices, مظنه or the calculator.
        $this->get("/invoices/{$printed}/print")->assertOk();
        $this->get('/mazneh')->assertOk();
        $this->get('/calculator')->assertOk();

        // Next Jalali month: the cap is fresh again and the waiting draft can be issued.
        [, $end] = Jalali::monthBounds(now(), $tenant->timezone);
        $this->travelTo($end->addHour());
        $this->issue($user, $pending, $v)->assertCreated();
    }

    public function test_free_new_customer_and_link_caps(): void
    {
        $user = $this->merchant();
        $tenant = $this->tenantOf($user);
        $this->inTenant($user, function () {
            foreach (range(1, 50) as $i) {
                Customer::create(['name' => 'مشتری '.$i]);
            }
        });
        $this->actingAs($user)->api('POST', '/api/customers', ['name' => 'پنجاه و یکم'])->assertStatus(409)->assertJsonPath('code', 'QUOTA_NEW_CUSTOMERS_PER_MONTH');

        [$id, $v] = $this->draft($user);
        $this->issue($user, $id, $v)->assertCreated();
        $invoiceId = Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->value('id');
        foreach (range(1, 10) as $i) {
            DB::table('invoice_shares')->insert(['tenant_id' => $tenant->id, 'invoice_id' => $invoiceId, 'token_hash' => hash('sha256', 'h'.$i), 'token' => 'enc'.$i, 'created_at' => now(), 'updated_at' => now(), 'revoked_at' => now()]);
        }
        $this->api('POST', "/api/invoices/{$id}/share")->assertStatus(409)->assertJsonPath('code', 'QUOTA_LINKS_PER_MONTH');
    }

    public function test_ids_from_another_shop_in_a_payload_never_cross_over(): void
    {
        $a = $this->merchant('professional');
        $b = $this->merchant('professional');
        [$bInvoice, $bv] = $this->draft($b);
        $this->issue($b, $bInvoice, $bv, '09351110000')->assertCreated();
        $bCustomer = $this->inTenant($b, fn () => Customer::create(['name' => 'مشتری فروشگاه ب', 'mobile' => '09352220000']));

        // A builds an installment agreement pointing at B's invoice: not found in A's shop.
        $aCustomer = $this->inTenant($a, fn () => Customer::create(['name' => 'مشتری الف']));
        $this->actingAs($a)->api('POST', "/api/customers/{$aCustomer->public_id}/agreements", [
            'invoice_id' => $bInvoice, 'count' => 3, 'frequency' => 'monthly', 'first_due' => Jalali::date(now()->addMonth(), 'Asia/Tehran'),
        ])->assertStatus(422)->assertJsonPath('errors.invoice_id.0', 'فاکتور قطعی پیدا نشد.');

        // A issues to the same buyer mobile that B saved as a customer: A's invoice never links to B's customer.
        [$aInvoice, $av] = $this->draft($a);
        $this->issue($a, $aInvoice, $av, '09352220000')->assertCreated();
        $linked = Invoice::withoutGlobalScope('tenant')->where('public_id', $aInvoice)->value('customer_id');
        $this->assertNotSame($bCustomer->id, $linked);

        // And B's records stay untouched by A's requests.
        $this->assertSame(0, DB::table('installment_agreements')->where('tenant_id', $this->tenantOf($b)->id)->count());
    }
}
