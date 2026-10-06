<?php

namespace Tests\Feature;

use App\Domain\Identity\ProofOfWork;
use App\Domain\Sms\SmsGateway;
use App\Models\Passkey;
use App\Models\User;
use App\Support\Digits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasskeyOfferTest extends TestCase
{
    use RefreshDatabase;

    private function solvePow(): array
    {
        $c = $this->getJson('/api/auth/pow')->assertOk()->json();
        $nonce = 0;
        while (ProofOfWork::leadingZeroBits(hash('sha256', $c['challenge'].':'.$nonce, true)) < $c['bits']) {
            $nonce++;
        }

        return ['pow_challenge' => $c['challenge'], 'pow_nonce' => (string) $nonce];
    }

    private function login(string $mobile, array $extra = []): void
    {
        $pow = $this->solvePow();
        $this->travel(3)->seconds(); // the proof-of-work rejects solutions returned too fast (bot guard)
        $this->postJson('/api/auth/otp/request', ['mobile' => $mobile] + $pow + $extra)->assertOk();
        $sent = app(SmsGateway::class)->sent;
        preg_match('/(\d{6})/', Digits::toLatin(end($sent)['body']), $m);
        $this->postJson('/api/auth/otp/verify', ['code' => $m[1]])->assertOk();
    }

    public function test_offer_appears_after_sms_login_for_a_user_without_a_passkey(): void
    {
        $this->login('09123456789');
        // The first page after login carries the one-time offer markup.
        $this->get(route('calculator'))->assertOk()->assertSee('data-passkey-offer', false);
        // It is one-shot: a later page does not repeat it.
        $this->get(route('calculator'))->assertOk()->assertDontSee('data-passkey-offer', false);
    }

    public function test_no_offer_when_the_user_already_has_a_passkey(): void
    {
        $user = User::create(['mobile' => '09123456700', 'name' => null]);
        Passkey::create([
            'owner_type' => 'user', 'owner_id' => $user->id, 'credential_id' => 'cred-'.$user->id,
            'public_key_pem' => 'x', 'alg' => -7, 'sign_count' => 0, 'name' => 'گوشی',
        ]);
        $this->login('09123456700');
        $this->get(route('calculator'))->assertOk()->assertDontSee('data-passkey-offer', false);
    }

    public function test_no_offer_when_heading_to_passkey_setup(): void
    {
        // Arriving via the "set up a passkey" link should not also show the offer card.
        $this->get(route('login', ['then' => 'passkey']))->assertOk();
        $this->login('09123456711');
        $this->get(route('settings'))->assertOk()->assertDontSee('data-passkey-offer', false);
    }
}
