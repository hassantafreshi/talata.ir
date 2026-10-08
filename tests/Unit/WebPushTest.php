<?php

namespace Tests\Unit;

use App\Support\WebPush;
use Tests\TestCase;

/** Web Push crypto against the published example of RFC 8291 (Appendix A) and RFC 8292 token shape. */
class WebPushTest extends TestCase
{
    public function test_encryption_matches_rfc8291_appendix_a(): void
    {
        $asPrivate = WebPush::unb64('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
        $asPublic = WebPush::unb64('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
        $body = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'BTBZMqHH6r4Tts7J_aSIgg',
            WebPush::privateKeyFromRaw($asPrivate, $asPublic),
            WebPush::unb64('DGv6ra1nlYgDCS1FRnbzlw'),
        );
        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            WebPush::b64($body),
        );
    }

    public function test_vapid_token_is_a_verifiable_es256_jwt(): void
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $pair = ['public' => WebPush::b64(WebPush::rawPublic($key)), 'private_pem' => $pem];
        $h = WebPush::vapidHeader('https://fcm.googleapis.com/fcm/send/abc', $pair, 1_700_000_000);
        $this->assertMatchesRegularExpression('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $h);
        preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=/', $h, $m);
        $claims = json_decode(WebPush::unb64($m[2]), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertSame(1_700_000_000 + 43200, $claims['exp']);
        // raw r||s back to DER, verified with the public key
        $raw = WebPush::unb64($m[3]);
        $this->assertSame(64, strlen($raw));
        $int = fn ($b) => (ord(ltrim($b, "\0")[0] ?? "\0") & 0x80 ? "\0" : '').ltrim($b, "\0");
        [$r, $s] = [$int(substr($raw, 0, 32)), $int(substr($raw, 32))];
        $der = "\x30".chr(4 + strlen($r) + strlen($s))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;
        $this->assertSame(1, openssl_verify($m[1].'.'.$m[2], $der, openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256));
    }

    public function test_only_known_push_services_are_accepted(): void
    {
        $this->assertTrue(WebPush::allowedEndpoint('https://fcm.googleapis.com/fcm/send/x'));
        $this->assertTrue(WebPush::allowedEndpoint('https://web.push.apple.com/QG'));
        $this->assertTrue(WebPush::allowedEndpoint('https://updates.push.services.mozilla.com/wpush/v2/x'));
        $this->assertFalse(WebPush::allowedEndpoint('http://fcm.googleapis.com/x'));
        $this->assertFalse(WebPush::allowedEndpoint('https://evil.example/fcm.googleapis.com'));
        $this->assertFalse(WebPush::allowedEndpoint('https://169.254.169.254/latest'));
        $this->assertFalse(WebPush::allowedEndpoint('https://fcm.googleapis.com.evil.example/x'));
    }
}
