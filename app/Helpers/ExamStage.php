<?php

namespace App\Helpers;

/**
 * VATSSA: where a practical exam has got to.
 *
 * ## Why this is a state machine and not a pile of booleans
 *
 * The CPT workflow has five parties -- mentor, training manager, student,
 * events team, examiner -- and eight handoffs. Every handoff is somewhere it
 * can stall silently, and the whole reason the current process hurts is that
 * nobody can see whose turn it is. `authorised_at`, `availability_at`,
 * `cleared_at`, `confirmed_at` as separate nullable columns answers "what has
 * happened" and never answers the only question anybody asks, which is
 * "who are we waiting for".
 *
 * One stage does. Every page in this feature sorts and filters by it, and the
 * Discord pings fire on transitions rather than on somebody remembering.
 *
 * ## The order is the workflow
 *
 * Values ascend, so `>` means "further along" and a list sorts correctly with
 * no lookup table. Unlike TrainingStatus -- which had to append AWAITING_MENTOR
 * as 4 because renumbering would have rewritten history -- nothing has been
 * stored yet, so this gets to be in the right order from the start.
 */
enum ExamStage: int
{
    /** The mentor has asked. Waiting on the ATC training manager. */
    case REQUESTED = 0;

    /** Authorised. Waiting on the student to give their availability. */
    case AWAITING_AVAILABILITY = 1;

    /** The student has submitted. Waiting on the events team to clear times. */
    case AWAITING_EVENTS = 2;

    /** Times are clear. Waiting on an examiner to take it. */
    case AWAITING_EXAMINER = 3;

    /** An examiner has confirmed a slot. Waiting on the events team to publish. */
    case CONFIRMED = 4;

    /** Banner, Discord, myVATSIM and the booking are all done. Waiting on the day. */
    case PUBLISHED = 5;

    /** Sat. The result lives on the training, not here. */
    case COMPLETED = 6;

    /** Called off. Kept, never deleted -- see the model. */
    case CANCELLED = -1;

    /** Nobody confirmed in time, or the student pulled out. Back to availability. */
    case LAPSED = -2;

    public function label(): string
    {
        return match ($this) {
            self::REQUESTED => 'Awaiting authorisation',
            self::AWAITING_AVAILABILITY => 'Awaiting student availability',
            self::AWAITING_EVENTS => 'Awaiting events team',
            self::AWAITING_EXAMINER => 'Awaiting an examiner',
            self::CONFIRMED => 'Confirmed',
            self::PUBLISHED => 'Published',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            self::LAPSED => 'Lapsed',
        };
    }

    /**
     * Whose turn it is, in words.
     *
     * The single most useful string in the feature. Every list shows it,
     * because "awaiting events team" tells four of the five parties that they
     * can stop looking.
     */
    public function waitingOn(): ?string
    {
        return match ($this) {
            self::REQUESTED => 'ATC training manager',
            self::AWAITING_AVAILABILITY => 'the student',
            self::AWAITING_EVENTS => 'the events team',
            self::AWAITING_EXAMINER => 'an examiner',
            self::CONFIRMED => 'the events team',
            self::PUBLISHED => 'the exam date',
            default => null,
        };
    }

    /** Still moving. Anything else is finished or fell over. */
    public function isOpen(): bool
    {
        return $this->value >= 0 && $this !== self::COMPLETED;
    }

    public function isFinished(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * A colour, and only three of them.
     *
     * Amber where somebody is being waited on, brand where it is settled and
     * moving, neutral where it is over. Nine stages in nine colours would be a
     * legend nobody learns.
     */
    public function tone(): string
    {
        return match ($this) {
            self::CONFIRMED, self::PUBLISHED => 'brand',
            self::COMPLETED => 'good',
            self::CANCELLED, self::LAPSED => 'neutral',
            default => 'warn',
        };
    }

    /**
     * May this stage follow the current one?
     *
     * Forward one step, or off the side to cancelled. Deliberately strict: the
     * point of the workflow is that the events team cannot clear times before
     * the student has given any, and an examiner cannot confirm a slot nobody
     * has cleared. A dropdown that lets somebody skip a step is a workflow that
     * is really just a status field.
     *
     * LAPSED is the one backwards move, and it lands on AWAITING_AVAILABILITY
     * rather than starting over -- the authorisation still stands, so asking
     * for it again would be make-work.
     */
    public function canFollow(self $current): bool
    {
        if ($this === self::CANCELLED) {
            return $current->isOpen();
        }

        if ($this === self::LAPSED) {
            return $current->value >= self::AWAITING_EVENTS->value;
        }

        if ($current === self::LAPSED) {
            return $this === self::AWAITING_AVAILABILITY;
        }

        return $this->value === $current->value + 1;
    }

    /** @return array<int, self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }
}
