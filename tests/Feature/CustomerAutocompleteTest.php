<?php

namespace Tests\Feature;

use App\Domain\Invoices\InvoiceService;
use App\Domain\Market\QuoteService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\NationalId;
use Tests\TestCase;

/** Buyer autocomplete on the review page, one mobile per person, optional national ID (owner request 2026-10-07). */
class CustomerAutocompleteTest extends TestCase
{
    private function issue(array $buyer, bool $save = false)
    {
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $d = $this->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $s = $this->api('PUT', '/api/invoices/drafts/'.$d->json('draft_id'), ['version' => $d->json('version'), 'rows' => [[
            'row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'انگشتر', 'net_weight_g' => '1', 'purity_ppt' => '750',
        ]]])->assertOk();

        return [$d->json('draft_id'), $this->api('POST', '/api/invoices/drafts/'.$d->json('draft_id').'/issue', [
            'mode' => 'ISSUE_ONLY', 'version' => $s->json('version'), 'idempotency_key' => 'k-'.bin2hex(random_bytes(8)), 'buyer' => $buyer, 'save_customer' => $save,
        ])];
    }

    public function test_national_id_checksum(): void
    {
        $this->assertSame('0084575948', NationalId::normalize('۰۰۸۴۵۷۵۹۴۸'));
        $this->assertSame('0084575948', NationalId::normalize('84575948'));   // leading zeros dropped when typed
        $this->assertNull(NationalId::normalize('0084575949'));               // wrong check digit
        $this->assertNull(NationalId::normalize('1111111111'));
        $this->assertNull(NationalId::normalize('12345'));
    }

    public function test_search_suggests_customers_by_name_mobile_or_national_id(): void
    {
        $this->actingAs($this->merchant());
        $this->api('POST', '/api/customers', ['name' => 'مریم حسینی', 'mobile' => '09351234567', 'national_id' => '0084575948'])->assertCreated();
        $this->api('GET', '/api/customers?q=مری')->assertOk()->assertJsonPath('items.0.mobile', '09351234567')->assertJsonPath('items.0.national_id', '0084575948');
        $this->api('GET', '/api/customers?q=0935123')->assertOk()->assertJsonPath('items.0.name', 'مریم حسینی');
        $this->api('GET', '/api/customers?q=00845')->assertOk()->assertJsonPath('items.0.name', 'مریم حسینی');

        // One mobile, one person; one national ID, one person.
        $this->api('POST', '/api/customers', ['name' => 'دیگری', 'mobile' => '09351234567'])->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_CUSTOMER');
        $this->api('POST', '/api/customers', ['name' => 'دیگری', 'national_id' => '0084575948'])->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_NATIONAL_ID');
        $this->api('POST', '/api/customers', ['name' => 'سوم', 'national_id' => '0084575949'])->assertStatus(422)->assertJsonPath('errors.national_id.0', 'کد ملی درست نیست (۱۰ رقم).');
    }

    public function test_a_known_mobile_completes_the_buyer_and_the_national_id_prints_but_never_goes_public(): void
    {
        $this->actingAs($this->merchant());
        $this->api('POST', '/api/customers', ['name' => 'مریم حسینی', 'mobile' => '09351234567', 'national_id' => '0084575948'])->assertCreated();

        // Only the mobile typed: name and national ID come from the saved customer.
        [$id] = $this->issue(['mobile' => '09351234567']);
        $invoice = Invoice::where('public_id', $id)->firstOrFail();
        $this->assertSame(['مریم حسینی', '0084575948'], [$invoice->buyer_name, $invoice->buyer_national_id]);
        $this->assertSame('0084575948', $invoice->snapshot['buyer']['national_id']);
        $this->get("/invoices/{$id}/print")->assertOk()->assertSee('کد ملی')->assertSee('۰۰۸۴۵۷۵۹۴۸');

        $share = parse_url($this->api('POST', "/api/invoices/{$id}/share")->assertOk()->json('url'), PHP_URL_PATH);
        $verify = parse_url(app(InvoiceService::class)->verifyUrl($invoice), PHP_URL_PATH);
        auth()->logout();
        foreach ([$share, $share.'/print', $verify] as $path) {
            $this->get($path)->assertOk()->assertDontSee('۰۰۸۴۵۷۵۹۴۸')->assertDontSee('0084575948');
        }
    }

    public function test_a_new_buyer_with_national_id_is_saved_and_a_bad_one_is_refused(): void
    {
        $this->actingAs($this->merchant());
        [, $res] = $this->issue(['name' => 'علی رضایی', 'mobile' => '09121234567', 'national_id' => '0084575949']);
        $res->assertStatus(422)->assertJsonPath('code', 'BUYER_NATIONAL_ID_INVALID');

        [, $res] = $this->issue(['name' => 'علی رضایی', 'mobile' => '09121234567', 'national_id' => '۰۰۸۴۵۷۵۹۴۸'], true);
        $res->assertCreated();
        $this->assertSame('0084575948', Customer::query()->where('mobile', '09121234567')->value('national_id'));
    }
}
