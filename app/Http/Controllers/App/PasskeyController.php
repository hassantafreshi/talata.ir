<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Identity\WebAuthn\WebAuthnException;
use App\Domain\Identity\WebAuthn\WebAuthnService;
use App\Models\Passkey;
use App\Support\Mobile;
use App\Support\TechLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A merchant user's own passkeys (per person, not per shop). */
class PasskeyController extends BaseController
{
    private function requireRecentLogin(Request $request): void
    {
        $at = (int) $request->session()->get('auth_at', 0);
        if (now()->getTimestamp() - $at > config('talata.webauthn.recent_auth_minutes') * 60) {
            // A stolen session must not be able to plant its own long-lived key.
            throw new DomainError('REAUTH_REQUIRED', 'برای امنیت، اول یک بار دیگر با کد پیامکی وارد شوید، سپس اثر انگشت را فعال کنید.', 403, ['login_url' => route('login', ['then' => 'passkey'])]);
        }
    }

    public function options(Request $request, WebAuthnService $webauthn): JsonResponse
    {
        $this->requireRecentLogin($request);
        $user = $request->user();

        return response()->json($webauthn->registrationOptions($user, 'user', Mobile::display($user->mobile)));
    }

    public function store(Request $request, WebAuthnService $webauthn): JsonResponse
    {
        $this->requireRecentLogin($request);
        $data = $request->validate(WebAuthnService::rules(true) + ['name' => ['nullable', 'string', 'max:60']]);
        try {
            $passkey = $webauthn->register($request->user(), 'user', $data['credential'], $data['name'] ?? $this->deviceName($request));
        } catch (WebAuthnException $e) {
            TechLog::warning('auth', 'passkey registration rejected', ['reason' => $e->getMessage()]);

            throw new DomainError('PASSKEY_REGISTER_FAILED', $e->getMessage() === 'Too many passkeys'
                ? 'حداکثر ۱۰ دستگاه برای ورود با اثر انگشت.' : 'فعال‌سازی انجام نشد. دوباره امتحان کنید. (کد: '.$e->reasonCode().')', 422, ['reason' => $e->reasonCode()]);
        }
        Audit::record('passkey.registered', $request->user(), ['passkey' => $passkey->id, 'name' => $passkey->name]);

        return response()->json(['id' => $passkey->id, 'name' => $passkey->name], 201);
    }

    public function destroy(Request $request, int $passkey): JsonResponse
    {
        $p = Passkey::query()->where('owner_type', 'user')->where('owner_id', $request->user()->id)->whereKey($passkey)->firstOrFail();
        $p->delete();
        Audit::record('passkey.removed', $request->user(), ['passkey' => $passkey]);

        return response()->json(['ok' => true]);
    }

    private function deviceName(Request $request): string
    {
        $ua = (string) $request->userAgent();

        return match (true) {
            str_contains($ua, 'iPhone') => 'آیفون', str_contains($ua, 'iPad') => 'آیپد', str_contains($ua, 'Android') => 'گوشی اندروید',
            str_contains($ua, 'Windows') => 'ویندوز', str_contains($ua, 'Mac OS') => 'مک', default => 'این دستگاه',
        };
    }
}
