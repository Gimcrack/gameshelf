<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * V3: every API response must be JSON, regardless of the client's Accept
 * header. Forcing Accept here makes auth failures, validation errors, and
 * exceptions render as JSON instead of redirects or HTML.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
