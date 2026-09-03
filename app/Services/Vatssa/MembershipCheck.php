<?php

namespace App\Services\Vatssa;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\VatsimRating;
use App\Models\Rating;
use App\Models\User;
use App\Models\Vatssa\PlatformRequirement;
use App\Models\Vatssa\TheoryAttempt;
use Illuminate\Support\Collection;

/**
 * VATSSA: the requirements, as a list that can be ASKED rather than only
 * enforced.
 *
 * ## The problem this solves
 *
 * Every one of these rules already existed, scattered across
 * `TrainingPolicy::apply()`, `PlatformRequirement`, `UserPlatform` and
 * `TheoryAttempt`. All of them were written to REFUSE: the policy returns the
 * first denial it reaches, as one sentence, in a pill on the dashboard. So a
 * member learned one reason at a time, in whatever order the policy happened to
 * check, and could not see what the requirements were before being turned away
 * by them.
 *
 * Nothing here is a new rule. It is the same rules, collected in one place and
 * asked rather than only enforced, so a page can say "here is what an S2 needs,
 * and here is where you stand" -- including for a rating somebody is not
 * eligible for yet, which is exactly the question people ask.
 *
 * ## Blocking versus shown
 *
 * `Requirement::$blocking` marks the ones that genuinely stop an application.
 * Most do not. A rule that blocks silently is a rule nobody can appeal, so the
 * rest render as a cross beside their instruction and let the reader act.
 *
 * ## Why it is called MembershipCheck
 *
 * The membership module needs the same component for transfers and visits --
 * days since the last rating upgrade, days since the last transfer, 50 hours on
 * positions requiring their current rating, the platform checks, and a
 * disciplinary check a person ticks. Those are TVCP rules and are not built
 * yet; they belong here beside `for()` when they are, so the request page and
 * the training page render one component rather than two that drift apart. See
 * projects/vatssa-training/CC-MEMBERSHIP-WORKFLOW.md.
 */
class MembershipCheck
{
    /**
     * What this person needs before they can be trained, and where they stand.
     *
     * `$rating` is optional: with one, the theory line names that rating and
     * eligibility is checked against it, which is what lets an S1 see what an S2
     * needs. Without one, the list is the rating-independent half.
     *
     * @return Collection<int, Requirement>
     */
    public static function for(User $user, ?Rating $rating = null): Collection
    {
        return collect([
            ...self::divisionRequirements($user),
            ...self::platformRequirements($user),
            ...self::ratingRequirements($user, $rating),
            ...self::timingRequirements($user),
        ]);
    }

    /** @return array<int, Requirement> */
    private static function divisionRequirements(User $user): array
    {
        $divisionName = config('app.owner_name_short');

        $inDivision = config('app.mode') === 'subdivision'
            ? in_array($user->subdivision, array_filter(explode(',', (string) Setting::get('trainingSubDivisions'))), true)
            : $user->division === config('app.owner_code');

        return [
            // Only when it is SHUT. "Training is open" is not a fact about the
            // reader -- it is true for everybody, most of the time, and a green
            // tick that never changes teaches somebody to stop reading the list
            // it is at the top of. When it is shut, that is the only thing that
            // matters and it says so.
            ...(Setting::get('trainingEnabled') ? [] : [
                new Requirement(
                    label: 'Training is open',
                    met: false,
                    detail: 'We are not accepting new training requests at the moment.',
                    blocking: true,
                ),
            ]),
            new Requirement(
                label: "Member of {$divisionName}",
                met: $inDivision,
                detail: $inDivision ? null : "Transfer to {$divisionName} on VATSIM. You can apply here within 24 hours.",
                blocking: true,
            ),
        ];
    }

    /**
     * Discord and Moodle.
     *
     * `PlatformRequirement::missingFor()` already returns instructions rather
     * than verdicts, so those strings come straight across. What it cannot say
     * is WHY something is missing, which is why `hasBeenChecked()` is asked
     * separately: never having been looked at is a different answer from having
     * been looked at and not found.
     *
     * @return array<int, Requirement>
     */
    private static function platformRequirements(User $user): array
    {
        $missing = PlatformRequirement::missingFor($user);
        $checked = PlatformRequirement::hasBeenChecked($user);

        $discordMissing = collect($missing)->contains(fn ($line) => str_contains($line, 'Discord'));
        $moodleMissing = collect($missing)->contains(fn ($line) => str_contains($line, 'Moodle'));

        return [
            new Requirement(
                label: 'Discord account linked',
                met: ! $discordMissing,
                detail: $discordMissing
                    ? ($checked
                        ? 'Join the VATSSA Discord server and link your VATSIM CID.'
                        : 'We have not checked yet. If you have just joined, this clears on its own.')
                    : null,
                blocking: true,
                unknown: $discordMissing && ! $checked,
            ),
            new Requirement(
                label: 'Moodle account linked',
                met: ! $moodleMissing,
                detail: $moodleMissing
                    ? ($checked
                        ? 'Create an account on the VATSSA training site. An account, not an enrolment — enrolling you is our job.'
                        : 'We have not checked yet. If you have just registered, this clears on its own.')
                    : null,
                blocking: true,
                unknown: $moodleMissing && ! $checked,
            ),
        ];
    }

