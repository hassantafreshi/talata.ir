<?php

namespace Tests\Support;

/** Software WebAuthn authenticator (ES256, attestation "none") for server-side tests. */
final class SoftAuthenticator
{
    public \OpenSSLAsymmetricKey $key;

    public string $credentialId;

    public int $counter = 0;

    public ?string $userHandle = null;

    public function __construct(public string $origin = 'http://localhost', public string $rpId = 'localhost', public int $flags = 0x05)
    {
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->credentialId = random_bytes(32);
    }

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public function create(array $options, ?string $challengeOverride = null): array
    {
        $this->userHandle = $options['user']['id'];
        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $challengeOverride ?? $options['challenge'], 'origin' => $this->origin, 'crossOrigin' => false]);
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $cose = self::map([[1, 2], [3, -7], [-1, 1], [-2, new Bytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT))], [-3, new Bytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))]]);
        $authData = hash('sha256', $this->rpId, true).chr($this->flags | 0x40).pack('N', $this->counter)
            .str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$cose;
        $att = self::map([['fmt', 'none'], ['attStmt', self::map([])], ['authData', new Bytes($authData)]]);

        return ['id' => self::b64($this->credentialId), 'rawId' => self::b64($this->credentialId), 'type' => 'public-key',
            'response' => ['clientDataJSON' => self::b64($clientData), 'attestationObject' => self::b64($att), 'transports' => ['internal']]];
    }

    public function get(array $options, ?string $origin = null, bool $bumpCounter = true): array
    {
        if ($bumpCounter) {
            $this->counter++;
        }
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $origin ?? $this->origin]);
        $authData = hash('sha256', $this->rpId, true).chr($this->flags).pack('N', $this->counter);
        openssl_sign($authData.hash('sha256', $clientData, true), $sig, $this->key, OPENSSL_ALGO_SHA256);

        return ['id' => self::b64($this->credentialId), 'rawId' => self::b64($this->credentialId), 'type' => 'public-key',
            'response' => ['clientDataJSON' => self::b64($clientData), 'authenticatorData' => self::b64($authData), 'signature' => self::b64($sig), 'userHandle' => $this->userHandle]];
    }

    // --- tiny CBOR encoder
    private static function head(int $major, int $n): string
    {
        return match (true) {
            $n < 24 => chr(($major << 5) | $n),
            $n < 256 => chr(($major << 5) | 24).chr($n),
            $n < 65536 => chr(($major << 5) | 25).pack('n', $n),
            default => chr(($major << 5) | 26).pack('N', $n),
        };
    }

    public static function enc(mixed $v): string
    {
        return match (true) {
            $v instanceof Bytes => self::head(2, strlen($v->v)).$v->v,
            $v instanceof Encoded => $v->v,
            is_int($v) && $v >= 0 => self::head(0, $v),
            is_int($v) => self::head(1, -1 - $v),
            is_string($v) => self::head(3, strlen($v)).$v,
        };
    }

    /** @param list<array{0:mixed,1:mixed}> $pairs */
    public static function map(array $pairs): Encoded
    {
        $out = self::head(5, count($pairs));
        foreach ($pairs as [$k, $v]) {
            $out .= self::enc($k).self::enc($v);
        }

        return new Encoded($out);
    }
}

final class Bytes
{
    public function __construct(public string $v) {}
}

final class Encoded
{
    public function __construct(public string $v) {}

    public function __toString(): string
    {
        return $this->v;
    }
}
