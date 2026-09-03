<?php

namespace Tests\Feature;

use App\Helpers\Vatssa\MembershipRequestState;
use App\Helpers\Vatssa\MembershipRequestType;
use App\Models\User;
use App\Models\Vatssa\MembershipRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: the membership desk, and who may do what at it.
 *
 * The split that matters is between READING a request and DECIDING it. The ATC
 * training manager holds `membership.requests.view` and not `.manage`: they may
 * assign a visiting endorsement on the strength of the membership team having
 * done the Terminal check, so they must be able to see whether it came back
 * clean -- without being able to decide the request or record the check
 * themselves.
 */
class MembershipAdminTest extends TestCase
{
    use RefreshDatabase;

    private function withRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role' => $role, 'area_id' => null]);

        return $user;
    }

    private function request(MembershipRequestType $type = MembershipRequestType::TRANSFER): MembershipRequest
    {
        return MembershipRequest::open($type, User::factory()->create());
    }

    // ------------------------------------------------------------ the queues

    #[Test]
    public function the_membership_manager_sees_the_three_queues(): void
    {
        $staff = $this->withRole('membership-manager');

        foreach (['open', 'training', 'closed'] as $queue) {
            $this->actingAs($staff)
                ->get(route('vatssa.membership.index', ['queue' => $queue]))
                ->assertOk();
        }
    }

    #[Test]
    public function each_queue_shows_only_what_belongs_in_it(): void
    {
        $onDesk = $this->request();

        $waiting = $this->request(MembershipRequestType::VISITING);
        $waiting->update(['state' => MembershipRequestState::PENDING_TRAINING]);

        $done = $this->request(MembershipRequestType::RATING_UPGRADE);
        $done->update(['state' => MembershipRequestState::COMPLETE]);

        $staff = $this->withRole('membership-manager');

        // Asserted on each row's OWN url, not on the CID. Factory CIDs are
        // short sequential integers and turn up in asset hashes and element
        // ids, so an assertion on one passes or fails by luck -- and an
        // assertion that passes by luck stops being checked.
        $link = fn (MembershipRequest $r) => route('vatssa.membership.show', $r);

        $open = $this->actingAs($staff)->get(route('vatssa.membership.index', ['queue' => 'open']))->getContent();
        $this->assertStringContainsString($link($onDesk), $open);
        $this->assertStringNotContainsString($link($waiting), $open);
        $this->assertStringNotContainsString($link($done), $open);

        $training = $this->actingAs($staff)->get(route('vatssa.membership.index', ['queue' => 'training']))->getContent();
        $this->assertStringContainsString($link($waiting), $training);
        $this->assertStringNotContainsString($link($onDesk), $training);

        $closed = $this->actingAs($staff)->get(route('vatssa.membership.index', ['queue' => 'closed']))->getContent();
        $this->assertStringContainsString($link($done), $closed);
        $this->assertStringNotContainsString($link($onDesk), $closed);
    }

    #[Test]
    public function an_invented_queue_is_a_404(): void
    {
        $this->actingAs($this->withRole('membership-manager'))
            ->get(route('vatssa.membership.index', ['queue' => 'everything']))
            ->assertNotFound();
    }

    #[Test]
    public function a_member_cannot_open_the_desk(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('vatssa.membership.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------- reading versus deciding

    #[Test]
    public function the_atc_training_manager_may_read_a_request(): void
    {
        // They assign the visiting endorsement, so they must see the check.
        $this->actingAs($this->withRole('atc-training-manager'))
            ->get(route('vatssa.membership.show', $this->request()))
            ->assertOk();
    }

    #[Test]
    public function the_atc_training_manager_may_not_decide_or_record_the_check(): void
    {
        $atm = $this->withRole('atc-training-manager');
        $request = $this->request();

        $this->actingAs($atm)
            ->post(route('vatssa.membership.transition', $request), [
                'state' => MembershipRequestState::COMPLETE->value,
            ])
            ->assertForbidden();

        $this->actingAs($atm)
            ->post(route('vatssa.membership.check', $request), ['clean' => 1])
            ->assertForbidden();

        $request->refresh();
        $this->assertSame(MembershipRequestState::PENDING_DISCIPLINARY, $request->state);
        $this->assertFalse($request->disciplinaryChecked());
    }

    // -------------------------------------------------- the disciplinary check

    #[Test]
    public function the_membership_manager_records_a_clean_check(): void
    {
        $staff = $this->withRole('membership-manager');
        $request = $this->request();

        $this->actingAs($staff)
            ->post(route('vatssa.membership.check', $request), ['clean' => 1])
            ->assertRedirect();

        $request->refresh();
        $this->assertTrue($request->disciplinary_clean);
        $this->assertSame($staff->id, $request->disciplinary_checked_by);
    }

    #[Test]
    public function a_finding_without_context_is_a_validation_error_not_a_500(): void
    {
        // The model throws on this, correctly. The controller must catch it at
        // the form so the person gets told what to type, rather than a stack
        // trace they cannot act on.
        $request = $this->request();

        $this->actingAs($this->withRole('membership-manager'))
            ->post(route('vatssa.membership.check', $request), ['clean' => 0])
            ->assertSessionHasErrors('context');

        $this->assertFalse($request->fresh()->disciplinaryChecked());
    }

    #[Test]
    public function a_finding_with_context_is_recorded(): void
    {
        $request = $this->request();

        $this->actingAs($this->withRole('membership-manager'))
            ->post(route('vatssa.membership.check', $request), [
                'clean' => 0,
                'context' => 'Suspended for 30 days in March 2026.',
            ])
            ->assertRedirect();

        $request->refresh();
        $this->assertFalse($request->disciplinary_clean);
        $this->assertSame('Suspended for 30 days in March 2026.', $request->disciplinary_context);
    }

    // ------------------------------------------------------------ transitions

    #[Test]
    public function a_request_cannot_be_moved_into_a_state_its_type_does_not_have(): void
    {
        // A rating upgrade has no "pending transfer complete". Offering the
        // wrong set is how a state nobody can reach gets set by accident.
        $upgrade = $this->request(MembershipRequestType::RATING_UPGRADE);

        $this->actingAs($this->withRole('membership-manager'))
            ->post(route('vatssa.membership.transition', $upgrade), [
                'state' => MembershipRequestState::PENDING_TRANSFER_COMPLETE->value,
            ])
            ->assertSessionHasErrors('state');

        $this->assertSame(MembershipRequestState::OPEN, $upgrade->fresh()->state);
    }

    #[Test]
    public function finishing_a_request_stamps_when_it_closed(): void
    {
        $request = $this->request();

        $this->actingAs($this->withRole('membership-manager'))
            ->post(route('vatssa.membership.transition', $request), [
                'state' => MembershipRequestState::COMPLETE->value,
            ])
            ->assertRedirect();

        $this->assertNotNull($request->fresh()->closed_at);
    }

    // --------------------------------------------------------- manual entry

    #[Test]
    public function staff_can_raise_any_of_the_five_types_by_hand(): void
    {
        // A first-class path, not a fallback: the three the member never files
        // can ONLY be entered here.
        $about = User::factory()->create();
        $staff = $this->withRole('membership-manager');

        $this->actingAs($staff)
            ->post(route('vatssa.membership.admin.store'), [
                'user_id' => $about->id,
                'type' => MembershipRequestType::STAFF_INQUIRY->value,
                'note' => 'Checking eligibility ahead of an appointment.',
            ])
            ->assertRedirect();

        $created = MembershipRequest::where('user_id', $about->id)->sole();

        $this->assertSame(MembershipRequestType::STAFF_INQUIRY, $created->type);
        $this->assertSame($staff->id, $created->created_by);
        $this->assertSame(MembershipRequestState::OPEN, $created->state);
    }

    #[Test]
    public function a_member_cannot_raise_one_through_the_staff_route(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('vatssa.membership.admin.store'), [
                'user_id' => User::factory()->create()->id,
                'type' => MembershipRequestType::OTHER->value,
            ])
            ->assertForbidden();

        $this->assertSame(0, MembershipRequest::count());
    }

    // ------------------------------------------------------------- the sidebar

    #[Test]
    public function the_queues_live_under_members_as_one_dropdown(): void
    {
        // Not their own heading with three top-level entries beside it: three
        // entries for three views of ONE list is a menu that grows every time a
        // queue is added, and a second heading split one subject across two
        // places. Members is who they are, Requests is what they asked for,
        // Terminal management is what we did about it.
        $html = $this->actingAs($this->withRole('membership-manager'))
            ->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('Members', $html);
        $this->assertStringContainsString('Open requests', $html);
        $this->assertStringContainsString('Pending training', $html);
        $this->assertStringContainsString('Terminal management', $html);

        // The collapse the three sit inside, which is what makes it a dropdown
        // rather than three rows.
        $this->assertStringContainsString('collapseMembership', $html);

        // And no heading of its own any more.
        $this->assertStringNotContainsString('>
            Membership
', $html);
    }

    #[Test]
    public function a_member_sees_none_of_it(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('Pending training', $html);
        $this->assertStringNotContainsString('Terminal management', $html);
    }

    #[Test]
    public function the_queue_page_carries_no_navigation_of_its_own(): void
    {
        // The sidebar owns navigation. Two menus for one thing eventually
        // disagree about which is current, and the page's copy is the one that
        // cannot show you where else you might go.
        $html = $this->actingAs($this->withRole('membership-manager'))
            ->get(route('vatssa.membership.index', ['queue' => 'open']))->getContent();

        $this->assertStringNotContainsString('nav-tabs', $html);
    }
}
