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
            // VATSSA: renamed, because both upstream labels describe a queue
            // and neither says what the student is waiting FOR. "In queue"
            // was especially ambiguous once awaiting-mentor existed -- two of
            // the five stages are a queue, and this is the other one.
            //
            // Only the LABEL changes. The case names and backing values are
            // untouched, so nothing in the database or the API moves.
            self::IN_QUEUE => 'Awaiting theory',
            self::PRE_TRAINING => 'Theory phase',
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

    /**
     * Where this stage sits in the LIFECYCLE, which is not its stored value.
     *
     * AWAITING_MENTOR is stored as 4 so that adding it renumbered nothing --
     * see the note at the top of this file. The price is that anything ordering
     * by the backing value puts it after "awaiting exam", which is nonsense: a
     * student waiting for a mentor has not started training, let alone finished
     * it.
     *
     * So ordering asks this instead. The stored value stays as it is, history
     * stays readable, and dropdowns and tables read in the order the stages
     * actually happen.
     *
     * Anything upstream adds falls through to its own value, which is right --
     * upstream's numbering IS its lifecycle order.
     */
    public function lifecycleOrder(): int
    {
        return match ($this) {
            self::AWAITING_MENTOR => 2,     // after theory, before training
            self::ACTIVE_TRAINING => 3,
            self::AWAITING_EXAM => 4,
            default => $this->value,
        };
    }

    /**
     * Every stage, in the order they happen rather than the order they are
     * stored. Use this anywhere a human reads the list.
     *
     * @return array<int, self>
     */
    public static function inLifecycleOrder(): array
    {
        $cases = self::cases();
        usort($cases, fn (self $a, self $b) => $a->lifecycleOrder() <=> $b->lifecycleOrder());

        return $cases;
    }

    public function isAssignableByStaff(): bool
    {
        return match ($this) {
            self::CLOSED_BY_SYSTEM,
            self::CLOSED_BY_STUDENT => false,

            // VATSSA: the pipeline owns these four, and a human setting one by
            // hand puts the bot and Control Center into disagreement about
            // where somebody is. The system moves people in and out of them.
            //
            // ACTIVE_TRAINING is here because it means "a mentor is assigned",
            // which is a fact about the mentor table rather than an opinion --
            // assigning the mentor is what moves somebody here.
            //
            // AWAITING_MENTOR is the one exception, and isAssignableFrom()
            // grants it: a student whose mentor has gone must be returnable to
            // the queue by hand, because that is a decision rather than a fact.
            self::IN_QUEUE,
            self::PRE_TRAINING,
            self::ACTIVE_TRAINING,
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

        // THE PIPELINE OWNS THESE THREE STAGES OUTRIGHT.
        //
        // Awaiting theory, theory phase and awaiting mentor are decided by
        // facts the pipeline holds and Control Center does not: a Moodle
        // enrolment, a theory pass, a mentor being assigned. Moving somebody by
        // hand asserts one of those happened, and the next cycle moves them
        // straight back -- which reads as a bug rather than a rule.
        //
        // The pause checkbox is untouched and is the one thing staff SHOULD do
        // here: a student stepping away mid-theory is exactly the case it
        // exists for.
        //
        // Closing stays available, and that is a deliberate exception rather
        // than an oversight. A student who drops out during theory has to be
        // closable by somebody, upstream already closes them automatically when
        // the interest confirmation goes unanswered, and blocking it would
        // strand every abandoned training in the system forever. Closing is not
        // progress.
        if (in_array($current, [self::IN_QUEUE, self::PRE_TRAINING, self::AWAITING_MENTOR], true)) {
            return $this->isClosed() && $this->isAssignableByStaff();
        }

        // Back to awaiting a mentor, from anywhere a mentor could be lost.
        // Active training is the obvious one; a student waiting on a CPT whose
        // mentor disappears needs it just as much, and rejoining the queue is
        // the honest answer for both.
        if ($this === self::AWAITING_MENTOR) {
            return in_array($current, [self::ACTIVE_TRAINING, self::AWAITING_EXAM], true);
        }

        // AND BACK AGAIN, from awaiting-exam to active training.
        //
        // This was blocked, and blocking it was wrong. The pipeline sets
        // ACTIVE_TRAINING when a mentor is assigned, which is why it is not
        // freely assignable -- but moving somebody OUT of awaiting-exam is not
        // that fact being asserted, it is a human saying the exam is not
        // happening yet. A failed CPT, a cancelled one, a student who turned
        // out not to be ready: all three leave a training parked in a stage
        // that claims they are ready to sit, with no way back.
        //
        // The pipeline never contradicts this, because nothing in it moves a
        // training out of ACTIVE_TRAINING on its own.
        if ($this === self::ACTIVE_TRAINING) {
            return $current === self::AWAITING_EXAM;
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
