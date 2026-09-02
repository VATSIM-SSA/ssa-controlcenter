<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| VATSSA: published because the framework default is `allowed_origins => ['*']`
| on every `api/*` path, and nobody had decided that.
|
| The practical exposure of the default was small -- `supports_credentials` is
| false and the API authenticates with a bearer token rather than a cookie, so
| a malicious page could not act as a signed-in member. What it could do was
| read the public endpoints from any origin, which is a scraping convenience
| nobody asked to provide.
|
| The list below is the sites that actually call this API. Add a hostname when
| a site needs it, rather than reopening the wildcard.
|
| `supports_credentials` stays FALSE. Turning it on with a wildcard origin is
| rejected by browsers anyway, and turning it on with a real origin would let
| that origin ride a member's session -- which is exactly what the token auth
| exists to avoid.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    /*
     * Comma-separated in the environment so a new consumer does not need a
     * deploy. Empty means "no cross-origin reads at all", which is the correct
     * resting state: server-to-server callers do not send an Origin header and
     * are unaffected by any of this.
     */
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://vatssa.com,https://www.vatssa.com')))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
