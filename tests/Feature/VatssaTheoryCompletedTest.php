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

// VATSSA: staff-only "Mark theory as completed", and upstream's pre-training
// toggle switched off.
class VatssaTheoryCompletedTest extends TestCase
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
            'status' => TrainingStatus::PRE_TRAINING->value,
        ]);
    }

    private function userWithRole(string $role, ?int $areaId): User
    {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role' => $role, 'area_id' => $areaId]);

        return $user;
    }

    #[Test]
    public function a_pipeline_coordinator_can_mark_theory_completed_and_back(): void
    {
        $coordinator = $this->userWithRole('pipeline-coordinator', $this->area->id);

        $this->actingAs($coordinator)
            ->post(route('training.action.theory', $this->training))
            ->assertRedirect($this->training->path());

        $this->assertTrue((bool) $this->training->fresh()->theory_completed);
        $this->assertDatabaseHas('training_activity', [
            'training_id' => $this->training->id,
            'type' => 'THEORY',
            'new_data' => 1,
            'triggered_by_id' => $coordinator->id,
        ]);

        $this->actingAs($coordinator)->post(route('training.action.theory', $this->training));
        $this->assertFalse((bool) $this->training->fresh()->theory_completed);
    }

    #[Test]
    public function a_global_training_manager_can_mark_theory_completed(): void
    {
        $atm = $this->userWithRole('atc-training-manager', null);

        $this->actingAs($atm)->post(route('training.action.theory', $this->training))->assertRedirect();

        $this->assertTrue((bool) $this->training->fresh()->theory_completed);
    }

    #[Test]
    public function the_student_and_a_mentor_cannot(): void
    {
        $mentor = $this->userWithRole('mentor', $this->area->id);

        $this->actingAs($this->training->user)->post(route('training.action.theory', $this->training))->assertForbidden();
        $this->actingAs($mentor)->post(route('training.action.theory', $this->training))->assertForbidden();

        $this->assertFalse((bool) $this->training->fresh()->theory_completed);
    }

    #[Test]
    public function a_coordinator_for_another_area_cannot(): void
    {
        $other = $this->userWithRole('pipeline-coordinator', Area::factory()->create()->id);

        $this->actingAs($other)->post(route('training.action.theory', $this->training))->assertForbidden();
    }

    #[Test]
    public function a_closed_training_cannot_be_marked(): void
    {
        $this->training->update(['status' => TrainingStatus::COMPLETED->value]);
        $coordinator = $this->userWithRole('pipeline-coordinator', $this->area->id);

        $this->actingAs($coordinator)->post(route('training.action.theory', $this->training))->assertForbidden();
    }

    #[Test]
    public function the_pre_training_toggle_is_off_for_everyone(): void
    {
        $admin = $this->userWithRole('admin', null);

        $this->actingAs($this->training->user)->get(route('training.action.pretraining', $this->training))->assertForbidden();
        $this->actingAs($admin)->get(route('training.action.pretraining', $this->training))->assertForbidden();
        $this->assertFalse((bool) $this->training->fresh()->pre_training_completed);
    }

    #[Test]
    public function the_training_page_shows_the_theory_button_to_staff_only(): void
    {
        $coordinator = $this->userWithRole('pipeline-coordinator', $this->area->id);

        $this->actingAs($coordinator)->get($this->training->path())
            ->assertOk()
            ->assertSee('Mark theory as completed')
            ->assertDontSee('Mark pre-training as completed');

        $this->actingAs($this->training->user)->get($this->training->path())
            ->assertOk()
            ->assertDontSee('Mark theory as completed')
            ->assertDontSee('Mark pre-training as completed');
    }

    #[Test]
    public function the_open_requests_list_shows_the_theory_badge(): void
    {
        $this->training->update(['theory_completed' => true]);
        $coordinator = $this->userWithRole('pipeline-coordinator', $this->area->id);

        $this->actingAs($coordinator)->get(route('requests'))
            ->assertOk()
            ->assertSee('Theory completed');
    }
}
