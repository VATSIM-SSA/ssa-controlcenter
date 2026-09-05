<?php

namespace Tests\Feature;

use App\Helpers\Vatssa\DivisionalRelationship;
use App\Helpers\Vatssa\MembershipRequestState;
use App\Helpers\Vatssa\MembershipRequestType;
use App\Helpers\Vatssa\StatusAxis;
use App\Models\User;
use App\Models\Vatssa\MembershipRequest;
use App\Models\Vatssa\MemberStatusEntry;
use App\Services\Vatssa\MemberStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: a member's standing, and the record of when it changed.
 *
 * The properties worth protecting:
 *
 *  1. The two axes are independent. A visitor can be an approved controller,
 *     and any design that cannot express that is wrong.
 *  2. A home member is home whatever else is open.
 *  3. A finished request stops meaning anything.
 *  4. The history is a list of TRANSITIONS, not a sample of when the job ran.
 */
class MemberStatusTest extends TestCase
{
    use RefreshDatabase;

    private function memberStatus(): MemberStatus
    {
        return app(MemberStatus::class);
    }

    /**
     * A member of this organisation.
     *
     * BOTH columns, because `isMember()` reads whichever one `app.mode` names
     * -- division in division mode, subdivision in subdivision mode -- and the
     * test suite runs in the default subdivision mode while VATSSA itself is a
     * division. Setting one would have made this test pass or fail on a config
     * value that has nothing to do with what it is checking.
     */
    private function homeMember(): User
    {
        return User::factory()->create([
            'division' => config('app.owner_code'),
            'subdivision' => config('app.owner_code'),
        ]);
    }

    private function outsider(): User
    {
        return User::factory()->create([
            'division' => 'ZZZ',
            'subdivision' => 'ZZZ',
        ]);
    }

    private function request(User $user, MembershipRequestType $type, MembershipRequestState $state): MembershipRequest
    {
        $request = MembershipRequest::open($type, $user, $user);
        $request->moveTo($state);

        return $request;
    }

    // --------------------------------------------------------- relationship

    #[Test]
    public function a_member_of_this_division_is_home(): void
    {
        $this->assertSame(
            DivisionalRelationship::HOME,
            $this->memberStatus()->relationshipFor($this->homeMember())
        );
    }

    #[Test]
    public function somebody_elsewhere_with_nothing_open_is_international(): void
    {
        $this->assertSame(
            DivisionalRelationship::INTERNATIONAL,
            $this->memberStatus()->relationshipFor($this->outsider())
        );
    }

    #[Test]
    public function an_outsider_with_a_visiting_request_is_a_visitor(): void
    {
        $user = $this->outsider();
        $this->request($user, MembershipRequestType::VISITING, MembershipRequestState::PENDING_DISCIPLINARY);

        $this->assertSame(
            DivisionalRelationship::VISITING,
            $this->memberStatus()->relationshipFor($user)
        );
    }

    #[Test]
    public function an_outsider_with_a_transfer_request_is_transferring(): void
    {
        $user = $this->outsider();
        $this->request($user, MembershipRequestType::TRANSFER, MembershipRequestState::PENDING_DISCIPLINARY);

        $this->assertSame(
            DivisionalRelationship::TRANSFERRING,
            $this->memberStatus()->relationshipFor($user)
        );
    }

    #[Test]
    public function a_home_member_stays_home_with_a_stale_request_open(): void
    {
        // The division field is the whole test for home. Somebody who
        // transferred in and whose old request was never closed must not read
        // as still transferring -- the profile would contradict the division
        // column two lines above it.
        $user = $this->homeMember();
        $this->request($user, MembershipRequestType::TRANSFER, MembershipRequestState::PENDING_DISCIPLINARY);

        $this->assertSame(
            DivisionalRelationship::HOME,
            $this->memberStatus()->relationshipFor($user)
        );
    }

    #[Test]
    public function an_abandoned_request_stops_meaning_anything(): void
    {
        // Somebody refused last year is international today. A rejection is not
        // a permanent mark, and a profile that kept showing "transferring"
        // years later would be reporting an event as a state.
        $user = $this->outsider();
        $this->request($user, MembershipRequestType::TRANSFER, MembershipRequestState::CLOSED);

        $this->assertSame(
            DivisionalRelationship::INTERNATIONAL,
            $this->memberStatus()->relationshipFor($user)
        );
    }

    #[Test]
    public function a_completed_transfer_stays_transferring_until_vatsim_moves_the_division(): void
    {
        // Completing the request is the DESK's half. VATSIM moving the division
        // field is what actually makes somebody a home member, and it arrives
        // later -- so the request completing must not promise it.
        //
        // The failure this prevents is worse than a wrong label: if COMPLETE
        // ended the transfer, the member would drop to INTERNATIONAL for the
        // days in between, reporting somebody halfway through a transfer as
        // having no relationship with us at all.
        $user = $this->outsider();
        $this->request($user, MembershipRequestType::TRANSFER, MembershipRequestState::COMPLETE);

        $this->assertSame(
            DivisionalRelationship::TRANSFERRING,
            $this->memberStatus()->relationshipFor($user)
        );
    }

    #[Test]
    public function the_division_field_is_what_finally_makes_them_home(): void
    {
        // The other half of the rule above. Once VATSIM reports the division,
        // isMember() catches it at the top of relationshipFor() and the
        // completed request stops mattering -- no second write, and no state
        // to keep in step.
        $user = $this->homeMember();
        $this->request($user, MembershipRequestType::TRANSFER, MembershipRequestState::COMPLETE);

        $this->assertSame(
            DivisionalRelationship::HOME,
            $this->memberStatus()->relationshipFor($user)
        );
    }

