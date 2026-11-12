<?php

namespace Tests\Feature;

use App\Helpers\TrainingStatus;
use App\Models\Area;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: staff-only "Stalled" flag, recorded as a staff comment.
 */
class VatssaTrainingStalledTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Training $training;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->area = Area::factory()->create();
        $this->training = Training::factory()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $this->area->id,
            'status' => TrainingStatus::IN_QUEUE->value,
        ]);
    }

    private function userWithRole(string $role, ?int $areaId): User
    {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role' => $role, 'area_id' => $areaId]);

        return $user;
    }

    #[Test]
    public function a_pipeline_coordinator_can_flag_and_clear_it(): void
    {
        $coordinator = $this->userWithRole('pipeline-coordinator', $this->area->id);

        $this->actingAs($coordinator)
            ->post(route('training.action.stalled', $this->training), ['stalled' => 1])
            ->assertRedirect($this->training->path());

        $this->assertNotNull($this->training->fresh()->stalled_at);
        $this->assertDatabaseHas('training_activity', [
            'training_id' => $this->training->id,
            'type' => 'COMMENT',
            'comment' => 'Training marked as stalled.',
            'triggered_by_id' => $coordinator->id,
        ]);

        $this->actingAs($coordinator)->post(route('training.action.stalled', $this->training), ['stalled' => 0]);

        $this->assertNull($this->training->fresh()->stalled_at);
        $this->assertDatabaseHas('training_activity', [
            'training_id' => $this->training->id,
            'comment' => 'Training no longer marked as stalled.',
        ]);
    }

    /**
     * The date stays when it was first flagged, and only one comment exists.
     */
    #[Test]
    public function setting_the_state_it_already_has_changes_nothing(): void
    {
        $coordinator = $this->userWithRole('pipeline-coordinator', $this->area->id);

        $this->actingAs($coordinator)->post(route('training.action.stalled', $this->training), ['stalled' => 1]);
        $since = $this->training->fresh()->stalled_at;
        $this->travel(1)->days();
        $this->actingAs($coordinator)->post(route('training.action.stalled', $this->training), ['stalled' => 1]);

        $this->assertEquals($since, $this->training->fresh()->stalled_at);
        $this->assertSame(1, $this->training->activities()->where('type', 'COMMENT')->count());
    }

    #[Test]
    public function the_state_is_required(): void
    {
        $coordinator = $this->userWithRole('pipeline-coordinator', $this->area->id);

        $this->actingAs($coordinator)->post(route('training.action.stalled', $this->training))->assertSessionHasErrors('stalled');
        $this->assertNull($this->training->fresh()->stalled_at);
    }

    #[Test]
    public function a_global_training_manager_can_flag_it(): void
    {
        $atm = $this->userWithRole('atc-training-manager', null);

        $this->actingAs($atm)->post(route('training.action.stalled', $this->training), ['stalled' => 1])->assertRedirect();

        $this->assertNotNull($this->training->fresh()->stalled_at);
    }

    #[Test]
    public function the_student_a_mentor_and_another_area_cannot(): void
    {
        $mentor = $this->userWithRole('mentor', $this->area->id);
        $other = $this->userWithRole('pipeline-coordinator', Area::factory()->create()->id);

        foreach ([$this->training->user, $mentor, $other] as $user) {
            $this->actingAs($user)->post(route('training.action.stalled', $this->training), ['stalled' => 1])->assertForbidden();
        }

        $this->assertNull($this->training->fresh()->stalled_at);
    }

    #[Test]
    public function a_closed_training_cannot_be_flagged(): void
    {
        $this->training->update(['status' => TrainingStatus::COMPLETED->value]);
        $coordinator = $this->userWithRole('pipeline-coordinator', $this->area->id);

        $this->actingAs($coordinator)->post(route('training.action.stalled', $this->training), ['stalled' => 1])->assertForbidden();
    }

    #[Test]
    public function staff_see_the_button_and_badge_and_the_student_sees_neither(): void
    {
        $coordinator = $this->userWithRole('pipeline-coordinator', $this->area->id);

        $this->actingAs($coordinator)->get($this->training->path())
            ->assertOk()
            ->assertSee('Mark training as stalled');

        $this->training->update(['stalled_at' => now()]);

        $this->actingAs($coordinator)->get($this->training->path())
            ->assertOk()
            ->assertSee('Clear stalled')
            ->assertSee('Stalled since');

        $this->actingAs($this->training->user)->get($this->training->path())
            ->assertOk()
            ->assertDontSee('Mark training as stalled')
            ->assertDontSee('Clear stalled')
            ->assertDontSee('Stalled since')
            ->assertDontSee('Training marked as stalled');
    }

    #[Test]
    public function the_open_requests_list_shows_the_stalled_badge(): void
    {
        $this->training->update(['stalled_at' => now()]);
        $coordinator = $this->userWithRole('pipeline-coordinator', $this->area->id);

        $this->actingAs($coordinator)->get(route('requests'))
            ->assertOk()
            ->assertSee('Stalled since', false);
    }

    #[Test]
    public function closing_a_training_clears_the_flag(): void
    {
        $this->training->update(['stalled_at' => now()]);

        $this->training->update(['status' => TrainingStatus::CLOSED_BY_STAFF->value]);

        $this->assertNull($this->training->fresh()->stalled_at);
    }

    #[Test]
    public function a_status_change_that_stays_open_keeps_the_flag(): void
    {
        $this->training->update(['stalled_at' => now()]);

        $this->training->update(['status' => TrainingStatus::PRE_TRAINING->value]);

        $this->assertNotNull($this->training->fresh()->stalled_at);
    }

    #[Test]
    public function the_theory_mark_still_works_beside_it(): void
    {
        $coordinator = $this->userWithRole('pipeline-coordinator', $this->area->id);

        $this->actingAs($coordinator)->post(route('training.action.theory', $this->training), ['completed' => 1]);
        $this->actingAs($coordinator)->post(route('training.action.stalled', $this->training), ['stalled' => 1]);

        $training = $this->training->fresh();
        $this->assertTrue((bool) $training->theory_completed);
        $this->assertNotNull($training->stalled_at);
    }
}
