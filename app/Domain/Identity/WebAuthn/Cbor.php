<?php

namespace App\Domain\Identity\WebAuthn;

/** Minimal CBOR (RFC 8949) decoder for WebAuthn attestation objects and COSE keys. */
final class Cbor
{
    private const MAX_DEPTH = 16;

    public static function decode(string $data, int &$offset = 0): mixed
    {
        return self::item($data, $offset, 0);
    }

    private static function item(string $d, int &$o, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new WebAuthnException('CBOR nesting too deep');
        }
        $initial = self::byte($d, $o);
        $major = $initial >> 5;
        $info = $initial & 0x1F;
        if ($major === 7) {
            return match ($info) {
                20 => false, 21 => true, 22, 23 => null,
                25 => self::half(self::take($d, $o, 2)),
                26 => unpack('G', self::take($d, $o, 4))[1],
                27 => unpack('E', self::take($d, $o, 8))[1],
                default => throw new WebAuthnException('Unsupported CBOR simple value'),
            };
        }
        $len = self::length($d, $o, $info);

        return match ($major) {
            0 => $len,
            1 => -1 - $len,
            2 => self::take($d, $o, $len),
            3 => self::take($d, $o, $len),
            4 => self::array($d, $o, $len, $depth),
            5 => self::map($d, $o, $len, $depth),
            6 => self::item($d, $o, $depth + 1), // tag: return tagged value
        };
    }

    private static function array(string $d, int &$o, int $len, int $depth): array
    {
        self::guardCount($len, $d, $o);
        $out = [];
        for ($i = 0; $i < $len; $i++) {
            $out[] = self::item($d, $o, $depth + 1);
        }

        return $out;
    }

    private static function map(string $d, int &$o, int $len, int $depth): array
    {
        self::guardCount($len, $d, $o);
        $out = [];
        for ($i = 0; $i < $len; $i++) {
            $key = self::item($d, $o, $depth + 1);
            if (! is_int($key) && ! is_string($key)) {
                throw new WebAuthnException('Unsupported CBOR map key');
            }
            $out[$key] = self::item($d, $o, $depth + 1);
        }

        return $out;
    }

    private static function length(string $d, int &$o, int $info): int
    {
        return match (true) {
            $info < 24 => $info,
            $info === 24 => self::byte($d, $o),
            $info === 25 => unpack('n', self::take($d, $o, 2))[1],
            $info === 26 => unpack('N', self::take($d, $o, 4))[1],
            $info === 27 => (function () use ($d, &$o) {
                $v = unpack('J', self::take($d, $o, 8))[1];
                if ($v < 0 || $v > PHP_INT_MAX) {
                    throw new WebAuthnException('CBOR length too large');
                }

                return $v;
            })(),
            default => throw new WebAuthnException('Indefinite CBOR lengths are not supported'),
        };
    }

    private static function guardCount(int $len, string $d, int $o): void
    {
        if ($len > strlen($d) - $o) {
            throw new WebAuthnException('CBOR item count exceeds input');
        }
    }

    private static function byte(string $d, int &$o): int
    {
        return ord(self::take($d, $o, 1));
    }

    private static function take(string $d, int &$o, int $n): string
    {
        if ($n < 0 || $o + $n > strlen($d)) {
            throw new WebAuthnException('Truncated CBOR');
        }
        $chunk = substr($d, $o, $n);
        $o += $n;

        return $chunk;
    }

    private static function half(string $b): float
    {
        $h = unpack('n', $b)[1];
        $exp = ($h >> 10) & 0x1F;
        $mant = $h & 0x3FF;
        $val = $exp === 0 ? $mant * 2 ** -24 : ($exp === 31 ? INF : ($mant + 1024) * 2 ** ($exp - 25));

        return ($h & 0x8000) ? -$val : $val;
    }
}
