<?php

namespace App\Helpers\Vatssa;

/**
 * VATSSA: what a membership request IS.
 *
 * Five types in one table, and the type decides two things: which state machine
 * the request runs, and whether the member ever sees it.
 *
 * ## The split that matters
 *
 * Two of these are **filed by a person**: a visiting request and a transfer
 * request. Somebody outside the division asks to come in, and the division
 * answers.
 *
 * The other three are **Terminal work** — things the membership desk does,
 * which happen to need the same record, the same Terminal log and the same
 * audit trail. Nobody filed them. Listing them back to the member as "your
 * requests" would show them something they never asked for.
 *
 * That is why `isMemberFiled()` exists and why it is asked before anything is
 * rendered to a member.
 */
enum MembershipRequestType: string
{
    case VISITING = 'visiting';

    case TRANSFER = 'transfer';

    case RATING_UPGRADE = 'rating-upgrade';

    case STAFF_INQUIRY = 'staff-inquiry';

    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::VISITING => 'Visiting request',
            self::TRANSFER => 'Transfer request',
            self::RATING_UPGRADE => 'Rating upgrade',
            self::STAFF_INQUIRY => 'Staff inquiry',
            self::OTHER => 'Other',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::VISITING => 'fa-plane-arrival',
            self::TRANSFER => 'fa-right-left',
            self::RATING_UPGRADE => 'fa-circle-arrow-up',
            self::STAFF_INQUIRY => 'fa-user-shield',
            self::OTHER => 'fa-circle-question',
        };
    }

    /**
     * Whether a member asked for this, or the desk raised it about them.
     *
     * The whole of the member-facing side keys off this. A rating upgrade is
     * raised by the system from a training, a staff inquiry by leadership, and
     * "other" by whoever needed a record of something — none of them is a
     * request the member made, and showing one back to them as theirs would be
     * inventing a thing they did.
     */
    public function isMemberFiled(): bool
    {
        return in_array($this, [self::VISITING, self::TRANSFER], true);
    }

    /**
     * Whether this type runs the full transfer workflow.
     *
     * Only the member-filed ones do. A disciplinary check, a Terminal action
     * and a familiarisation training that can fail describe a person moving
     * between divisions; a rating upgrade has none of that, and "pending
     * transfer complete" against one is a state nobody can reach and somebody
     * eventually sets by accident.
     */
    public function runsFullWorkflow(): bool
    {
        return $this->isMemberFiled();
    }

    /**
     * Whether this type carries a TVCP eligibility snapshot.
     *
     * Only visiting and transfer. A rating upgrade has nothing to check against
     * TVCP 4.2, and storing an empty snapshot against one would make the column
     * mean two different things depending on the row.
     */
    public function carriesTvcpChecks(): bool
    {
        return $this->isMemberFiled();
    }

    /** Where a request of this type starts. */
    public function initialState(): MembershipRequestState
    {
        return $this->runsFullWorkflow()
            ? MembershipRequestState::PENDING_DISCIPLINARY
            : MembershipRequestState::OPEN;
    }

    /**
     * The states a request of this type may ever hold.
     *
     * @return array<int, MembershipRequestState>
     */
    public function states(): array
    {
        return $this->runsFullWorkflow()
            ? MembershipRequestState::fullWorkflow()
            : MembershipRequestState::terminalWorkflow();
    }

    /**
     * The two a member may file for themselves.
     *
     * @return array<int, self>
     */
    public static function memberFiled(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => $type->isMemberFiled()));
    }
}
