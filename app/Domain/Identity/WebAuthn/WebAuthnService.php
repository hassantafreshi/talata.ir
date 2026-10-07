<?php

namespace App\Domain\Identity\WebAuthn;

use App\Models\Passkey;
use App\Support\DbLock;
use App\Support\Tokens;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * WebAuthn (passkey) relying party: fingerprint, face or device-lock sign-in.
 * - Attestation "none": we trust the key, not the device maker; biometrics never reach us.
 * - User verification is required on every ceremony.
 * - Challenges are random, single use, bound to the session and short-lived.
 * - Origin, RP ID hash, flags, signature and signature counter are verified server-side.
 */
final class WebAuthnService
{
    private const FLAG_UP = 0x01;

    private const FLAG_UV = 0x04;

    private const FLAG_BE = 0x08;

    private const FLAG_BS = 0x10;

    private const FLAG_AT = 0x40;

    public const MAX_PER_OWNER = 10;

    private function session(): Session
    {
        return request()->session();
    }

    public function rpId(): string
    {
        return (string) (config('talata.webauthn.rp_id') ?: parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /** @return list<string> */
    public function origins(): array
    {
        $configured = array_filter(array_map('trim', explode(',', (string) config('talata.webauthn.origins'))));
        if ($configured) {
            return array_values($configured);
        }
        $u = parse_url((string) config('app.url'));

        return [($u['scheme'] ?? 'https').'://'.($u['host'] ?? '').(isset($u['port']) ? ':'.$u['port'] : '')];
    }

    /** Validation rules for the browser's credential JSON (shape and size limits; no arrays where strings are expected). */
    public static function rules(bool $registration): array
    {
        $r = [
            'credential' => ['required', 'array'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.rawId' => ['required', 'string', 'max:700', 'regex:/^[A-Za-z0-9_-]+$/'],
            'credential.response' => ['required', 'array'],
            'credential.response.clientDataJSON' => ['required', 'string', 'max:4096', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];

        return $registration ? $r + [
            'credential.response.attestationObject' => ['required', 'string', 'max:16384', 'regex:/^[A-Za-z0-9_-]+$/'],
            'credential.response.transports' => ['nullable', 'array', 'max:6'],
            'credential.response.transports.*' => ['string', 'max:20'],
        ] : $r + [
            'credential.response.authenticatorData' => ['required', 'string', 'max:4096', 'regex:/^[A-Za-z0-9_-]+$/'],
            'credential.response.signature' => ['required', 'string', 'max:2048', 'regex:/^[A-Za-z0-9_-]+$/'],
            'credential.response.userHandle' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]*$/'],
        ];
    }

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]*$/', $text)) {
            throw new WebAuthnException('Invalid base64url');
        }
        $bin = base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        if ($bin === false) {
            throw new WebAuthnException('Invalid base64url');
        }

        return $bin;
    }

    private function challenge(string $purpose, array $extra = []): string
    {
        $challenge = random_bytes(32);
        $this->session()->put("webauthn.$purpose", ['challenge' => self::b64($challenge), 'expires' => now()->addMinutes(3)->getTimestamp()] + $extra);

        return self::b64($challenge);
    }

    private function pullChallenge(string $purpose): array
    {
        $state = $this->session()->pull("webauthn.$purpose");
        if (! is_array($state) || ($state['expires'] ?? 0) < now()->getTimestamp()) {
            throw new WebAuthnException('Challenge expired');
        }

        return $state;
    }

    // ---------------------------------------------------------------- registration

