<?php

namespace Tests\Feature;

use App\Helpers\TrainingStatus;
use App\Helpers\Vatssa\MembershipRequestState;
use App\Helpers\Vatssa\MembershipRequestType;
use App\Helpers\Vatssa\TerminalLogReason;
use App\Helpers\Vatssa\TerminalLogType;
use App\Models\Training;
use App\Models\User;
use App\Models\Vatssa\ActionLog;
use App\Models\Vatssa\MembershipRequest;
use App\Models\Vatssa\TerminalComment;
use App\Models\Vatssa\TerminalLogEntry;
use Database\Seeders\VatssaPipelineSeeder;
use Database\Seeders\VatssaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: the Terminal log, the comment catalogue, and the two automatic
 * transitions.
 *
 * The properties worth protecting:
 *
 *  1. A log row always says who DID it, which is not always who typed it.
 *  2. A comment composes into copy-ready text, and an unfilled placeholder is
 *     left visible rather than silently blanked.
 *  3. A familiarisation training that completes finishes its request, and one
 *     that does not puts it back on the desk LOUDLY.
 *  4. A rating upgrade raises a membership request, not a Task.
 */
class TerminalLogTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        return User::factory()->create();
    }

    private function entry(array $attributes = []): TerminalLogEntry
    {
        $recorder = $this->member();

        return TerminalLogEntry::create(array_merge([
            'type' => TerminalLogType::QUERY,
            'reason' => TerminalLogReason::TVCP_CHECK,
            'user_id' => $this->member()->id,
            'actor_user_id' => $recorder->id,
            'recorded_by' => $recorder->id,
            'performed_at' => now(),
        ], $attributes));
    }

    #[Test]
    public function an_entry_defaults_to_having_happened_now(): void
    {
        // The column is NOT NULL and every caller was having to remember it.
        // "Now" is the right answer for anything that is not a backfill, and a
        // caller who forgets should get that rather than a constraint
        // violation.
        $recorder = $this->member();

        $entry = TerminalLogEntry::create([
            'type' => TerminalLogType::QUERY,
            'reason' => TerminalLogReason::TVCP_CHECK,
            'user_id' => $this->member()->id,
            'actor_user_id' => $recorder->id,
            'recorded_by' => $recorder->id,
        ]);

        $this->assertNotNull($entry->performed_at);
    }

    // ------------------------------------------------------------- the actor

    #[Test]
    public function an_entry_must_say_who_did_it(): void
    {
        // A row naming nobody says the action happened by itself.
        $this->expectException(\InvalidArgumentException::class);

        $this->entry(['actor_user_id' => null, 'actor_name' => null]);
    }

    #[Test]
    public function the_actor_can_be_somebody_without_a_control_center_account(): void
    {
        // The case that made this table necessary: it happened on Terminal, by
        // somebody who was not in Control Center at the time, and is being
        // recorded afterwards.
        $entry = $this->entry(['actor_user_id' => null, 'actor_name' => 'A. Person']);

        $this->assertSame('A. Person', $entry->actorLabel());
    }

    #[Test]
    public function the_recorder_and_the_actor_are_separate_people(): void
    {
        $actor = $this->member();
        $recorder = $this->member();

        $entry = $this->entry(['actor_user_id' => $actor->id, 'recorded_by' => $recorder->id]);

        $this->assertSame($actor->id, $entry->actor_user_id);
        $this->assertSame($recorder->id, $entry->recorded_by);
        $this->assertSame($actor->name, $entry->actorLabel());
    }

    #[Test]
    public function a_clean_disciplinary_check_is_a_recordable_result(): void
    {
        // "We looked and there was nothing" is what you need six months later,
        // so null (not a check) and false (checked, nothing found) are
        // different values rather than both being absence.
        $notACheck = $this->entry();
        $this->assertFalse($notACheck->isDisciplinaryCheck());

        $clean = $this->entry(['discipline_found' => false]);
        $this->assertTrue($clean->isDisciplinaryCheck());
        $this->assertFalse($clean->discipline_found);
    }

    // ------------------------------------------------------------- fixtures

    #[Test]
    public function the_seeder_fills_the_log_across_every_shape_that_renders_differently(): void
    {
        // An empty AUDIT log is worse than an empty queue: it reads exactly
        // like a log nothing is writing to, which is the one thing an audit
        // surface must never be mistaken for.
        putenv('VATSSA_SEED_FORCE=1');
        $_ENV['VATSSA_SEED_FORCE'] = '1';
        $_SERVER['VATSSA_SEED_FORCE'] = '1';
        $this->seed(VatssaSeeder::class);
        $this->seed(VatssaPipelineSeeder::class);

        foreach (TerminalLogType::cases() as $type) {
            $this->assertTrue(
                TerminalLogEntry::where('type', $type)->exists(),
                $type->value . ' has no fixture, so its row never renders on a dev box'
            );
        }

        // Both actor shapes. The typed name is the case the second column
        // exists for, and it is the one a hand-made fixture forgets.
        $this->assertTrue(TerminalLogEntry::whereNotNull('actor_user_id')->exists());
        $this->assertTrue(TerminalLogEntry::whereNotNull('actor_name')->exists());

        // All three disciplinary outcomes: not a check, checked and clean,
        // checked with a finding.
        $this->assertTrue(TerminalLogEntry::whereNull('discipline_found')->exists());
        $this->assertTrue(TerminalLogEntry::where('discipline_found', false)->exists());

        $finding = TerminalLogEntry::where('discipline_found', true)->first();
        $this->assertNotNull($finding);
        $this->assertNotEmpty($finding->discipline_context, 'a finding carries its context');

        putenv('VATSSA_SEED_FORCE');
        unset($_ENV['VATSSA_SEED_FORCE'], $_SERVER['VATSSA_SEED_FORCE']);
    }

    // --------------------------------------------------------- the catalogue

    #[Test]
    public function the_catalogue_ships_filled_and_every_entry_composes(): void
    {
        $comments = TerminalComment::offered()->get();

        $this->assertNotEmpty($comments, 'the admin page is empty without these');

        foreach ($comments as $comment) {
            $this->assertStringStartsWith('SSA | ', $comment->compose());
        }
    }

    #[Test]
    public function one_rating_update_entry_covers_every_upgrade(): void
    {
        // Not one row per rating pair: sixteen rows differing by two characters
        // is a list nobody can scan, and it goes stale the moment a rating is
        // added.
        $comment = TerminalComment::find('SSA-VT-001');

        $this->assertNotNull($comment);
        $this->assertContains('from', $comment->placeholders());
        $this->assertContains('to', $comment->placeholders());

        $composed = $comment->compose(['from' => 'S1', 'to' => 'S2', 'date' => '3 September 2026']);

        $this->assertStringContainsString('S1', $composed);
        $this->assertStringContainsString('S2', $composed);
        $this->assertStringNotContainsString('{from}', $composed);
    }

    #[Test]
    public function an_unfilled_placeholder_is_left_visible(): void
    {
        // Obviously unfinished is the safer failure when the text is about to
        // go on somebody's permanent record. A comment that silently lost a
        // value would read as complete and be wrong.
        $composed = TerminalComment::find('SSA-VT-001')->compose(['from' => 'S1']);

        $this->assertStringContainsString('{to}', $composed);
    }

    #[Test]
    public function every_comment_carries_the_house_shape(): void
    {
        $composed = TerminalComment::find('SSA-VT-008')->compose();

        $this->assertMatchesRegularExpression('/^SSA \| [A-Z]+ - /', $composed);
    }

    // ------------------------------------------------ the two transitions

    private function requestAwaitingTraining(): array
    {
        $member = $this->member();

        // ACTIVE_TRAINING explicitly, not whatever the factory rolls.
        //
        // TrainingFactory picks a status between -4 and 3, so roughly one run
        // in eight it produced a training ALREADY in the status the test then
        // sets -- `wasChanged('status')` is false, the observer correctly does
        // nothing, and the test fails for a reason that has nothing to do with
        // the observer. It passed the rest of the time, which is worse: a test
        // that passes by luck stops being checked.
        $training = Training::factory()->create([
            'user_id' => $member->id,
            'status' => TrainingStatus::ACTIVE_TRAINING,
        ]);

        $request = MembershipRequest::open(MembershipRequestType::VISITING, $member, $member, [
            'training_id' => $training->id,
        ]);
        $request->update(['state' => MembershipRequestState::PENDING_TRAINING]);

        return [$request, $training];
    }

    #[Test]
    public function a_completed_familiarisation_finishes_the_request(): void
    {
        [$request, $training] = $this->requestAwaitingTraining();

        $training->update(['status' => TrainingStatus::COMPLETED]);

        $request->refresh();
        $this->assertSame(MembershipRequestState::COMPLETE, $request->state);
        $this->assertNotNull($request->closed_at);
    }

    #[Test]
    public function a_failed_familiarisation_puts_the_request_back_on_the_desk(): void
    {
        [$request, $training] = $this->requestAwaitingTraining();

        $training->update(['status' => TrainingStatus::CLOSED_BY_STAFF]);

        $request->refresh();
        $this->assertSame(MembershipRequestState::PENDING_TRANSFER, $request->state);
        $this->assertTrue($request->state->isOnTheDesk());
    }

    #[Test]
    public function a_failed_familiarisation_is_not_silent(): void
    {
        // TVCP 4.7 usually means a transfer back to the previous allocation.
        // That is a real consequence for a real person, and its trigger is a
        // training quietly closing -- so it goes where the desk looks.
        [, $training] = $this->requestAwaitingTraining();

        $training->update(['status' => TrainingStatus::CLOSED_BY_STAFF]);

        $this->assertTrue(
            ActionLog::where('action', 'membership.training_failed')->exists(),
            'the desk has to be told'
        );
    }

    #[Test]
    public function a_training_with_no_membership_request_behind_it_is_left_alone(): void
    {
        $training = Training::factory()->create(['user_id' => $this->member()->id]);

        $training->update(['status' => TrainingStatus::COMPLETED]);

        $this->assertSame(0, MembershipRequest::count());
        $this->assertFalse(ActionLog::where('action', 'membership.training_failed')->exists());
    }

    #[Test]
    public function a_request_not_waiting_on_training_is_not_moved_by_one_closing(): void
    {
        // Only PENDING_TRAINING is listening. A request still on the desk must
        // not be finished by an unrelated training closing.
        $member = $this->member();
        $training = Training::factory()->create(['user_id' => $member->id]);
        $request = MembershipRequest::open(MembershipRequestType::TRANSFER, $member, $member, [
            'training_id' => $training->id,
        ]);

        $training->update(['status' => TrainingStatus::COMPLETED]);

        $this->assertSame(MembershipRequestState::PENDING_DISCIPLINARY, $request->fresh()->state);
    }
}
