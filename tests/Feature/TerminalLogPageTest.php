<?php

namespace Tests\Feature;

use App\Helpers\TrainingStatus;
use App\Helpers\Vatssa\MembershipRequestState;
use App\Helpers\Vatssa\MembershipRequestType;
use App\Helpers\Vatssa\TerminalLogReason;
use App\Helpers\Vatssa\TerminalLogType;
use App\Models\Rating;
use App\Models\Training;
use App\Models\User;
use App\Models\Vatssa\MembershipRequest;
use App\Models\Vatssa\TerminalLogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: the Terminal management page.
 *
 * The audit surface, so the tests are mostly about who may read it and what a
 * row is forced to say. The form has to be stricter than the model rather than
 * looser: the model throws on a row that names nobody, and a throw reaching a
 * person as a stack trace is a guard that has stopped protecting anybody.
 */
class TerminalLogPageTest extends TestCase
{
    use RefreshDatabase;

    private function withRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role' => $role, 'area_id' => null]);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => TerminalLogType::QUERY->value,
            'reason' => TerminalLogReason::TVCP_CHECK->value,
            'user_id' => User::factory()->create()->id,
            'actor_user_id' => User::factory()->create()->id,
        ], $overrides);
    }

    // -------------------------------------------------------------- reading

    #[Test]
    public function the_membership_manager_can_read_the_log(): void
    {
        $this->actingAs($this->withRole('membership-manager'))
            ->get(route('vatssa.terminal.index'))
            ->assertOk();
    }

    #[Test]
    public function nobody_else_can(): void
    {
        // Not even the ATC training manager. The log carries CERT queries and
        // disciplinary findings -- the same sensitivity class as a member note,
        // which is admin-only for exactly that reason.
        foreach ([null, 'atc-training-manager', 'pipeline-coordinator'] as $role) {
            $user = $role === null ? User::factory()->create() : $this->withRole($role);

            $this->actingAs($user)->get(route('vatssa.terminal.index'))->assertForbidden();
        }
    }

    #[Test]
    public function the_catalogue_is_on_the_page_with_its_composed_text(): void
    {
        $html = $this->actingAs($this->withRole('membership-manager'))
            ->get(route('vatssa.terminal.index'))->getContent();

        $this->assertStringContainsString('SSA-VT-001', $html);
        // Composed, not raw. The point of the page is copy-ready text.
        $this->assertStringContainsString('SSA | MM - ', $html);
    }

    // -------------------------------------------------------------- writing

    #[Test]
    public function an_entry_records_who_typed_it_separately_from_who_did_it(): void
    {
        $recorder = $this->withRole('membership-manager');
        $actor = User::factory()->create();

        $this->actingAs($recorder)
            ->post(route('vatssa.terminal.store'), $this->payload(['actor_user_id' => $actor->id]))
            ->assertRedirect();

        $entry = TerminalLogEntry::sole();

        $this->assertSame($actor->id, $entry->actor_user_id);
        $this->assertSame($recorder->id, $entry->recorded_by);
    }

    #[Test]
    public function the_recorder_cannot_be_set_from_the_form(): void
    {
        // "Who says this happened" is not a field somebody fills in.
        $recorder = $this->withRole('membership-manager');
        $somebodyElse = User::factory()->create();

        $this->actingAs($recorder)
            ->post(route('vatssa.terminal.store'), $this->payload(['recorded_by' => $somebodyElse->id]));

        $this->assertSame($recorder->id, TerminalLogEntry::sole()->recorded_by);
    }

    #[Test]
    public function a_row_naming_nobody_is_a_form_error_not_a_500(): void
    {
        // The model throws on this. The form has to catch it first, or the
        // guard reaches a person as a stack trace.
        $this->actingAs($this->withRole('membership-manager'))
            ->post(route('vatssa.terminal.store'), $this->payload([
                'actor_user_id' => null,
                'actor_name' => null,
            ]))
            ->assertSessionHasErrors('actor_name');

        $this->assertSame(0, TerminalLogEntry::count());
    }

    #[Test]
    public function a_typed_name_is_enough(): void
    {
        $this->actingAs($this->withRole('membership-manager'))
            ->post(route('vatssa.terminal.store'), $this->payload([
                'actor_user_id' => null,
                'actor_name' => 'A. Person',
            ]))
            ->assertRedirect();

        $this->assertSame('A. Person', TerminalLogEntry::sole()->actorLabel());
    }

    #[Test]
    public function a_finding_without_context_is_refused_at_the_form(): void
    {
        $this->actingAs($this->withRole('membership-manager'))
            ->post(route('vatssa.terminal.store'), $this->payload(['discipline_found' => 1]))
            ->assertSessionHasErrors('discipline_context');

        $this->assertSame(0, TerminalLogEntry::count());
    }

    #[Test]
    public function a_clean_check_needs_no_context_and_is_still_recorded(): void
    {
        $this->actingAs($this->withRole('membership-manager'))
            ->post(route('vatssa.terminal.store'), $this->payload(['discipline_found' => 0]))
            ->assertRedirect();

        $entry = TerminalLogEntry::sole();

        $this->assertTrue($entry->isDisciplinaryCheck());
        $this->assertFalse($entry->discipline_found);
    }

    #[Test]
    public function an_action_cannot_be_logged_as_happening_in_the_future(): void
    {
        $this->actingAs($this->withRole('membership-manager'))
            ->post(route('vatssa.terminal.store'), $this->payload([
                'performed_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ]))
            ->assertSessionHasErrors('performed_at');
    }

    #[Test]
    public function a_member_cannot_write_to_the_log(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('vatssa.terminal.store'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, TerminalLogEntry::count());
    }

    // ------------------------------------------------------- the regression

    #[Test]
    public function a_completed_membership_upgrade_satisfies_the_sign_off_warning(): void
    {
        // The regression this fixes: rating upgrades stopped being Tasks, so
        // the training page's "no upgrade requested" warning became permanently
        // true. A warning that is always on is one people click past.
        $member = User::factory()->create();
        $training = Training::factory()->create([
            'user_id' => $member->id,
            'status' => TrainingStatus::ACTIVE_TRAINING,
        ]);
        $rating = Rating::whereNotNull('vatsim_rating')->first();
        $training->ratings()->attach($rating);

        $request = MembershipRequest::open(MembershipRequestType::RATING_UPGRADE, $member, null, [
            'training_id' => $training->id,
            'rating_id' => $rating->id,
        ]);

        $this->assertFalse(
            MembershipRequest::upgradeCompletedFor($training->fresh(), $rating),
            'an OPEN upgrade has not been done yet'
        );

        $request->moveTo(MembershipRequestState::COMPLETE);

        $this->assertTrue(MembershipRequest::upgradeCompletedFor($training->fresh(), $rating));
    }
}
