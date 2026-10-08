<?php

namespace Tests\Feature;

use App\Domain\Invoices\ProformaService;
use App\Domain\Market\QuoteService;
use App\Domain\Sms\SmsGateway;
use App\Domain\Sms\SmsService;
use App\Models\Invoice;
use App\Models\OtpChallenge;
use App\Models\Proforma;
use App\Models\PushSubscription;
use App\Models\ShopProfile;
use App\Models\SmsMessage;
use App\Support\Digits;
use App\Support\WebPush;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** پیش‌فاکتور (docs/PROFORMA.md): send, validity, customer confirmation by mobile + SMS code, automatic issuance. */
class ProformaTest extends TestCase
{
    private const BUYER = '09351234567';

    private function draft($user): array
    {
        $rate = app(QuoteService::class)->latestDto('Asia/Tehran')['value_irr'];
        $res = $this->actingAs($user)->api('POST', '/api/invoices/drafts', ['mode' => 'MARKET', 'value_irr' => $rate])->assertCreated();
        $id = $res->json('draft_id');
        $state = $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => $res->json('version'), 'rows' => [
            ['item_type' => 'GOLD', 'name' => 'انگشتر طلا زنانه', 'net_weight_g' => '3.5', 'purity_ppt' => '750', 'wage_percent' => '18', 'profit_percent' => '7'],
        ], 'buyer' => ['name' => 'خانم رضایی', 'mobile' => self::BUYER]])->assertOk();

        return [$id, $state->json('version')];
    }

    private function send($user, array $over = []): array
    {
        [$id, $version] = $this->draft($user);
        $res = $this->api('POST', "/api/invoices/drafts/{$id}/proforma", $over + ['version' => $version, 'hours' => 24, 'send_sms' => true, 'buyer' => ['name' => 'خانم رضایی', 'mobile' => self::BUYER]]);

        return [$id, $res];
    }

    private function lastSmsBody(): string
    {
        $sent = app(SmsGateway::class)->sent;

        return Digits::toLatin(end($sent)['body']);
    }

    public function test_sending_locks_the_draft_and_texts_the_customer_with_the_validity_and_link(): void
    {
        $user = $this->merchant();
        [$id, $res] = $this->send($user);
        $res->assertCreated();
        $this->assertNull($res->json('sms.message_fa'), (string) $res->json('sms.message_fa'));
        $p = Proforma::query()->firstOrFail();
        $this->assertSame('SENT', $p->state());
        $this->assertSame(24, $p->valid_hours);
        $this->assertSame('proforma', Invoice::query()->where('public_id', $id)->value('status'));

        $sms = SmsMessage::query()->where('purpose', 'PROFORMA')->firstOrFail();
        $this->assertSame(self::BUYER, $sms->recipient);
        $this->assertStringContainsString('پیش‌فاکتور طلافروشی آزمون', $sms->body);
        $this->assertStringContainsString('مهلت تأیید تا ', $sms->body);
        $this->assertLessThanOrEqual(2, $sms->segments, $sms->body);
        $this->assertStringContainsString('/p/'.$p->token, $sms->body);

        // Locked: the draft cannot be edited or issued while the پیش‌فاکتور is out.
        $this->api('PUT', "/api/invoices/drafts/{$id}", ['version' => 99, 'rows' => [], 'buyer' => []])->assertStatus(409);
        $this->get("/invoices/{$id}/items")->assertRedirect(route('proformas.show', $p));
        $this->get(route('proformas.show', $p))->assertOk()->assertSee('منتظر تأیید مشتری')->assertSee('مهلت تأیید');
        $this->get(route('proformas.print', $p))->assertOk()->assertSee('پیش‌فاکتور')->assertSee('مدت اعتبار');
        $this->get('/proformas')->assertOk()->assertSee('منتظر تأیید');
    }

    public function test_customer_confirms_with_mobile_and_sms_code_and_the_invoice_is_issued(): void
    {
        $user = $this->merchant();
        $this->send($user)[1]->assertCreated();
        $p = Proforma::query()->firstOrFail();
        auth()->logout();

        $this->get('/p/'.$p->token)->assertOk()->assertSee('منتظر تأیید شما')->assertSee('مهلت تأیید')->assertSee('دریافت کد تأیید')
            ->assertDontSee(self::BUYER)->assertHeader('Cache-Control', 'no-store, private');
        // Another number never receives a code.
        $this->post('/p/'.$p->token.'/code', ['mobile' => '09121111111'])->assertStatus(422)->assertSee('یکی نیست');
        $this->post('/p/'.$p->token.'/code', ['mobile' => '۰۹۳۵ ۱۲۳ ۴۵۶۷'])->assertOk()->assertSee('تأیید پیش‌فاکتور و نهایی کردن خرید');
        $sent = app(SmsGateway::class)->sent;
        $this->assertStringContainsString('کد تأیید پیش‌فاکتور', end($sent)['body']);
        preg_match('/(\d{6})/', $this->lastSmsBody(), $m);
        $challenge = OtpChallenge::query()->where('purpose', 'proforma')->latest('created_at')->value('id');

        $this->post('/p/'.$p->token.'/confirm', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->post('/p/'.$p->token.'/confirm', ['challenge' => $challenge, 'code' => $m[1]])->assertOk()->assertSee('خرید شما تأیید شد')->assertSee('صادر شد');

        $p->refresh();
        $this->assertSame('CONFIRMED', $p->status);
        $this->assertNotNull($p->issued_at);
        $invoice = Invoice::withoutGlobalScope('tenant')->findOrFail($p->invoice_id);
        $this->assertSame('issued', $invoice->status);
        $this->assertSame((string) $p->payable_irr, (string) $invoice->payable_irr, 'same amount as the confirmed پیش‌فاکتور');
        $this->assertSame(self::BUYER, $invoice->buyer_mobile);
    }

    public function test_after_the_validity_it_is_cancelled_and_cannot_be_confirmed(): void
    {
        $user = $this->merchant();
        $this->send($user, ['hours' => 3])[1]->assertCreated();
        $p = Proforma::query()->firstOrFail();
        $this->travel(3)->hours();
        $this->travel(1)->minutes();
        $this->assertSame('EXPIRED', $p->refresh()->state());
        auth()->logout();
        $this->get('/p/'.$p->token)->assertOk()->assertSee('ابطال شده')->assertSee('مهلت تأیید')->assertDontSee('دریافت کد تأیید');
        $this->post('/p/'.$p->token.'/code', ['mobile' => self::BUYER])->assertStatus(422)->assertSee('ابطال شده');
    }

    public function test_cancel_or_edit_reopens_the_draft_and_the_old_link_shows_cancelled(): void
    {
        $user = $this->merchant();
        [$id] = $this->send($user);
        $p = Proforma::query()->firstOrFail();
        $this->api('POST', "/api/proformas/{$p->public_id}/cancel", ['reason' => 'EDIT'])->assertOk()->assertJsonPath('next', route('invoices.items', $id));
        $this->assertSame('draft', Invoice::query()->where('public_id', $id)->value('status'));
        $this->get("/invoices/{$id}/items")->assertOk();
        $this->get('/p/'.$p->token)->assertOk()->assertSee('ابطال شده');
    }

    public function test_validity_is_chosen_by_the_shop_and_remembered(): void
    {
        $user = $this->merchant();
        [, $bad] = $this->send($user, ['hours' => 5]);
        $bad->assertStatus(422);
        Invoice::query()->delete();
        [, $res] = $this->send($user, ['hours' => 72]);
        $res->assertCreated();
        $this->assertSame(72, Proforma::query()->firstOrFail()->valid_hours);
        [$id] = $this->draft($user);
        $this->get("/invoices/{$id}/review")->assertOk()->assertSee('name="pf_hours" value="72" checked', false);
    }

    public function test_a_mobile_is_required_and_other_shops_cannot_see_it(): void
    {
        $user = $this->merchant();
        [$id, $version] = $this->draft($user);
        $this->api('POST', "/api/invoices/drafts/{$id}/proforma", ['version' => $version, 'hours' => 24, 'buyer' => ['mobile' => '']])->assertStatus(422)->assertJsonPath('code', 'BUYER_MOBILE_REQUIRED');
        $this->api('POST', "/api/invoices/drafts/{$id}/proforma", ['version' => $version, 'hours' => 24, 'buyer' => ['mobile' => self::BUYER]])->assertCreated();
        $p = Proforma::query()->firstOrFail();

        $this->actingAs($this->merchant());
        $this->get(route('proformas.show', $p))->assertNotFound();
        $this->api('POST', "/api/proformas/{$p->public_id}/cancel", ['reason' => 'OTHER'])->assertNotFound();
    }

    public function test_three_wrong_numbers_lock_the_confirmation(): void
    {
        $user = $this->merchant();
        $this->send($user)[1]->assertCreated();
        $p = Proforma::query()->firstOrFail();
        auth()->logout();
        foreach (['09121111111', '09121111112', '09121111113'] as $n) {
            $this->post('/p/'.$p->token.'/code', ['mobile' => $n])->assertStatus(422);
        }
        $this->post('/p/'.$p->token.'/code', ['mobile' => self::BUYER])->assertStatus(422)->assertSee('با فروشنده تماس بگیرید');
        $this->assertSame(0, OtpChallenge::query()->where('purpose', 'proforma')->count());
    }

    public function test_when_automatic_issuance_fails_the_shop_sees_why_and_issues_it_later(): void
    {
        $user = $this->merchant();
        $this->send($user)[1]->assertCreated();
        $p = Proforma::query()->firstOrFail();
        // Profile became incomplete meanwhile: issuance must not happen silently, nor be lost.
        ShopProfile::withoutGlobalScope('tenant')->where('tenant_id', $p->tenant_id)->update(['address' => null]);
        auth()->logout();
        $this->post('/p/'.$p->token.'/code', ['mobile' => self::BUYER])->assertOk();
        $sent = app(SmsGateway::class)->sent;
        preg_match('/(\d{6})/', Digits::toLatin(end($sent)['body']), $m);
        $challenge = OtpChallenge::query()->where('purpose', 'proforma')->latest('created_at')->value('id');
        $this->post('/p/'.$p->token.'/confirm', ['challenge' => $challenge, 'code' => $m[1]])->assertOk()->assertSee('خرید شما تأیید شد')->assertSee('فروشنده پس از بررسی');
        $p->refresh();
        $this->assertSame('CONFIRMED', $p->status);
        $this->assertNull($p->issued_at);
        $this->assertNotNull($p->issue_error);

        ShopProfile::withoutGlobalScope('tenant')->where('tenant_id', $p->tenant_id)->update(['address' => 'تهران، بازار بزرگ، پلاک ۱']);
        $this->actingAs($user)->get(route('proformas.show', $p))->assertOk()->assertSee('صدور فاکتور فروش')->assertSee($p->issue_error);
        $this->api('POST', "/api/proformas/{$p->public_id}/issue")->assertOk();
        $this->assertNotNull($p->refresh()->issued_at);
        $this->assertSame('issued', Invoice::query()->whereKey($p->invoice_id)->value('status'));
    }

    private function confirmAsCustomer(Proforma $p)
    {
        $this->post('/p/'.$p->token.'/code', ['mobile' => self::BUYER])->assertOk();
        $sent = app(SmsGateway::class)->sent;
        preg_match('/(\d{6})/', Digits::toLatin(end($sent)['body']), $m);
        $challenge = OtpChallenge::query()->where('purpose', 'proforma')->latest('created_at')->value('id');

        return $this->post('/p/'.$p->token.'/confirm', ['challenge' => $challenge, 'code' => $m[1]])->assertOk();
    }

    public function test_free_plan_issues_automatically_and_cannot_switch_to_manual(): void
    {
        $this->actingAs($this->merchant());
        $this->get('/settings/proforma')->assertOk()->assertSee('صدور فاکتور پس از تأیید مشتری')->assertSee('پلن پایه و حرفه‌ای')->assertSee('قیمت پیش‌فاکتور قفل است');
        $this->api('PUT', '/api/settings/proforma', ['auto_issue' => false])->assertStatus(403);
        $this->api('PUT', '/api/settings/proforma', ['hours' => 48])->assertOk();
        $this->api('PUT', '/api/settings/proforma', ['hours' => 5])->assertStatus(422);
        $this->assertSame(48, ProformaService::defaultHours());
    }

    public function test_manual_issuance_on_basic_waits_for_the_shop_and_keeps_the_choice_it_was_sent_with(): void
    {
        $user = $this->merchant('basic');
        $this->actingAs($user)->api('PUT', '/api/settings/proforma', ['auto_issue' => false])->assertOk();
        $this->get('/settings')->assertSee('صدور دستی توسط فروشنده');
        $this->send($user)[1]->assertCreated();
        $p = Proforma::query()->firstOrFail();
        $this->assertFalse($p->auto_issue);
        // Switching back later does not change a پیش‌فاکتور already sent.
        $this->api('PUT', '/api/settings/proforma', ['auto_issue' => true])->assertOk();

        auth()->logout();
        $this->get('/p/'.$p->token)->assertSee('فروشنده فاکتور فروش را صادر می‌کند')->assertSee('قیمت قفل است');
        $this->confirmAsCustomer($p)->assertSee('خرید شما تأیید شد')->assertSee('فروشنده پس از بررسی');
        $p->refresh();
        $this->assertSame('CONFIRMED', $p->status);
        $this->assertNull($p->issued_at);
        $this->assertNull($p->issue_error);
        $this->assertSame('proforma', Invoice::withoutGlobalScope('tenant')->whereKey($p->invoice_id)->value('status'));

        $this->actingAs($user)->get(route('proformas.show', $p))->assertSee('مشتری پیش‌فاکتور را تأیید کرد')->assertSee('روش «دستی»');
        $this->get('/proformas')->assertSee('منتظر صدور');
        $this->get('/invoices')->assertSee('تأییدشده منتظر صدور فاکتور');
        $this->api('POST', "/api/proformas/{$p->public_id}/issue")->assertOk();
        $this->assertSame('issued', Invoice::query()->whereKey($p->invoice_id)->value('status'));
    }

    public function test_a_shop_that_loses_the_capability_falls_back_to_automatic(): void
    {
        $user = $this->merchant('basic');
        $this->actingAs($user)->api('PUT', '/api/settings/proforma', ['auto_issue' => false])->assertOk();
        $tenant = $this->tenantOf($user);
        $this->assertFalse(ProformaService::autoIssue($tenant));
        $this->setPlan($tenant, 'free');
        $this->assertTrue(ProformaService::autoIssue($tenant->refresh()));
    }

    private function deviceKeys(): array
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        return ['p256dh' => WebPush::b64(WebPush::rawPublic($k)), 'auth' => WebPush::b64(random_bytes(16))];
    }

    public function test_owner_gets_an_sms_and_a_phone_notification_with_the_invoice_link(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201), 'updates.push.services.mozilla.com/*' => Http::response('', 410)]);
        $user = $this->merchant();
        $this->actingAs($user)->api('POST', '/api/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/dev1', 'keys' => $this->deviceKeys()])->assertOk();
        $this->api('POST', '/api/push/subscribe', ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/old', 'keys' => $this->deviceKeys()])->assertOk();
        $this->send($user)[1]->assertCreated();
        $p = Proforma::query()->firstOrFail();
        auth()->logout();
        $this->confirmAsCustomer($p);

        $invoice = Invoice::withoutGlobalScope('tenant')->findOrFail($p->invoice_id);
        $notice = SmsMessage::query()->where('purpose', 'SHOP_NOTICE')->firstOrFail();
        $this->assertSame($user->mobile, $notice->recipient);
        $this->assertSame('OPERATIONAL', $notice->charge_source);
        $this->assertStringContainsString('را تأیید کرد', $notice->body);
        $this->assertStringContainsString(route('invoices.issued', $invoice), $notice->body);

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://fcm.googleapis.com/') && $r->header('Content-Encoding')[0] === 'aes128gcm'
            && str_starts_with($r->header('Authorization')[0], 'vapid t=') && $r->header('Urgency')[0] === 'high');
        // The device that answered 410 (unsubscribed) is forgotten; the live one stays.
        $this->assertSame(['https://fcm.googleapis.com/fcm/send/dev1'], PushSubscription::query()->pluck('endpoint')->all());
    }

    public function test_the_owner_sms_can_be_switched_off_and_is_capped(): void
    {
        Http::fake();
        $user = $this->merchant();
        $this->actingAs($user)->api('PUT', '/api/settings/proforma', ['notify_sms' => false])->assertOk();
        $this->send($user)[1]->assertCreated();
        $p = Proforma::query()->firstOrFail();
        auth()->logout();
        $this->confirmAsCustomer($p);
        $this->assertSame(0, SmsMessage::query()->where('purpose', 'SHOP_NOTICE')->count());

        config(['talata.sms.shop_notice_daily_cap' => 1]);
        $sms = app(SmsService::class);
        $this->assertNotNull($sms->queueShopNotice($p->tenant_id, '09121234567', 'x', 'k1'));
        $this->assertNull($sms->queueShopNotice($p->tenant_id, '09121234567', 'x', 'k2'));
        $this->assertNull($sms->queueShopNotice($p->tenant_id, '+447700900123', 'x', 'k3'), 'Iranian numbers only');
    }

    public function test_push_subscriptions_accept_only_known_push_services(): void
    {
        $this->actingAs($this->merchant());
        $this->api('GET', '/api/push/key')->assertOk()->assertJsonStructure(['key']);
        $this->api('POST', '/api/push/subscribe', ['endpoint' => 'https://169.254.169.254/x', 'keys' => $this->deviceKeys()])->assertStatus(422)->assertJsonPath('code', 'PUSH_SERVICE');
        $this->api('POST', '/api/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'keys' => ['p256dh' => 'abc', 'auth' => 'def']])->assertStatus(422);
        $this->api('POST', '/api/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'keys' => $this->deviceKeys()])->assertOk();
        $this->api('POST', '/api/push/unsubscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'])->assertOk();
        $this->assertSame(0, PushSubscription::query()->count());
        $this->get('/settings/proforma')->assertOk()->assertSee('خبر تأیید مشتری')->assertSee('اعلان روی همین گوشی');
    }
}
