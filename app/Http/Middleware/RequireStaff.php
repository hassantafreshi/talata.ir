<?php

namespace App\Http\Middleware;

use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/** Staff-only area: active account, idle timeout, optional admin-only role. */
class RequireStaff
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
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

            return $request->expectsJson() ? response()->json(['code' => 'UNAUTHENTICATED', 'message_fa' => 'دوباره وارد شوید.'], 401) : redirect()->route('admin.login');
        }
        if ($role === 'admin' && ! $staff->isAdmin()) {
            abort(403);
        }
        $request->session()->put('staff.seen', now()->getTimestamp());
        Context::add('staff_id', $staff->id);

        return $next($request);
    }
}
