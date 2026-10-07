<?php

namespace Tests\Feature;

use App\Domain\Identity\LoginService;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Invoices\Qr;
use App\Domain\Market\QuoteService;
use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\Membership;
use App\Support\Tokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_security_revocation_retires_the_qr_code_shows_revoked_and_is_audited(): void
    {
        $user = $this->merchant('basic');
        $id = $this->issued($user);
        $invoice = Invoice::where('public_id', $id)->firstOrFail();
        $old = parse_url(app(InvoiceService::class)->verifyUrl($invoice), PHP_URL_PATH);

        $this->api('POST', "/api/invoices/{$id}/verification/revoke", ['reason' => ''])->assertStatus(422);
        $this->api('POST', "/api/invoices/{$id}/verification/revoke", ['reason' => 'عکس فاکتور در فضای مجازی پخش شده'])->assertOk();

        $invoice->refresh();
        $new = parse_url(app(InvoiceService::class)->verifyUrl($invoice), PHP_URL_PATH);
        $this->assertNotSame($old, $new);
        $this->assertSame('issued', $invoice->status, 'the invoice itself is unchanged');
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('event', 'invoice.verification_revoked')->count());
        $this->get("/invoices/{$id}/print")->assertOk()->assertSee(Qr::svg(config('talata.public_url').$new), false);
        auth()->logout();

        // The old printed sheet reads «لغوشده» and reveals nothing about the invoice; the new code verifies.
        $this->get($old)->assertStatus(410)->assertSee('لغوشده')->assertDontSee($invoice->number)->assertDontSee('خانم آزمون')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get($new)->assertOk()->assertSee('قطعی و معتبر');
        $this->get('/v/'.str_repeat('A', 43))->assertNotFound()->assertDontSee('لغوشده');
    }

    public function test_only_members_who_may_void_can_revoke_and_never_across_shops(): void
    {
        $owner = $this->merchant('basic');
        $id = $this->issued($owner);
        $this->api('POST', '/api/users/invite', ['mobile' => '09371230077', 'permissions' => ['invoice.issue', 'invoices.view']])->assertOk();
        $seller = app(LoginService::class)->completeLogin('09371230077')['user'];
        $invite = Membership::query()->where('invited_mobile', '09371230077')->where('status', 'invited')->firstOrFail();
        $this->actingAs($seller)->api('POST', "/api/invites/{$invite->id}/accept")->assertOk();

        $this->actingAs($seller)->api('POST', "/api/invoices/{$id}/verification/revoke", ['reason' => 'آزمون دسترسی'])->assertForbidden();
        $this->actingAs($this->merchant())->api('POST', "/api/invoices/{$id}/verification/revoke", ['reason' => 'آزمون دسترسی'])->assertNotFound();
        $this->assertSame(0, AuditEvent::withoutGlobalScopes()->where('event', 'invoice.verification_revoked')->count());
    }

    public function test_verification_code_is_stable_and_shows_void_and_replaced_states_independent_of_links_and_plan(): void
    {
        $user = $this->merchant('basic');
        $id = $this->issued($user);
        $invoice = Invoice::where('public_id', $id)->firstOrFail();
        $path = parse_url(app(InvoiceService::class)->verifyUrl($invoice), PHP_URL_PATH);

        // A share link revoked and the plan downgraded: the printed QR keeps verifying the same record.
        $this->api('POST', "/api/invoices/{$id}/share")->assertOk();
        $this->api('POST', "/api/invoices/{$id}/share/revoke")->assertOk();
        $this->setPlan($this->tenantOf($user), 'free');
        $this->assertSame($path, parse_url(app(InvoiceService::class)->verifyUrl($invoice->fresh()), PHP_URL_PATH));
        $this->get($path)->assertOk()->assertSee('قطعی و معتبر');

        // Void, then replaced: the same code says so, never «معتبر», and never links to the new invoice.
        $this->actingAs($user)->api('POST', "/api/invoices/{$id}/void", ['reason' => 'WRONG_WEIGHT'])->assertOk();
        $this->get($path)->assertOk()->assertSee('باطل‌شده')->assertDontSee('قطعی و معتبر');
        $next = $this->api('POST', "/api/invoices/{$id}/replace")->assertOk()->json('next');
        $draftId = basename(dirname(parse_url($next, PHP_URL_PATH)));
        $this->get($path)->assertDontSee('جایگزین‌شده', false); // a draft replacement is not announced yet
        $draft = Invoice::where('public_id', $draftId)->firstOrFail();
        $this->assertNull($draft->verify_token_hash, 'drafts have no verification code');
        $this->inTenant($user, fn () => Invoice::whereKey($draft->id)->update(['status' => 'issued', 'issued_at' => now()]));
        auth()->logout();
        $page = $this->get($path)->assertOk()->assertSee('باطل‌شده و جایگزین‌شده');
        $page->assertDontSee($draftId)->assertDontSee('/v/', false);
    }

    public function test_buyer_details_are_revealed_only_after_the_right_mobile_and_at_most_three_numbers(): void
    {
        $user = $this->merchant();
        $invoice = Invoice::where('public_id', $this->issued($user, 'خانم آزمون'))->firstOrFail();
        $path = parse_url(app(InvoiceService::class)->verifyUrl($invoice), PHP_URL_PATH);
        auth()->logout();

        // The plain verify page never shows the buyer; it offers the mobile gate instead.
        $this->get($path)->assertOk()->assertDontSee('خانم آزمون')->assertSee('نمایش اطلاعات خریدار');

        // Wrong numbers never reveal and count down the allowance; the same wrong number twice is one attempt.
        $this->post($path, ['buyer_mobile' => '09120000001'])->assertOk()->assertDontSee('خانم آزمون')->assertSee('هم‌خوان نیست');
        $this->post($path, ['buyer_mobile' => '09120000001'])->assertOk()->assertDontSee('خانم آزمون');
        $this->post($path, ['buyer_mobile' => '09120000002'])->assertOk()->assertDontSee('خانم آزمون');
        // A third distinct wrong number locks the gate; even the correct number no longer reveals.
        $this->post($path, ['buyer_mobile' => '09120000003'])->assertOk();
        $this->post($path, ['buyer_mobile' => '09351234567'])->assertOk()->assertDontSee('خانم آزمون')->assertSee('موقتاً بسته');
    }

    public function test_the_right_buyer_mobile_reveals_the_name(): void
    {
        $user = $this->merchant();
        $invoice = Invoice::where('public_id', $this->issued($user, 'آقای خریدار'))->firstOrFail();
        $path = parse_url(app(InvoiceService::class)->verifyUrl($invoice), PHP_URL_PATH);
        auth()->logout();

        // Any accepted Iranian form of the right number reveals the buyer's name (and a masked mobile).
        $this->post($path, ['buyer_mobile' => '+989351234567'])->assertOk()->assertSee('آقای خریدار')->assertSee('0935•••4567');
    }

    public function test_share_links_are_short_codes_and_old_long_links_keep_working(): void
    {
        $user = $this->merchant();
        $id = $this->issued($user);
        $url = $this->api('POST', "/api/invoices/{$id}/share")->assertOk()->json('url');
        // «/i/» + 14 letters/digits (owner decision): short enough to keep the invoice SMS small.
        $this->assertMatchesRegularExpression('#/i/[A-Za-z0-9]{14}$#', $url);
        auth()->logout();
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee('خانم آزمون');

        // A link sent before short codes (43-character token) still opens the same invoice.
        $invoice = Invoice::where('public_id', $id)->firstOrFail();
        $old = Tokens::make();
        DB::table('invoice_shares')->insert(['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'token' => encrypt($old, false), 'token_hash' => Tokens::hash($old), 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/i/'.$old)->assertOk()->assertSee('خانم آزمون');
        // Anything else is just «not found».
        $this->get('/i/ABC')->assertNotFound();
        $this->get('/i/'.str_repeat('Z', 14))->assertNotFound();
    }

    public function test_shared_links_carry_a_link_preview_branded_only_for_shops_with_branding(): void
    {
        Storage::fake('local');
        // Free: the static Zarlio card; shop name in the title; never the buyer.
        $free = $this->merchant('free');
        $id = $this->issued($free, 'خانم پنهان');
        $path = parse_url($this->api('POST', "/api/invoices/{$id}/share")->assertOk()->json('url'), PHP_URL_PATH);
        auth()->logout();
        $page = $this->get($path)->assertOk()->assertSee('og:image', false)->assertSee('/og/zarlio-invoice.png', false)->assertSee('og:title', false);
        $this->assertStringNotContainsString('خانم پنهان', implode("\n", array_filter(explode("\n", $page->getContent()), fn ($l) => str_contains($l, 'og:') || str_contains($l, 'name="description"'))));

        // Basic: a card made for the shop (logo/monogram, name, phone), served from our own route.
        $basic = $this->merchant('basic');
        $id = $this->issued($basic);
        $path = parse_url($this->actingAs($basic)->api('POST', "/api/invoices/{$id}/share")->assertOk()->json('url'), PHP_URL_PATH);
        auth()->logout();
        preg_match('#property="og:image" content="([^"]+)"#', $this->get($path)->assertOk()->getContent(), $m);
        $this->assertMatchesRegularExpression('#/og/[0-9a-z]{26}/[0-9a-f]{20}\.png$#', $m[1]);
        $img = $this->get(parse_url($m[1], PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame([1200, 630], array_slice(getimagesizefromstring($img->getContent()), 0, 2));
    }
}
