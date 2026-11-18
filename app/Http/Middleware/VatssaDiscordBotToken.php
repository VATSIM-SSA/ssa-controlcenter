<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * VATSSA: the Discord role sync bot's own token, for reporting results only.
 *
 * Deliberately not a CC API key: the bot's API key stays read-only for every
 * upstream route, and this token opens exactly one VATSSA route. No token,
 * or one shorter than 32 characters, means the routes are closed to everyone.
 */
class VatssaDiscordBotToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('vatssa.discord_bot_token');
        $given = (string) $request->bearerToken();

        // Fail closed on a missing or guessable token: at least 32 characters.
        if (strlen($expected) < 32 || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
