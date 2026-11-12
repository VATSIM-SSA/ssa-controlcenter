<?php

namespace Tests\Feature;

use App\Exceptions\InvalidTrainingActivityType;
use App\Helpers\TrainingStatus;
use App\Http\Controllers\TrainingActivityController;
use App\Models\Area;
use App\Models\Training;
use App\Models\User;
use App\Services\VatssaTrainingFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: staff-only "Requires attention" flag, beside Stalled and the theory
 * mark, and the guarantees both flags share (history, locking, closing).
 */
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

    /**
     * The attention route reads 'attention', not another flag's field.
     */
    #[Test]
    public function the_wrong_field_is_refused(): void
    {
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
    public function closing_or_completing_keeps_the_theory_mark(): void
    {
        foreach ([TrainingStatus::COMPLETED, TrainingStatus::CLOSED_BY_STAFF] as $status) {
            $this->training->update(['status' => TrainingStatus::ACTIVE_TRAINING->value, 'theory_completed' => true, 'attention_at' => now(), 'stalled_at' => now()]);

            $this->training->update(['status' => $status->value]);

            $training = $this->training->fresh();
            $this->assertTrue((bool) $training->theory_completed, $status->name);
            $this->assertNull($training->attention_at, $status->name);
            $this->assertNull($training->stalled_at, $status->name);
        }
    }

    #[Test]
    public function closing_records_each_cleared_flag_on_the_timeline(): void
    {
        $this->training->update(['attention_at' => now(), 'stalled_at' => now()]);

        $this->training->update(['status' => TrainingStatus::CLOSED_BY_STAFF->value]);

        $comments = $this->training->activities()->where('type', 'COMMENT')->pluck('comment')->all();
        $this->assertContains('Requires attention cleared automatically: the training was closed.', $comments);
        $this->assertContains('Stalled cleared automatically: the training was closed.', $comments);
    }

    #[Test]
    public function closing_a_training_without_flags_adds_nothing(): void
    {
        $this->training->update(['status' => TrainingStatus::COMPLETED->value]);

        $this->assertSame(0, $this->training->activities()->where('type', 'COMMENT')->count());
    }

    /**
     * If clearing the flags fails, the close still completes: upstream's own
     * side effects (e-mail, endorsements, mentor detach) must never be cut off.
     */
    #[Test]
    public function a_failing_clear_never_breaks_the_close(): void
    {
        $this->training->update(['stalled_at' => now()]);
        $this->app->instance(VatssaTrainingFlags::class, new class extends VatssaTrainingFlags
        {
            public function clearOnClose(Training $training): void
            {
                throw new \RuntimeException('database unavailable');
            }
        });

        $this->training->update(['status' => TrainingStatus::CLOSED_BY_STAFF->value]);

        $this->assertSame(TrainingStatus::CLOSED_BY_STAFF, $this->training->fresh()->status);
    }

    /**
     * A training loaded before a flag was set, then closed, must still lose
     * the flag: the clear reads the database, not the stale model.
     */
    #[Test]
    public function closing_a_stale_copy_still_clears_a_flag_set_meanwhile(): void
    {
        $stale = Training::find($this->training->id);

        $this->actingAs($this->coordinator())->post(route('training.action.stalled', $this->training), ['stalled' => 1]);
        $this->assertNotNull($this->training->fresh()->stalled_at);

        $stale->update(['status' => TrainingStatus::CLOSED_BY_STAFF->value]);

        $this->assertNull($this->training->fresh()->stalled_at);
    }

    /**
     * The flag, its activity-log entry and its timeline comment are one
     * transaction: if the history cannot be written, the flag is not set.
     */
    #[Test]
    public function a_flag_is_never_set_without_its_history(): void
    {
        $types = TrainingActivityController::$activityTypes;
        unset(TrainingActivityController::$activityTypes['COMMENT']);

        try {
            $this->withoutExceptionHandling();
            $this->expectException(InvalidTrainingActivityType::class);
            $this->actingAs($this->coordinator())->post(route('training.action.attention', $this->training), ['attention' => 1]);
        } finally {
            TrainingActivityController::$activityTypes = $types;
            $this->assertNull($this->training->fresh()->attention_at);
        }
    }

    #[Test]
    public function a_training_closed_after_the_page_loaded_is_not_flagged(): void
    {
        $coordinator = $this->coordinator();
        $this->training->update(['status' => TrainingStatus::CLOSED_BY_STAFF->value]);

        $this->actingAs($coordinator)->post(route('training.action.attention', $this->training), ['attention' => 1])->assertForbidden();
        $this->assertNull(app(VatssaTrainingFlags::class)->set($this->training, 'attention', true, $coordinator->id));
        $this->assertNull($this->training->fresh()->attention_at);
    }

    /**
     * A staff member who is also a student sees Open requests, including their
     * own training. Staff comments on it (flag history, mentor notes) must not
     * reach them through the State column's hover note.
     */
    #[Test]
    public function the_open_requests_note_hides_staff_comments_from_the_student(): void
    {
        $studentWhoIsStaff = $this->training->user;
        $studentWhoIsStaff->roleAssignments()->create(['role' => 'pipeline-coordinator', 'area_id' => Area::factory()->create()->id]);

        app(VatssaTrainingFlags::class)->set($this->training, 'stalled', true, $this->coordinator()->id);

        $this->actingAs($studentWhoIsStaff->fresh())->get(route('requests'))
            ->assertOk()
            ->assertSee('/training/' . $this->training->id)
            ->assertDontSee('Training marked as stalled');

        $this->actingAs($this->coordinator())->get(route('requests'))
            ->assertOk()
            ->assertSee('Training marked as stalled');
    }

    /**
     * The badge words are drawn by CSS from data-label (so the State filter
     * ignores them). Feature tests cannot render CSS, so guard the rule itself:
     * without it the badges would show icons only.
     */
    #[Test]
    public function the_badge_label_rule_is_in_the_theme(): void
    {
        $scss = file_get_contents(resource_path('sass/themes/_custom.scss'));

        $this->assertMatchesRegularExpression('/\.vatssa-flag::after\s*\{[^}]*content:\s*attr\(data-label\)/', $scss);
        $this->assertStringContainsString('@import "themes/custom"', file_get_contents(resource_path('sass/app.scss')));
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
