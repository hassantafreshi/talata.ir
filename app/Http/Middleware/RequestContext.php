<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Server-generated request id (never taken from the client) shared by activity and technical logs. */
class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = strtolower((string) Str::ulid());
        Context::add('request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
