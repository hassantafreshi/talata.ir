<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Binds the logged-in user's active membership to the request. Suspended tenants are blocked. */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $preferred = $request->session()->get('tenant_id');
        $membership = Membership::query()->with('tenant')->where('user_id', $user->id)->where('status', 'active')
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [(int) $preferred])->orderBy('id')->first();

        if (! $membership) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login');
        }
        if (! $membership->tenant->isActive()) {
            abort(403, 'این فروشگاه موقتاً غیرفعال است. با پشتیبانی طلاتا تماس بگیرید.');
        }
        $request->session()->put('tenant_id', $membership->tenant_id);
        $this->context->set($membership->tenant, $membership);

        return $next($request);
    }
}
