<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Notifications\PushService;
use App\Models\PushSubscription;
use App\Support\WebPush;
use Illuminate\Http\Request;

/** «اعلان روی گوشی»: this device subscribes to Web Push for the signed-in user. */
class PushController extends BaseController
{
    public function key()
    {
        return response()->json(['key' => WebPush::vapid()['public']]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'], 'keys.p256dh' => ['required', 'string', 'max:120'], 'keys.auth' => ['required', 'string', 'max:60'],
        ]);
        if (! WebPush::allowedEndpoint($data['endpoint'])) {
            throw new DomainError('PUSH_SERVICE', 'این مرورگر از سرویس اعلان پشتیبانی‌شده استفاده نمی‌کند.', 422);
        }
        try {
            if (strlen(WebPush::unb64($data['keys']['p256dh'])) !== 65 || strlen(WebPush::unb64($data['keys']['auth'])) < 16) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new DomainError('PUSH_KEYS', 'اطلاعات اعلان این دستگاه درست نیست.', 422);
        }
        $user = $request->user();
        $sub = PushSubscription::query()->updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $user->id, 'endpoint' => $data['endpoint'], 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
            'device' => mb_substr((string) $request->userAgent(), 0, 120), 'failures' => 0,
        ]);
        // A few devices per person; the oldest go first.
        $ids = PushSubscription::query()->where('user_id', $user->id)->orderByDesc('updated_at')->pluck('id');
        PushSubscription::query()->whereIn('id', $ids->slice((int) config('talata.webpush.max_per_user', 10)))->delete();
        Audit::record('push.subscribed', null, ['host' => parse_url($sub->endpoint, PHP_URL_HOST)]);

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $endpoint = (string) $request->input('endpoint', '');
        PushSubscription::query()->where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $endpoint))->delete();

        return response()->json(['ok' => true]);
    }

    public function test(Request $request, PushService $push)
    {
        $n = $push->toUsers([$request->user()->id], ['title' => 'زرلیو', 'body' => 'اعلان‌ها روشن است. وقتی مشتری پیش‌فاکتور را تأیید کند، همین‌جا خبر می‌دهیم.', 'url' => route('home'), 'tag' => 'test']);
        if ($n === 0) {
            throw new DomainError('PUSH_NOT_DELIVERED', 'اعلان آزمایشی به سرویس اعلان نرسید. ممکن است سرویس اعلان مرورگر (مثلاً گوگل) از ایران در دسترس نباشد؛ پیامک همیشه فرستاده می‌شود.', 502);
        }

        return response()->json(['ok' => true, 'devices' => $n]);
    }
}
