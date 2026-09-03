<?php

namespace Tests\Feature;

use App\Helpers\Vatssa\MembershipRequestType;
use App\Models\Endorsement;
use App\Models\User;
use App\Models\Vatssa\MembershipRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: what the dashboard offers, and to whom.
 *
 * The rule is membership OR visiting, not membership alone:
 *
 *   not a member, not visiting  ->  the membership box, no training block
 *   visiting                    ->  the training block returns
 *   member                      ->  the training block, as now
 *
 * A visiting controller gets training back because they can request endorsement
 * training -- FSS and the like -- so training genuinely is available to them.
 * A non-member could only ever be told "Not eligible" by that block, which
 * reads as the application being broken and hides the two things that ARE open.
 */
class MembershipDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function outsider(): User
    {
        // Not in the division, and in our own region -- so the box offers a
        // TRANSFER. Region decides which of the two, per TVCP 5.1-5.3.
        return User::factory()->create([
            'division' => 'XXX',
            'subdivision' => null,
            'region' => config('vatssa.region'),
        ]);
    }

    private function member(): User
    {
        return User::factory()->create([
            'division' => config('app.owner_code'),
            'subdivision' => config('app.owner_code'),
        ]);
    }

    private function visitor(): User
    {
        $visitor = $this->outsider();

        // Assigned property by property: Endorsement guards user_id, so a
        // mass-assigned fixture silently creates a row belonging to nobody.
        $endorsement = new Endorsement;
        $endorsement->user_id = $visitor->id;
        $endorsement->type = 'VISITING';
        $endorsement->expired = false;
        $endorsement->revoked = false;
        $endorsement->valid_from = now();
        $endorsement->save();

        return $visitor->fresh();
    }

    #[Test]
    public function an_outsider_is_offered_a_membership_request_and_not_training(): void
    {
        $html = $this->actingAs($this->outsider())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Transfer request', $html);
        $this->assertStringNotContainsString('Request Training', $html);
    }

    #[Test]
    public function somebody_outside_the_region_is_offered_a_visit_instead(): void
    {
        $far = User::factory()->create([
            'division' => 'XXX',
            'subdivision' => null,
            'region' => 'AMAS',
        ]);

        $html = $this->actingAs($far)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('Visiting request', $html);
        $this->assertStringNotContainsString('Transfer request', $html);
    }

    #[Test]
    public function a_member_gets_the_training_block(): void
    {
        $html = $this->actingAs($this->member())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Request Training', $html);
    }

    #[Test]
    public function a_visiting_controller_gets_the_training_block_back(): void
    {
        // The reason this case exists: a visitor can request endorsement
        // training, so training is genuinely available to them even though they
        // are not a member.
        $html = $this->actingAs($this->visitor())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Request Training', $html);
    }

    #[Test]
    public function a_member_sees_their_own_requests_but_never_terminal_work(): void
    {
        $member = $this->member();

        MembershipRequest::open(MembershipRequestType::TRANSFER, $member, $member);
        MembershipRequest::open(MembershipRequestType::RATING_UPGRADE, $member);
        MembershipRequest::open(MembershipRequestType::STAFF_INQUIRY, $member);

        $html = $this->actingAs($member)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('Your membership requests', $html);
        $this->assertStringContainsString('Transfer request', $html);
        $this->assertStringNotContainsString('Rating upgrade', $html);
        $this->assertStringNotContainsString('Staff inquiry', $html);
    }

    #[Test]
    public function the_requests_card_is_absent_when_there_is_nothing_in_it(): void
    {
        $html = $this->actingAs($this->member())->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('Your membership requests', $html);
    }

    // -------------------------------------------------------------- the form

    #[Test]
    public function an_outsider_can_file_a_transfer_request(): void
    {
        $outsider = $this->outsider();

        $this->actingAs($outsider)
            ->get(route('vatssa.membership.create', ['type' => 'transfer']))
            ->assertOk();

        $this->actingAs($outsider)
            ->post(route('vatssa.membership.store'), [
                'type' => 'transfer',
                'motivation' => 'I fly here most evenings and would rather control here too.',
            ])
            ->assertRedirect(route('dashboard'));

        $request = MembershipRequest::where('user_id', $outsider->id)->sole();

        $this->assertSame(MembershipRequestType::TRANSFER, $request->type);
        $this->assertSame($outsider->id, $request->created_by);
        $this->assertNotNull($request->checks, 'the TVCP snapshot is taken when they ask');
    }

    #[Test]
    public function a_member_cannot_file_one(): void
    {
        $this->actingAs($this->member())
            ->post(route('vatssa.membership.store'), [
                'type' => 'transfer',
                'motivation' => 'Already here.',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors();

        $this->assertSame(0, MembershipRequest::count());
    }

    #[Test]
    public function a_second_request_of_the_same_type_is_refused(): void
    {
        $outsider = $this->outsider();
        MembershipRequest::open(MembershipRequestType::TRANSFER, $outsider, $outsider);

        $this->actingAs($outsider)
            ->post(route('vatssa.membership.store'), [
                'type' => 'transfer',
                'motivation' => 'Asking again.',
            ])
            ->assertSessionHasErrors();

        $this->assertSame(1, MembershipRequest::count());
    }

    #[Test]
    public function the_three_terminal_types_cannot_be_filed_through_the_member_form(): void
    {
        $outsider = $this->outsider();

        foreach (['rating-upgrade', 'staff-inquiry', 'other'] as $type) {
            $this->actingAs($outsider)
                ->get(route('vatssa.membership.create', ['type' => $type]))
                ->assertNotFound();

            $this->actingAs($outsider)
                ->post(route('vatssa.membership.store'), ['type' => $type, 'motivation' => 'x'])
                ->assertSessionHasErrors('type');
        }

        $this->assertSame(0, MembershipRequest::count());
    }
}
