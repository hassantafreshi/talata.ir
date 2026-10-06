<?php

namespace App\Domain\Identity\WebAuthn;

/** COSE public key (RFC 9053) → PEM SubjectPublicKeyInfo. Supports ES256 (P-256) and RS256. */
final class CoseKey
{
    public const ES256 = -7;

    public const RS256 = -257;

    /** @return array{alg:int,pem:string} */
    public static function toPem(array $cose): array
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        if ($kty === 2 && $alg === self::ES256) {
            if (($cose[-1] ?? null) !== 1 || strlen($cose[-2] ?? '') !== 32 || strlen($cose[-3] ?? '') !== 32) {
                throw new WebAuthnException('Invalid P-256 key');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$cose[-2].$cose[-3];
        } elseif ($kty === 3 && $alg === self::RS256) {
            $n = $cose[-1] ?? '';
            $e = $cose[-2] ?? '';
            if (strlen($n) < 256 || $e === '') {
                throw new WebAuthnException('RSA key too small');
            }
            $rsa = self::seq(self::int($n).self::int($e));
            $der = self::seq(self::seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00")."\x03".self::len(strlen($rsa) + 1)."\x00".$rsa);
        } else {
            throw new WebAuthnException('Unsupported credential algorithm');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        if (! openssl_pkey_get_public($pem)) {
            throw new WebAuthnException('Unreadable public key');
        }

        return ['alg' => $alg, 'pem' => $pem];
    }

    private static function int(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) & 0x80) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::len(strlen($bytes)).$bytes;
    }

    private static function seq(string $inner): string
    {
        return "\x30".self::len(strlen($inner)).$inner;
    }

    private static function len(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\x00");

        return chr(0x80 | strlen($b)).$b;
    }
}
