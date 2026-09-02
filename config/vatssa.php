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
    | Discord webhooks, one per audience.
    |
    | Call sites name the AUDIENCE -- 'examiners', 'events' -- and this maps it
    | to a URL, so moving a channel is one edit here rather than a search
    | through the application.
    |
    | UNSET IS SILENT, NOT BROKEN. App\Services\Vatssa\Discord records that it
    | could not post and carries on: every stage change is on the page and in
    | the action log regardless, so a missing webhook costs a notification and
    | never a step. That is what makes it safe to ship this before the channels
    | exist.
    |
    | Webhook URLs are credentials -- anybody holding one can post to that
    | channel as the bot. They live in the .env on the VPS and are blank in
    | every template in this repository, which is public.
    */
    'discord' => [
        'examiners' => env('VATSSA_DISCORD_EXAMINERS'),
        'events' => env('VATSSA_DISCORD_EVENTS'),
        'training' => env('VATSSA_DISCORD_TRAINING'),
    ],

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
    /*
    |--------------------------------------------------------------------------
    | Availability grid
    |--------------------------------------------------------------------------
    |
    | The window of the day the grid draws, and the timezone it is labelled in.
    |
    | Configurable because the honest answer differs by division and by season.
    | 06:00-23:00 Zulu is right for VATSSA, whose members sit between UTC+0 and
    | UTC+4: it covers everybody's evening. A division sitting in UTC-5 wants a
    | different seventeen hours, and hard-coding ours makes the grid useless to
    | them while looking deliberate.
    |
    | ## The timezone is a LABEL, not a conversion
    |
    | Slots are stored and compared in UTC and always will be. This setting
    | changes what the grid says the times are, nothing else. Setting it to
    | anything other than UTC without also converting the display is how a CPT
    | gets confirmed for the wrong hour, which is the worst bug this workflow
    | has. It exists so the label can be made explicit -- "Zulu (UTC)" -- rather
    | than so the times can be moved.
    |
    */
    'availability' => [
        'day_starts' => (int) env('VATSSA_AVAILABILITY_DAY_STARTS', 6),
        'day_ends' => (int) env('VATSSA_AVAILABILITY_DAY_ENDS', 23),

        // Shown on the grid and in every email that links to it. Say the offset
        // out loud: "Zulu" alone is read as local time by roughly everybody who
        // has not controlled yet, and the students are the ones answering.
        'timezone_label' => env('VATSSA_AVAILABILITY_TZ_LABEL', 'Zulu (UTC+0)'),

        // Longest window somebody may ask about, in weeks. The form offers 2, 4
        // and 8; this is the ceiling the server enforces, because the form is a
        // courtesy and the POST is the rule.
        'max_weeks' => (int) env('VATSSA_AVAILABILITY_MAX_WEEKS', 8),
    ],

    /*
    | The CPT deadline, and how far ahead the poll asks.
    |
    | Configurable because it is a division rule rather than a fact about the
    | software, and it was hard-coded in two places -- Exam and
    | AvailabilityPoll -- which is one place too many for a number that decides
    | whether a booking is legal. One source now, read through
    | Exam::noticeDays().
    */
    'exams' => [
        // Everything settled this long before the exam: examiner confirmed,
        // events told, myVATSIM uploaded. One deadline, not three.
        'notice_days' => (int) env('VATSSA_EXAM_NOTICE_DAYS', 7),

        // How many weeks of availability a new CPT poll asks about, starting
        // at the notice deadline.
        'poll_weeks' => (int) env('VATSSA_EXAM_POLL_WEEKS', 6),
    ],

    'default_mentor_capacity' => env('VATSSA_DEFAULT_MENTOR_CAPACITY') !== null
        ? (int) env('VATSSA_DEFAULT_MENTOR_CAPACITY')
        : null,

];
