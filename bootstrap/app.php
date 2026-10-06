<?php

use App\Domain\DomainError;
use App\Domain\Pricing\PricingError;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'perm' => RequirePermission::class,
        ]);
        // Tenant context must exist before route-model binding so bindings are tenant-scoped.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTenant::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('invoices.new'));
        // The bank posts back cross-site; the callback is protected by the gateway authority + server verify instead.
        $middleware->validateCsrfTokens(except: ['pay/callback/*']);
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->dontReport([DomainError::class, PricingError::class]);

        $exceptions->render(function (DomainError $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['code' => $e->codeName, 'message_fa' => $e->messageFa, 'trace_id' => (string) Str::ulid()] + $e->context, $e->status);
            }

            if ($request->isMethod('GET')) {
                return response()->view('errors.page', ['status' => $e->status, 'message' => $e->messageFa], $e->status);
            }

            return back()->with('error', $e->messageFa)->withInput();
        });

        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['code' => 'SESSION_EXPIRED', 'message_fa' => 'نشست شما منقضی شد. صفحه را دوباره باز کنید.'], 419);
            }

            return redirect()->route('login')->with('error', 'نشست شما منقضی شد. دوباره وارد شوید.');
        });
    })->create();
