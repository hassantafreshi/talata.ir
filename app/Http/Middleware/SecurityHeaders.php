<?php

namespace App\Http\Middleware;

use App\Domain\Billing\PaymentGateways;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/** Strict CSP (no inline script, no third-party origins), anti-framing and transport hardening. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $dev = app()->isLocal() && file_exists(public_path('hot'));
        $devOrigin = $dev ? ' '.trim(file_get_contents(public_path('hot'))).' ws:' : '';
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".$devOrigin,
            "style-src 'self' 'nonce-{$nonce}'".$devOrigin,
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'".$devOrigin,
            "form-action 'self'".$this->pspFormHosts($request),
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "object-src 'none'",
        ]);

        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($headers as $k => $v) {
            $response->headers->set($k, $v);
        }
        $response->headers->remove('X-Powered-By');

        return $response;
    }

    /** Only the plan/SMS purchase pages may POST to a PSP, and only to the hosts the active adapter declares. */
    private function pspFormHosts(Request $request): string
    {
        if (! $request->routeIs('settings.plan', 'settings.sms')) {
            return '';
        }
        try {
            $hosts = app(PaymentGateways::class)->default()->formActionHosts();
        } catch (\Throwable) {
            return '';
        }
        $hosts = array_filter($hosts, fn ($h) => preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $h));

        return $hosts ? ' '.implode(' ', $hosts) : '';
    }
}
