<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin console runs on its own session cookie scoped to /admin with a short lifetime, so a
 * merchant session can never be used there (and vice versa). Optional IP allowlist hides it.
 */
class UseAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) config('talata.admin.allowed_ips'))));
        if ($allowed && ! in_array($request->ip(), $allowed, true)) {
            abort(404);
        }
        config([
            'session.cookie' => config('talata.admin.session_cookie'),
            'session.path' => '/admin',
            'session.lifetime' => config('talata.admin.session_minutes'),
            'session.expire_on_close' => true,
        ]);
        // Rebuild the session store only if it was created with the merchant cookie name.
        if (app('session')->driver()->getName() !== config('session.cookie')) {
            app('session')->forgetDrivers();
        }

        return $next($request);
    }
}