    /**
     * Eligibility for one rating, and its theory.
     *
     * The eligibility rule is upstream's, from `TrainingController::apply()`:
     * the rating must be above what you hold, and you must already hold what it
     * requires. Restated here as a question instead of a filter, because "why
     * is S3 not in the list" is the thing somebody actually wants answered.
     *
     * @return array<int, Requirement>
     */
    private static function ratingRequirements(User $user, ?Rating $rating): array
    {
        if ($rating === null) {
            return [];
        }

        $held = $user->rating;
        $required = $rating->pivot->required_vatsim_rating ?? null;

        $isUpgrade = $rating->vatsim_rating !== null
            ? $rating->vatsim_rating->isGreaterThan($held)
            : ! $user->hasEndorsementRating($rating);

        $meetsRequired = $required === null || $required <= $held->value;

        $passedTheory = TheoryAttempt::passedRating($user->id, $rating->name);

        return [
            new Requirement(
                label: "Eligible for {$rating->name}",
                met: $isUpgrade && $meetsRequired,
                detail: match (true) {
                    ! $isUpgrade => "You already hold {$rating->name}.",
                    // tryFrom, not from: `required_vatsim_rating` is a plain
                    // integer column and a value outside the enum must read as
                    // an unknown requirement, not throw on a page somebody is
                    // only trying to read.
                    ! $meetsRequired && $required !== null => 'You need ' . (VatsimRating::tryFrom($required)?->name ?? 'a higher rating') . ' first.',
                    default => null,
                },
                blocking: true,
            ),
            new Requirement(
                label: "{$rating->name} theory passed",
                met: $passedTheory,
                // NOT blocking. Theory is sat DURING the pipeline, not before
                // it -- the bot enrols you once you are in the queue. Showing
                // it as a cross tells somebody what is coming; refusing them
                // for it would be refusing them for not having done something
                // they cannot start yet.
                detail: $passedTheory ? null : 'Sat on Moodle once your training starts. Nothing to do yet.',
            ),
        ];
    }

    /**
     * The two rules about when, rather than about who.
     *
     * @return array<int, Requirement>
     */
    private static function timingRequirements(User $user): array
    {
        $recentlyCompleted = $user->hasRecentlyCompletedTraining();
        $hasActive = $user->hasActiveTrainings(true);
        $divisionName = config('app.owner_name_short');

        // Upstream's own carve-out: an OBS has no rating to keep active, and
        // somebody already in training is measured by their training rather
        // than by their hours.
        $activityApplies = ! $hasActive && $user->rating->isGreaterThan(VatsimRating::OBS);

        // The 7-day wait is only a rule for somebody who HAS just finished
        // something. Listing it against a member who has never trained here is
        // a requirement they cannot fail and did not need explaining.
        $waitApplies = $recentlyCompleted;

        return [
            new Requirement(
                label: 'No other training open',
                met: ! $hasActive,
                detail: $hasActive ? 'You have a training request open. One at a time.' : null,
                blocking: true,
            ),
            ...($waitApplies ? [
                new Requirement(
                    label: '7 days since your last completed training',
                    met: false,
                    detail: 'Wait seven days after finishing a training before asking for the next one.',
                    blocking: true,
                ),
            ] : []),

            // ONLY WHEN IT APPLIES, and this is the fix rather than the
            // formatting.
            //
            // An OBS has no ATC rating to keep active, so telling them their
            // rating is inactive is telling them something that is not about
            // them -- and it was rendering as a requirement they could never
            // satisfy and never needed to. The same is true of anybody already
            // in training, who is measured by their training rather than by
            // their hours, which is exactly what makes refresher training
            // reachable at all.
            //
            // The rule itself is upstream's and unchanged. What changed is that
            // a rule that does not apply to you is no longer shown to you.
            ...($activityApplies ? [
                new Requirement(
                    label: "ATC rating active in {$divisionName}",
                    met: $user->isAtcActive(),
                    detail: $user->isAtcActive()
                        ? null
                        : 'Your rating is inactive here. Ask training staff about a refresh.',
                    blocking: true,
                ),
            ] : []),
        ];
    }

    /**
     * Whether every BLOCKING requirement is met.
     *
     * Not a replacement for `TrainingPolicy::apply()` and must not become one:
     * the policy is the gate and this is the explanation. Two implementations
     * of one rule is how they come to disagree, so anything that has to REFUSE
     * still asks the policy.
     */
    public static function blockersFor(User $user, ?Rating $rating = null): Collection
    {
        return self::for($user, $rating)->filter(fn (Requirement $r) => $r->blocking && ! $r->met)->values();
    }
}
