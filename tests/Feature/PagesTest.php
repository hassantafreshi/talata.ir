<?php

namespace Tests\Feature;

use App\Domain\Invoices\LayoutSettings;
use App\Domain\Market\QuoteService;
use App\Models\InstallmentAgreement;
use App\Models\InstallmentPayment;
use App\Models\Invoice;
use App\Models\InvoiceLayout;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PagesTest extends TestCase
{
    public function test_every_merchant_page_renders_for_each_plan(): void
    {
        foreach (['free', 'basic', 'professional'] as $plan) {
            $user = $this->merchant($plan);
            $this->actingAs($user);
            foreach (['/invoices/new', '/invoices', '/mazneh', '/calculator', '/customers', '/settings', '/settings/business', '/settings/appearance', '/settings/sms-template', '/settings/users', '/settings/plan', '/settings/sms'] as $url) {
                $this->get($url)->assertOk()->assertHeader('Content-Security-Policy');
            }
            $this->api('GET', '/api/quotes/latest')->assertOk()->assertJsonStructure(['value_irr', 'freshness', 'is_demo']);
            $this->api('GET', '/api/quotes/board')->assertOk()->assertJsonStructure(['rows' => ['GOLD_18_SELL', 'GOLD_18_BUY', 'GOLD_24', 'USD_IRR', 'XAU_USD']]);
            $this->api('GET', '/api/entitlements')->assertOk()->assertJsonPath('plan.code', $plan);
            auth()->logout();
        }
    }

    public function test_guests_are_redirected_and_security_headers_are_set(): void
    {
        $this->get('/invoices/new')->assertRedirect('/login');
        $res = $this->get('/login')->assertOk();
        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', explode('style-src', $csp)[0]);
        $res->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_customers_and_installments_flow(): void
    {
        $user = $this->merchant('professional');
        $this->actingAs($user);
        $id = $this->api('POST', '/api/customers', ['name' => 'زهرا', 'mobile' => '۰۹۳۵ ۱۱۱ ۲۲۳۳'])->assertCreated()->json('id');
        $this->api('POST', '/api/customers', ['name' => 'تکراری', 'mobile' => '09351112233'])->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_CUSTOMER');
        $this->get("/customers/{$id}")->assertOk()->assertSee('زهرا');
        $this->get("/customers/{$id}/agreements/new")->assertOk()->assertSee('سررسید اولین قسط');
        $first = jymd(now()->addDays(10));
        $preview = $this->api('POST', "/api/customers/{$id}/agreements", ['principal_toman' => '10000000', 'down_payment_toman' => '1000000', 'count' => 3, 'frequency' => 'monthly', 'first_due' => $first, 'preview' => true])->assertOk();
        $this->assertTrue($preview->json('ok'));
        $this->assertCount(3, $preview->json('lines'));
        $this->api('POST', "/api/customers/{$id}/agreements", ['principal_toman' => '10000000', 'down_payment_toman' => '1000000', 'count' => 3, 'frequency' => 'monthly', 'first_due' => $first])->assertCreated();
        $agreement = InstallmentAgreement::withoutGlobalScope('tenant')->first();
        $key = 'pay-'.bin2hex(random_bytes(6));
        $this->api('POST', "/api/agreements/{$agreement->public_id}/payments", ['amount_toman' => '3000000', 'method' => 'cash', 'paid_on' => jymd(), 'idempotency_key' => $key])->assertOk();
        $this->api('POST', "/api/agreements/{$agreement->public_id}/payments", ['amount_toman' => '3000000', 'method' => 'cash', 'paid_on' => jymd(), 'idempotency_key' => $key])->assertOk();
        $this->assertSame(1, InstallmentPayment::withoutGlobalScope('tenant')->count());
        $this->api('POST', "/api/agreements/{$agreement->public_id}/payments", ['amount_toman' => '999999999', 'method' => 'cash', 'paid_on' => jymd(), 'idempotency_key' => 'pay-over-1'])->assertStatus(422);
        $this->get("/customers/{$id}")->assertOk()->assertSee('پرداخت‌های ثبت‌شده');
    }

    public function test_installments_are_professional_only(): void
    {
        $user = $this->merchant('basic');
        $this->actingAs($user);
        $id = $this->api('POST', '/api/customers', ['name' => 'علی'])->assertCreated()->json('id');
        $this->get("/customers/{$id}/agreements/new")->assertRedirect();
        $this->api('POST', "/api/customers/{$id}/agreements", ['principal_toman' => '1000000', 'count' => 2, 'frequency' => 'monthly', 'first_due' => jymd(now()->addDay())])->assertStatus(403);
    }

    public function test_appearance_preview_save_and_snapshot_isolation(): void
    {
        $user = $this->merchant('basic');
        $this->actingAs($user);
        $html = $this->api('POST', '/api/settings/appearance/preview', ['settings' => ['template_id' => 'shop']])->assertOk()->json('html');
        $this->assertStringContainsString('بررسی اصالت فاکتور', $html);
        $settings = LayoutSettings::preset('shop');
        $settings['blocks'][] = ['kind' => '<script>', 'area' => 'header'];
        $settings['summary']['public_note'] = ['visible' => true, 'text' => '<b>سپاس</b>'];
        $this->api('PUT', '/api/settings/appearance', ['settings' => $settings, 'version' => 1])->assertOk();
        $this->api('PUT', '/api/settings/appearance', ['settings' => $settings, 'version' => 1])->assertStatus(409);
        $stored = InvoiceLayout::withoutGlobalScope('tenant')->first()->settings;
        $this->assertSame('سپاس', $stored['summary']['public_note']['text']);
        $this->assertNotContains('<script>', array_column($stored['blocks'], 'kind'));
        // Free plan cannot save.
        $free = $this->merchant();
        $this->actingAs($free)->api('PUT', '/api/settings/appearance', ['settings' => $settings, 'version' => 1])->assertStatus(403);
    }

    public function test_logo_upload_is_reencoded_and_gated_by_plan(): void
    {
        $free = $this->merchant();
        $this->actingAs($free)->api('POST', '/api/settings/logo', ['logo' => UploadedFile::fake()->image('l.png', 300, 300)])->assertStatus(403);
        $user = $this->merchant('basic');
        $this->actingAs($user);
        $this->post('/api/settings/logo', ['logo' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], ['Accept' => 'application/json'])->assertStatus(422);
        $url = $this->post('/api/settings/logo', ['logo' => UploadedFile::fake()->image('l.jpg', 400, 300)], ['Accept' => 'application/json'])->assertOk()->json('url');
        $res = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $res->getContent());
    }

    public function test_public_share_page_and_print(): void
    {
        $user = $this->merchant();
        $this->actingAs($user);
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $d = $this->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate]);
        $id = $d->json('draft_id');
        $s = $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $d->json('version'), 'rows' => [['row_uid' => 'a', 'item_type' => 'GOLD', 'name' => 'گردنبند', 'net_weight_g' => '۳٫۵', 'purity_ppt' => '750', 'wage_percent' => '7', 'profit_percent' => '7']], 'buyer' => ['name' => 'مریم', 'mobile' => '09351112233']]);
        $this->api('POST', "/api/invoices/drafts/{$id}/issue", ['mode' => 'ISSUE_ONLY', 'version' => $s->json('version'), 'idempotency_key' => 'k-share-001'])->assertCreated();
        $url = $this->api('POST', "/api/invoices/{$id}/share")->assertOk()->json('url');
        $path = parse_url($url, PHP_URL_PATH);
        auth()->logout();
        $this->get($path)->assertOk()->assertSee('گردنبند')->assertSee('مریم')->assertDontSee('09351112233');
        $this->get($path.'/print')->assertOk()->assertSee('بررسی اصالت فاکتور');
        $inv = Invoice::withoutGlobalScope('tenant')->where('public_id', $id)->first();
        $this->assertNotSame($inv->getRawOriginal('verify_token'), $inv->verify_token, 'raw token must be stored encrypted');
        $this->actingAs($user)->api('POST', "/api/invoices/{$id}/share/revoke")->assertOk();
        auth()->logout();
        $this->get($path)->assertNotFound();
    }
}
