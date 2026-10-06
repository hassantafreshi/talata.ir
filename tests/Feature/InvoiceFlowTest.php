<?php

namespace Tests\Feature;

use App\Domain\Invoices\InvoiceService;
use App\Domain\Market\QuoteService;
use App\Models\Invoice;
use App\Models\SmsMessage;
use Tests\TestCase;

class InvoiceFlowTest extends TestCase
{
    private function latestIrr(): string
    {
        return app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
    }

    private function goldRow(array $over = []): array
    {
        return array_merge([
            'row_uid' => 'r'.bin2hex(random_bytes(6)), 'item_type' => 'GOLD', 'name' => 'انگشتر', 'description' => '',
            'net_weight_g' => '2', 'purity_ppt' => '750', 'wage_percent' => '2', 'profit_percent' => '5',
            'discount_toman' => '', 'discount_scope' => 'TAXABLE_COMPONENTS', 'manual_total_toman' => '',
        ], $over);
    }

    /** Creates a priced draft and returns [publicId, version]. */
    protected function draft($user, ?array $rows = null): array
    {
        $res = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $this->latestIrr()])->assertCreated();
        $id = $res->json('draft_id');
        $save = $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $res->json('version'), 'rows' => $rows ?? [$this->goldRow()], 'buyer' => ['name' => 'مشتری', 'mobile' => '09351234567']])->assertOk();
        $this->assertTrue($save->json('valid'));

        return [$id, $save->json('version')];
    }

    protected function issue($user, string $id, int $version, string $mode = 'ISSUE_ONLY', ?string $key = null)
    {
        return $this->actingAs($user)->api('POST', "/api/invoices/drafts/{$id}/issue", [
            'mode' => $mode, 'version' => $version, 'idempotency_key' => $key ?? 'k-'.bin2hex(random_bytes(8)),
            'buyer' => ['name' => 'مشتری', 'mobile' => '09351234567'],
        ]);
    }

    public function test_full_invoice_journey_renders_every_page(): void
    {
        $user = $this->merchant();
        $this->actingAs($user)->get('/invoices/new')->assertOk()->assertSee('شروع');
        [$id, $version] = $this->draft($user);
        $this->get("/invoices/{$id}/items")->assertOk()->assertSee('افزودن ردیف');
        $this->get("/invoices/{$id}/review")->assertOk()->assertSee('صدور و ارسال پیامکی')->assertSee('فقط صدور');

        $issued = $this->issue($user, $id, $version, 'ISSUE_AND_SMS')->assertCreated();
        $this->assertSame('ISSUED', $issued->json('status'));
        $invoice = Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->first();
        $this->assertSame('issued', $invoice->status);
        $this->assertNotNull($invoice->snapshot);
        // 2g × 18K @ rate, wage 2%, profit 5%, VAT 10% on services — same as the shared vector when rate = 100,000,000.
        $this->assertSame(1, SmsMessage::query()->where('invoice_id', $invoice->id)->count());

        $this->get("/invoices/{$id}/issued")->assertOk()->assertSee('صادر شد');
        $this->get("/invoices/{$id}")->assertOk()->assertSee('ابطال فاکتور');
        $this->get("/invoices/{$id}/print")->assertOk()->assertSee('بررسی اصالت فاکتور')->assertSee('<svg', false);
        $this->get('/invoices')->assertOk();
        $this->api('GET', '/api/invoices?filter=issued')->assertOk()->assertJsonPath('total', 1);

        // Public verification: by token, no buyer PII.
        $verifyUrl = app(InvoiceService::class)->verifyUrl($invoice->fresh());
        $path = parse_url($verifyUrl, PHP_URL_PATH);
        auth()->logout();
        $page = $this->get($path)->assertOk()->assertSee('قطعی و معتبر')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $page->assertDontSee('09351234567')->assertDontSee('۰۹۳۵ ۱۲۳ ۴۵۶۷');
        $this->get('/v/'.str_repeat('a', 43))->assertNotFound();
    }

    public function test_issue_is_idempotent_and_never_double_numbers(): void
    {
        $user = $this->merchant();
        [$id, $version] = $this->draft($user);
        $first = $this->issue($user, $id, $version, 'ISSUE_ONLY', 'same-key-123456')->assertCreated();
        $again = $this->issue($user, $id, $version, 'ISSUE_ONLY', 'same-key-123456')->assertOk();
        $this->assertTrue($again->json('replayed'));
        $this->assertSame($first->json('number'), $again->json('number'));
        $this->issue($user, $id, $version, 'ISSUE_ONLY')->assertStatus(409);
        $this->assertSame(1, Invoice::withoutGlobalScope('tenant')->where('status', 'issued')->count());
    }

    public function test_draft_version_conflict_is_rejected(): void
    {
        $user = $this->merchant();
        [$id, $version] = $this->draft($user);
        $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $version - 1, 'rows' => [$this->goldRow()]])->assertStatus(409)->assertJsonPath('code', 'DRAFT_CONFLICT');
    }

    public function test_market_start_with_stale_rate_returns_rate_changed(): void
    {
        $user = $this->merchant();
        $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => '123'])
            ->assertStatus(409)->assertJsonPath('code', 'RATE_CHANGED')->assertJsonStructure(['latest' => ['value_irr']]);
    }

    public function test_issue_requires_complete_business_profile(): void
    {
        $user = $this->merchant(complete: false);
        [$id, $version] = $this->draft($user);
        $this->issue($user, $id, $version)->assertStatus(409)->assertJsonPath('code', 'PROFILE_INCOMPLETE');
    }

    public function test_misc_row_requires_title_and_price_and_ignores_gold_math(): void
    {
        $user = $this->merchant();
        [$id] = $this->draft($user);
        $inv = Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->first();
        $res = $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $inv->version, 'rows' => [
            $this->goldRow(), ['row_uid' => 'misc1', 'item_type' => 'MISC', 'name' => '', 'manual_total_toman' => '200000', 'net_weight_g' => '5'],
        ]])->assertOk();
        $this->assertFalse($res->json('valid'));
        $res = $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $res->json('version'), 'rows' => [
            $this->goldRow(), ['row_uid' => 'misc1', 'item_type' => 'MISC', 'name' => 'جعبه', 'manual_total_toman' => '200000', 'net_weight_g' => '5'],
        ]])->assertOk();
        $this->assertTrue($res->json('valid'));
        $this->assertSame('2000000', $res->json('totals.misc_irr'));
    }

    public function test_void_and_replace(): void
    {
        $user = $this->merchant();
        [$id, $version] = $this->draft($user);
        $this->issue($user, $id, $version)->assertCreated();
        $this->api('POST', "/api/invoices/{$id}/replace")->assertStatus(409)->assertJsonPath('code', 'REPLACE_REQUIRES_VOID');
        $this->api('POST', "/api/invoices/{$id}/void", ['reason' => 'WRONG_WEIGHT'])->assertOk();
        $this->get("/invoices/{$id}")->assertOk()->assertSee('باطل شده');
        $next = $this->api('POST', "/api/invoices/{$id}/replace")->assertOk()->json('next');
        $this->get($next)->assertOk();
    }

    public function test_installment_filter_shows_only_invoices_with_an_agreement(): void
    {
        $user = $this->merchant('professional');
        [$plain, $pv] = $this->draft($user);
        $this->issue($user, $plain, $pv)->assertCreated();
        [$withPlan, $wv] = $this->draft($user);
        $this->issue($user, $withPlan, $wv)->assertCreated();

        $this->inTenant($user, function () use ($withPlan) {
            $invoice = Invoice::where('public_id', $withPlan)->firstOrFail();
            $customer = \App\Models\Customer::create(['name' => 'خریدار قسطی']);
            \App\Models\InstallmentAgreement::create([
                'customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'principal_irr' => '1000000',
                'count' => 3, 'frequency' => 'monthly', 'status' => 'active',
            ]);
        });

        $all = $this->actingAs($user)->api('GET', '/api/invoices')->assertOk();
        $this->assertSame(2, $all->json('total'));
        $only = $this->api('GET', '/api/invoices?installment=1')->assertOk();
        $this->assertSame(1, $only->json('total'));
        $this->assertStringContainsString($withPlan, $only->json('html'));
        $this->assertStringNotContainsString($plain, $only->json('html'));
    }

    public function test_this_month_filter_excludes_invoices_issued_before_this_month(): void
    {
        $user = $this->merchant('professional');
        [$old, $ov] = $this->draft($user);
        $this->issue($user, $old, $ov)->assertCreated();
        [$fresh, $fv] = $this->draft($user);
        $this->issue($user, $fresh, $fv)->assertCreated();

        $this->inTenant($user, fn () => Invoice::where('public_id', $old)->update(['issued_at' => now()->subMonths(2)]));

        $month = $this->actingAs($user)->api('GET', '/api/invoices?month=1')->assertOk();
        $this->assertSame(1, $month->json('total'));
        $this->assertStringContainsString($fresh, $month->json('html'));
        $this->assertStringNotContainsString($old, $month->json('html'));
    }
}
