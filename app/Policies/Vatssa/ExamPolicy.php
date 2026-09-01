<?php

namespace App\Policies\Vatssa;

use App\Helpers\ExamStage;
use App\Models\Training;
use App\Models\User;
use App\Models\Vatssa\Exam;

/**
 * VATSSA: who may do which step of an exam booking.
 *
 * ## Every method is a different job
 *
 * The temptation is one `manage` check that any staff member passes. That is
 * how the current process works and it is why it does not: the events team end
 * up picking dates, an examiner ends up chasing banners, and nobody is
 * accountable for a step because everybody could have done it.
 *
 * So authorising, clearing the calendar and confirming a slot are three
 * separate permissions held by three different groups, and the workflow is only
 * a workflow because none of them can do another's step.
 *
 * ## Stage is part of the check, not just permission
 *
 * `confirm` is false for an examiner when the events team have not cleared the
 * times yet, even though that examiner will be allowed to confirm in an hour.
 * Checking permission alone would let a crafted POST jump the queue, and the
 * ordering IS the feature.
 */
class ExamPolicy
{
    /**
     * Admins see and do everything, as everywhere else in this application.
     *
     * Not a shortcut: somebody has to be able to unstick a booking at 2am when
     * the examiner who took it has vanished, and the alternative is a database
     * console.
     */
    public function before(User $user, string $ability): ?bool
    {
        // NOT every ability. An unrestricted before() short-circuits the stage
        // checks below, which are the workflow -- so an admin was offered the
        // student's availability form on an exam nobody had authorised yet, and
        // the page died on a poll that does not exist at that stage.
        //
        // The stage ordering is the feature. An admin bypassing it is not a
        // superpower, it is the workflow not applying to the one person most
        // likely to be clicking around it.
        //
        // Reading and stopping are the two an admin genuinely needs at 2am when
        // the examiner who took it has vanished. Everything else falls through
        // to its own check, which an admin passes on permission anyway -- at
        // the right stage.
        if (! in_array($ability, ['view', 'viewAny', 'cancel'], true)) {
            return null;
        }

        return $user->hasPermission('system.settings.manage') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        // Deliberately wide. A student needs this page to find their own exam,
        // a mentor to see their student's, an examiner to find work. The LIST
        // is filtered per person; the page itself is not a secret.
        return true;
    }

    public function view(User $user, Exam $exam): bool
    {
        return $exam->training?->user_id === $user->id
            || $exam->training?->mentors->contains($user->id)
            || $user->hasPermission('fir.management.reports.view')
            || $user->hasPermission('examinations.manage');
    }

    /**
     * Raise one. The mentor's call, because they are the one who knows.
     */
    public function create(User $user, Training $training): bool
    {
        if (! $training->status->isInProgress()) {
            return false;
        }

        return $training->mentors->contains($user->id)
            || $user->hasPermission('training.ratings.manage')
            || $user->hasPermission('fir.management.reports.view');
    }

    /**
     * Authorise it. The ATC training manager only.
     *
     * Not the pipeline coordinator, deliberately. A coordinator asking for
     * their own student's exam and approving it is not an authorisation, it is
     * a formality with an extra click.
     */
    public function authorise(User $user, Exam $exam): bool
    {
        return $exam->stage === ExamStage::REQUESTED
            && $user->hasPermission('training.ratings.manage');
    }

    /**
     * The student marking their own availability as finished.
     */
    public function submitAvailability(User $user, Exam $exam): bool
    {
        return $exam->stage === ExamStage::AWAITING_AVAILABILITY
            && $exam->training?->user_id === $user->id;
    }

    /**
     * Clear the calendar, and later publish it. The events team.
     *
     * ## Why this is a human step and not a bookings query
     *
     * `bookings` has event flags and times, so the clash could in principle be
     * computed. It cannot be trusted to: the events team routinely have plans
     * that are not published yet, so the calendar is a hint and their answer is
     * the fact. Automating this step would confidently clear a slot that the
     * division had already promised to something else.
     */
    public function clear(User $user, Exam $exam): bool
    {
        return in_array($exam->stage, [ExamStage::AWAITING_EVENTS, ExamStage::CONFIRMED], true)
            && $user->hasPermission('events.exams.manage');
    }

    /**
     * Take the exam and name the slot.
     *
     * Examiner endorsement covering this training's rating. Holding the
     * endorsement for S3 does not make somebody an S1 examiner by accident --
     * the endorsement carries its ratings, and this reads them rather than
     * assuming a ladder.
     */
    public function confirm(User $user, Exam $exam): bool
    {
        if ($exam->stage !== ExamStage::AWAITING_EXAMINER) {
            return false;
        }

        if (! $user->hasPermission('examinations.manage')) {
            return false;
        }

        // Never your own student. An examiner who has been mentoring somebody
        // cannot assess them, and this is the one conflict of interest the
        // system can actually prevent.
        if ($exam->training?->mentors->contains($user->id)) {
            return false;
        }

        $ratings = $exam->training?->ratings ?? collect();

        return $ratings->isEmpty()
            || $ratings->contains(fn ($rating) => $user->hasEndorsementRating($rating));
    }

    /**
     * Call it off.
     *
     * Anybody who could have moved it forward, plus the student. A student who
     * knows they are not ready should not have to ask three people to stop a
     * booking that exists for their benefit.
     */
    public function cancel(User $user, Exam $exam): bool
    {
        return $exam->stage->isOpen()
            && ($exam->training?->user_id === $user->id
                || $exam->training?->mentors->contains($user->id)
                || $user->hasPermission('training.ratings.manage')
                || $user->hasPermission('events.exams.manage')
                || $user->hasPermission('examinations.manage'));
    }
}
