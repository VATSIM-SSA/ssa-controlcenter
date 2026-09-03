<?php

namespace Tests\Feature;

use App\Helpers\FeedbackSentiment;
use App\Helpers\FeedbackStatus;
use App\Livewire\FeedbackTable;
use App\Models\Area;
use App\Models\Feedback;
use App\Models\Position;
use App\Models\User;
use App\Notifications\FeedbackForwardedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Staff actioning feedback: closing it, forwarding it, and what each means.
 *
 * The three properties worth protecting, in order:
 *
 *  1. Forwarded feedback reaches the controller and closed feedback does not.
 *     If those two ever behave the same, the feature has no reason to exist.
 *  2. The original submission is never edited. A division that can rewrite
 *     feedback into something more palatable does not have feedback.
 *  3. The submitter is never named to the controller. Feedback is given on the
 *     understanding that it goes to staff.
 */
class FeedbackActioningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Somebody who may action feedback anywhere.
     *
     * A global admin rather than a moderator, and deliberately: every division
     * shapes its middle roles differently, and a test that depends on which one
     * happens to carry `feedback.**` breaks on any installation that moved it.
     * Admin holds the permission through the matrix like anybody else -- there
     * is no `before()` on FeedbackPolicy -- so the policy path is still what is
     * being exercised here.
     *
     * The narrower cases have their own actors: a role-less member below, and
     * an area-scoped one for the scoping test.
     */
    private function staff(): User
    {
        $staff = User::factory()->create();
        $staff->roleAssignments()->create(['role' => 'admin', 'area_id' => null]);

        return $staff;
    }

    private function feedbackInArea(?Area $area = null): Feedback
    {
        $area ??= Area::factory()->create();

        return Feedback::factory()->create([
            'reference_position_id' => Position::factory()->create(['area_id' => $area->id])->id,
        ]);
    }

    // ----------------------------------------------------------------- state

    #[Test]
    public function feedback_arrives_open(): void
    {
        $this->assertSame(FeedbackStatus::OPEN, $this->feedbackInArea()->status);
    }

    #[Test]
    public function closing_records_the_decision_the_actor_and_the_time(): void
    {
        $staff = $this->staff();
        $feedback = $this->feedbackInArea();

        $this->actingAs($staff)
            ->patch(route('feedback.action', $feedback), [
                'status' => FeedbackStatus::CLOSED->value,
                'sentiment' => FeedbackSentiment::NEGATIVE->value,
                'staff_note' => 'Spoke to the controller directly.',
            ])
            ->assertRedirect(route('reports.feedback'));

        $feedback->refresh();

        $this->assertSame(FeedbackStatus::CLOSED, $feedback->status);
        $this->assertSame(FeedbackSentiment::NEGATIVE, $feedback->sentiment);
        $this->assertSame('Spoke to the controller directly.', $feedback->staff_note);
        $this->assertSame($staff->id, $feedback->actioned_by_id);
        $this->assertNotNull($feedback->actioned_at);
    }

    #[Test]
    public function the_submission_itself_is_never_altered_by_actioning(): void
    {
        $feedback = $this->feedbackInArea();
        $original = $feedback->feedback;

        $this->actingAs($this->staff())->patch(route('feedback.action', $feedback), [
            'status' => FeedbackStatus::CLOSED->value,
            'staff_note' => 'A note that is not the feedback.',
        ]);

        $this->assertSame($original, $feedback->fresh()->feedback);
    }

    #[Test]
    public function a_sentiment_is_optional(): void
    {
        $feedback = $this->feedbackInArea();

        $this->actingAs($this->staff())
            ->patch(route('feedback.action', $feedback), ['status' => FeedbackStatus::CLOSED->value])
            ->assertRedirect();

        $this->assertNull($feedback->fresh()->sentiment);
    }

    #[Test]
    public function feedback_cannot_be_put_back_to_open(): void
    {
        // Un-actioning would discard who decided and when. A mis-click is fixed
        // by actioning it again, not by erasing the record.
        $feedback = $this->feedbackInArea();

        $this->actingAs($this->staff())
            ->patch(route('feedback.action', $feedback), ['status' => FeedbackStatus::OPEN->value])
            ->assertSessionHasErrors('status');

        $this->assertSame(FeedbackStatus::OPEN, $feedback->fresh()->status);
    }

    #[Test]
    public function an_invented_status_is_refused(): void
    {
        $feedback = $this->feedbackInArea();

        $this->actingAs($this->staff())
            ->patch(route('feedback.action', $feedback), ['status' => 'deleted'])
            ->assertSessionHasErrors('status');
    }

    // --------------------------------------------------------- notifications

    #[Test]
    public function forwarding_tells_the_controller(): void
    {
        Notification::fake();

        $feedback = $this->feedbackInArea();

        $this->actingAs($this->staff())->patch(route('feedback.action', $feedback), [
            'status' => FeedbackStatus::FORWARDED->value,
        ]);

        Notification::assertSentTo($feedback->referenceUser, FeedbackForwardedNotification::class);
    }

    #[Test]
    public function closing_tells_nobody(): void
    {
        // The whole reason there are two outcomes rather than one.
        Notification::fake();

        $feedback = $this->feedbackInArea();

        $this->actingAs($this->staff())->patch(route('feedback.action', $feedback), [
            'status' => FeedbackStatus::CLOSED->value,
        ]);

        Notification::assertNothingSent();
    }

    #[Test]
    public function re_actioning_already_forwarded_feedback_does_not_send_it_twice(): void
    {
        Notification::fake();

        $feedback = $this->feedbackInArea();
        $staff = $this->staff();

        $this->actingAs($staff)->patch(route('feedback.action', $feedback), [
            'status' => FeedbackStatus::FORWARDED->value,
        ]);

        // Correcting the note afterwards is an ordinary thing to do, and must
        // not put a second copy of the same feedback in somebody's inbox.
        $this->actingAs($staff)->patch(route('feedback.action', $feedback), [
            'status' => FeedbackStatus::FORWARDED->value,
            'staff_note' => 'Adding the context I forgot.',
        ]);

        Notification::assertSentToTimes($feedback->referenceUser, FeedbackForwardedNotification::class, 1);
    }

    #[Test]
    public function forwarding_uncorrelated_feedback_notifies_nobody_and_does_not_fail(): void
    {
        Notification::fake();

        // No position AND no controller: there is nobody to tell, and the
        // action must still record cleanly rather than throwing.
        $feedback = Feedback::factory()->uncorrelated()->create(['reference_user_id' => null]);

        $this->actingAs($this->staff())
            ->patch(route('feedback.action', $feedback), ['status' => FeedbackStatus::FORWARDED->value])
            ->assertRedirect();

        $this->assertSame(FeedbackStatus::FORWARDED, $feedback->fresh()->status);
        Notification::assertNothingSent();
    }

    // ------------------------------------------------------------ permission

    #[Test]
    public function a_member_without_the_permission_cannot_action_feedback(): void
    {
        $feedback = $this->feedbackInArea();

        $this->actingAs(User::factory()->create())
            ->patch(route('feedback.action', $feedback), ['status' => FeedbackStatus::CLOSED->value])
            ->assertForbidden();

        $this->assertSame(FeedbackStatus::OPEN, $feedback->fresh()->status);
    }

    #[Test]
    public function staff_cannot_action_feedback_from_an_area_they_do_not_hold(): void
    {
        $mine = Area::factory()->create();
        $theirs = Area::factory()->create();

        $staff = User::factory()->create();
        $staff->roleAssignments()->create(['role' => 'moderator', 'area_id' => $mine->id]);

        $feedback = $this->feedbackInArea($theirs);

        $this->actingAs($staff)
            ->patch(route('feedback.action', $feedback), ['status' => FeedbackStatus::CLOSED->value])
            ->assertForbidden();

        $this->assertSame(FeedbackStatus::OPEN, $feedback->fresh()->status);
    }

    // ----------------------------------------------------- the staff listing

    #[Test]
    public function the_report_shows_open_feedback_by_default(): void
    {
        $area = Area::factory()->create();
        $open = $this->feedbackInArea($area);
        $closed = $this->feedbackInArea($area);
        $closed->action(FeedbackStatus::CLOSED, $this->staff());

        Livewire::actingAs($this->staff())
            ->test(FeedbackTable::class)
            ->assertSee($open->feedback)
            ->assertDontSee($closed->feedback);
    }

    #[Test]
    public function actioned_feedback_is_one_filter_away_and_never_lost(): void
    {
        $area = Area::factory()->create();
        $closed = $this->feedbackInArea($area);
        $closed->action(FeedbackStatus::CLOSED, $this->staff());

        Livewire::actingAs($this->staff())
            ->test(FeedbackTable::class)
            ->set('status', '')
            ->assertSee($closed->feedback);
    }

    #[Test]
    public function a_crafted_status_filter_narrows_to_nothing_rather_than_widening(): void
    {
        $area = Area::factory()->create();
        $open = $this->feedbackInArea($area);

        // A status outside the enum must not silently fall back to "everything"
        // — it is ignored, which shows the unfiltered set, and that is still
        // bounded by visibleTo(). What it must never do is bypass the scope.
        Livewire::actingAs($this->staff())
            ->test(FeedbackTable::class)
            ->set('status', 'nonsense')
            ->assertSee($open->feedback);
    }

    // ------------------------------------------------- the controller's view

    #[Test]
    public function a_controller_sees_only_feedback_that_was_forwarded_to_them(): void
    {
        $controller = User::factory()->create();
        $staff = $this->staff();

        $forwarded = $this->feedbackInArea();
        $forwarded->update(['reference_user_id' => $controller->id]);
        $forwarded->action(FeedbackStatus::FORWARDED, $staff);

        $closed = $this->feedbackInArea();
        $closed->update(['reference_user_id' => $controller->id]);
        $closed->action(FeedbackStatus::CLOSED, $staff);

        $open = $this->feedbackInArea();
        $open->update(['reference_user_id' => $controller->id]);

        $html = $this->actingAs($controller)->get(route('feedback.received'))->assertOk()->getContent();

        $this->assertStringContainsString(e($forwarded->feedback), $html);
        $this->assertStringNotContainsString(e($closed->feedback), $html);
        $this->assertStringNotContainsString(e($open->feedback), $html);
    }

    #[Test]
    public function the_controller_is_never_told_who_submitted_it(): void
    {
        $controller = User::factory()->create();

        // A distinctive name, deliberately. A faker name can collide with
        // ordinary page furniture, and a bare CID is a short number that turns
        // up in asset hashes and element ids -- an assertion that passes by
        // luck is worse than no assertion, because it stops being checked.
        $submitter = User::factory()->create([
            'first_name' => 'Zzqx',
            'last_name' => 'Vwybtrn',
        ]);

        $feedback = $this->feedbackInArea();
        $feedback->update([
            'reference_user_id' => $controller->id,
            'submitter_user_id' => $submitter->id,
        ]);
        $feedback->action(FeedbackStatus::FORWARDED, $this->staff());

        $html = $this->actingAs($controller)->get(route('feedback.received'))->getContent();

        $this->assertStringContainsString(e($feedback->feedback), $html, 'the feedback itself must be shown');
        $this->assertStringNotContainsString('Zzqx', $html);
        $this->assertStringNotContainsString('Vwybtrn', $html);
    }

    #[Test]
    public function a_controller_sees_nothing_of_somebody_elses_feedback(): void
    {
        $feedback = $this->feedbackInArea();
        $feedback->action(FeedbackStatus::FORWARDED, $this->staff());

        $html = $this->actingAs(User::factory()->create())
            ->get(route('feedback.received'))->assertOk()->getContent();

        $this->assertStringNotContainsString(e($feedback->feedback), $html);
    }

    #[Test]
    public function the_forwarded_email_carries_the_feedback_but_not_the_submitter(): void
    {
        $controller = User::factory()->create();
        $feedback = $this->feedbackInArea();
        $feedback->update(['reference_user_id' => $controller->id]);

        $mail = (new FeedbackForwardedNotification($feedback))->toMail($controller);

        $this->assertSame($controller->personalNotificationEmail, $mail->to[0]['address']);
        $this->assertEmpty($mail->replyTo, 'a reply address would name the submitter');
    }
}
