<?php

namespace Tests\Feature;

use App\Domain\Identity\WebAuthn\Cbor;
use App\Domain\Identity\WebAuthn\WebAuthnException;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        $this->login($device)->assertOk()->assertJsonPath('next', route('home'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, Passkey::query()->value('sign_count'));
        $this->get('/home')->assertRedirect(route('invoices.new'));
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

    public function test_malformed_credentials_are_rejected_cleanly(): void
    {
        $this->postJson('/api/auth/passkey/options')->assertOk();
        $bad = [
            ['rawId' => ['x'], 'type' => 'public-key', 'response' => []],
            ['rawId' => 'abc', 'type' => 'public-key', 'response' => ['clientDataJSON' => ['a'], 'authenticatorData' => 'a', 'signature' => 'a']],
            ['rawId' => 'abc', 'type' => 'public-key', 'response' => ['clientDataJSON' => SoftAuthenticator::b64('{"type":"webauthn.get","challenge":["x"],"origin":"http://localhost:8000"}'), 'authenticatorData' => 'AAAA', 'signature' => 'AAAA']],
        ];
        foreach ($bad as $credential) {
            $this->postJson('/api/auth/passkey/options');
            $this->postJson('/api/auth/passkey/verify', ['credential' => $credential])->assertStatus(422);
        }
        $this->assertSame(0, DB::connection(config('database.log_connection'))->table('system_logs')->where('level', 'error')->count(), 'no error-level log rows from hostile input');
    }

    public function test_login_challenge_expires(): void
    {
        $user = $this->merchant();
        $device = $this->device();
        $this->enroll($user, $device)->assertCreated();
        auth()->logout();
        $options = $this->postJson('/api/auth/passkey/options')->assertOk()->json();
        $this->travel(4)->minutes();
        $this->postJson('/api/auth/passkey/verify', ['credential' => $device->get($options)])->assertStatus(422)->assertJsonPath('code', 'PASSKEY_FAILED');
        $this->assertGuest();
    }

    public function test_a_removed_passkey_no_longer_signs_in(): void
    {
        $user = $this->merchant();
        $device = $this->device();
        $this->enroll($user, $device)->assertCreated();
        $this->api('DELETE', '/api/passkeys/'.Passkey::query()->value('id'))->assertOk();
        auth()->logout();
        $this->flushSession();
        $this->login($device)->assertStatus(422)->assertJsonPath('code', 'PASSKEY_FAILED');
        $this->assertGuest();
    }

    public function test_wrong_rp_missing_user_verification_or_foreign_user_handle_are_rejected(): void
    {
        $user = $this->merchant();
        $device = $this->device();
        $this->enroll($user, $device)->assertCreated();
        auth()->logout();

        $device->rpId = 'evil.example';                 // assertion made for another site
        $this->login($device)->assertStatus(422);
        $device->rpId = 'localhost';

        $device->flags = 0x01;                          // user present but not verified (no fingerprint/PIN)
        $this->login($device)->assertStatus(422);
        $device->flags = 0x05;

        $realHandle = $device->userHandle;
        $device->userHandle = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->login($device)->assertStatus(422);
        $device->userHandle = $realHandle;

        $this->assertGuest();
        $this->login($device)->assertOk();               // the genuine assertion still works
    }

    public function test_passkey_never_opens_a_removed_membership_or_a_suspended_shop(): void
    {
        $user = $this->merchant();
        $device = $this->device();
        $this->enroll($user, $device)->assertCreated();
        auth()->logout();
        $this->flushSession();

        $tenant = $this->tenantOf($user);
        $tenant->forceFill(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => 'test'])->save();
        $this->login($device)->assertOk();
        // Signed in as a person, but the suspended shop's screens and APIs stay closed.
        $this->get('/invoices')->assertStatus(403);
        $this->api('GET', '/api/invoices')->assertStatus(403)->assertJsonPath('code', 'TENANT_SUSPENDED');

        auth()->logout();
        $this->flushSession();
        Membership::query()->where('user_id', $user->id)->update(['status' => 'removed']);
        $this->login($device)->assertStatus(422)->assertJsonPath('code', 'PASSKEY_FAILED');
        $this->assertGuest();
    }
}