    /** Options for navigator.credentials.create(). $owner is a User or StaffUser. */
    public function registrationOptions(Model $owner, string $ownerType, string $displayName): array
    {
        $handle = $this->handleFor($owner);
        $exclude = Passkey::query()->where('owner_type', $ownerType)->where('owner_id', $owner->getKey())->pluck('credential_id')
            ->map(fn ($id) => ['type' => 'public-key', 'id' => $id])->values()->all();

        return [
            'challenge' => $this->challenge('register', ['owner_type' => $ownerType, 'owner_id' => $owner->getKey()]),
            'rp' => ['id' => $this->rpId(), 'name' => 'زرلیو'],
            'user' => ['id' => $handle, 'name' => (string) $owner->mobile, 'displayName' => $displayName],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => CoseKey::ES256], ['type' => 'public-key', 'alg' => CoseKey::RS256]],
            'timeout' => 120000,
            'attestation' => 'none',
            'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'residentKey' => 'preferred', 'requireResidentKey' => false, 'userVerification' => 'required'],
            'excludeCredentials' => $exclude,
        ];
    }

    public function register(Model $owner, string $ownerType, array $credential, string $name): Passkey
    {
        try {
            return $this->doRegister($owner, $ownerType, $credential, $name);
        } catch (\TypeError|\ValueError|\JsonException|\ErrorException $e) {
            throw new WebAuthnException('Malformed credential');
        }
    }

    private function doRegister(Model $owner, string $ownerType, array $credential, string $name): Passkey
    {
        $state = $this->pullChallenge('register');
        if ($state['owner_type'] !== $ownerType || (string) $state['owner_id'] !== (string) $owner->getKey()) {
            throw new WebAuthnException('Challenge belongs to another account');
        }
        if (($credential['type'] ?? '') !== 'public-key') {
            throw new WebAuthnException('Wrong credential type');
        }
        $clientDataJson = self::unb64((string) ($credential['response']['clientDataJSON'] ?? ''));
        $this->checkClientData($clientDataJson, 'webauthn.create', $state['challenge']);

        $attestation = Cbor::decode(self::unb64((string) ($credential['response']['attestationObject'] ?? '')));
        if (! is_array($attestation) || ! is_string($attestation['authData'] ?? null) || ! is_string($attestation['fmt'] ?? null)) {
            throw new WebAuthnException('Malformed attestation object');
        }
        $auth = $this->parseAuthData($attestation['authData'], true);
        $rawId = self::unb64((string) ($credential['rawId'] ?? ''));
        if ($rawId === '' || ! hash_equals($auth['credential_id'], $rawId) || strlen($rawId) > 380) {
            throw new WebAuthnException('Credential id mismatch');
        }
        $key = CoseKey::toPem($auth['cose']);
        $id = self::b64($rawId);

        return DB::transaction(function () use ($owner, $ownerType, $id, $key, $auth, $name, $credential) {
            DbLock::key('passkeys:'.$ownerType.':'.$owner->getKey());
            $count = Passkey::query()->where('owner_type', $ownerType)->where('owner_id', $owner->getKey())->count();
            if ($count >= self::MAX_PER_OWNER) {
                throw new WebAuthnException('Too many passkeys');
            }
            if (Passkey::query()->where('credential_id', $id)->exists()) {
                throw new WebAuthnException('Credential already registered');
            }
            $transports = array_values(array_intersect((array) ($credential['response']['transports'] ?? []), ['internal', 'hybrid', 'usb', 'nfc', 'ble']));

            return Passkey::create([
                'owner_type' => $ownerType, 'owner_id' => $owner->getKey(), 'credential_id' => $id, 'public_key_pem' => $key['pem'],
                'alg' => $key['alg'], 'sign_count' => $auth['sign_count'], 'name' => mb_substr(trim(strip_tags($name)) ?: 'این دستگاه', 0, 60),
                'transports' => $transports, 'backed_up' => (bool) ($auth['flags'] & self::FLAG_BS),
            ]);
        });
    }

    // ---------------------------------------------------------------- authentication

    /** Options for navigator.credentials.get(): discoverable credentials, no account hint (no enumeration). */
    public function loginOptions(string $ownerType): array
    {
        return [
            'challenge' => $this->challenge('login', ['owner_type' => $ownerType]),
            'rpId' => $this->rpId(),
            'timeout' => 120000,
            'userVerification' => 'required',
            'allowCredentials' => [],
        ];
    }

    /** Verifies an assertion and returns the matching passkey (counter updated). */
    public function authenticate(string $ownerType, array $credential, callable $handleOf): Passkey
    {
        try {
            return $this->doAuthenticate($ownerType, $credential, $handleOf);
        } catch (\TypeError|\ValueError|\JsonException|\ErrorException $e) {
            throw new WebAuthnException('Malformed credential');
        }
    }

    private function doAuthenticate(string $ownerType, array $credential, callable $handleOf): Passkey
    {
        $state = $this->pullChallenge('login');
        if (($state['owner_type'] ?? '') !== $ownerType || ($credential['type'] ?? '') !== 'public-key') {
            throw new WebAuthnException('Wrong ceremony');
        }
        $rawId = self::unb64((string) ($credential['rawId'] ?? ''));
        $clientDataJson = self::unb64((string) ($credential['response']['clientDataJSON'] ?? ''));
        $authData = self::unb64((string) ($credential['response']['authenticatorData'] ?? ''));
        $signature = self::unb64((string) ($credential['response']['signature'] ?? ''));
        $this->checkClientData($clientDataJson, 'webauthn.get', $state['challenge']);
        $auth = $this->parseAuthData($authData, false);

        return DB::transaction(function () use ($ownerType, $rawId, $clientDataJson, $authData, $signature, $auth, $credential, $handleOf) {
            $passkey = Passkey::query()->where('owner_type', $ownerType)->where('credential_id', self::b64($rawId))->lockForUpdate()->first();
            if (! $passkey) {
                throw new WebAuthnException('Unknown credential');
            }
            $userHandle = (string) ($credential['response']['userHandle'] ?? '');
            if ($userHandle !== '' && ! hash_equals((string) $handleOf($passkey), $userHandle)) {
                throw new WebAuthnException('User handle mismatch');
            }
            $ok = openssl_verify($authData.hash('sha256', $clientDataJson, true), $signature, $passkey->public_key_pem, OPENSSL_ALGO_SHA256);
            if ($ok !== 1) {
                throw new WebAuthnException('Bad signature');
            }
            // Counter must grow when the authenticator uses one; a non-increasing value suggests a cloned key.
            if (($auth['sign_count'] > 0 || $passkey->sign_count > 0) && $auth['sign_count'] <= $passkey->sign_count) {
                throw new WebAuthnException('Signature counter did not increase (possible cloned credential)');
            }
            $passkey->forceFill(['sign_count' => $auth['sign_count'], 'last_used_at' => now(), 'backed_up' => (bool) ($auth['flags'] & self::FLAG_BS)])->save();

            return $passkey;
        });
    }

    // ---------------------------------------------------------------- helpers

    public function handleFor(Model $owner): string
    {
        if (! $owner->webauthn_handle) {
            $owner->forceFill(['webauthn_handle' => Tokens::make(32)])->save();
        }

        return $owner->webauthn_handle;
    }

    private function checkClientData(string $json, string $type, string $challenge): void
    {
        $c = json_decode($json, true);
        if (! is_array($c) || ($c['type'] ?? '') !== $type) {
            throw new WebAuthnException('Wrong client data type');
        }
        if (! hash_equals($challenge, (string) ($c['challenge'] ?? ''))) {
            throw new WebAuthnException('Challenge mismatch');
        }
        if (! in_array($c['origin'] ?? '', $this->origins(), true)) {
            throw new WebAuthnException('Origin not allowed');
        }
        if (($c['crossOrigin'] ?? false) === true) {
            throw new WebAuthnException('Cross-origin ceremonies are not allowed');
        }
    }

    /** @return array{flags:int,sign_count:int,credential_id?:string,cose?:array} */
    private function parseAuthData(string $d, bool $expectCredential): array
    {
        if (strlen($d) < 37) {
            throw new WebAuthnException('Authenticator data too short');
        }
        if (! hash_equals(hash('sha256', $this->rpId(), true), substr($d, 0, 32))) {
            throw new WebAuthnException('RP ID mismatch');
        }
        $flags = ord($d[32]);
        if (! ($flags & self::FLAG_UP) || ! ($flags & self::FLAG_UV)) {
            throw new WebAuthnException('User presence and verification are required');
        }
        if (($flags & self::FLAG_BS) && ! ($flags & self::FLAG_BE)) {
            throw new WebAuthnException('Invalid backup flags');
        }
        $out = ['flags' => $flags, 'sign_count' => unpack('N', substr($d, 33, 4))[1]];
        if ($expectCredential) {
            if (! ($flags & self::FLAG_AT) || strlen($d) < 55) {
                throw new WebAuthnException('Missing attested credential data');
            }
            $idLen = unpack('n', substr($d, 53, 2))[1];
            $out['credential_id'] = substr($d, 55, $idLen);
            if (strlen($out['credential_id']) !== $idLen) {
                throw new WebAuthnException('Truncated credential id');
            }
            $offset = 55 + $idLen;
            $cose = Cbor::decode($d, $offset);
            if (! is_array($cose)) {
                throw new WebAuthnException('Malformed credential key');
            }
            $out['cose'] = $cose;
        }

        return $out;
    }
}
