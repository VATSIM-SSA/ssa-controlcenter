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
use Illuminate\Support\Carbon;
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
            public function clearOnStatusChange(Training $training, Carbon $changedAt): void
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
        $this->assertSame(VatssaTrainingFlags::CLOSED, app(VatssaTrainingFlags::class)->set($this->training, 'attention', true, $coordinator->id));
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
     * without it the badges would show icons only. Column rules must stay
     * inside the Open requests table block, however they are indented: My
     * students, Closed requests and Member overview reuse the same data-field
     * names.
     */
    #[Test]
    public function the_badge_label_rule_is_in_the_theme(): void
    {
        $scss = file_get_contents(resource_path('sass/themes/_custom.scss'));

        $this->assertMatchesRegularExpression('/\.vatssa-flag::after\s*\{[^}]*content:\s*attr\(data-label\)/', $scss);
        $this->assertStringContainsString('@import "themes/custom"', file_get_contents(resource_path('sass/app.scss')));

        $open = 'table[data-cookie-id-table="trainings"] {';
        $start = strpos($scss, $open);
        $this->assertNotFalse($start, 'The Open requests table block is missing.');

        $depth = 0;
        for ($i = $start + strlen($open) - 1; $i < strlen($scss); $i++) {
            $depth += match ($scss[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };
            if ($depth === 0) {
                break;
            }
        }
        $outside = substr($scss, 0, $start) . substr($scss, $i + 1);

        $this->assertStringContainsString('data-field="state"', substr($scss, $start, $i - $start));
        $this->assertStringNotContainsString('[data-field=', $outside);
        $this->assertStringNotContainsString('data-cookie-id-table="trainings"', $outside);
    }

    /**
     * A form rendered before someone else changed the training is refused
     * rather than undoing their change; the flag keeps its newer state. Here
     * the first request is retried from its original page.
     */
    #[Test]
    public function a_change_from_an_older_page_is_refused(): void
    {
        $flags = app(VatssaTrainingFlags::class);
        $first = $this->coordinator();
        $second = $this->coordinator();

        $pageVersion = $flags->version($this->training);
        $this->actingAs($first)->post(route('training.action.attention', $this->training), ['attention' => 1, 'version' => $pageVersion]);
        $afterSet = $flags->version($this->training);
        $this->actingAs($second)->post(route('training.action.attention', $this->training), ['attention' => 0, 'version' => $afterSet]);
        $this->assertNull($this->training->fresh()->attention_at);

        $this->actingAs($first)->post(route('training.action.attention', $this->training), ['attention' => 1, 'version' => $pageVersion])
            ->assertSessionHasErrors();
        $this->assertNull($this->training->fresh()->attention_at);
        $this->assertSame(2, $this->training->activities()->where('type', 'COMMENT')->count());
    }

    /**
     * A double click sends the same page version twice: the second is simply
     * "already done", not an error.
     */
    #[Test]
    public function a_double_click_is_harmless(): void
    {
        $version = app(VatssaTrainingFlags::class)->version($this->training);
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->post(route('training.action.stalled', $this->training), ['stalled' => 1, 'version' => $version]);
        $this->actingAs($coordinator)->post(route('training.action.stalled', $this->training), ['stalled' => 1, 'version' => $version])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->training->activities()->where('type', 'COMMENT')->count());
    }

    #[Test]
    public function the_training_page_sends_its_version(): void
    {
        $version = app(VatssaTrainingFlags::class)->version($this->training);

        $this->actingAs($this->coordinator())->get($this->training->path())
            ->assertOk()
            ->assertSee('name="version" value="' . $version . '"', false);
    }

    /**
     * If the close's own clearing failed, the flags left behind are cleared
     * when the training reopens, with a timeline entry each.
     */
    #[Test]
    public function reopening_clears_flags_left_from_before(): void
    {
        $this->training->update(['attention_at' => now()->subDay(), 'stalled_at' => now()->subDay()]);
        $real = app(VatssaTrainingFlags::class);
        $this->app->instance(VatssaTrainingFlags::class, new class extends VatssaTrainingFlags
        {
            public function clearOnStatusChange(Training $training, Carbon $changedAt): void
            {
                throw new \RuntimeException('database unavailable');
            }
        });
        $this->training->update(['status' => TrainingStatus::CLOSED_BY_STAFF->value]);
        $this->assertNotNull($this->training->fresh()->stalled_at);

        $this->app->instance(VatssaTrainingFlags::class, $real);
        $this->training->update(['status' => TrainingStatus::ACTIVE_TRAINING->value]);

        $training = $this->training->fresh();
        $this->assertNull($training->attention_at);
        $this->assertNull($training->stalled_at);
        $this->assertContains('Stalled cleared automatically: it was set before the training was reopened.',
            $this->training->activities()->pluck('comment')->all());
    }

    /**
     * Reopening clears only flags older than the reopen: one set afterwards
     * stays.
     */
    #[Test]
    public function a_flag_set_after_the_reopen_is_kept(): void
    {
        $reopenedAt = now()->subMinute();
        $this->training->update(['stalled_at' => now()->subDays(2), 'attention_at' => now()]);

        app(VatssaTrainingFlags::class)->clearOnStatusChange($this->training, $reopenedAt);

        $training = $this->training->fresh();
        $this->assertNull($training->stalled_at);
        $this->assertNotNull($training->attention_at);
    }

    /**
     * If the status moved again before the clearing ran (closed, then reopened
     * and flagged by someone else), the clearing for the close does nothing.
     */
    #[Test]
    public function a_clear_for_an_outdated_status_does_nothing(): void
    {
        $this->training->update(['stalled_at' => now()]);
        $closedCopy = Training::find($this->training->id);
        $closedCopy->status = TrainingStatus::CLOSED_BY_STAFF;

        app(VatssaTrainingFlags::class)->clearOnStatusChange($closedCopy, now()->subMinute());

        $this->assertNotNull($this->training->fresh()->stalled_at);
        $this->assertSame(0, $this->training->activities()->where('type', 'COMMENT')->count());
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
