<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Web Push with PHP's openssl only (no gmp/bcmath needed on shared hosting): message encryption per RFC 8291
 * (aes128gcm, RFC 8188) and VAPID per RFC 8292. Tested against the RFC 8291 Appendix A example
 * (tests/Unit/WebPushTest.php). The VAPID key pair is generated once and stored encrypted in platform settings.
 */
final class WebPush
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Push services a subscription may point at (the server POSTs to it: no arbitrary URLs). */
    public const ALLOWED_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'web.push.apple.com', 'notify.windows.com'];

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        $raw = base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($raw === false) {
            throw new RuntimeException('bad base64url');
        }

        return $raw;
    }

    public static function allowedEndpoint(string $endpoint): bool
    {
        $u = parse_url($endpoint);
        if (($u['scheme'] ?? '') !== 'https' || empty($u['host'])) {
            return false;
        }
        foreach (self::ALLOWED_HOSTS as $h) {
            if ($u['host'] === $h || str_ends_with($u['host'], '.'.$h)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{public: string, private_pem: string} public is the raw 65-byte point, base64url. */
    public static function vapid(): array
    {
        $stored = PlatformSetting::get('webpush.vapid');
        if (is_string($stored) && $stored !== '') {
            return json_decode(Crypt::decryptString($stored), true);
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $pair = ['public' => self::b64(self::rawPublic($key)), 'private_pem' => $pem];
        PlatformSetting::put('webpush.vapid', Crypt::encryptString(json_encode($pair)), null);

        return $pair;
    }

    /** Uncompressed P-256 point 0x04||X||Y of an EC key. */
    public static function rawPublic($key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];

        return "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function publicKeyFromRaw(string $raw)
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX).$raw), 64, "\n")."-----END PUBLIC KEY-----\n";
        $k = openssl_pkey_get_public($pem);
        if (! $k) {
            throw new RuntimeException('bad P-256 public key');
        }

        return $k;
    }

    /** SEC1 private key from the raw scalar and its public point (used for the RFC test vector). */
    public static function privateKeyFromRaw(string $d, string $publicRaw)
    {
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').$publicRaw;
        $k = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n");
        if (! $k) {
            throw new RuntimeException('bad P-256 private key');
        }

        return $k;
    }

    /**
     * RFC 8291 encryption of one push message. $senderKey/$salt are only fixed in tests.
     *
     * @param  string  $uaPublic  subscription keys.p256dh (base64url)
     * @param  string  $authSecret  subscription keys.auth (base64url)
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, $senderKey = null, ?string $salt = null): string
    {
        $uaRaw = self::unb64($uaPublic);
        $auth = self::unb64($authSecret);
        if (strlen($uaRaw) !== 65 || strlen($auth) < 16) {
            throw new RuntimeException('bad subscription keys');
        }
        $senderKey ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $asRaw = self::rawPublic($senderKey);
        $salt ??= random_bytes(16);

        $ecdh = openssl_pkey_derive(self::publicKeyFromRaw($uaRaw), $senderKey, 32);
        if ($ecdh === false) {
            throw new RuntimeException('ECDH failed');
        }
        $prkKey = hash_hmac('sha256', $ecdh, $auth, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0".$uaRaw.$asRaw."\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        return $salt.pack('N', 4096).chr(65).$asRaw.$cipher.$tag;
    }

    /** VAPID Authorization header value for one push service origin. */
    public static function vapidHeader(string $endpoint, ?array $pair = null, ?int $now = null): string
    {
        $pair ??= self::vapid();
        $u = parse_url($endpoint);
        $claims = ['aud' => $u['scheme'].'://'.$u['host'], 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => (string) config('talata.webpush.subject')];
        $input = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'.self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        openssl_sign($input, $der, $pair['private_pem'], OPENSSL_ALGO_SHA256);

        return 'vapid t='.$input.'.'.self::b64(self::derToRaw($der)).', k='.$pair['public'];
    }

    /** ECDSA DER signature → raw r||s (64 bytes) as JWS ES256 requires. */
    public static function derToRaw(string $der): string
    {
        $pos = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }

        return $out;
    }
}
