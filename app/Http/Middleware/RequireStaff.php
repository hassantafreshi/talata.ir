<?php

namespace App\Http\Middleware;

use App\Models\Passkey;
use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff-only area: active account, idle timeout, then an optional requirement:
 *   staff:admin           → admin role
 *   staff:<permission>    → StaffUser::PERMISSIONS key (e.g. staff:payments.manage)
 *   staff:<permission>,fresh → plus a sign-in within talata.admin.reauth_minutes (dangerous actions)
 */
class RequireStaff
{
    public function handle(Request $request, Closure $next, ?string $role = null, ?string $fresh = null): Response
    {
        $guard = Auth::guard('staff');
        // Fresh row every request: deactivation or role changes apply immediately.
        $staff = $guard->id() ? StaffUser::query()->find($guard->id()) : null;
        $idle = (int) config('talata.admin.idle_minutes') * 60;
        $seen = (int) $request->session()->get('staff.seen', 0);
        if (! $staff || ! $staff->active || ($seen && now()->getTimestamp() - $seen > $idle)) {
            if ($staff) {
                $guard->logout();
                $request->session()->invalidate();
            }

            return $request->expectsJson() ? response()->json(['code' => 'UNAUTHENTICATED', 'message_fa' => 'نشست مدیریت تمام شد. دوباره وارد شوید.', 'login' => route('admin.login')], 401) : redirect()->route('admin.login');
        }
        if ($role === 'admin' ? ! $staff->isAdmin() : ($role !== null && ! $staff->allows($role))) {
            return $request->expectsJson() ? response()->json(['code' => 'STAFF_FORBIDDEN', 'message_fa' => 'نقش شما اجازه این کار را ندارد.'], 403) : abort(403);
        }
        // Mandatory passkey (A-00): an SMS-code session only reaches the dashboard and «حساب من» (to add the first
        // passkey). Once the person has a passkey, everything else needs a passkey sign-in, so a stolen SMS code
        // (SIM swap, SS7) never opens the console.
        if (config('talata.admin.require_passkey') && $request->session()->get('staff.auth_method') !== 'passkey'
            && ! $request->routeIs('admin.dashboard', 'admin.account', 'admin.account.*', 'admin.logout')) {
            $has = Passkey::query()->where('owner_type', 'staff')->where('owner_id', $staff->id)->exists();
            [$code, $message, $to] = $has
                ? ['PASSKEY_SIGNIN_REQUIRED', 'برای این بخش با کلید عبور (اثر انگشت یا قفل دستگاه) وارد شوید؛ ورود پیامکی کافی نیست.', route('admin.login', ['reauth' => 1])]
                : ['PASSKEY_REQUIRED', 'برای کار با کنسول مدیریت، اول در «حساب من» کلید عبور (اثر انگشت یا قفل دستگاه) اضافه کنید.', route('admin.account')];

            return $request->expectsJson()
                ? response()->json(['code' => $code, 'message_fa' => $message, 'login' => $has ? $to : null], 403)
                : redirect($to)->with('error', $message);
        }
        if ($fresh === 'fresh') {
            $authAt = (int) $request->session()->get('staff.auth_at', 0);
            if (now()->getTimestamp() - $authAt > (int) config('talata.admin.reauth_minutes') * 60) {
                return response()->json(['code' => 'REAUTH_REQUIRED', 'message_fa' => 'برای این کار حساس، دوباره وارد شوید (کد پیامکی یا کلید عبور).', 'login' => route('admin.login', ['reauth' => 1])], 403);
            }
        }
        $request->session()->put('staff.seen', now()->getTimestamp());
        Context::add('staff_id', $staff->id);

        return $next($request);
    }
}
