<?php

namespace App\Http\Controllers;

use App\Support\TechLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records why a phone's browser refused a passkey ceremony (cancelled, not allowed, unsupported …). The server
 * never sees these failures otherwise, so they would be invisible in the technical log. Only the error name and
 * message are accepted — no credential data — and the route is throttled.
 */
class WebAuthnReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', 'string', 'in:register,register-offer,login,login-hinted'],
            'name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z]+$/'],
            'message' => ['nullable', 'string', 'max:300'],
            'in_app' => ['nullable', 'boolean'],
        ]);
        $area = $request->is('admin/*') ? 'admin' : 'auth';
        TechLog::warning($area, 'passkey browser error', [
            'stage' => $data['stage'], 'error' => $data['name'], 'detail' => TechLog::scrub(mb_substr((string) ($data['message'] ?? ''), 0, 300)),
            'in_app_browser' => (bool) ($data['in_app'] ?? false), 'ua' => mb_substr((string) $request->userAgent(), 0, 180),
        ]);

        return response()->json(['ok' => true]);
    }
}
