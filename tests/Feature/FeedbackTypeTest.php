<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\User;
use App\Models\Vatssa\FeedbackType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: feedback says what kind it is.
 *
 * Upstream's feedback is one undifferentiated stream, so a compliment, a
 * complaint and a bug report arrive in the same queue and read the same. Three
 * different jobs with three different urgencies, and a team that cannot sort
 * them reads the whole queue to find the one that mattered.
 *
 * The list is a table, not a constant, for the same reason training types and
 * request desks are: it describes how the division organises itself, which
 * changes more often than the code does.
 */
class FeedbackTypeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roleAssignments()->create(['role' => 'admin', 'area_id' => null]);

        return $admin;
    }

    #[Test]
    public function the_three_types_are_seeded_by_the_migration(): void
    {
        $this->assertSame(
            array_keys(FeedbackType::FALLBACK),
            FeedbackType::orderBy('sort_order')->pluck('key')->all()
        );
    }

    #[Test]
    public function a_submission_records_its_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('feedback.store'), [
            'feedback' => 'Nice work on the S2 sessions.',
            'vatssa_type' => 'compliment',
        ]);

        $this->assertSame('compliment', Feedback::latest('id')->first()?->vatssa_type);
    }

    #[Test]
    public function a_submission_with_no_type_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('feedback.store'), ['feedback' => 'Something happened.'])
            ->assertSessionHasErrors('vatssa_type');
    }

    #[Test]
    public function an_invented_type_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('feedback.store'), [
                'feedback' => 'Something happened.',
                'vatssa_type' => 'urgent',
            ])
            ->assertSessionHasErrors('vatssa_type');
    }

    #[Test]
    public function a_retired_type_is_no_longer_offered_but_still_labels_old_feedback(): void
    {
        $type = FeedbackType::find('bug-report');
        $type->update(['active' => false]);

        $this->assertArrayNotHasKey('bug-report', FeedbackType::map(true));
        // Retired, not deleted. Feedback that already used it must still read
        // as a bug report rather than as a blank cell.
        $this->assertSame('Bug report', FeedbackType::label('bug-report'));

        $this->actingAs(User::factory()->create())
            ->post(route('feedback.store'), [
                'feedback' => 'Something happened.',
                'vatssa_type' => 'bug-report',
            ])
            ->assertSessionHasErrors('vatssa_type');
    }

    #[Test]
    public function an_admin_can_add_a_type_and_it_is_offered(): void
    {
        $this->actingAs($this->admin())
            ->post(route('vatssa.admin.setup.feedback-types.store'), [
                'key' => 'suggestion',
                'label' => 'Suggestion',
                'hint' => 'An idea rather than a problem.',
            ])
            ->assertRedirect();

        $this->assertArrayHasKey('suggestion', FeedbackType::map(true));
    }

    #[Test]
    public function a_member_cannot_add_a_type(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('vatssa.admin.setup.feedback-types.store'), [
                'key' => 'whatever',
                'label' => 'Whatever',
            ])
            ->assertForbidden();

        $this->assertArrayNotHasKey('whatever', FeedbackType::map());
    }
}
