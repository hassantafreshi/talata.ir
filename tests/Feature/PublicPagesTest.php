<?php

namespace Tests\Feature;

use App\Domain\Invoices\InvoiceService;
use App\Domain\Market\QuoteService;
use App\Models\Invoice;
use Tests\TestCase;

/** Public token pages: headers, what they show, link revoke/expiry (docs/INVOICE_DELIVERY_AND_VERIFICATION.md). */
class PublicPagesTest extends TestCase
{
    private function issued($user, string $buyerName = 'خانم آزمون'): string
    {
        $irr = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $d = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $irr])->assertCreated();
        $s = $this->api('PUT', '/api/invoices/drafts/'.$d->json('draft_id'), ['version' => $d->json('version'), 'rows' => [[
            'row_uid' => 'r1', 'item_type' => 'GOLD', 'name' => 'گوشواره', 'net_weight_g' => '1.5', 'purity_ppt' => '750', 'wage_percent' => '5', 'profit_percent' => '7',
        ]], 'buyer' => ['name' => $buyerName, 'mobile' => '09351234567']])->assertOk();
        $this->api('POST', '/api/invoices/drafts/'.$d->json('draft_id').'/issue', [
            'mode' => 'ISSUE_ONLY', 'version' => $s->json('version'), 'idempotency_key' => 'k-'.bin2hex(random_bytes(8)), 'buyer' => ['name' => $buyerName, 'mobile' => '09351234567'],
        ])->assertCreated();

        return $d->json('draft_id');
    }

    public function test_token_pages_are_private_no_store_no_referrer_and_verification_shows_no_buyer(): void
    {
        $user = $this->merchant();
        $id = $this->issued($user);
        $invoice = Invoice::where('public_id', $id)->firstOrFail();
        $share = $this->api('POST', "/api/invoices/{$id}/share")->assertOk()->json('url');
        $verify = app(InvoiceService::class)->verifyUrl($invoice);
        auth()->logout();

        foreach ([$verify, $share, $share.'/print'] as $url) {
            $res = $this->get(parse_url($url, PHP_URL_PATH))->assertOk();
            $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'), $url);
            $this->assertSame('noindex, nofollow', $res->headers->get('X-Robots-Tag'), $url);
            $this->assertSame('no-referrer', $res->headers->get('Referrer-Policy'), $url);
            $this->assertStringNotContainsString('content="strict-origin-when-cross-origin"', $res->getContent(), $url);
        }
        $page = $this->get(parse_url($verify, PHP_URL_PATH))->assertOk()->assertSee('قطعی و معتبر');
        $page->assertDontSee('خانم آزمون')->assertDontSee('۰۹۳۵')->assertDontSee('0935');
        // Other pages keep the site-wide policy.
        $this->assertSame('strict-origin-when-cross-origin', $this->get('/login')->headers->get('Referrer-Policy'));
    }

    public function test_revoked_link_dies_and_a_new_one_works_and_links_can_expire(): void
    {
        config(['talata.public.share_ttl_days' => 7]);
        $user = $this->merchant();
        $id = $this->issued($user);
        $old = parse_url($this->api('POST', "/api/invoices/{$id}/share")->assertOk()->json('url'), PHP_URL_PATH);
        $this->api('POST', "/api/invoices/{$id}/share/revoke")->assertOk();
        $new = parse_url($this->api('POST', "/api/invoices/{$id}/share")->assertOk()->json('url'), PHP_URL_PATH);

        $this->assertNotSame($old, $new);
        $this->get($old)->assertNotFound();
        $this->get($new)->assertOk();

        $this->travel(8)->days();
        $this->get($new)->assertNotFound();
        // Verification (QR) never expires.
        $this->get(parse_url(app(InvoiceService::class)->verifyUrl(Invoice::where('public_id', $id)->first()), PHP_URL_PATH))->assertOk();
        // Asking again after expiry gives a fresh link.
        $fresh = parse_url($this->actingAs($user)->api('POST', "/api/invoices/{$id}/share")->assertOk()->json('url'), PHP_URL_PATH);
        $this->assertNotSame($new, $fresh);
        $this->get($fresh)->assertOk();
    }

    public function test_void_and_replaced_invoices_verify_as_such_and_drafts_never_verify(): void
    {
        $user = $this->merchant();
        $id = $this->issued($user);
        $invoice = Invoice::where('public_id', $id)->firstOrFail();
        $verify = parse_url(app(InvoiceService::class)->verifyUrl($invoice), PHP_URL_PATH);
        $this->api('POST', "/api/invoices/{$id}/void", ['reason' => 'WRONG_WEIGHT'])->assertOk();
        $this->get($verify)->assertOk()->assertSee('باطل');
        // A replacement draft alone changes nothing; once the replacement is issued, the old one says so.
        $this->api('POST', "/api/invoices/{$id}/replace")->assertOk();
        $this->get($verify)->assertOk()->assertDontSee('جایگزین');
        $replacement = Invoice::where('replaces_invoice_id', $invoice->id)->firstOrFail();
        $this->api('POST', "/api/invoices/drafts/{$replacement->public_id}/issue", [
            'mode' => 'ISSUE_ONLY', 'version' => $replacement->version, 'idempotency_key' => 'k-'.bin2hex(random_bytes(8)), 'buyer' => ['mobile' => '09351234567'],
        ])->assertCreated();
        $this->get($verify)->assertOk()->assertSee('جایگزین');

        $this->get('/v/'.str_repeat('A', 43))->assertNotFound();
        $this->get('/v/not-a-token')->assertNotFound();
    }
}
