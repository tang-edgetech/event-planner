<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public endpoints are called only by the public site's server, which sends the shared INTERNAL_API_KEY.
 * Anyone else (a browser, a bot) gets a plain 404, so the API reveals nothing.
 */
class InternalOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) config('services.internal.key');
        if ($key === '' || ! hash_equals($key, (string) $request->header('X-Internal-Key'))) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return $next($request);
    }
}
