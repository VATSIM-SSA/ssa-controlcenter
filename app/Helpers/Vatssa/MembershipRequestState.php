<?php

namespace App\Helpers\Vatssa;

/**
 * VATSSA: where a membership request has got to.
 *
 * Two state sets in one enum, because the type decides which applies. See
 * MembershipRequestType and decisions/log.md, 2026-09-03.
 *
 * ## The full workflow — visiting and transfer
 *
 *   Pending disciplinary check
 *      -> Pending transfer            the Terminal action is ours to do
 *      -> Pending transfer complete
 *      -> Pending training            automatic; leaves the open queue
 *      -> Complete
 *
 *   Awaiting member feedback is a PARK, not a step. It can be entered from any
 *   of those and returns to the one it left. A request sitting there is not the
 *   desk's problem and the queue should say so.
 *
 *   Closed by member is terminal and reachable from anywhere. It is NOT a
 *   decline: a decline is one of the three TVCP 5.4 grounds and carries a
 *   written reason entered in the member's record, per 5.5.
 *
 * ## The Terminal workflow — rating upgrade, staff inquiry, other
 *
 *   Open -> Complete, or Open -> Closed when nothing needed doing.
 *
 * ## Why not integer-backed
 *
 * There is no order to compare. Unlike TrainingStatus, nothing asks whether one
 * membership state is greater than another -- the questions are "is this on the
 * desk", "is this finished", and those are named below. A value that reads
 * correctly in a database client is the better one.
 */
enum MembershipRequestState: string
{
    // ------------------------------------------------ the full workflow
    case PENDING_DISCIPLINARY = 'pending-disciplinary';

    case PENDING_TRANSFER = 'pending-transfer';

    case AWAITING_MEMBER = 'awaiting-member';

    case PENDING_TRANSFER_COMPLETE = 'pending-transfer-complete';

    case PENDING_TRAINING = 'pending-training';

    // ---------------------------------------------- the Terminal workflow
    case OPEN = 'open';

    // ------------------------------------------------------ both, at the end
    case COMPLETE = 'complete';

    case CLOSED = 'closed';

    case CLOSED_BY_MEMBER = 'closed-by-member';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_DISCIPLINARY => 'Pending disciplinary check',
            self::PENDING_TRANSFER => 'Pending transfer',
            self::AWAITING_MEMBER => 'Awaiting member feedback',
            self::PENDING_TRANSFER_COMPLETE => 'Pending transfer complete',
            self::PENDING_TRAINING => 'Pending training',
            self::OPEN => 'Open',
            self::COMPLETE => 'Complete',
            self::CLOSED => 'Closed',
            self::CLOSED_BY_MEMBER => 'Closed by member',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::COMPLETE => 'success',
            self::CLOSED, self::CLOSED_BY_MEMBER => 'secondary',
            self::AWAITING_MEMBER => 'info',
            self::PENDING_TRAINING => 'primary',
            default => 'warning',
        };
    }

    /**
     * Whether the membership desk has to do something about this.
     *
     * PENDING_TRAINING is deliberately NOT on the desk. The request is alive,
     * but the next move belongs to the training pipeline -- mixing it into the
     * open queue is how a desk stops trusting its own queue length.
     *
     * AWAITING_MEMBER is off the desk for the same reason from the other side:
     * we asked somebody a question and are waiting for them.
     */
    public function isOnTheDesk(): bool
    {
        return match ($this) {
            self::PENDING_DISCIPLINARY,
            self::PENDING_TRANSFER,
            self::PENDING_TRANSFER_COMPLETE,
            self::OPEN => true,
            default => false,
        };
    }

    /** Whether anything more will happen to this request. */
    public function isFinished(): bool
    {
        return in_array($this, [self::COMPLETE, self::CLOSED, self::CLOSED_BY_MEMBER], true);
    }

    /**
     * The park, and the two states that end a request from outside the flow.
     *
     * Named rather than inlined because three separate places ask "can this
     * still be moved", and three copies of a list is how they come to disagree.
     */
    public function isPark(): bool
    {
        return $this === self::AWAITING_MEMBER;
    }

    /** @return array<int, self> */
    public static function fullWorkflow(): array
    {
        return [
            self::PENDING_DISCIPLINARY,
            self::PENDING_TRANSFER,
            self::AWAITING_MEMBER,
            self::PENDING_TRANSFER_COMPLETE,
            self::PENDING_TRAINING,
            self::COMPLETE,
            self::CLOSED_BY_MEMBER,
        ];
    }

    /** @return array<int, self> */
    public static function terminalWorkflow(): array
    {
        return [self::OPEN, self::COMPLETE, self::CLOSED];
    }

    /**
     * Every state that keeps a request on the membership desk.
     *
     * @return array<int, self>
     */
    public static function onTheDesk(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOnTheDesk()));
    }
}
