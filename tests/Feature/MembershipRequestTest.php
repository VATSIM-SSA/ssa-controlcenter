<?php

namespace Tests\Feature;

use App\Helpers\Vatssa\MembershipRequestState;
use App\Helpers\Vatssa\MembershipRequestType;
use App\Models\User;
use App\Models\Vatssa\MembershipRequest;
use Database\Seeders\VatssaPipelineSeeder;
use Database\Seeders\VatssaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: the membership request, and the rules that keep it honest.
 *
 * Five types in one table with two state machines behind them. The things worth
 * protecting, in order:
 *
 *  1. A transfer never skips its disciplinary check by starting in the wrong
 *     state.
 *  2. "Nobody has looked" stays distinguishable from "looked, and it was
 *     clean". The visiting endorsement is gated on that difference, so
 *     conflating them issues endorsements on unread records.
 *  3. A finding always has context. "We looked and there was something" with no
 *     note is worse than not having looked -- the next person can neither act
 *     on it nor tell whether anybody did.
 *  4. A member is never shown a "request" they did not file.
 */
class MembershipRequestTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        return User::factory()->create();
    }

    /**
     * VatssaSeeder first: it builds the fixed accounts the pipeline seeder
     * checks for before it will write anything.
     */
    private function seedFixtures(): void
    {
        putenv('VATSSA_SEED_FORCE=1');
        $_ENV['VATSSA_SEED_FORCE'] = '1';
        $_SERVER['VATSSA_SEED_FORCE'] = '1';

        $this->seed(VatssaSeeder::class);
        $this->seed(VatssaPipelineSeeder::class);
    }

    protected function tearDown(): void
    {
        putenv('VATSSA_SEED_FORCE');
        unset($_ENV['VATSSA_SEED_FORCE'], $_SERVER['VATSSA_SEED_FORCE']);

        parent::tearDown();
    }

    // ------------------------------------------------------ types and states

    #[Test]
    public function a_member_filed_request_starts_at_the_disciplinary_check(): void
    {
        foreach (MembershipRequestType::memberFiled() as $type) {
            $request = MembershipRequest::open($type, $this->member());

            $this->assertSame(
                MembershipRequestState::PENDING_DISCIPLINARY,
                $request->state,
                $type->value . ' must not skip its disciplinary check'
            );
        }
    }

    #[Test]
    public function terminal_work_starts_open(): void
    {
        foreach ([MembershipRequestType::RATING_UPGRADE, MembershipRequestType::STAFF_INQUIRY, MembershipRequestType::OTHER] as $type) {
            $request = MembershipRequest::open($type, $this->member());

            $this->assertSame(MembershipRequestState::OPEN, $request->state);
        }
    }

    #[Test]
    public function only_visiting_and_transfer_run_the_full_workflow(): void
    {
        $this->assertSame(
            [MembershipRequestType::VISITING, MembershipRequestType::TRANSFER],
            MembershipRequestType::memberFiled()
        );

        $this->assertCount(7, MembershipRequestType::VISITING->states());
        $this->assertCount(3, MembershipRequestType::RATING_UPGRADE->states());
    }

    #[Test]
    public function a_tvcp_snapshot_is_stored_only_where_it_means_something(): void
    {
        $snapshot = ['hours' => 50, 'daysSinceUpgrade' => 120];

        $transfer = MembershipRequest::open(
            MembershipRequestType::TRANSFER, $this->member(), null, ['checks' => $snapshot]
        );
        $this->assertSame($snapshot, $transfer->fresh()->checks);

        // A rating upgrade has nothing to check against TVCP 4.2, and an empty
        // snapshot against one would make the column mean two different things
        // depending on the row.
        $upgrade = MembershipRequest::open(
            MembershipRequestType::RATING_UPGRADE, $this->member(), null, ['checks' => $snapshot]
        );
        $this->assertNull($upgrade->fresh()->checks);
    }

    // -------------------------------------------------- the disciplinary check

    #[Test]
    public function an_unchecked_request_is_not_the_same_as_a_clean_one(): void
    {
        $request = MembershipRequest::open(MembershipRequestType::VISITING, $this->member());

        $this->assertFalse($request->disciplinaryChecked());
        $this->assertNull($request->disciplinary_clean);
        $this->assertFalse($request->mayAssignVisitingEndorsement());
    }

    #[Test]
    public function a_clean_check_is_a_tick_and_needs_no_context(): void
    {
        $request = MembershipRequest::open(MembershipRequestType::VISITING, $this->member());
        $staff = $this->member();

        $request->recordDisciplinaryCheck(true, $staff);

        $this->assertTrue($request->disciplinaryChecked());
        $this->assertTrue($request->disciplinary_clean);
        $this->assertNull($request->disciplinary_context);
        $this->assertSame($staff->id, $request->disciplinary_checked_by);
        $this->assertNotNull($request->disciplinary_checked_at);
    }

    #[Test]
    public function a_finding_without_context_is_refused(): void
    {
        $request = MembershipRequest::open(MembershipRequestType::TRANSFER, $this->member());

        $this->expectException(\InvalidArgumentException::class);

        $request->recordDisciplinaryCheck(false, $this->member());
    }

    #[Test]
    public function a_finding_with_context_is_recorded(): void
    {
        $request = MembershipRequest::open(MembershipRequestType::TRANSFER, $this->member());

        $request->recordDisciplinaryCheck(false, $this->member(), 'Suspended in 2026 for 30 days.');

        $this->assertTrue($request->disciplinaryChecked());
        $this->assertFalse($request->disciplinary_clean);
        $this->assertSame('Suspended in 2026 for 30 days.', $request->disciplinary_context);
    }

    #[Test]
    public function a_re_check_that_comes_back_clean_clears_the_old_finding(): void
    {
        // A stale note from a previous finding sitting under a green tick is
        // the worst of both -- it reads as current and is not.
        $request = MembershipRequest::open(MembershipRequestType::TRANSFER, $this->member());
        $request->recordDisciplinaryCheck(false, $this->member(), 'Looked at the wrong CID.');

        $request->recordDisciplinaryCheck(true, $this->member());

        $this->assertTrue($request->disciplinary_clean);
        $this->assertNull($request->disciplinary_context);
    }

    // ------------------------------------------------ the visiting endorsement

    #[Test]
    public function a_visiting_endorsement_needs_a_clean_check(): void
    {
        $request = MembershipRequest::open(MembershipRequestType::VISITING, $this->member());
        $request->recordDisciplinaryCheck(false, $this->member(), 'Open case.');

        $this->assertFalse($request->mayAssignVisitingEndorsement());
    }

    #[Test]
    public function a_visiting_endorsement_needs_a_visiting_request(): void
    {
        // A familiarisation training can be run without anybody visiting, so
        // completing one is not on its own evidence that somebody should hold a
        // visiting endorsement.
        $transfer = MembershipRequest::open(MembershipRequestType::TRANSFER, $this->member());
        $transfer->recordDisciplinaryCheck(true, $this->member());

        $this->assertFalse($transfer->mayAssignVisitingEndorsement());
    }

    #[Test]
    public function a_visiting_request_with_a_clean_check_may_have_one(): void
    {
        $request = MembershipRequest::open(MembershipRequestType::VISITING, $this->member());
        $request->recordDisciplinaryCheck(true, $this->member());

        $this->assertTrue($request->mayAssignVisitingEndorsement());
    }

    // ----------------------------------------------------------------- queues

    #[Test]
    public function pending_training_and_awaiting_member_are_off_the_desk(): void
    {
        // The request is alive in both, but the next move belongs to somebody
        // else. Mixing them into the open queue is how a desk stops trusting
        // its own queue length.
        $this->assertFalse(MembershipRequestState::PENDING_TRAINING->isOnTheDesk());
        $this->assertFalse(MembershipRequestState::AWAITING_MEMBER->isOnTheDesk());

        $this->assertTrue(MembershipRequestState::PENDING_DISCIPLINARY->isOnTheDesk());
        $this->assertTrue(MembershipRequestState::OPEN->isOnTheDesk());
    }

    #[Test]
    public function the_desk_scope_matches_the_states_that_say_they_are_on_it(): void
    {
        $member = $this->member();

        $onDesk = MembershipRequest::open(MembershipRequestType::VISITING, $member);
        $waiting = MembershipRequest::open(MembershipRequestType::TRANSFER, $this->member());
        $waiting->update(['state' => MembershipRequestState::PENDING_TRAINING]);

        $ids = MembershipRequest::onTheDesk()->pluck('id')->all();

        $this->assertContains($onDesk->id, $ids);
        $this->assertNotContains($waiting->id, $ids);
    }

    // ------------------------------------------------------- what a member sees

    #[Test]
    public function a_member_sees_only_the_requests_they_filed(): void
    {
        $member = $this->member();

        $visiting = MembershipRequest::open(MembershipRequestType::VISITING, $member, $member);
        $upgrade = MembershipRequest::open(MembershipRequestType::RATING_UPGRADE, $member);
        $inquiry = MembershipRequest::open(MembershipRequestType::STAFF_INQUIRY, $member);
        $other = MembershipRequest::open(MembershipRequestType::OTHER, $member);

        $ids = MembershipRequest::filedBy($member)->pluck('id')->all();

        $this->assertSame([$visiting->id], $ids);
        $this->assertNotContains($upgrade->id, $ids);
        $this->assertNotContains($inquiry->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    #[Test]
    public function an_open_request_of_one_type_does_not_block_another(): void
    {
        $member = $this->member();
        MembershipRequest::open(MembershipRequestType::RATING_UPGRADE, $member);

        $this->assertFalse(
            MembershipRequest::hasOpenFor($member, MembershipRequestType::TRANSFER),
            'a rating upgrade on the desk must not stop somebody asking to transfer'
        );

        MembershipRequest::open(MembershipRequestType::TRANSFER, $member, $member);
        $this->assertTrue(MembershipRequest::hasOpenFor($member, MembershipRequestType::TRANSFER));
    }

    // ------------------------------------------------------------- fixtures

    #[Test]
    public function the_seeder_fills_every_queue_and_every_disciplinary_outcome(): void
    {
        // An empty queue on a dev box is indistinguishable from a broken one,
        // which is the whole reason these fixtures exist. This pins that the
        // set actually covers what it claims to rather than happening to
        // produce twelve rows in one state.
        $this->seedFixtures();

        $states = MembershipRequest::pluck('state')->unique();

        $this->assertTrue(
            MembershipRequest::onTheDesk()->exists(),
            'the open queue must have something in it'
        );
        $this->assertTrue(
            MembershipRequest::pendingTraining()->exists(),
            'the pending-training queue must have something in it'
        );
        $this->assertTrue(
            MembershipRequest::finished()->exists(),
            'the closed queue must have something in it'
        );

        // Every type, so no queue filter is left untested by the fixtures.
        foreach (MembershipRequestType::cases() as $type) {
            $this->assertTrue(
                MembershipRequest::where('type', $type)->exists(),
                $type->value . ' has no fixture'
            );
        }

        // The three disciplinary outcomes behave differently, and the third is
        // the one a hand-made fixture always forgets.
        $this->assertTrue(
            MembershipRequest::whereNull('disciplinary_checked_at')->exists(),
            'never checked'
        );
        $this->assertTrue(
            MembershipRequest::where('disciplinary_clean', true)->exists(),
            'checked and clean'
        );

        $finding = MembershipRequest::where('disciplinary_clean', false)->first();
        $this->assertNotNull($finding, 'checked and NOT clean');
        $this->assertNotEmpty(
            $finding->disciplinary_context,
            'a seeded finding must carry its context, like a real one'
        );

        $this->assertGreaterThan(1, $states->count());
    }

    #[Test]
    public function the_seeder_is_idempotent(): void
    {
        $this->seedFixtures();
        $first = MembershipRequest::count();

        $this->seed(VatssaPipelineSeeder::class);

        $this->assertSame($first, MembershipRequest::count(), 're-running must not duplicate');
    }

    #[Test]
    public function a_finished_request_no_longer_blocks_a_new_one(): void
    {
        $member = $this->member();
        $done = MembershipRequest::open(MembershipRequestType::VISITING, $member, $member);
        $done->update(['state' => MembershipRequestState::COMPLETE]);

        $this->assertFalse(MembershipRequest::hasOpenFor($member, MembershipRequestType::VISITING));
    }
}
