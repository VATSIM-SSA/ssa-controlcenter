<?php

use Spatie\LoginLink\Http\Controllers\LoginLinkController;

return [
    /*
     * Login links will only work in these environments. In all
     * other environments, an exception will be thrown.
     */
    // VATSSA: CLOSED BY DEFAULT, everywhere.
    //
    // `spatie/laravel-login-link` is an upstream PRODUCTION dependency, not a
    // dev one, so POST /laravel-login-link-login is registered on every
    // environment including production. The controller logs in whichever user
    // you name -- `key` is the primary key, which here is the VATSIM CID -- so
    // the only thing between the internet and an administrator session is this
    // array and the host list below.
    //
    // An empty list means `app()->environment([])` is false and the controller
    // throws before it looks at anything else. Somebody who genuinely needs it
    // locally sets LOGIN_LINK_ENVIRONMENTS=local in their own .env; nobody can
    // switch it on by accident, and a box brought up with APP_ENV=local is no
    // longer a full authentication bypass.
    'allowed_environments' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('LOGIN_LINK_ENVIRONMENTS', '')))
    )),

    /*
     * Login links will only work in these hosts. In all
     * other hosts, an exception will be thrown.
     */
    'allowed_hosts' => [
        'localhost',
        '127.0.0.1',
        '*.test',
        'vatsca.local',
    ],

    /*
     * The package will automatically create a user model when trying
     * to log in a user that doesn't exist.
     */
    // VATSSA: false. If this ever does run it must not be able to CREATE the
    // account it then logs in as.
    'automatically_create_missing_users' => false,

    /*
     * The user model that should be logged in. If this is set to `null`
     * we'll take a look at the model used for the `users`
     * provider in config/auth.php
     */
    'user_model' => null,

    /*
     * After a login link is clicked, we'll redirect the user to this route.
     * If it is set to `null`, we'll redirect the user to their last intended/requested url.
     * You can set it to `/`, for making redirect to the root page.
     */
    'redirect_route_name' => null,

    /*
     * The package will register a route that points to this controller. To have fine
     * grained control over what happens when a login link is clicked, you can
     * override this class.
     */
    'login_link_controller' => LoginLinkController::class,

    /*
     * This middleware will be applied on the route
     * that logs in a user via a link.
     */
    'middleware' => ['web'],
];
