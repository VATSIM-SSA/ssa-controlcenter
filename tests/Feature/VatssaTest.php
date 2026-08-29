<?php

namespace Tests\Feature;

use App\Helpers\TrainingStatus;
use App\Models\Area;
use App\Models\Position;
use App\Models\Training;
use App\Models\User;
use App\Models\Vatssa\MessageTemplate;
use App\Models\Vatssa\MoodleCourse;
use App\Models\Vatssa\TheoryAttempt;
use App\Models\Vatssa\UserPlatform;
use App\Services\PermissionMatrix;
use Database\Seeders\VatssaPipelineSeeder;
use Database\Seeders\VatssaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
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
            \App\Http\Controllers\TaskController::getTypes()
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

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('vatssa_theory_attempts');
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
        // The whole point of the cohort: click through the pipeline with no
        // bot, bridge, Moodle or Discord running. If a stage has nobody in it,
        // that page cannot be looked at.
        $this->seed(VatssaSeeder::class);
        $this->seed(VatssaPipelineSeeder::class);

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
    public function the_pipeline_seeder_is_safe_to_run_twice(): void
    {
        // deploy-cc.sh runs this on EVERY dev and staging deploy, unlike
        // VatssaSeeder which returns early once there are users. Every write is
        // keyed for exactly this reason.
        $this->seed(VatssaSeeder::class);
        $this->seed(VatssaPipelineSeeder::class);

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
        $this->seed(VatssaSeeder::class);
        $this->seed(VatssaPipelineSeeder::class);

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
        $this->seed(VatssaSeeder::class);
        $this->seed(VatssaPipelineSeeder::class);

        $this->assertFalse(UserPlatform::find(10000310)->vatsim_member);
        $this->assertTrue(UserPlatform::find(10000304)->vatsim_member);
    }

    #[Test]
    public function the_pipeline_seeder_refuses_to_run_in_production(): void
    {
        // It invents students, exam results and emails that never happened.
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);

        $this->seed(VatssaPipelineSeeder::class);
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
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);

        $this->seed(VatssaSeeder::class);
    }
}
