<?php

namespace Tests\Feature;

use App\Domain\Market\QuoteService;
use App\Models\FeatureOverride;
use App\Models\Invoice;
use App\Models\MarketQuote;
use App\Models\ShopProfile;
use App\Models\SmsMessage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Phase 1 checklist acceptance items for drafts, starts without a market rate, snapshots (docs/PHASE_1_CHECKLIST.md M2/M3). */
class InvoiceAcceptanceTest extends TestCase
{
    private function irr(): string
    {
        return app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
    }

    private function save($id, int $version, array $rows, array $buyer = [])
    {
        return $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $version, 'rows' => $rows, 'buyer' => $buyer]);
    }

    private function issueNow(string $id, int $version, string $mode = 'ISSUE_ONLY', ?string $key = null)
    {
        return $this->api('POST', "/api/invoices/drafts/{$id}/issue", ['mode' => $mode, 'version' => $version, 'idempotency_key' => $key ?? 'k-'.bin2hex(random_bytes(8)), 'buyer' => ['mobile' => '09351234567']]);
    }

    public function test_calculator_works_with_no_market_rate_and_without_invoice_rights(): void
    {
        $user = $this->merchant();
        MarketQuote::query()->delete();
        FeatureOverride::withoutGlobalScope('tenant')->create(['tenant_id' => $this->tenantOf($user)->id, 'key' => 'invoice.finalize', 'value' => ['enabled' => false], 'reason' => 'test']);
        $this->actingAs($user)->get('/calculator')->assertOk()->assertSee('نرخ بازار در دسترس نیست')->assertDontSee('data-to-invoice', false);
        $this->get('/mazneh')->assertOk();
    }

    public function test_manual_rate_start_and_misc_only_invoice_without_any_market_rate(): void
    {
        $user = $this->merchant();
        MarketQuote::query()->delete();
        $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => '1'])->assertStatus(409)->assertJsonPath('code', 'RATE_UNAVAILABLE');
        $this->api('POST', '/api/invoices/drafts', ['mode' => 'MANUAL', 'value_toman' => 'abc', 'reason' => 'MARKET_UNAVAILABLE'])->assertStatus(422);

        // Manual rate: stored with its reason and used for pricing.
        $m = $this->api('POST', '/api/invoices/drafts', ['mode' => 'MANUAL', 'value_toman' => '۱۰٬۰۰۰٬۰۰۰', 'reason' => 'MARKET_UNAVAILABLE'])->assertCreated();
        $inv = Invoice::where('public_id', $m->json('draft_id'))->firstOrFail();
        $this->assertSame('MANUAL', $inv->rate_mode);
        $this->assertSame('100000000', (string) $inv->accepted_rate_irr);
        $this->assertSame('MARKET_UNAVAILABLE', $inv->rate_manual_reason);

        // MISC-only (no gold, no rate): payable equals the manual row price; no gold math, no tax.
        $n = $this->api('POST', '/api/invoices/drafts', ['mode' => 'NONE'])->assertCreated();
        $s = $this->save($n->json('draft_id'), $n->json('version'), [['row_uid' => 'm1', 'item_type' => 'MISC', 'name' => 'جعبه و کارت هدیه', 'description' => 'بسته‌بندی', 'manual_total_toman' => '۲۵۰٬۰۰۰']])->assertOk();
        $this->issueNow($n->json('draft_id'), $s->json('version'))->assertCreated();
        $issued = Invoice::where('public_id', $n->json('draft_id'))->firstOrFail();
        $this->assertSame('2500000', (string) $issued->payable_irr);
        $this->assertSame('0', (string) $issued->vat_irr);
        $this->assertSame('NONE', $issued->rate_mode);
    }

    public function test_row_order_names_and_descriptions_survive_review_issue_print_and_public_view(): void
    {
        $user = $this->merchant();
        $d = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $this->irr()])->assertCreated();
        $rows = [
            ['row_uid' => 'a', 'item_type' => 'GOLD', 'name' => 'گردنبند زنجیر کارتیه', 'description' => 'طرح ونیزی، طول ۴۵ سانتی‌متر، قفل مخفی', 'net_weight_g' => '4.2', 'purity_ppt' => '750', 'wage_percent' => '12', 'profit_percent' => '7'],
            ['row_uid' => 'b', 'item_type' => 'MISC', 'name' => 'حکاکی نام', 'description' => 'حروف فارسی روی پلاک', 'manual_total_toman' => '150000'],
            ['row_uid' => 'c', 'item_type' => 'GOLD', 'name' => 'انگشتر ۲۱ عیار', 'description' => 'سنگ فیروزه نیشابور', 'net_weight_g' => '2', 'purity_ppt' => '875', 'wage_percent' => '10', 'profit_percent' => '7'],
        ];
        $s = $this->save($d->json('draft_id'), $d->json('version'), $rows, ['name' => 'مشتری', 'mobile' => '09351234567'])->assertOk();
        $id = $d->json('draft_id');
        $order = fn ($html) => [mb_strpos($html, 'گردنبند زنجیر کارتیه'), mb_strpos($html, 'حکاکی نام'), mb_strpos($html, 'انگشتر ۲۱ عیار')];

        $review = $this->get("/invoices/{$id}/review")->assertOk()->assertSee('طرح ونیزی، طول ۴۵ سانتی‌متر، قفل مخفی')->assertSee('حروف فارسی روی پلاک')->getContent();
        $o = $order($review);
        $this->assertTrue($o[0] < $o[1] && $o[1] < $o[2], 'review keeps row order');

        $this->issueNow($id, $s->json('version'))->assertCreated();
        $print = $this->get("/invoices/{$id}/print")->assertOk()->assertSee('سنگ فیروزه نیشابور')->getContent();
        $o = $order($print);
        $this->assertTrue($o[0] < $o[1] && $o[1] < $o[2], 'print keeps row order');

        $url = $this->api('POST', "/api/invoices/{$id}/share")->assertOk()->json('url');
        $public = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee('طرح ونیزی، طول ۴۵ سانتی‌متر، قفل مخفی')->getContent();
        $o = $order($public);
        $this->assertTrue($o[0] < $o[1] && $o[1] < $o[2], 'public view keeps row order');
    }

    public function test_incomplete_profile_detours_to_business_settings_and_returns_to_the_same_draft(): void
    {
        $user = $this->merchant('free', complete: false);
        $d = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $this->irr()])->assertCreated();
        $s = $this->save($d->json('draft_id'), $d->json('version'), [['row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'انگشتر', 'net_weight_g' => '1', 'purity_ppt' => '750', 'wage_percent' => '0', 'profit_percent' => '0']])->assertOk();
        $res = $this->issueNow($d->json('draft_id'), $s->json('version'))->assertStatus(409)->assertJsonPath('code', 'PROFILE_INCOMPLETE');
        $this->assertStringContainsString('return='.$d->json('draft_id'), $res->json('redirect'));

        $next = $this->api('POST', '/api/settings/business', ['name' => 'طلای آفتاب', 'business_mobile' => '۰۹۱۲۱۱۱۲۲۳۳', 'address' => 'اصفهان، بازار', 'return' => $d->json('draft_id')])->assertOk()->json('next');
        $this->assertSame(route('invoices.review', $d->json('draft_id')), $next);
        $this->issueNow($d->json('draft_id'), $s->json('version'))->assertCreated();
        // No landline: the invoice shows the business mobile as the contact number.
        $this->get('/invoices/'.$d->json('draft_id').'/print')->assertOk()->assertSee('۰۹۱۲ ۱۱۱ ۲۲۳۳');
    }

    public function test_issued_invoices_cannot_be_edited_or_deleted_and_an_sms_issue_replays_once(): void
    {
        $user = $this->merchant();
        $d = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $this->irr()])->assertCreated();
        $s = $this->save($d->json('draft_id'), $d->json('version'), [['row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'انگشتر', 'net_weight_g' => '1', 'purity_ppt' => '750', 'wage_percent' => '0', 'profit_percent' => '0']], ['mobile' => '09351234567'])->assertOk();
        $this->issueNow($d->json('draft_id'), $s->json('version'), 'ISSUE_AND_SMS', 'same-sms-key-01')->assertCreated();
        $this->issueNow($d->json('draft_id'), $s->json('version'), 'ISSUE_AND_SMS', 'same-sms-key-01')->assertOk();
        $this->assertSame(1, SmsMessage::query()->where('purpose', 'INVOICE')->count());

        $this->save($d->json('draft_id'), $s->json('version'), [])->assertStatus(409)->assertJsonPath('code', 'NOT_DRAFT');
        $this->api('DELETE', '/api/invoices/drafts/'.$d->json('draft_id'))->assertStatus(409)->assertJsonPath('code', 'NOT_DRAFT');
        $this->assertSame('issued', Invoice::where('public_id', $d->json('draft_id'))->value('status'));
    }

    public function test_issued_invoice_keeps_its_layout_and_logo_after_edits_and_a_downgrade(): void
    {
        $user = $this->merchant('basic');
        $this->actingAs($user)->post('/api/settings/logo', ['logo' => UploadedFile::fake()->image('a.png', 300, 300)], ['Accept' => 'application/json'])->assertOk();
        $d = $this->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $this->irr()])->assertCreated();
        $s = $this->save($d->json('draft_id'), $d->json('version'), [['row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'انگشتر', 'net_weight_g' => '1', 'purity_ppt' => '750', 'wage_percent' => '0', 'profit_percent' => '0']])->assertOk();
        $this->issueNow($d->json('draft_id'), $s->json('version'))->assertCreated();
        $id = $d->json('draft_id');
        $before = $this->get("/invoices/{$id}/print")->assertOk()->getContent();
        $logoV1 = $this->inTenant($user, fn () => ShopProfile::first()->logo_version);

        // Afterwards: new logo, new address, then a downgrade to Free.
        $this->post('/api/settings/logo', ['logo' => UploadedFile::fake()->image('b.png', 400, 400)], ['Accept' => 'application/json'])->assertOk();
        $this->api('POST', '/api/settings/business', ['name' => 'طلافروشی آزمون', 'business_mobile' => '09121112233', 'address' => 'نشانی تازه پس از صدور'])->assertOk();
        $this->setPlan($this->tenantOf($user), 'free');

        $after = $this->get("/invoices/{$id}/print")->assertOk()->getContent();
        $strip = fn ($h) => preg_replace('/<meta name="csrf-token"[^>]*>|nonce="[^"]*"/', '', $h);
        $this->assertSame($strip($before), $strip($after), 'issued print must not change');
        $this->assertStringNotContainsString('نشانی تازه پس از صدور', $after);
        // The old logo file is still served for that invoice.
        $tenantPublic = $this->tenantOf($user)->public_id;
        $this->get(route('public.logo', [$tenantPublic, $logoV1], false))->assertOk();
    }
}
