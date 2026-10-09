<?php

namespace App\Domain\Notifications;

use App\Models\PushSubscription;
use App\Support\WebPush;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends Web Push notifications to shop users' devices (installed web app with notifications allowed).
 * Best effort: a dead subscription (404/410) is removed, repeated failures retire it. Delivery depends on the
 * browser's push service (Google FCM for Chrome/Android, Apple, Mozilla), which may be unreachable from Iran
 * without a VPN — SMS stays the reliable channel (docs/PROFORMA.md).
 */
class PushService
{
    /** @param  array{title: string, body: string, url: string, tag?: string}  $payload */
    public function toUsers(array $userIds, array $payload): int
    {
        $sent = 0;
        foreach (PushSubscription::query()->whereIn('user_id', array_unique(array_filter($userIds)))->get() as $sub) {
            $sent += $this->send($sub, $payload) ? 1 : 0;
        }

        return $sent;
    }

    public function send(PushSubscription $sub, array $payload): bool
    {
        if (! WebPush::allowedEndpoint($sub->endpoint)) {
            $sub->delete();

            return false;
        }
        try {
            $body = WebPush::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sub->p256dh, $sub->auth);
            $res = Http::withHeaders([
                'TTL' => '86400', 'Urgency' => 'high', 'Content-Encoding' => 'aes128gcm',
                'Authorization' => WebPush::vapidHeader($sub->endpoint),
            ])->withBody($body, 'application/octet-stream')->timeout(8)->post($sub->endpoint);
            if ($res->successful()) {
                $sub->forceFill(['last_success_at' => now(), 'failures' => 0])->save();

                return true;
            }
            if (in_array($res->status(), [404, 410], true)) {
                $sub->delete(); // unsubscribed or expired on the device

                return false;
            }
            Log::warning('webpush rejected', ['status' => $res->status(), 'host' => parse_url($sub->endpoint, PHP_URL_HOST)]);
        } catch (Throwable $e) {
            Log::warning('webpush failed', ['error' => $e->getMessage(), 'host' => parse_url($sub->endpoint, PHP_URL_HOST)]);
        }
        $sub->increment('failures');
        if ($sub->failures >= 10) {
            $sub->delete();
        }

        return false;
    }
}
