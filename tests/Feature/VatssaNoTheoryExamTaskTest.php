<?php

namespace Tests\Feature;

use App\Helpers\VatsimRating;
use App\Models\Area;
use App\Models\Rating;
use App\Models\Task;
use App\Models\Training;
use App\Models\User;
use App\Tasks\Types\TheoreticalExam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// VATSSA: "Theoretical Exam Access" requests are not offered and cannot be
// created. The theory exam runs in Moodle.
class VatssaNoTheoryExamTaskTest extends TestCase
{
    use RefreshDatabase;

    private Training $training;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $area = Area::factory()->create();
        $this->training = Training::factory()
            ->has(Rating::factory(['vatsim_rating' => VatsimRating::S2, 'name' => 'TST-S2']))
            ->create(['user_id' => User::factory()->create()->id, 'area_id' => $area->id]);

        $this->coordinator = User::factory()->create();
        $this->coordinator->roleAssignments()->create(['role' => 'pipeline-coordinator', 'area_id' => $area->id]);
    }

    #[Test]
    public function the_request_menu_no_longer_offers_theoretical_exam_access(): void
    {
        // Rating Upgrade proves the menu still renders the VATSIM-rating types.
        $this->actingAs($this->coordinator)->get($this->training->path())
            ->assertOk()
            ->assertSeeText('Rating Upgrade')
            ->assertDontSeeText('Theoretical Exam Access');
    }

    #[Test]
    public function the_request_cannot_be_created_through_the_form(): void
    {
        $this->actingAs($this->coordinator)
            ->from($this->training->path())
            ->post(route('task.store'), [
                'type' => TheoreticalExam::class,
                'subject_user_id' => $this->training->user_id,
                'subject_training_id' => $this->training->id,
                'subject_training_rating_id' => $this->training->ratings->first()->id,
                'assignee_user_id' => $this->coordinator->id,
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, Task::where('type', TheoreticalExam::class)->count());
    }

    #[Test]
    public function the_model_refuses_it_from_anywhere_else(): void
    {
        $this->expectException(ValidationException::class);

        Task::create([
            'type' => TheoreticalExam::class,
            'subject_user_id' => $this->training->user_id,
            'subject_training_id' => $this->training->id,
            'assignee_user_id' => $this->coordinator->id,
            'creator_user_id' => $this->coordinator->id,
        ]);
    }
}
