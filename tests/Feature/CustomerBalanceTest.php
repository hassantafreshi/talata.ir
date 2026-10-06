<?php

namespace Tests\Feature;

use App\Domain\Market\QuoteService;
use App\Models\Customer;
use App\Models\InstallmentAgreement;
use App\Models\InstallmentLine;
use App\Models\InstallmentPayment;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function seedCustomers($user): void
    {
        $this->inTenant($user, function () {
            $owing = Customer::create(['name' => 'مشتری بدهکار']);
            $settled = Customer::create(['name' => 'مشتری تسویه']);
            Customer::create(['name' => 'مشتری بدون قسط']); // never had installments

            $a1 = InstallmentAgreement::create(['customer_id' => $owing->id, 'principal_irr' => '3000000', 'count' => 2, 'frequency' => 'monthly', 'status' => 'active']);
            InstallmentLine::create(['agreement_id' => $a1->id, 'number' => 1, 'due_date' => now(), 'amount_irr' => '1000000', 'paid_irr' => '0']);
            InstallmentLine::create(['agreement_id' => $a1->id, 'number' => 2, 'due_date' => now(), 'amount_irr' => '2000000', 'paid_irr' => '500000']);

            $a2 = InstallmentAgreement::create(['customer_id' => $settled->id, 'principal_irr' => '1000000', 'count' => 1, 'frequency' => 'monthly', 'status' => 'active']);
            InstallmentLine::create(['agreement_id' => $a2->id, 'number' => 1, 'due_date' => now(), 'amount_irr' => '1000000', 'paid_irr' => '1000000']);
        });
    }

    public function test_owing_filter_keeps_only_customers_with_an_unpaid_installment_balance(): void
    {
        $user = $this->merchant('professional');
        $this->seedCustomers($user);

        $this->actingAs($user)->get('/customers?bal=owing')->assertOk()
            ->assertSee('مشتری بدهکار')->assertSee('مانده قسط')
            ->assertDontSee('مشتری تسویه')->assertDontSee('مشتری بدون قسط');
    }

    public function test_settled_filter_keeps_only_customers_who_had_installments_and_now_owe_nothing(): void
    {
        $user = $this->merchant('professional');
        $this->seedCustomers($user);

        $this->actingAs($user)->get('/customers?bal=settled')->assertOk()
            ->assertSee('مشتری تسویه')
            ->assertDontSee('مشتری بدهکار')->assertDontSee('مشتری بدون قسط');
    }

    public function test_without_a_filter_all_customers_show(): void
    {
        $user = $this->merchant('professional');
        $this->seedCustomers($user);

        $this->actingAs($user)->get('/customers')->assertOk()
            ->assertSee('مشتری بدهکار')->assertSee('مشتری تسویه')->assertSee('مشتری بدون قسط');
    }

    public function test_free_plan_has_no_balance_filter_or_column(): void
    {
        $user = $this->merchant(); // free
        $this->actingAs($user)->get('/customers')->assertOk()->assertDontSee('مانده قسط دارند');
    }

    public function test_old_debt_stays_payable_after_a_downgrade_and_an_agreement_can_be_cancelled_before_voiding(): void
    {
        $user = $this->merchant('professional');
        $irr = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $d = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $irr])->assertCreated();
        $s = $this->api('PUT', '/api/invoices/drafts/'.$d->json('draft_id'), ['version' => $d->json('version'), 'rows' => [[
            'row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'النگو', 'net_weight_g' => '3', 'purity_ppt' => '750', 'wage_percent' => '5', 'profit_percent' => '5',
        ]], 'buyer' => ['name' => 'خریدار قسطی', 'mobile' => '09351119999']])->assertOk();
        $this->api('POST', '/api/invoices/drafts/'.$d->json('draft_id').'/issue', ['mode' => 'ISSUE_ONLY', 'version' => $s->json('version'), 'idempotency_key' => 'k-'.bin2hex(random_bytes(8)),
            'buyer' => ['name' => 'خریدار قسطی', 'mobile' => '09351119999'], 'save_customer' => true])->assertCreated();
        $customer = $this->inTenant($user, fn () => Customer::where('mobile', '09351119999')->firstOrFail());
        $this->api('POST', "/api/customers/{$customer->public_id}/agreements", [
            'invoice_id' => $d->json('draft_id'), 'count' => 3, 'frequency' => 'monthly', 'first_due' => Jalali::date(now()->addMonth(), 'Asia/Tehran'),
        ])->assertCreated();
        $agreement = $this->inTenant($user, fn () => InstallmentAgreement::firstOrFail());

        // Downgrade: no new agreements, but the existing debt is still visible and payable.
        $this->setPlan($this->tenantOf($user), 'free');
        $this->get("/customers/{$customer->public_id}")->assertOk()->assertSee('ثبت دریافت')->assertSee('لغو قرارداد')->assertDontSee('+ قرارداد اقساط');
        $this->api('POST', "/api/agreements/{$agreement->public_id}/payments", ['amount_toman' => '100000', 'method' => 'cash', 'paid_on' => Jalali::date(now(), 'Asia/Tehran'), 'idempotency_key' => 'pay-after-downgrade'])->assertOk();

        // Voiding needs the agreement settled or cancelled first; cancelling keeps the payment on record.
        $this->api('POST', '/api/invoices/'.$d->json('draft_id').'/void', ['reason' => 'WRONG_WEIGHT'])->assertStatus(409)->assertJsonPath('code', 'HAS_INSTALLMENTS');
        $this->api('POST', "/api/agreements/{$agreement->public_id}/cancel", ['reason' => 'x'])->assertStatus(422);
        $this->api('POST', "/api/agreements/{$agreement->public_id}/cancel", ['reason' => 'فاکتور باطل می‌شود'])->assertOk();
        $this->api('POST', "/api/agreements/{$agreement->public_id}/cancel", ['reason' => 'دوباره'])->assertStatus(409);
        $this->api('POST', '/api/invoices/'.$d->json('draft_id').'/void', ['reason' => 'WRONG_WEIGHT'])->assertOk();
        $this->assertSame(1, $this->inTenant($user, fn () => InstallmentPayment::count()));
        $this->assertDatabaseHas('audit_events', ['event' => 'installment.agreement_cancelled']);
    }
}
