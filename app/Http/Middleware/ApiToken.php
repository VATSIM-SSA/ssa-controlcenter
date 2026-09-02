<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response|RedirectResponse)  $next
     * @param  mixed  $editRights
     * @return Response|RedirectResponse
     */
    public function handle(Request $request, Closure $next, $args = '')
    {
        // VATSSA: by hash, not by value.
        //
        // This was `ApiKey::find($request->bearerToken())` -- the token was the
        // primary key, stored as issued, so the table was a list of live
        // credentials in the clear. Now only a SHA-256 is stored; see
        // database/migrations-vatssa/2026_09_02_100000_vatssa_hash_api_keys.php
        // for why a fast hash is the right one for a random token.
        //
        // An expired key is treated as no key at all, so expiry needs no
        // separate branch and can never be forgotten at a call site.
        $key = ApiKey::forToken($request->bearerToken());

        if ($key == null || ($args == 'edit' && $key->read_only == true)) {

            // Exception for open routes. Compare the path only (getPathInfo), so a query
            // string such as `/api/v1/bookings?date=today` still resolves as a public route.
            $openRoutes = ['/api/bookings', '/api/positions', '/api/v1/bookings', '/api/v1/positions'];
            if (in_array($request->getPathInfo(), $openRoutes, true)) {
                $request->attributes->set('unauthenticated', true);

                return $next($request);
            } else {
                return response()->json([
                    'message' => 'Unauthorized',
                ], 401);
            }
        }

        // Update last used
        $key->update(['last_used_at' => now()]);

        return $next($request);
    }
}
