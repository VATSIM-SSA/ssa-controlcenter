<?php

use App\Tasks\Types\MentorCapacityRequest;
use App\Tasks\Types\MentorNeeded;
use App\Tasks\Types\RatingUpgrade;
use App\Tasks\Types\SoloEndorsement;

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
    | Moodle's own webhook secret.
    |
    | SEPARATE FROM THE BRIDGE TOKEN, and that is the whole point. The bridge
    | is 403'd at Caddy so it is unreachable from the internet; Moodle is on
    | the internet. Handing Moodle the bridge token would mean opening the
    | bridge, which undoes the thing that makes the bridge safe.
    |
    | One endpoint sits behind this, and it may only write platform presence.
    | A leak from Moodle therefore costs one write path rather than everything.
    |
    | Unset means the endpoint refuses everything, which is the correct resting
    | state -- shipping the route before Moodle is configured is then safe.
    */
    'moodle_secret' => env('VATSSA_MOODLE_SECRET'),

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
        // Capacity is the ATC training manager's call, always.
        MentorCapacityRequest::class => 'training-manager',
        // Finding a mentor IS the pipeline coordinator's job. Per-rating, so
        // the observer resolves it to the coordinator for this student's
        // rating rather than to 'the pipeline', which is nobody.
        MentorNeeded::class => 'coordinator',
    ],

    /*
    | Request types that only make sense for some ratings.
    |
    | Task type class => the ratings it applies to. A type absent from this list
    | is offered everywhere, which is the common case.
    |
    | S1 controllers do not hold solo endorsements, so offering a solo request
    | on an S1 training is an option that can only ever be declined -- and an
    | option nobody should pick is worse than no option, which is the same
    | reason the theoretical-exam task type was deleted outright.
    |
    | Ratings are named, not id'd, because ids differ between environments and a
    | routing table that breaks on a database restore is worse than useless.
    */
    'request_ratings' => [
        SoloEndorsement::class => ['S2', 'S3', 'C1'],
    ],

    /*
    | How many students a mentor is assumed to be willing to run.
    |
    | A starting point, not a rule -- nothing enforces it. Overridden per mentor,
    | and per rating, on the mentorship admin page. Null means no default limit,
    | which is different from a limit of zero and is shown differently.
    */
    'default_mentor_capacity' => env('VATSSA_DEFAULT_MENTOR_CAPACITY') !== null
        ? (int) env('VATSSA_DEFAULT_MENTOR_CAPACITY')
        : null,

];
