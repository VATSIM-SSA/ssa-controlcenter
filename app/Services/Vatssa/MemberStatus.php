<?php

namespace App\Services\Vatssa;

use App\Helpers\Vatssa\DivisionalRelationship;
use App\Helpers\Vatssa\MembershipRequestType;
use App\Helpers\Vatssa\StatusAxis;
use App\Models\User;
use App\Models\Vatssa\MembershipRequest;
use App\Models\Vatssa\MemberStatusEntry;
use Illuminate\Support\Collection;

/**
 * VATSSA: what a member is to the division, worked out from data.
 *
 * ## Nothing here is set by hand
 *
 * Every value is derived from three sources: the VATSIM division field, the
 * roster, and the transfer/visit system. The profile that shows it asserts
 * nothing of its own, so it cannot disagree with the systems it reports on.
 *
 * Where an exception is needed it is made in the system that OWNS the fact --
 * an exception to the roster in the roster, an exception to a visit in the
 * visiting system. That is the point of deriving rather than storing: a
 * hand-set field on a profile drifts from the roster within weeks and leaves
 * two screens disagreeing about the same person, with no way to tell which is
 * lying.
 *
 * ## Two axes, not one label
 *
 * `relationshipFor()` answers where a member belongs. `isApprovedController()`
 * answers whether they may control. They are independent: a visiting controller
 * with approved-controller permissions is an ordinary member, and a single
 * five-value field could not have represented one.
 *
 * ## What sync() is for
 *
 * The derivation is complete without any stored state, with one exception it
 * cannot cover: SINCE WHEN. Today's roster says a member is approved; it does
 * not say they were approved in March, and re-deriving never recovers a date
 * nobody wrote down. sync() appends a history row when the derived answer
 * differs from the last one recorded, and does nothing at all otherwise.
 */
class MemberStatus
{
    /**
     * Where this member belongs, right now.
     *
     * Order matters. A member whose division is ours is home whatever else is
     * open -- a home member with a stale visiting request is home, not a
     * visitor -- so the division test comes first and the in-progress states
     * are only ever reachable from outside.
     */
    public function relationshipFor(User $user): DivisionalRelationship
    {
        if ($user->isMember()) {
            return DivisionalRelationship::HOME;
        }

        // Transfer outranks visit. Somebody with both open is on their way to
        // becoming a home member, and that is the more consequential of the
        // two -- it is the one that ends with their division changing.
        if ($this->hasLiveRequest($user, MembershipRequestType::TRANSFER)) {
            return DivisionalRelationship::TRANSFERRING;
        }

        if ($this->hasLiveRequest($user, MembershipRequestType::VISITING)) {
            return DivisionalRelationship::VISITING;
        }

        return DivisionalRelationship::INTERNATIONAL;
    }

    /**
     * Whether they may control: active on the roster.
     *
     * The roster is upstream's ATC activity, which is what "active on the
     * roster" already means everywhere else in Control Center. Reusing it
     * rather than adding a second flag keeps one answer to the question.
     */
    public function isApprovedController(User $user): bool
    {
        return (bool) $user->isAtcActive();
    }

    /**
     * A request that still says something about where this member stands.
     *
     * "Live" is anything not finished -- on the desk, waiting on training,
     * waiting on the member. A completed visit stops making somebody a visitor
     * in the in-progress sense; what it leaves behind is a visiting endorsement
     * and a place on the roster, which the other axis reports.
     *
     * A closed or rejected request says nothing at all, which is why a member
     * refused last year is international today rather than permanently marked.
     */
    private function hasLiveRequest(User $user, MembershipRequestType $type): bool
    {
        return MembershipRequest::where('user_id', $user->id)
            ->where('type', $type)
            ->get()
            ->contains(fn (MembershipRequest $request) => ! $request->state->isFinished());
    }

    /**
     * Record a change, if there has been one.
     *
     * Idempotent, and silent when nothing moved: calling it on every member
     * every night writes nothing on a night when nothing happened. That is what
     * makes the table a list of transitions rather than a sample of when the
     * job last ran.
     *
     * @return int how many rows were written, for a command that reports
     */
    public function sync(User $user, ?string $note = null): int
    {
        $written = 0;

        $written += $this->record(
            $user,
            StatusAxis::RELATIONSHIP,
            $this->relationshipFor($user)->value,
            $note
        ) ? 1 : 0;

        $written += $this->record(
            $user,
            StatusAxis::ROSTER,
            $this->rosterValue($user),
            $note
        ) ? 1 : 0;

        return $written;
    }

    private function rosterValue(User $user): string
    {
        return $this->isApprovedController($user)
            ? MemberStatusEntry::ROSTER_APPROVED
            : MemberStatusEntry::ROSTER_NOT_APPROVED;
    }

    /**
     * Append one row, if the value has moved.
     *
     * effective_from is now rather than the moment the change really happened,
     * because the change happened somewhere this code cannot see -- a division
     * field updating at VATSIM, an activity recalculation. Now is when we
     * NOTICED, and it is the most honest date available. A caller who genuinely
     * knows better passes a note saying so.
     */
    private function record(User $user, StatusAxis $axis, string $value, ?string $note): bool
    {
        $latest = $this->latest($user, $axis);

        if ($latest && $latest->value === $value) {
            return false;
        }

        MemberStatusEntry::create([
            'user_id' => $user->id,
            'axis' => $axis,
            'value' => $value,
            'effective_from' => now(),
            // The first row for an axis is not a change, and saying "became
            // home" about somebody who has always been home would be a small
            // lie on every profile in the division on the day this ships.
            'note' => $note ?? ($latest === null ? 'First recorded' : null),
        ]);

        return true;
    }

    private function latest(User $user, StatusAxis $axis): ?MemberStatusEntry
    {
        return MemberStatusEntry::about($user)
            ->forAxis($axis)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The recorded history, newest first, both axes together.
     *
     * One list rather than two, because the question a reader has is "what has
     * happened to this member" and the answer interleaves: became a visitor,
     * went on the roster, transferred, became home.
     *
     * @return Collection<int, MemberStatusEntry>
     */
    public function historyFor(User $user): Collection
    {
        return MemberStatusEntry::about($user)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The history of one axis only, for the roster box.
     *
     * @return Collection<int, MemberStatusEntry>
     */
    public function historyForAxis(User $user, StatusAxis $axis): Collection
    {
        return MemberStatusEntry::about($user)
            ->forAxis($axis)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * When the current value took effect, or null if it was never recorded.
     *
     * Null is the ordinary answer for the first months after this ships, and it
     * has to stay distinguishable from "changed today" -- the profile says
     * "not recorded" rather than inventing a date.
     */
    public function currentSince(User $user, StatusAxis $axis): ?MemberStatusEntry
    {
        $latest = $this->latest($user, $axis);

        if ($latest === null) {
            return null;
        }

        $current = $axis === StatusAxis::RELATIONSHIP
            ? $this->relationshipFor($user)->value
            : $this->rosterValue($user);

        // The recorded row is only "since when" if it still matches what the
        // derivation says today. When it does not, the sync has not caught up
        // and the honest answer is that we do not know.
        return $latest->value === $current ? $latest : null;
    }
}
