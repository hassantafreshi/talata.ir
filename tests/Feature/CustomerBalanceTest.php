<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentAgreement;
use App\Models\InstallmentLine;
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
}
