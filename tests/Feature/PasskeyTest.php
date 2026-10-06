<?php

namespace Tests\Feature;

use App\Domain\Identity\WebAuthn\Cbor;
use App\Domain\Identity\WebAuthn\WebAuthnException;
use App\Models\AuditEvent;
use App\Models\Passkey;
use App\Models\User;
use Tests\Support\SoftAuthenticator;
use Tests\TestCase;

class PasskeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost:8000', 'talata.webauthn.rp_id' => null, 'talata.webauthn.origins' => null]);
    }

    private function device(int $flags = 0x05): SoftAuthenticator
    {
        return new SoftAuthenticator('http://localhost:8000', 'localhost', $flags);
    }

    /** Logged in recently (as right after an SMS login) and registers the device. */
    private function enroll(User $user, SoftAuthenticator $device)
    {
        $this->actingAs($user)->withSession(['auth_at' => now()->getTimestamp()]);
        $options = $this->api('POST', '/api/passkeys/options')->assertOk()->json();
        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->assertNotSame($user->mobile, base64_decode(strtr($options['user']['id'], '-_', '+/')), 'user handle is opaque');

        return $this->api('POST', '/api/passkeys', ['credential' => $device->create($options)]);
    }

    private function login(SoftAuthenticator $device, ?string $origin = null, bool $bump = true)
    {
        $options = $this->postJson('/api/auth/passkey/options')->assertOk()->json();
        $this->assertSame([], $options['allowCredentials'], 'no account hints before login');

        return $this->postJson('/api/auth/passkey/verify', ['credential' => $device->get($options, $origin, $bump)]);
    }

    public function test_enroll_then_sign_in_with_fingerprint(): void
    {
        $user = $this->merchant();
        $device = $this->device();
        $this->enroll($user, $device)->assertCreated();
        $pk = Passkey::query()->firstOrFail();
        $this->assertSame('user', $pk->owner_type);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pk->public_key_pem);
        $this->get('/settings')->assertOk()->assertSee('ورود با اثر انگشت');

        auth()->logout();
        $this->flushSession();
        $this->login($device)->assertOk()->assertJsonPath('next', route('invoices.new'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, Passkey::query()->value('sign_count'));
        $this->get('/invoices/new')->assertOk();
    }

    public function test_login_rejects_replay_wrong_origin_and_cloned_counter(): void
    {
        $user = $this->merchant();
        $device = $this->device();
        $this->enroll($user, $device)->assertCreated();
        auth()->logout();

        // Replaying a signed assertion against a fresh challenge fails (challenge mismatch).
        $first = $this->postJson('/api/auth/passkey/options')->json();
        $signed = $device->get($first);
        $this->postJson('/api/auth/passkey/verify', ['credential' => $signed])->assertOk();
        auth()->logout();
        $this->postJson('/api/auth/passkey/options');
        $this->postJson('/api/auth/passkey/verify', ['credential' => $signed])->assertStatus(422)->assertJsonPath('code', 'PASSKEY_FAILED');
        // Phishing origin.
        $this->login($device, 'https://talata-login.example')->assertStatus(422);
        // Counter that does not increase (cloned key): same value as the last accepted login.
        $device->counter = Passkey::query()->value('sign_count');
        $this->login($device, null, false)->assertStatus(422);
        $this->assertGuest();
        $this->assertGreaterThan(0, AuditEvent::query()->where('event', 'auth.passkey_failed')->count());
    }

    public function test_user_verification_is_required(): void
    {
        $user = $this->merchant();
        $this->enroll($user, $this->device(0x01))->assertStatus(422)->assertJsonPath('code', 'PASSKEY_REGISTER_FAILED');
        $this->assertSame(0, Passkey::query()->count());
    }

    public function test_enrolment_needs_recent_login_and_own_passkeys_only(): void
    {
        $user = $this->merchant();
        $this->actingAs($user)->withSession(['auth_at' => now()->subHour()->getTimestamp()]);
        $this->api('POST', '/api/passkeys/options')->assertStatus(403)->assertJsonPath('code', 'REAUTH_REQUIRED');

        $this->enroll($user, $this->device())->assertCreated();
        $id = Passkey::query()->value('id');
        $other = $this->merchant();
        $this->actingAs($other)->api('DELETE', "/api/passkeys/{$id}")->assertNotFound();
        $this->actingAs($user)->api('DELETE', "/api/passkeys/{$id}")->assertOk();
        $this->assertSame(0, Passkey::query()->count());
    }

    public function test_registration_challenge_cannot_be_reused_or_forged(): void
    {
        $user = $this->merchant();
        $device = $this->device();
        $this->actingAs($user)->withSession(['auth_at' => now()->getTimestamp()]);
        $options = $this->api('POST', '/api/passkeys/options')->json();
        $this->api('POST', '/api/passkeys', ['credential' => $device->create($options, 'forged-challenge')])->assertStatus(422);
        $this->api('POST', '/api/passkeys', ['credential' => $device->create($options)])->assertStatus(422); // consumed
        $this->assertSame(0, Passkey::query()->count());
    }

    public function test_cbor_decoder_rejects_hostile_input(): void
    {
        foreach (["\x9f", "\x5b\xff\xff\xff\xff\xff\xff\xff\xff", "\xa1", str_repeat("\x81", 40)."\x00", "\x9a\xff\xff\xff\xff"] as $bad) {
            try {
                Cbor::decode($bad);
                $this->fail('accepted hostile CBOR '.bin2hex($bad));
            } catch (WebAuthnException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
