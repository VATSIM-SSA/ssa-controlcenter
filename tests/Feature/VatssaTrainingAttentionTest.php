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

// VATSSA: staff-only "Requires attention" flag, beside Stalled and the theory mark.
class VatssaTrainingAttentionTest extends TestCase
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
            'status' => TrainingStatus::ACTIVE_TRAINING->value,
        ]);
    }

    private function coordinator(): User
    {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role' => 'pipeline-coordinator', 'area_id' => $this->area->id]);

        return $user;
    }

    #[Test]
    public function staff_can_flag_and_clear_it(): void
    {
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->post(route('training.action.attention', $this->training), ['attention' => 1])->assertRedirect($this->training->path());
        $this->assertNotNull($this->training->fresh()->attention_at);
        $this->assertDatabaseHas('training_activity', ['training_id' => $this->training->id, 'type' => 'COMMENT', 'comment' => 'Training marked as requiring attention.']);

        $this->actingAs($coordinator)->post(route('training.action.attention', $this->training), ['attention' => 0]);
        $this->assertNull($this->training->fresh()->attention_at);
    }

    #[Test]
    public function the_three_flags_are_independent(): void
    {
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->post(route('training.action.theory', $this->training), ['completed' => 1]);
        $this->actingAs($coordinator)->post(route('training.action.stalled', $this->training), ['stalled' => 1]);
        $this->actingAs($coordinator)->post(route('training.action.attention', $this->training), ['attention' => 1]);
        $this->actingAs($coordinator)->post(route('training.action.stalled', $this->training), ['stalled' => 0]);

        $training = $this->training->fresh();
        $this->assertTrue((bool) $training->theory_completed);
        $this->assertNull($training->stalled_at);
        $this->assertNotNull($training->attention_at);
    }

    #[Test]
    public function the_student_and_a_mentor_cannot(): void
    {
        $mentor = User::factory()->create();
        $mentor->roleAssignments()->create(['role' => 'mentor', 'area_id' => $this->area->id]);

        foreach ([$this->training->user, $mentor] as $user) {
            $this->actingAs($user)->post(route('training.action.attention', $this->training), ['attention' => 1])->assertForbidden();
        }
        $this->assertNull($this->training->fresh()->attention_at);
    }

    #[Test]
    public function the_wrong_field_is_refused(): void
    {
        // The attention route reads 'attention', not another flag's field.
        $this->actingAs($this->coordinator())->post(route('training.action.attention', $this->training), ['stalled' => 1])->assertSessionHasErrors('attention');
        $this->assertNull($this->training->fresh()->attention_at);
    }

    #[Test]
    public function closing_clears_both_flags(): void
    {
        $this->training->update(['attention_at' => now(), 'stalled_at' => now()]);

        $this->training->update(['status' => TrainingStatus::CLOSED_BY_STUDENT->value]);

        $training = $this->training->fresh();
        $this->assertNull($training->attention_at);
        $this->assertNull($training->stalled_at);
    }

    #[Test]
    public function staff_see_the_amber_badge_and_the_student_does_not(): void
    {
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get($this->training->path())->assertOk()->assertSee('Mark as requiring attention');

        $this->training->update(['attention_at' => now()]);

        $this->actingAs($coordinator)->get($this->training->path())
            ->assertOk()
            ->assertSee('Clear attention')
            ->assertSee('Requires attention since');
        $this->actingAs($coordinator)->get(route('requests'))->assertOk()->assertSee('Requires attention since', false);

        $this->actingAs($this->training->user)->get($this->training->path())
            ->assertOk()
            ->assertDontSee('Requires attention')
            ->assertDontSee('Mark as requiring attention');
    }
}