    #[Test]
    public function a_completed_visit_leaves_them_international(): void
    {
        // The asymmetry that is easiest to get wrong. A completed VISIT leaves
        // somebody international, on the roster; a completed TRANSFER makes
        // them home. Same familiarisation training, different destination.
        $user = $this->outsider();
        $this->request($user, MembershipRequestType::VISITING, MembershipRequestState::COMPLETE);

        $this->assertSame(
            DivisionalRelationship::INTERNATIONAL,
            $this->memberStatus()->relationshipFor($user)
        );
    }

    #[Test]
    public function a_transfer_outranks_a_visit_when_both_are_open(): void
    {
        // Somebody with both is on their way to becoming a home member, which
        // is the more consequential of the two.
        $user = $this->outsider();
        $this->request($user, MembershipRequestType::VISITING, MembershipRequestState::PENDING_DISCIPLINARY);
        $this->request($user, MembershipRequestType::TRANSFER, MembershipRequestState::PENDING_DISCIPLINARY);

        $this->assertSame(
            DivisionalRelationship::TRANSFERRING,
            $this->memberStatus()->relationshipFor($user)
        );
    }

    // ---------------------------------------------------------- the history

    #[Test]
    public function the_first_sync_records_both_axes(): void
    {
        $user = $this->homeMember();

        $this->assertSame(2, $this->memberStatus()->sync($user));

        $this->assertSame(1, MemberStatusEntry::about($user)->forAxis(StatusAxis::RELATIONSHIP)->count());
        $this->assertSame(1, MemberStatusEntry::about($user)->forAxis(StatusAxis::ROSTER)->count());
    }

    #[Test]
    public function a_sync_that_finds_no_change_writes_nothing(): void
    {
        // The property that makes this a list of transitions rather than a
        // sample every time the nightly job ran. Without it a member's history
        // would be one identical row per day for as long as they exist.
        $user = $this->homeMember();
        $this->memberStatus()->sync($user);

        $this->assertSame(0, $this->memberStatus()->sync($user));
        $this->assertSame(2, MemberStatusEntry::about($user)->count());
    }

    #[Test]
    public function a_change_of_standing_appends_a_row(): void
    {
        $user = $this->outsider();
        $this->memberStatus()->sync($user);

        $this->request($user, MembershipRequestType::TRANSFER, MembershipRequestState::PENDING_DISCIPLINARY);

        $this->assertSame(1, $this->memberStatus()->sync($user));

        $rows = MemberStatusEntry::about($user)
            ->forAxis(StatusAxis::RELATIONSHIP)
            ->orderBy('id')
            ->pluck('value')
            ->all();

        $this->assertSame(['international', 'transferring'], $rows);
    }

    #[Test]
    public function the_first_row_is_not_described_as_a_change(): void
    {
        // Saying "became home" about somebody who has always been home would
        // be a small lie on every profile in the division on the day this
        // shipped.
        $user = $this->homeMember();
        $this->memberStatus()->sync($user);

        $first = MemberStatusEntry::about($user)->forAxis(StatusAxis::RELATIONSHIP)->sole();

        $this->assertSame('First recorded', $first->note);
    }

    #[Test]
    public function since_when_is_null_until_something_has_been_recorded(): void
    {
        // The ordinary answer for the first months after this ships. It has to
        // stay distinguishable from "changed today", or the profile invents a
        // date it does not have.
        $user = $this->homeMember();

        $this->assertNull($this->memberStatus()->currentSince($user, StatusAxis::RELATIONSHIP));
    }

    #[Test]
    public function since_when_is_withheld_while_the_record_disagrees_with_today(): void
    {
        // The sync runs nightly, so between a change and the next run the
        // recorded row is stale. Reporting its date as "since" would attach a
        // real date to the wrong value.
        $user = $this->outsider();
        $this->memberStatus()->sync($user);

        $this->request($user, MembershipRequestType::TRANSFER, MembershipRequestState::PENDING_DISCIPLINARY);

        $this->assertNull($this->memberStatus()->currentSince($user, StatusAxis::RELATIONSHIP));
    }

    #[Test]
    public function the_history_carries_both_axes_together(): void
    {
        $user = $this->homeMember();
        $this->memberStatus()->sync($user);

        $history = $this->memberStatus()->historyFor($user);

        $this->assertCount(2, $history);
        $this->assertEqualsCanonicalizing(
            [StatusAxis::RELATIONSHIP, StatusAxis::ROSTER],
            $history->pluck('axis')->all()
        );
    }

    #[Test]
    public function a_row_labels_itself_whichever_axis_it_is_on(): void
    {
        // One accessor, so the history template never branches on the axis to
        // work out how to print a value.
        $user = $this->homeMember();
        $this->memberStatus()->sync($user);

        foreach ($this->memberStatus()->historyFor($user) as $entry) {
            $this->assertNotSame('', $entry->label());
        }
    }

    // ------------------------------------------------------------ the command

    #[Test]
    public function the_command_records_everybody_and_then_goes_quiet(): void
    {
        User::factory()->count(3)->create();

        $this->artisan('vatssa:sync:member-status')->assertSuccessful();

        $this->assertGreaterThan(0, MemberStatusEntry::count());

        $after = MemberStatusEntry::count();
        $this->artisan('vatssa:sync:member-status')->assertSuccessful();

        $this->assertSame($after, MemberStatusEntry::count(), 'a quiet night writes nothing');
    }
}
