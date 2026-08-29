<?php

use App\Tasks\Types\RatingUpgrade;

/*
|--------------------------------------------------------------------------
| VATSSA configuration
|--------------------------------------------------------------------------
|
| Everything this fork adds on top of upstream Control Center. Kept in its own
| file on purpose: upstream never touches a path it does not know about, so
| nothing here can ever be a merge conflict.
|
*/

return [

    /*
    | The training pipeline bridge.
    |
    | The bot writes to Control Center through /api/vatssa/bridge/*. Without a
    | token the routes still exist and refuse everything, which is the correct
    | resting state -- the bot degrades into "here is what to click" lines on
    | the daily digest rather than failing.
    |
    | The bot reaches the container directly over the Docker network, so the
    | bridge does not need to be reachable from the internet. Caddy 403s the
    | path at the edge; this token is the second lock, not the only one.
    */
    'bridge_token' => env('VATSSA_BRIDGE_TOKEN'),

    /*
    | The consolidated roster.
    |
    | VATSSA's rule is that active in one area is active everywhere, so the
    | public roster is one list rather than four. The homepage reads it every
    | few minutes and the underlying data changes hourly at most, so it is
    | cached; the number is seconds.
    */
    'roster_cache_seconds' => (int) env('VATSSA_ROSTER_CACHE_SECONDS', 300),

    /*
    | Which desk each kind of request goes to.
    |
    | Task type class => the permission that defines its desk. VatssaTaskObserver
    | assigns the task to whoever holds that permission in the training's area,
    | with the fewest open tasks.
    |
    | SHIPS EMPTY ON PURPOSE, and empty means behaviour is unchanged: the person
    | raising the task picks the recipient, exactly as upstream. Fill this in
    | once it has been DECIDED where each request should go -- a routing table
    | guessed at rather than agreed would quietly send real requests to the
    | wrong people, which is worse than the manual choice it replaces.
    |
    | Example, once decided:
    |
    |   \App\Tasks\Types\SoloEndorsement::class => 'endorsements.solo.create',
    |   \App\Tasks\Types\RatingUpgrade::class   => 'training.ratings.manage',
    */
    'task_routing' => [
        // rating -> permission, once decided
    ],

    /*
    | Request types whose desk is never a choice.
    |
    | Task type class => tier. A rating upgrade is membership work and the one
    | request whose destination is never in doubt, so the form shows it as
    | settled rather than offering a picker to get wrong.
    |
    | HERE RATHER THAN ON THE TYPE CLASS, so upstream's own task types stay
    | verbatim. Adding a method to RatingUpgrade.php would have made it a
    | modified upstream file for one line, and a conflict on every release.
    */
    'fixed_desks' => [
        RatingUpgrade::class => 'membership',
    ],

];
