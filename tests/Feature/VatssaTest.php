<?php

namespace Tests\Feature;

use App\Helpers\TaskStatus;
use App\Helpers\TrainingStatus;
use App\Http\Controllers\TaskController;
use App\Models\Area;
use App\Models\Position;
use App\Models\Rating;
use App\Models\Task;
use App\Models\Training;
use App\Models\User;
use App\Models\Vatssa\MessageLog;
use App\Models\Vatssa\MessageTemplate;
use App\Models\Vatssa\MoodleCourse;
use App\Models\Vatssa\RequestTarget;
use App\Models\Vatssa\TheoryAttempt;
use App\Models\Vatssa\UserPlatform;
use App\Services\PermissionMatrix;
use Database\Seeders\VatssaPipelineSeeder;
use Database\Seeders\VatssaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The VATSSA divergence, asserted.
 *
 * Why this file exists at all: the fork keeps its divergence in ADDED files
 * wherever it can, because an added file cannot conflict on an upstream
 * absorption. The cost of that choice is that git then gives no signal when
 * upstream changes underneath one. A conflict is the drift detector for the one
 * file VATSSA modifies (`config/roles.php`); this test file is the drift
 * detector for everything VATSSA adds.
 *
 * It runs in CI on every PR, which means it runs on the `integration` -> `dev`
 * PR of every upstream absorption. That is the moment you want to hear about it.
 *
 * Upstream's own `tests/Unit/RolesConfigTest.php` already covers the generic
 * invariants: no orphan permissions, no duplicate catalogue entries, every
 * matrix role defined, every grantable role carrying a `roles.*.manage`. Do not
 * duplicate those here. What follows is only what is true of VATSSA and would
 * not be true of upstream.
 */
class VatssaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed the fixtures, overriding both seeders' safety valves.
     *
     * `VatssaSeeder` returns early whenever the database already has users, and
     * `VatssaPipelineSeeder` refuses a database that does not look like
     * fixtures. Both guards are right in production and both are in the way
     * here -- a test that silently seeds nothing then asserts against an empty
     * database is worse than one that fails, because it usually passes.
     *
     * VATSSA_SEED_FORCE is the escape hatch both of them already document. It
     * is set through putenv AND the superglobals because Laravel's env() reads
     * whichever the platform populated.
     */
    private function seedFixtures(): void
    {
        putenv('VATSSA_SEED_FORCE=1');
        $_ENV['VATSSA_SEED_FORCE'] = '1';
        $_SERVER['VATSSA_SEED_FORCE'] = '1';

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        putenv('VATSSA_SEED_FORCE');
        unset($_ENV['VATSSA_SEED_FORCE'], $_SERVER['VATSSA_SEED_FORCE']);

        parent::tearDown();
    }

    private const ROLES = [
        'admin',
        'atc-training-manager',
        'pipeline-coordinator',
        'mentor',
        'nav-editor',
        'feedback-team',
    ];

    #[Test]
    public function the_role_list_is_exactly_the_six_vatssa_roles(): void
    {
        // Catches an upstream role reappearing after a badly resolved conflict
        // in config/roles.php, which is the most likely way this file breaks.
        $this->assertSame(self::ROLES, array_keys(config('roles.roles')));
    }

    #[Test]
    public function atc_training_manager_is_a_superset_of_pipeline_coordinator(): void
    {
        // ATM is written out longhand rather than inheriting, because the matrix
        // has no inheritance mechanism. Editing one and forgetting the other is
        // the standing risk, and this is what catches it.
        $matrix = new PermissionMatrix;

        $missing = array_diff(
            $matrix->permissionsFor('pipeline-coordinator'),
            $matrix->permissionsFor('atc-training-manager'),
        );

        $this->assertSame([], array_values($missing));
    }

    #[Test]
    public function atc_training_manager_may_grant_mentor_and_nothing_else(): void
    {
        // In v7.0.0 this is pure config, which is why the UserPolicy override
        // was dropped. If a future upstream release moves grant authority back
        // into code, this fails and the override has to come back.
        $matrix = new PermissionMatrix;

        $grants = array_values(array_filter(
            $matrix->permissionsFor('atc-training-manager'),
            fn (string $p) => str_starts_with($p, 'roles.'),
        ));

        $this->assertSame(['roles.mentor.manage'], $grants);
    }

    #[Test]
    public function only_admin_may_grant_every_role(): void
    {
        $matrix = new PermissionMatrix;

        foreach (self::ROLES as $role) {
            if ($role === 'admin') {
                continue;
            }

            $this->assertContains(
                "roles.{$role}.manage",
                $matrix->permissionsFor('admin'),
                "admin cannot grant {$role}",
            );
        }
    }

    #[Test]
    public function admin_is_not_grantable_through_the_ui(): void
    {
        $this->assertNotContains('roles.admin.manage', config('roles.permissions'));
        $this->assertSame('global', config('roles.roles.admin.scope'));
    }

    #[Test]
    public function every_grant_permission_names_a_real_role(): void
    {
        // UserPolicy::updateRole builds "roles.{$role}.manage" from the role key.
        // A rename on one side and not the other silently means nobody can grant
        // anything, with no error anywhere.
        foreach (config('roles.permissions') as $permission) {
            if (! str_starts_with($permission, 'roles.')) {
                continue;
            }

            $role = substr($permission, strlen('roles.'), -strlen('.manage'));

            $this->assertArrayHasKey($role, config('roles.roles'), "{$permission} names no role");
        }
    }

    #[Test]
    public function the_reference_migration_loads_areas_and_positions(): void
    {
        // RefreshDatabase has already run every migration path, this one
        // included, so the rows are simply expected to be here.
        $this->assertSame('Southern Africa', Area::find(1)->name);
        $this->assertSame('Central Africa', Area::find(4)->name);

        $this->assertSame(401, Position::count());
        $this->assertSame(27, Position::distinct()->count('fir'));

        // Every position must belong to an area, or it is invisible to the
        // area-scoped screens that are the whole point of the role model.
        $this->assertSame(0, Position::whereNull('area_id')->count());
    }

    #[Test]
    public function the_reference_migration_is_idempotent(): void
    {
        $before = DB::table('positions')->orderBy('callsign')->get()->toArray();

        $this->artisan('migrate:refresh', ['--path' => 'database/migrations-vatssa'])
            ->assertSuccessful();

        $this->assertEquals($before, DB::table('positions')->orderBy('callsign')->get()->toArray());
    }

    #[Test]
    public function the_seeder_runs_and_assigns_the_expected_roles(): void
    {
        // Exercises the added seeder end to end. If upstream renames a model,
        // changes a factory signature, or alters the role_user shape, this is
        // where it surfaces.
        //
        // Forced, because VatssaSeeder returns early whenever the database
        // already has users -- and under RefreshDatabase it sometimes does.
        // Without this the seeder quietly does nothing and every assertion
        // below is made against an empty table.
        putenv('VATSSA_SEED_FORCE=1');
        $_ENV['VATSSA_SEED_FORCE'] = '1';
        $_SERVER['VATSSA_SEED_FORCE'] = '1';

        $this->seed(VatssaSeeder::class);

        $expected = [
            10000004 => [['nav-editor', 1], ['feedback-team', null]],
            10000005 => [['pipeline-coordinator', null]],
            10000008 => [['pipeline-coordinator', 1]],
            10000009 => [['atc-training-manager', null]],
            10000010 => [['admin', null]],
        ];

        foreach ($expected as $cid => $assignments) {
            $actual = User::find($cid)->roleAssignments()
                ->get()
                ->map(fn ($a) => [$a->role, $a->area_id])
                ->sortBy(fn ($a) => $a[0])
                ->values()
                ->all();

            $this->assertEquals(collect($assignments)->sortBy(fn ($a) => $a[0])->values()->all(), $actual, "CID {$cid}");
        }

        // No role_user row may reference a role the matrix cannot resolve.
        $orphans = DB::table('role_user')
            ->whereNotIn('role', self::ROLES)
            ->pluck('role')
            ->unique()
            ->all();

        $this->assertSame([], $orphans);
    }


    // ---------------------------------------------------------------------
    // The training pipeline
    // ---------------------------------------------------------------------

    #[Test]
    public function awaiting_mentor_does_not_disturb_the_status_order(): void
    {
        // THE detector for the appended-not-inserted decision.
        //
        // AWAITING_MENTOR belongs between PRE_TRAINING and ACTIVE_TRAINING in
        // the lifecycle, but is stored as 4 so that nothing has to be
        // renumbered under a live database -- `training_activities` records
        // past transitions as bare integers, and renumbering would rewrite
        // history silently.
        //
        // That is only safe while upstream's own values stay where they are. If
        // upstream ever adds a case at 4, or moves one of these, this fails
        // loudly rather than two stages quietly becoming one.
        $this->assertSame(0, TrainingStatus::IN_QUEUE->value);
        $this->assertSame(1, TrainingStatus::PRE_TRAINING->value);
        $this->assertSame(2, TrainingStatus::ACTIVE_TRAINING->value);
        $this->assertSame(3, TrainingStatus::AWAITING_EXAM->value);
        $this->assertSame(4, TrainingStatus::AWAITING_MENTOR->value);
    }

    #[Test]
    public function awaiting_mentor_counts_as_open_and_in_progress(): void
    {
        // Every ordered comparison in the codebase is `>= IN_QUEUE` or
        // `>= PRE_TRAINING`. Appending as 4 gives the right answer for both,
        // which is precisely why appending is safe.
        $this->assertTrue(TrainingStatus::AWAITING_MENTOR->isOpen());
        $this->assertTrue(TrainingStatus::AWAITING_MENTOR->isInProgress());
        $this->assertFalse(TrainingStatus::AWAITING_MENTOR->isClosed());
    }

    #[Test]
    public function the_pipeline_stages_cannot_be_set_by_hand(): void
    {
        // A human setting one of these puts Control Center and the bot into
        // disagreement about where somebody is, and the bot moves them back --
        // which looks like a bug rather than a rule.
        $this->assertFalse(TrainingStatus::IN_QUEUE->isAssignableByStaff());
        $this->assertFalse(TrainingStatus::PRE_TRAINING->isAssignableByStaff());
        $this->assertFalse(TrainingStatus::AWAITING_MENTOR->isAssignableByStaff());

        $this->assertTrue(TrainingStatus::ACTIVE_TRAINING->isAssignableByStaff());
        $this->assertTrue(TrainingStatus::COMPLETED->isAssignableByStaff());
    }

    #[Test]
    public function a_student_can_be_returned_to_awaiting_mentor_from_active_training(): void
    {
        // The one manual move that IS wanted: a mentor leaves, and the student
        // goes back into the queue rather than sitting in a training nobody is
        // running.
        $this->assertTrue(
            TrainingStatus::AWAITING_MENTOR->isAssignableFrom(TrainingStatus::ACTIVE_TRAINING)
        );

        // And from nowhere else.
        $this->assertFalse(
            TrainingStatus::AWAITING_MENTOR->isAssignableFrom(TrainingStatus::IN_QUEUE)
        );
        $this->assertFalse(
            TrainingStatus::AWAITING_MENTOR->isAssignableFrom(TrainingStatus::PRE_TRAINING)
        );
    }

    #[Test]
    public function saving_a_training_without_touching_the_status_is_always_allowed(): void
    {
        // The status field is submitted with every save of the details form, so
        // a rule that rejected the CURRENT value would make a training in a
        // system stage impossible to edit at all.
        foreach (TrainingStatus::cases() as $status) {
            $this->assertTrue(
                $status->isAssignableFrom($status),
                "{$status->name} cannot be saved as itself"
            );
        }
    }

    #[Test]
    public function the_interest_chase_still_reaches_people_waiting_for_a_mentor(): void
    {
        // SendTrainingInterestNotifications used to bound on `<= PRE_TRAINING`.
        // With AWAITING_MENTOR appended as 4 that would silently exclude the
        // people who have been waiting longest -- exactly who should be asked
        // whether they are still interested. It is a whereIn now, and this is
        // the thing that catches a revert to a range.
        $source = file_get_contents(app_path('Console/Commands/SendTrainingInterestNotifications.php'));

        $this->assertStringNotContainsString("'<=', TrainingStatus::PRE_TRAINING", $source);
        $this->assertStringContainsString('TrainingStatus::AWAITING_MENTOR', $source);
    }

    #[Test]
    public function the_theoretical_exam_task_type_is_gone(): void
    {
        // Task types are directory-scanned, so deleting the file removes the
        // option. VATSSA's theory exam lives inside the Moodle course; there is
        // no access to grant, and an option nobody should ever pick is worse
        // than no option.
        $this->assertFileDoesNotExist(app_path('Tasks/Types/TheoreticalExam.php'));

        $types = array_map(
            fn ($type) => $type::class,
            TaskController::getTypes()
        );
        $this->assertNotContains('App\\Tasks\\Types\\TheoreticalExam', $types);
    }

    #[Test]
    public function manual_training_creation_has_its_own_permission(): void
    {
        // Upstream gates this on fir.management.reports.view, which ALSO opens
        // the training request queue. Narrowing it there would take the queue
        // away from the coordinators who work out of it every day.
        $matrix = app(PermissionMatrix::class);

        $this->assertContains('training.create.manual', config('roles.permissions'));
        $this->assertSame(
            ['admin', 'atc-training-manager'],
            $matrix->rolesFor('training.create.manual')
        );
    }

    #[Test]
    public function only_the_training_manager_sees_theory_marks(): void
    {
        // Two tiers, deliberately. A coordinator needs to know somebody failed
        // twice; they do not need to know the mark was 62%.
        $matrix = app(PermissionMatrix::class);

        $this->assertContains('pipeline-coordinator', $matrix->rolesFor('training.results.view'));
        $this->assertNotContains('pipeline-coordinator', $matrix->rolesFor('training.results.grades'));
        $this->assertContains('atc-training-manager', $matrix->rolesFor('training.results.grades'));
    }

    #[Test]
    public function mentors_cannot_see_theory_results_at_all(): void
    {
        // Mentors see their own students' training, not the division's exam
        // record. Nothing in the mentor role expands to a wildcard, so this
        // catches a stray addition rather than a wildcard leak.
        $matrix = app(PermissionMatrix::class);

        $this->assertNotContains('mentor', $matrix->rolesFor('training.results.view'));
        $this->assertNotContains('mentor', $matrix->rolesFor('tasks.overview'));
    }

    #[Test]
    public function the_endorsement_rosters_are_staff_only(): void
    {
        // Upstream ships indexSolos, indexExaminers and indexVisitors with NO
        // authorize() call, so any logged-in member can read every solo,
        // examiner and visiting endorsement in the division. Who examines is
        // not something VATSSA publishes.
        $matrix = app(PermissionMatrix::class);

        $this->assertSame(
            ['admin', 'atc-training-manager', 'pipeline-coordinator'],
            $matrix->rolesFor('endorsements.rosters.view')
        );
    }

    #[Test]
    public function the_public_roster_never_names_an_examiner(): void
    {
        // Absent from the payload rather than filtered by the caller. An
        // endpoint that hands out something sensitive and trusts every
        // consumer to drop it will eventually meet one that does not.
        $source = file_get_contents(app_path('Http/Controllers/Vatssa/RosterController.php'));

        $this->assertStringNotContainsString("'examiner' =>", $source);
        $this->assertStringContainsString("'visiting' =>", $source);
        $this->assertStringContainsString("'solo' =>", $source);
    }


    // ---------------------------------------------------------------------
    // Request routing
    // ---------------------------------------------------------------------

    #[Test]
    public function a_request_goes_to_the_desk_not_to_whoever_was_typed(): void
    {
        // The whole point. Upstream takes the assignee from the form; the
        // observer replaces it with whoever staffs the desk that was chosen.
        $this->seedFixtures();

        $requester = User::find(10000006);
        $coordinator = User::find(10000008);
        $rating = Rating::whereNotNull('vatsim_rating')->first();

        RequestTarget::create([
            'tier' => RequestTarget::COORDINATOR,
            'rating_id' => $rating->id,
            'user_id' => $coordinator->id,
        ]);

        $training = Training::where('user_id', 10000304)->firstOrFail();

        $task = Task::create([
            'type' => \App\Tasks\Types\LeaveOfAbsence::class,
            'vatssa_tier' => RequestTarget::COORDINATOR,
            'vatssa_rating_id' => $rating->id,
            'subject_user_id' => $training->user_id,
            'subject_training_id' => $training->id,
            'assignee_user_id' => $requester->id,   // what the form posted
            'creator_user_id' => $requester->id,
        ]);

        $this->assertSame($coordinator->id, $task->assignee_user_id);
    }

    #[Test]
    public function an_empty_desk_leaves_the_request_with_the_requester(): void
    {
        // Not silent, but visible. A request that landed in the wrong place
        // gets chased; one auto-assigned to an arbitrary role-holder looks
        // handled and is not. assignee_user_id is NOT NULL, so there is no
        // third option.
        $this->seedFixtures();

        $requester = User::find(10000006);
        $training = Training::where('user_id', 10000304)->firstOrFail();

        $task = Task::create([
            'type' => \App\Tasks\Types\LeaveOfAbsence::class,
            'vatssa_tier' => RequestTarget::VATSSA1,     // nobody assigned
            'subject_user_id' => $training->user_id,
            'subject_training_id' => $training->id,
            'assignee_user_id' => $requester->id,
            'creator_user_id' => $requester->id,
        ]);

        $this->assertSame($requester->id, $task->assignee_user_id);
    }

    #[Test]
    public function the_coordinator_desk_is_per_rating(): void
    {
        // VATSSA runs a pipeline per rating, so "the S2 coordinator" is a
        // different person from "the C1 coordinator". Control Center scopes
        // roles by AREA, which is why this needed its own table.
        $this->seedFixtures();

        $ratings = Rating::whereNotNull('vatsim_rating')->orderBy('vatsim_rating')->take(2)->get();
        $this->assertCount(2, $ratings, 'need two ratings for this test');

        RequestTarget::create(['tier' => RequestTarget::COORDINATOR,
            'rating_id' => $ratings[0]->id, 'user_id' => 10000008]);
        RequestTarget::create(['tier' => RequestTarget::COORDINATOR,
            'rating_id' => $ratings[1]->id, 'user_id' => 10000005]);

        $this->assertSame(10000008, RequestTarget::nextAt(RequestTarget::COORDINATOR, $ratings[0]->id)?->id);
        $this->assertSame(10000005, RequestTarget::nextAt(RequestTarget::COORDINATOR, $ratings[1]->id)?->id);
    }

    #[Test]
    public function a_catch_all_coordinator_covers_every_rating(): void
    {
        // A division with one coordinator should not have to fill in four
        // identical rows.
        $this->seedFixtures();

        RequestTarget::create(['tier' => RequestTarget::COORDINATOR,
            'rating_id' => null, 'user_id' => 10000008]);

        $rating = Rating::whereNotNull('vatsim_rating')->first();

        $this->assertSame(10000008, RequestTarget::nextAt(RequestTarget::COORDINATOR, $rating->id)?->id);
    }

    #[Test]
    public function the_missing_request_types_exist(): void
    {
        // STATE.md lists five student requests. Three had no type at all, so
        // they would have arrived as free-text Custom Requests -- exactly the
        // arbitrariness the desks are meant to remove.
        $types = array_map(fn ($type) => $type::class, TaskController::getTypes());

        $this->assertContains(\App\Tasks\Types\LeaveOfAbsence::class, $types);
        $this->assertContains(\App\Tasks\Types\ReturnFromLeave::class, $types);
        $this->assertContains(\App\Tasks\Types\CheckoutRequest::class, $types);
    }

    // ---------------------------------------------------------------------
    // Status transitions
    // ---------------------------------------------------------------------

    #[Test]
    public function nothing_advances_out_of_pre_training_by_hand(): void
    {
        // Pre-training means the theory is not passed. Advancing somebody past
        // it manually asserts they passed an exam they did not sit.
        foreach ([TrainingStatus::ACTIVE_TRAINING, TrainingStatus::AWAITING_MENTOR,
            TrainingStatus::AWAITING_EXAM, TrainingStatus::IN_QUEUE] as $target) {
            $this->assertFalse(
                $target->isAssignableFrom(TrainingStatus::PRE_TRAINING),
                "{$target->name} should not be settable from pre-training"
            );
        }
    }

    #[Test]
    public function a_pre_training_student_can_still_be_closed(): void
    {
        // A student who drops out during theory has to be closable, and
        // upstream closes them automatically when the interest confirmation
        // goes unanswered. Closing is not progress.
        $this->assertTrue(
            TrainingStatus::CLOSED_BY_STAFF->isAssignableFrom(TrainingStatus::PRE_TRAINING)
        );
    }

    #[Test]
    public function a_lost_mentor_sends_a_student_back_to_the_queue(): void
    {
        // From active training, and from awaiting exam too -- a student waiting
        // on a CPT whose mentor disappears needs this just as much.
        $this->assertTrue(
            TrainingStatus::AWAITING_MENTOR->isAssignableFrom(TrainingStatus::ACTIVE_TRAINING)
        );
        $this->assertTrue(
            TrainingStatus::AWAITING_MENTOR->isAssignableFrom(TrainingStatus::AWAITING_EXAM)
        );
    }

    #[Test]
    public function the_training_manager_no_longer_audits_roles_or_edits_positions(): void
    {
        // Trimmed 2026-08-29. The access report needed its own permission
        // first: upstream gated it on fir.management.reports.view, which ALSO
        // opens the training request queue, so it could not be taken away
        // without taking the queue with it.
        $matrix = app(PermissionMatrix::class);

        foreach (['fir.management.access.view', 'users.access.view', 'fir.positions.view'] as $permission) {
            $this->assertNotContains('atc-training-manager', $matrix->rolesFor($permission), $permission);
            $this->assertNotContains('pipeline-coordinator', $matrix->rolesFor($permission), $permission);
        }

        // And the queue itself survived the split, which was the whole risk.
        $this->assertContains('atc-training-manager', $matrix->rolesFor('fir.management.reports.view'));
        $this->assertContains('pipeline-coordinator', $matrix->rolesFor('fir.management.reports.view'));
    }

    #[Test]
    public function the_bridge_refuses_everything_without_a_token(): void
    {
        // An unconfigured VATSSA_BRIDGE_TOKEN must never mean "let everyone in".
        // This is the property that makes shipping the routes before the bot
        // exists safe.
        config(['vatssa.bridge_token' => null]);

        $this->postJson('/api/vatssa/bridge/users/1/platforms', [
            'on_discord' => true,
            'on_moodle' => true,
        ])->assertStatus(401);
    }

    #[Test]
    public function the_bridge_refuses_a_wrong_token(): void
    {
        config(['vatssa.bridge_token' => 'the-real-one']);

        $this->withHeader('Authorization', 'Bearer not-the-real-one')
            ->postJson('/api/vatssa/bridge/users/1/platforms', [
                'on_discord' => true,
                'on_moodle' => true,
            ])->assertStatus(401);
    }

    #[Test]
    public function the_bridge_writes_platforms_idempotently(): void
    {
        // The bot re-reads the same state on every sweep by design, so downtime
        // costs freshness rather than data. Writes that were not idempotent
        // would turn that into duplicates.
        config(['vatssa.bridge_token' => 'test-token']);
        $user = User::factory()->create();

        $payload = [
            'discord_user_id' => 123456789012345678,
            'on_discord' => true,
            'on_moodle' => false,
            'vatsim_member' => true,
        ];

        for ($i = 0; $i < 2; $i++) {
            $this->withHeader('Authorization', 'Bearer test-token')
                ->postJson("/api/vatssa/bridge/users/{$user->id}/platforms", $payload)
                ->assertOk();
        }

        $this->assertSame(1, UserPlatform::where('user_id', $user->id)->count());
        $this->assertTrue(UserPlatform::find($user->id)->on_discord);
    }

    #[Test]
    public function a_theory_pass_is_the_latest_attempt_not_the_best(): void
    {
        // The rule the whole gate rests on. Somebody who passed two years ago
        // and failed a retake last week does not currently know the material.
        $user = User::factory()->create();

        TheoryAttempt::create([
            'user_id' => $user->id, 'rating' => 'S2',
            'moodle_course_id' => 14, 'moodle_quiz_id' => 52, 'moodle_attempt_id' => 1,
            'grade' => 95, 'passed' => true, 'taken_at' => now()->subYear(),
        ]);
        TheoryAttempt::create([
            'user_id' => $user->id, 'rating' => 'S2',
            'moodle_course_id' => 14, 'moodle_quiz_id' => 52, 'moodle_attempt_id' => 2,
            'grade' => 40, 'passed' => false, 'taken_at' => now()->subWeek(),
        ]);

        $this->assertFalse(TheoryAttempt::passedRating($user->id, 'S2'));

        TheoryAttempt::create([
            'user_id' => $user->id, 'rating' => 'S2',
            'moodle_course_id' => 14, 'moodle_quiz_id' => 52, 'moodle_attempt_id' => 3,
            'grade' => 82, 'passed' => true, 'taken_at' => now(),
        ]);

        $this->assertTrue(TheoryAttempt::passedRating($user->id, 'S2'));
    }

    #[Test]
    public function a_theory_result_survives_the_training_that_produced_it(): void
    {
        // Keyed to person plus rating, never to a training. Close a training,
        // open a new one, and the pass is still there -- because the training
        // looks the result up rather than owning it.
        $user = User::factory()->create();

        TheoryAttempt::create([
            'user_id' => $user->id, 'rating' => 'S2',
            'moodle_course_id' => 14, 'moodle_quiz_id' => 52, 'moodle_attempt_id' => 1,
            'grade' => 88, 'passed' => true, 'taken_at' => now()->subMonths(6),
        ]);

        $columns = Schema::getColumnListing('vatssa_theory_attempts');
        $this->assertNotContains('training_id', $columns);
        $this->assertTrue(TheoryAttempt::passedRating($user->id, 'S2'));
    }

    #[Test]
    public function an_unconfigured_moodle_course_is_left_out_of_the_map(): void
    {
        // Placeholder ids must not read as "this student has no attempts",
        // which is indistinguishable from a room full of failures. Dropping the
        // rating instead makes it visibly need no theory, which gets fixed.
        MoodleCourse::create(['rating' => 'S2', 'course_id' => 14, 'exam_quiz_id' => 52, 'pass_mark' => 80]);
        MoodleCourse::create(['rating' => 'S3', 'course_id' => 0, 'exam_quiz_id' => 0, 'pass_mark' => 80]);

        $map = MoodleCourse::map();

        $this->assertArrayHasKey('S2', $map);
        $this->assertArrayNotHasKey('S3', $map);
    }

    #[Test]
    public function task_routing_is_inert_until_it_is_configured(): void
    {
        // It ships with an empty map on purpose. A routing table guessed at
        // rather than agreed would send real requests to the wrong people,
        // quietly -- worse than the manual choice it replaces.
        $this->assertSame([], config('vatssa.task_routing'));
    }


    // ---------------------------------------------------------------------
    // The pipeline cohort
    // ---------------------------------------------------------------------

    #[Test]
    public function the_pipeline_seeder_covers_every_stage(): void
    {
        // The whole point: click through the pipeline with no bot, bridge,
        // Moodle or Discord running. If a stage has nobody in it, that page
        // cannot be looked at.
        $this->seedFixtures();

        $stages = Training::whereBetween('user_id', [10000301, 10000310])
            ->pluck('status')
            ->unique();

        foreach ([
            TrainingStatus::IN_QUEUE,
            TrainingStatus::PRE_TRAINING,
            TrainingStatus::AWAITING_MENTOR,
            TrainingStatus::ACTIVE_TRAINING,
            TrainingStatus::AWAITING_EXAM,
            TrainingStatus::COMPLETED,
        ] as $stage) {
            $this->assertTrue(
                $stages->contains($stage),
                "No seeded student is in {$stage->name}"
            );
        }
    }

    #[Test]
    public function every_seeded_user_has_a_platform_row(): void
    {
        // VatssaSeeder makes 250 users. Without the backfill every one of them
        // has an empty Platforms panel, so every profile on dev looks broken
        // and the deliberately interesting students are lost among them.
        $this->seedFixtures();

        $this->assertSame(User::count(), UserPlatform::count());
    }

    #[Test]
    public function the_backfill_puts_real_trainings_into_awaiting_mentor(): void
    {
        // TrainingFactory rolls a status between -4 and 3, so it can NEVER
        // produce AWAITING_MENTOR (4, appended rather than inserted). Without
        // the promotion step, the one stage this fork exists to add would be
        // the only empty page on dev.
        $this->seedFixtures();

        $promoted = Training::where('status', TrainingStatus::AWAITING_MENTOR)
            ->whereNotBetween('user_id', [10000301, 10000400])
            ->count();

        $this->assertGreaterThan(0, $promoted);
    }

    #[Test]
    public function nobody_in_training_is_missing_a_theory_pass(): void
    {
        // Fixtures that contradict the rules are worse than no fixtures: a
        // student in active training with no pass looks like a bug in the gate
        // rather than a gap in the seed data.
        $this->seedFixtures();

        $inTraining = Training::where('type', 1)
            ->whereIn('status', [
                TrainingStatus::AWAITING_MENTOR,
                TrainingStatus::ACTIVE_TRAINING,
                TrainingStatus::AWAITING_EXAM,
            ])
            ->get();

        $this->assertGreaterThan(0, $inTraining->count(), 'nothing to check');

        foreach ($inTraining as $training) {
            $this->assertTrue(
                TheoryAttempt::where('user_id', $training->user_id)
                    ->where('passed', true)->exists(),
                "Training {$training->id} is past the gate with no theory pass"
            );
        }
    }

    #[Test]
    public function the_non_standard_tracks_sit_no_theory(): void
    {
        // Visiting, transfer and refresher controllers already hold the rating.
        // Giving them attempts would misrepresent the rule the map encodes.
        $this->seedFixtures();

        $others = Training::where('type', '!=', 1)
            ->where('status', '>=', TrainingStatus::IN_QUEUE)
            ->pluck('user_id')
            ->diff(Training::where('type', 1)->pluck('user_id'));

        // ONE assertion, always made. A foreach over a collection that happens
        // to be empty asserts nothing at all and PHPUnit marks it risky --
        // which is exactly what happened the first time this ran.
        $this->assertSame(
            0,
            TheoryAttempt::whereIn('user_id', $others)->count(),
            'A non-standard track has theory attempts, which it should never have'
        );
    }

    #[Test]
    public function every_open_training_has_an_email_history(): void
    {
        $this->seedFixtures();

        $open = Training::where('status', '>=', TrainingStatus::IN_QUEUE);

        // Prove there is something to check before checking it. Zero open
        // trainings would otherwise satisfy this test by having nothing to
        // fail on.
        $this->assertGreaterThan(0, (clone $open)->count(), 'no open trainings were seeded');

        $withoutLog = (clone $open)
            ->whereNotIn('id', MessageLog::query()->whereNotNull('training_id')->pluck('training_id'))
            ->count();

        $this->assertSame(0, $withoutLog);
    }

    #[Test]
    public function the_seeder_fills_the_task_board(): void
    {
        // Every student and mentor request in Control Center is a Task. Without
        // these the Tasks page is empty for everybody, and the VATSSA overview
        // tab has nothing to show -- which is the one thing that cannot be
        // judged from a single person's inbox.
        $this->seedFixtures();

        $this->assertGreaterThan(0, Task::where('status', TaskStatus::PENDING)->count());

        // Spread across more than one desk, or the overview proves nothing.
        $this->assertGreaterThan(
            1,
            Task::where('status', TaskStatus::PENDING)->distinct()->count('assignee_user_id'),
            'every seeded task landed on the same person'
        );

        // And some closed ones, so the Archived tab is not empty either.
        $this->assertGreaterThan(0, Task::where('status', TaskStatus::COMPLETED)->count());
    }

    #[Test]
    public function the_pipeline_seeder_is_safe_to_run_twice(): void
    {
        // deploy-cc.sh runs this on EVERY dev and staging deploy, unlike
        // VatssaSeeder which returns early once there are users. Every write is
        // keyed for exactly this reason.
        $this->seedFixtures();

        $users = User::count();
        $attempts = TheoryAttempt::count();
        $messages = DB::table('vatssa_message_log')->count();

        $this->seed(VatssaPipelineSeeder::class);

        $this->assertSame($users, User::count());
        $this->assertSame($attempts, TheoryAttempt::count());
        $this->assertSame($messages, DB::table('vatssa_message_log')->count());
    }

    #[Test]
    public function the_retake_student_reads_as_currently_passed(): void
    {
        // Student 10000305 exists to make "latest, not best" visible: pass,
        // fail, pass. All three attempts show, and the person is through the
        // gate -- and would NOT be if the middle attempt were the last one.
        $this->seedFixtures();

        $this->assertSame(3, TheoryAttempt::where('user_id', 10000305)->count());
        $this->assertTrue(TheoryAttempt::passedRating(10000305, 'S2'));

        // And the one who only ever failed is not.
        $this->assertFalse(TheoryAttempt::passedRating(10000303, 'S2'));
    }

    #[Test]
    public function the_cohort_includes_an_account_that_is_not_a_vatsim_member(): void
    {
        // A bot or a test account. It is its own state, not a missing tick, and
        // the profile panel has to be able to show it.
        $this->seedFixtures();

        $this->assertFalse(UserPlatform::find(10000310)->vatsim_member);
        $this->assertTrue(UserPlatform::find(10000304)->vatsim_member);
    }

    #[Test]
    public function the_pipeline_seeder_refuses_a_database_of_real_members(): void
    {
        // THE guard that matters, and the one APP_ENV does not give you.
        // deploy-cc.sh runs this seeder on staging as well as dev, and Phase B
        // of the migration puts a copy of PRODUCTION DATA on staging to
        // rehearse against. Staging is still APP_ENV=staging at that moment,
        // so the environment check passes and this would write exam results
        // and emails that never happened against real members' names.
        //
        // The test is on the data: the VatssaSeeder dev accounts exist only in
        // a seeded database.
        $realMember = User::factory()->create(['id' => 1234567]);

        $this->seed(VatssaPipelineSeeder::class);

        $this->assertSame(0, UserPlatform::count());
        $this->assertSame(0, TheoryAttempt::count());
        $this->assertNull(UserPlatform::find($realMember->id));
    }

    #[Test]
    public function the_pipeline_seeder_refuses_to_run_in_production(): void
    {
        // It invents students, exam results and emails that never happened.
        // Called directly rather than through $this->seed(). The artisan
        // seed command wraps the seeder in a mocked console command, and the
        // mock explodes on the first ->warn() or ->info() -- which is a
        // BadMethodCall, not the RuntimeException this test is about.
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);

        (new VatssaPipelineSeeder)->run();
    }

    #[Test]
    public function the_seeded_templates_and_courses_are_present(): void
    {
        // Seeded by migration, not by a seeder, because they are real content
        // rather than fixtures -- both admin pages are empty without them, in
        // production too.
        $this->assertSame(17, MessageTemplate::count());
        $this->assertNotNull(MessageTemplate::find('T7'));

        $this->assertSame(4, MoodleCourse::count());
    }

    #[Test]
    public function the_seeded_moodle_courses_start_unconfigured(): void
    {
        // Nobody has read the ids out of Moodle yet, so the map must be EMPTY
        // rather than wrong. Inventing ids would give every student no
        // attempts, which is indistinguishable from a room full of failures.
        $this->assertSame([], MoodleCourse::map());
    }

    #[Test]
    public function the_seeder_is_safe_to_run_twice(): void
    {
        // deploy-cc.sh calls the seeder on every dev and staging deploy, so this
        // property is load-bearing, not a nicety. The fixed accounts carry
        // hardcoded CIDs and a second insert would collide.
        $this->seed(VatssaSeeder::class);
        $after = User::count();

        $this->seed(VatssaSeeder::class);

        $this->assertSame($after, User::count());
    }

    #[Test]
    public function the_seeder_refuses_to_run_in_production(): void
    {
        // The one guard that stands between a mistyped artisan command and
        // eleven fake controllers in the live member list.
        // Direct, for the same reason as the pipeline seeder above: the
        // artisan wrapper's mocked command turns any console output into a
        // BadMethodCall before the guard can throw.
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);

        (new VatssaSeeder)->run();
    }
}
