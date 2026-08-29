<?php

namespace App\Helpers;

use App\Traits\ComparableIntEnum;

/**
 * Constants for training status.
 *
 * VATSSA adds AWAITING_MENTOR: theory is passed, nobody is mentoring yet.
 * Upstream cannot express it -- both the theory window and the mentor wait are
 * "pre-training" -- and it is the stage the whole pipeline turns on.
 *
 * IT IS APPENDED AS 4, NOT INSERTED AS 2, AND THAT IS DELIBERATE.
 *
 * Inserting it where it belongs in the lifecycle would mean renumbering
 * ACTIVE_TRAINING and AWAITING_EXAM under a live database. Two things break
 * when you do that, and only the first is obvious:
 *
 *   1. every `trainings.status` row has to be rewritten, in the right order,
 *      or rows collide mid-migration;
 *   2. `training_activities` stores the OLD and NEW status as bare integers,
 *      as a permanent audit trail. Renumbering silently rewrites history --
 *      every past status change starts describing a different transition, and
 *      nothing in the application will ever tell you.
 *
 * Appending costs nothing, because NO ordered comparison in the codebase uses
 * a bound above PRE_TRAINING. They are all `>= IN_QUEUE` or `>= PRE_TRAINING`,
 * both of which give the right answer for a stage that comes after theory.
 * The single range check that existed -- the 30-day interest chase -- is now a
 * whereIn, which is a fix in its own right: somebody waiting for a mentor is
 * exactly who should be asked whether they are still interested.
 *
 * The detector: `VatssaTest::test_awaiting_mentor_does_not_disturb_the_order`.
 * If upstream ever adds a case of its own at 4, that test fails loudly rather
 * than two stages quietly becoming one.
 */
enum TrainingStatus: int
{
    use ComparableIntEnum;
    case CLOSED_BY_SYSTEM = -4;
    case CLOSED_BY_STUDENT = -3;
    case CLOSED_BY_STAFF = -2;
    case COMPLETED = -1;
    case IN_QUEUE = 0;
    case PRE_TRAINING = 1;
    case ACTIVE_TRAINING = 2;
    case AWAITING_EXAM = 3;
    case AWAITING_MENTOR = 4;   // VATSSA. Lifecycle position: after 1, before 2.

    public function label(): string
    {
        return match ($this) {
            self::CLOSED_BY_SYSTEM => 'Closed by system',
            self::CLOSED_BY_STUDENT => 'Closed by student',
            self::CLOSED_BY_STAFF => 'Closed by staff',
            self::COMPLETED => 'Completed',
            self::IN_QUEUE => 'In queue',
            self::PRE_TRAINING => 'Pre-training',
            self::ACTIVE_TRAINING => 'Active training',
            self::AWAITING_EXAM => 'Awaiting exam',
            self::AWAITING_MENTOR => 'Awaiting mentor',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CLOSED_BY_SYSTEM,
            self::CLOSED_BY_STUDENT,
            self::CLOSED_BY_STAFF => 'danger',
            self::COMPLETED,
            self::ACTIVE_TRAINING => 'success',
            self::IN_QUEUE,
            self::AWAITING_MENTOR,
            self::AWAITING_EXAM => 'warning',
            self::PRE_TRAINING => 'info',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CLOSED_BY_SYSTEM,
            self::CLOSED_BY_STUDENT => 'fa fa-ban',
            self::CLOSED_BY_STAFF => 'fas fa-ban',
            self::COMPLETED => 'fas fa-check',
            self::IN_QUEUE => 'fas fa-hourglass',
            self::PRE_TRAINING,
            self::ACTIVE_TRAINING => 'fas fa-book-open',
            self::AWAITING_MENTOR => 'fas fa-user-clock',
            self::AWAITING_EXAM => 'fas fa-graduation-cap',
        };
    }

    public function isAssignableByStaff(): bool
    {
        return match ($this) {
            self::CLOSED_BY_SYSTEM,
            self::CLOSED_BY_STUDENT => false,

            // VATSSA: the pipeline owns these three, and a human setting one
            // by hand puts the bot and Control Center into disagreement about
            // where somebody is. The system moves people in and out of them.
            self::IN_QUEUE,
            self::PRE_TRAINING,
            self::AWAITING_MENTOR => false,

            default => true,
        };
    }

    /**
     * VATSSA: whether staff may move a training from $current to this status.
     *
     * `isAssignableByStaff()` has no context, so it cannot express the one
     * manual move that is wanted: a student in active training whose mentor
     * has gone should be able to go back to awaiting-mentor and rejoin the
     * queue. Everything else about the three system stages stays closed.
     *
     * This is the UI's filter AND the server's rule -- see
     * `App\Rules\AssignableTrainingStatus`. A dropdown that merely hides an
     * option is decoration.
     */
    public function isAssignableFrom(self $current): bool
    {
        if ($this === $current) {
            return true;    // saving the form without touching the status
        }

        if ($this === self::AWAITING_MENTOR) {
            return $current === self::ACTIVE_TRAINING;
        }

        return $this->isAssignableByStaff();
    }

    public function isOpen(): bool
    {
        return $this->isGreaterThanOrEqual(self::IN_QUEUE);
    }

    public function isClosed(): bool
    {
        return $this->isLessThan(self::IN_QUEUE);
    }

    public function isInProgress(): bool
    {
        return $this->isGreaterThanOrEqual(self::PRE_TRAINING);
    }
}
