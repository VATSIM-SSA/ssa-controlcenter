<?php

namespace App\Helpers;

/**
 * Where a piece of feedback is in the staff workflow.
 *
 * Three states, and the split between the last two is the point: reading
 * feedback and passing it on are different decisions, and a division needs to
 * record that it dealt with something without implying it sent it to anybody.
 *
 * - OPEN      submitted, nobody has looked at it yet. The default queue.
 * - CLOSED    read, and deliberately not passed on. Leaves the queue, keeps
 *             its history, and stays findable behind a filter.
 * - FORWARDED read, and shown to the controller it is about.
 *
 * This replaces the `forwarded` boolean the feedback table has carried since
 * 2023 and which nothing has ever read or written. A boolean cannot say "we
 * read this and decided not to send it", which is the state most feedback
 * actually ends in.
 *
 * ## Why a string enum and not an integer
 *
 * Feedback statuses are a short, stable list with no order to compare. There is
 * no "greater than" question to ask, unlike TrainingStatus, so the value that
 * reads correctly in a database client is the better one.
 */
enum FeedbackStatus: string
{
    case OPEN = 'open';

    case CLOSED = 'closed';

    case FORWARDED = 'forwarded';

    /**
     * The label a staff member reads.
     *
     * "Read and …" rather than bare past participles, because the pair only
     * makes sense together: closed and forwarded are both outcomes of having
     * read something, and naming them that way stops "closed" being read as
     * "rejected".
     */
    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::CLOSED => 'Read and closed',
            self::FORWARDED => 'Read and forwarded',
        };
    }

    /** Bootstrap contextual colour for badges. */
    public function color(): string
    {
        return match ($this) {
            self::OPEN => 'warning',
            self::CLOSED => 'secondary',
            self::FORWARDED => 'success',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OPEN => 'fa-inbox',
            self::CLOSED => 'fa-check',
            self::FORWARDED => 'fa-share',
        };
    }

    /** Whether staff have dealt with this at all. */
    public function isActioned(): bool
    {
        return $this !== self::OPEN;
    }

    /**
     * The statuses staff may choose when actioning.
     *
     * OPEN is not among them. Feedback arrives open and leaves; putting it back
     * would mean an "unaction" that discards who decided and when, and a
     * mis-click is better fixed by re-actioning it than by erasing the record.
     *
     * @return array<int, self>
     */
    public static function actionable(): array
    {
        return [self::CLOSED, self::FORWARDED];
    }
}
