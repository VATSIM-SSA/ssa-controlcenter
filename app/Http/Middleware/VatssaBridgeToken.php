<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * VATSSA: the only thing allowed through /api/vatssa/bridge/*.
 *
 * Deliberately not the ApiKey mechanism upstream uses for /api/*. An edit-rights
 * ApiKey also opens booking writes, and the training bot has no business
 * creating bookings. One token, one purpose.
 *
 * Refusing when no token is configured is the point, not an oversight: an empty
 * `VATSSA_BRIDGE_TOKEN` must never mean "let everyone in".
 */
class VatssaBridgeToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('vatssa.bridge_token');
        $given = $request->bearerToken();

        // hash_equals, not ===, so a wrong token cannot be found one character
        // at a time by measuring how long the comparison takes.
        if (! is_string($expected) || $expected === ''
            || ! is_string($given)
            || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
