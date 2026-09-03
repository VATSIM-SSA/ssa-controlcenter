<?php

namespace Database\Seeders;

use App\Helpers\FactoryHelper;
use App\Helpers\TaskStatus;
use App\Helpers\TrainingStatus;
use App\Helpers\VatsimRating;
use App\Helpers\Vatssa\MembershipRequestState;
use App\Helpers\Vatssa\MembershipRequestType;
use App\Helpers\Vatssa\TerminalLogReason;
use App\Helpers\Vatssa\TerminalLogType;
use App\Http\Controllers\TaskController;
use App\Models\Rating;
use App\Models\Task;
use App\Models\Training;
use App\Models\TrainingActivity;
use App\Models\User;
use App\Models\Vatssa\ActionLog;
use App\Models\Vatssa\AvailabilityPoll;
use App\Models\Vatssa\AvailabilityResponse;
use App\Models\Vatssa\Confirmation;
use App\Models\Vatssa\MembershipRequest;
use App\Models\Vatssa\MessageLog;
use App\Models\Vatssa\RosterWarning;
use App\Models\Vatssa\TerminalLogEntry;
use App\Models\Vatssa\TheoryAttempt;
use App\Models\Vatssa\UserPlatform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: make the training pipeline usable on dev, with nothing else running.
 *
 *   php artisan db:seed --class=VatssaPipelineSeeder
 *
 * ## What this is for
 *
 * Everything the pipeline writes into Control Center normally arrives over the
 * bridge, from a bot polling Moodle and Discord. Standing all that up before
 * you can answer "does the training page look right for somebody waiting on a
 * mentor" is absurd. This writes the same rows directly. **No bot, no bridge,
 * no Moodle, no Discord.**
 *
 * ## It backfills what is already there
 *
 * `VatssaSeeder` creates 250 users and around 110 trainings. Without this they
 * all have an empty Platforms panel, no theory results and no email log -- so
 * every page you open looks broken, and the two or three deliberately
 * interesting students are lost among them.
 *
 * So this runs in two parts:
 *
 * 1. **The backfill.** Every user gets a platform row and every open standard
 *    training gets theory attempts and an email history, consistent with the
 *    stage it is in. Browsing dev then looks like browsing a real division.
 * 2. **The named cohort**, on CIDs 10000301-10000312. Twelve students built by
 *    hand, one per situation worth looking at on purpose.
 *
 *    Two of them are the halves of the same problem and look nothing alike in
 *    practice: 10000310 is on Moodle only and has sat the exam anyway, so they
 *    are progressing invisibly; 10000311 is on Discord only and cannot start at
 *    all, and nobody has noticed. The platform panel is the only place either
 *    shows.
 *
 * ## The factory cannot produce awaiting-mentor
 *
 * `TrainingFactory` rolls a status between -4 and 3, and AWAITING_MENTOR is 4
 * (appended rather than inserted -- see `App\Helpers\TrainingStatus`). So the
 * backfill MOVES a share of pre-training rows into it. Without that, the one
 * stage this whole fork exists to add would be the only empty page on dev.
 *
 * ## Two of the named ten are worth staring at
 *
 * **10000305** has three attempts: pass, fail, pass. All three show, and the
 * person reads as currently passed -- and would read as failed if the middle
 * one were last. That is the whole "latest, not best" rule on one page.
 *
 * **10000310** resolves to no CID. That is a bot or a test account: its own
 * state, not a missing tick.
 *
 * ## Guards
 *
 * Two, and the second is the one that matters.
 *
 * It refuses when `APP_ENV=production`. That is the obvious one, and on its own
 * it is not enough: `deploy-cc.sh` runs this on dev AND staging, and Phase B of
 * the migration puts a copy of production data on staging to rehearse against.
 * Staging is still `APP_ENV=staging` at that moment.
 *
 * So it also refuses unless the database looks like fixtures -- the VatssaSeeder
 * dev accounts have to be present. Otherwise this would write exam results and
 * emails that never happened against real members' names. `VATSSA_SEED_FORCE=1`
 * overrides it, deliberately awkwardly.
 *
 * Every write is keyed, so re-running updates in place rather than duplicating,
 * which is why the deploy script can call it unconditionally.
 */
class VatssaPipelineSeeder extends Seeder
{
    private const FIRST_CID = 10000301;

    /** Standard track. The only one that sits theory. */
    private const TYPE_STANDARD = 1;

    /**
     * The named ten. `theory` is a list of [rating, grade, passed, days ago].
     */
    private const COHORT = [
        [
            'name' => 'Queue Only',
            'status' => TrainingStatus::IN_QUEUE,
            'platforms' => ['discord' => false, 'moodle' => false],
            'theory' => [],
            'note' => 'Just registered. Nothing has happened yet.',
        ],
        [
            'name' => 'Theory Started',
            'status' => TrainingStatus::PRE_TRAINING,
            // Registered on Moodle but NEVER ENROLLED in a course. Two green
            // ticks on the old panel and nothing happening -- the stall the
            // enrolment indicator exists to make visible.
            'platforms' => ['discord' => true, 'moodle' => true, 'enrolment' => null],
            'theory' => [],
            'note' => 'Registered on Moodle but never enrolled in a course. Stalled, invisibly.',
        ],
        [
            'name' => 'Theory Failed',
            'status' => TrainingStatus::PRE_TRAINING,
            'platforms' => ['discord' => true, 'moodle' => true],
            'theory' => [['S2', 48.0, false, 12]],
            'note' => 'Failed once. Still has time to retake.',
        ],
        [
            'name' => 'Awaiting Mentor',
            'status' => TrainingStatus::AWAITING_MENTOR,
            'platforms' => ['discord' => true, 'moodle' => true],
            'theory' => [['S2', 84.0, true, 20]],
            'note' => 'Passed, waiting for a mentor. The stage upstream cannot express.',
        ],
        [
            'name' => 'Retake Story',
            'status' => TrainingStatus::AWAITING_MENTOR,
            'platforms' => ['discord' => true, 'moodle' => true],
            'theory' => [
                ['S2', 91.0, true, 400],
                ['S2', 39.0, false, 45],
                ['S2', 80.0, true, 6],
            ],
            'note' => 'Pass, fail, pass. Latest counts -- currently passed.',
        ],
        [
            'name' => 'In Training',
            'status' => TrainingStatus::ACTIVE_TRAINING,
            'platforms' => ['discord' => true, 'moodle' => true],
            'theory' => [['S2', 77.0, true, 90]],
            'note' => 'Mentored. Past the gate, so a later attempt would not matter.',
        ],
        [
            'name' => 'Awaiting Exam',
            'status' => TrainingStatus::AWAITING_EXAM,
            'platforms' => ['discord' => true, 'moodle' => true],
            'theory' => [['S2', 86.0, true, 150]],
            'note' => 'Ready for a CPT.',
        ],
        [
            'name' => 'Rated Controller',
            'status' => TrainingStatus::COMPLETED,
            'platforms' => ['discord' => true, 'moodle' => true],
            'theory' => [['S2', 88.0, true, 300]],
            'note' => 'Passed the CPT. The theory row outlives the training.',
        ],
        [
            'name' => 'Left Discord',
            'status' => TrainingStatus::PRE_TRAINING,
            'platforms' => ['discord' => false, 'moodle' => true],
            'theory' => [],
            'note' => 'On Moodle, gone from Discord. The chase case.',
        ],
        [
            'name' => 'Moodle Orphan',
            'status' => TrainingStatus::PRE_TRAINING,
            'platforms' => ['discord' => false, 'moodle' => true],
            // Registered AND enrolled -- they are working, just unreachable.
            'theory' => [['S2', 71.0, false, 8]],
            'note' => 'On Moodle, never joined Discord. Has sat the exam anyway.',
        ],
        [
            'name' => 'Discord Orphan',
            'status' => TrainingStatus::IN_QUEUE,
            'platforms' => ['discord' => true, 'moodle' => false],
            'theory' => [],
            'note' => 'On Discord, never registered on Moodle. Cannot start theory.',
        ],
        [
            'name' => 'Not Vatsim',
            'status' => TrainingStatus::PRE_TRAINING,
            'platforms' => ['discord' => true, 'moodle' => false, 'vatsim_member' => false],
            'theory' => [],
            'note' => 'Resolves to no CID -- a bot, or a test account. Not a missing tick.',
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'VatssaPipelineSeeder refuses to run with APP_ENV=production. '
                . 'It invents students, exam results and emails that never happened.'
            );
        }

        $this->assertTablesExist();

        if (! $this->isFixtureDatabase()) {
            $this->command?->warn(
                'VatssaPipelineSeeder: this does not look like a fixture database '
                . '(the VatssaSeeder dev accounts are missing), so nothing was written. '
                . 'Set VATSSA_SEED_FORCE=1 if you really mean to invent training '
                . 'records for these members.'
            );

            return;
        }

        $this->seedCohort();
        $this->promoteSomeToAwaitingMentor();
        $this->backfillPlatforms();
        $this->backfillTheory();
        $this->seedTasks();
        $this->backfillMessageLog();
        $this->backfillTimelines();
        $this->seedActionLog();
        $this->seedConfirmations();
        $this->seedStandalonePolls();
        $this->seedRosterWarnings();
        $this->seedMembershipRequests();
        $this->seedTerminalLog();

        $this->command?->info(sprintf(
            'VatssaPipelineSeeder: %d platform rows, %d theory attempts, %d logged emails, '
            . '%d open tasks, %d timeline entries, %d confirmations, %d polls, '
            . '%d membership requests, %d Terminal log rows. '
            . 'Named cohort on CIDs %d-%d.',
            UserPlatform::count(),
            TheoryAttempt::count(),
            MessageLog::count(),
            Task::where('status', TaskStatus::PENDING)->count(),
            TrainingActivity::count(),
            Confirmation::count(),
            AvailabilityPoll::count(),
            MembershipRequest::count(),
            TerminalLogEntry::count(),
            self::FIRST_CID,
            self::FIRST_CID + count(self::COHORT) - 1
        ));
    }

    /**
     * Whether this database is fixtures rather than real people.
     *
     * APP_ENV IS NOT ENOUGH, AND THIS IS THE WHOLE POINT OF THE METHOD.
     * `deploy-cc.sh` runs this seeder on dev AND staging, and Phase B of the
     * migration puts a copy of PRODUCTION DATA on staging to rehearse against.
     * At that moment staging is still `APP_ENV=staging`, the environment check
     * passes, and this would invent theory attempts and emails for real
     * members -- writing exam results that never happened against people's
     * names.
     *
     * So the test is on the data, not on the branch or the environment. The
     * VatssaSeeder dev accounts (10000001-10000011) exist only in a seeded
     * database. If they are missing, this is somebody's real member list.
     *
     * `VatssaSeeder` gets away without this because it returns early whenever
     * the database already has users. This one backfills existing rows, so
     * that guard does not apply to it and it needs its own.
     */
    private function isFixtureDatabase(): bool
    {
        if (env('VATSSA_SEED_FORCE')) {
            return true;
        }

        return User::whereBetween('id', [10000001, 10000011])->count() >= 10;
    }

    /**
     * Fail loudly rather than half-seeding. Without the pipeline tables this
     * would write users and trainings and then throw, leaving a database that
     * is neither seeded nor clean.
     */
    private function assertTablesExist(): void
    {
        foreach (['vatssa_user_platforms', 'vatssa_theory_attempts', 'vatssa_message_log'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                throw new \RuntimeException(
                    "Table {$table} is missing. Run the VATSSA migrations first: "
                    . 'php artisan migrate --path=database/migrations-vatssa'
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // The named ten
    // -----------------------------------------------------------------

    private function seedCohort(): void
    {
        $trainingRating = Rating::where('name', 'S2')->first()
            ?? Rating::whereNotNull('vatsim_rating')->orderBy('vatsim_rating')->first();

        foreach (self::COHORT as $index => $spec) {
            [$first, $last] = explode(' ', $spec['name']);

            $user = $this->cohortUser(self::FIRST_CID + $index, $first, $last);

            $this->writePlatforms(
                $user->id,
                $spec['platforms']['discord'],
                $spec['platforms']['moodle'],
                $spec['platforms']['vatsim_member'] ?? true,
                array_key_exists('enrolment', $spec['platforms']) ? $spec['platforms']['enrolment'] : 'active',
            );

            foreach ($spec['theory'] as $attemptIndex => [$forRating, $grade, $passed, $daysAgo]) {
                $this->writeAttempt($user->id, $forRating, $attemptIndex + 1, $grade, $passed, $daysAgo);
            }

            $training = $this->cohortTraining($user, $spec, $trainingRating);
            $this->writeMessages($training, $spec['theory'] !== []);
        }
    }

    /**
     * One of the named students.
     *
     * THROUGH THE FACTORY, NOT updateOrCreate, AND THAT IS THE POINT.
     * `users` has a run of NOT NULL columns with no defaults -- rating_short,
     * rating_long, last_login, four notification settings -- because they are
     * written at login from VATSIM Connect rather than defaulted by the schema.
     * Listing them here means chasing upstream every time it adds another, one
     * failed seed at a time. The factory is the single place that knows the
     * full set, and `VatssaSeeder` builds its fixed accounts the same way.
     *
     * Idempotency comes from creating only when the row is absent, then
     * updating the handful of fields this seeder actually cares about.
     */
    private function cohortUser(int $cid, string $first, string $last): User
    {
        $attributes = [
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower("{$first}.{$last}") . '@example.com',
            'rating' => VatsimRating::S1,
            'rating_short' => VatsimRating::S1->name,
            'rating_long' => FactoryHelper::longRating(VatsimRating::S1),
            'region' => 'EMEA',
            'division' => 'SSA',
            'subdivision' => null,
        ];

        $user = User::find($cid);

        if ($user === null) {
            return User::factory()->create($attributes + ['id' => $cid]);
        }

        $user->update($attributes);

        return $user;
    }

    private function cohortTraining(User $user, array $spec, ?Rating $rating): Training
    {
        $status = $spec['status'];

        $training = Training::updateOrCreate(
            ['user_id' => $user->id, 'area_id' => 1],
            [
                'status' => $status,
                'type' => self::TYPE_STANDARD,
                'motivation' => $spec['note'],
                'english_only_training' => false,
                'experience' => 1,
                'started_at' => $status->isInProgress() ? now()->subDays(60) : null,
                'closed_at' => $status->isClosed() ? now()->subDays(5) : null,
                'created_at' => now()->subDays(80),
            ]
        );

        if ($rating && ! $training->ratings()->where('ratings.id', $rating->id)->exists()) {
            $training->ratings()->attach($rating);
        }

        // Anybody past the gate has a mentor. Web Six holds mentor on area 1.
        if (in_array($status, [TrainingStatus::ACTIVE_TRAINING, TrainingStatus::AWAITING_EXAM], true)) {
            $mentor = User::find(10000006);
            if ($mentor && ! $training->mentors()->where('users.id', $mentor->id)->exists()) {
                $training->mentors()->attach($mentor, ['expire_at' => now()->addYears(5)]);
            }
        }

        return $training;
    }

    // -----------------------------------------------------------------
    // The backfill
    // -----------------------------------------------------------------

    /**
     * Put a share of the pre-training rows into awaiting-mentor.
     *
     * `TrainingFactory` rolls a status between -4 and 3, so it can never
     * produce AWAITING_MENTOR (4). Without this the one stage this fork exists
     * to add is the only empty page on dev.
     *
     * Only rows with no mentor move, which is what awaiting-mentor means.
     */
    private function promoteSomeToAwaitingMentor(): void
    {
        $candidates = Training::where('status', TrainingStatus::PRE_TRAINING)
            // Never the named cohort -- those ten say what they are by hand.
            ->whereNotBetween('user_id', [self::FIRST_CID, self::FIRST_CID + 99])
            ->whereDoesntHave('mentors')
            ->orderBy('id')
            ->get();

        // Every third, so pre-training keeps a decent population of its own.
        foreach ($candidates as $index => $training) {
            if ($index % 3 !== 0) {
                continue;
            }

            // Through resolveStatusChanges rather than by assignment, which is
            // what sets started_at -- awaiting-mentor counts as in progress, so
            // a row without it would be inconsistent in a way no real
            // transition can produce. It is also the exact path the bridge
            // takes, so the seeder exercises it.
            $training->fill($training->resolveStatusChanges(TrainingStatus::AWAITING_MENTOR));
            $training->save();
        }
    }

    /**
     * A platform row for every user.
     *
     * The distribution is the point. Almost everybody is on both -- they are
     * mandatory to train here -- with a scattering of gaps so the chase cases
     * are visible, and a couple of accounts that resolve to no CID at all.
     *
     * Derived from the CID rather than randomised, so a rebuilt dev database
     * puts the same people in the same states and a bug you saw yesterday is
     * still there today.
     */
    private function backfillPlatforms(): void
    {
        // whereNotIn rather than a relation, so User.php stays verbatim
        // upstream. At division scale the id list is a few hundred rows.
        User::whereNotIn('id', UserPlatform::pluck('user_id'))
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $bucket = $user->id % 20;

                    $this->writePlatforms(
                        $user->id,
                        discord: $bucket !== 3,          // 1 in 20 not on Discord
                        moodle: $bucket !== 7,           // 1 in 20 not on Moodle
                        vatsimMember: $bucket !== 13,    // 1 in 20 is a bot
                    );
                }
            });
    }

    /**
     * Theory attempts for every open standard training.
     *
     * Consistent with the stage, because an inconsistent one is worse than
     * none: a student in active training with no theory pass would look like a
     * bug in the gate rather than a gap in the fixtures.
     *
     *   in queue        nothing -- they have not started
     *   pre-training    nothing, or one fail
     *   awaiting mentor a pass, and for some a failed first attempt before it
     *   beyond that     a pass
     *
     * Only the standard track. Visiting, transfer and refresher trainings sit
     * no theory, and giving them attempts would misrepresent the rule.
     */
    private function backfillTheory(): void
    {
        $trainings = Training::where('type', self::TYPE_STANDARD)
            ->where('status', '>=', TrainingStatus::IN_QUEUE)
            ->with('ratings')
            ->get();

        foreach ($trainings as $training) {
            // Nothing has happened yet, by definition.
            if ($training->status === TrainingStatus::IN_QUEUE) {
                continue;
            }

            $rating = $training->ratings->first()?->name ?? 'S2';
            $bucket = $training->id % 4;

            // WHAT IS ALREADY TRUE OF THE PERSON, not of this training.
            // Attempts are keyed to person plus rating, and VatssaSeeder gives
            // some people more than one training -- so checking "does this
            // training have attempts" would be the wrong question and would
            // leave somebody past the gate with only a failed attempt from an
            // earlier one.
            $hasAny = TheoryAttempt::where('user_id', $training->user_id)->exists();
            $hasPass = TheoryAttempt::where('user_id', $training->user_id)
                ->where('passed', true)->exists();

            if ($training->status === TrainingStatus::PRE_TRAINING) {
                // Still inside the window. A quarter have failed once; the rest
                // have not sat it yet.
                if (! $hasAny && $bucket === 0) {
                    $this->writeAttempt($training->user_id, $rating, 1, 52.0, false, 20);
                }

                continue;
            }

            // Past the gate, so a pass has to exist or the fixture contradicts
            // the rule it is meant to demonstrate.
            if (! $hasPass) {
                $this->passedSequence($training, $rating, $hasAny ? 0 : $bucket);
            }
        }
    }

    /**
     * A pass, and for a quarter of them a failed first attempt before it.
     *
     * The failed-then-passed shape is what makes the profile panel worth
     * looking at: a single row proves nothing about ordering.
     */
    private function passedSequence(Training $training, string $rating, int $bucket): void
    {
        // Continue the person's numbering rather than restarting it. An
        // attempt id is unique per user per quiz, so reusing 1 would overwrite
        // the failed attempt an earlier training left behind instead of adding
        // the pass after it.
        $attempt = TheoryAttempt::where('user_id', $training->user_id)->count() + 1;

        if ($bucket === 1) {
            $this->writeAttempt($training->user_id, $rating, $attempt++, 61.0, false, 140);
        }

        $this->writeAttempt(
            $training->user_id,
            $rating,
            $attempt,
            70.0 + ($training->id % 30),      // 70-99, stable per training
            true,
            100
        );
    }

    /**
     * An email history for every open training.
     *
     * Both sources appear on purpose. The panel exists to show that Control
     * Center and the pipeline both write to a student, which is the thing
     * nobody could see before it.
     */
    private function backfillMessageLog(): void
    {
        Training::where('status', '>=', TrainingStatus::IN_QUEUE)
            ->orderBy('id')
            ->chunkById(200, function ($trainings) {
                foreach ($trainings as $training) {
                    if (MessageLog::where('training_id', $training->id)->exists()) {
                        continue;
                    }
                    $this->writeMessages(
                        $training,
                        TheoryAttempt::where('user_id', $training->user_id)->where('passed', true)->exists()
                    );
                }
            });
    }

    /**
     * Requests, spread across the people who can act on them.
     *
     * Every student and mentor request in Control Center is a Task, so without
     * these the Tasks page is empty for everybody -- and the VATSSA overview
     * tab ("Everyone") has nothing to show at all, which is the one thing that
     * cannot be judged from a single inbox.
     *
     * Deliberately spread across several assignees and left mostly PENDING:
     * an overview of one person's completed tasks would tell you nothing about
     * whether the overview works.
     *
     * Task types are directory-scanned, so this asks the application which
     * types exist rather than hardcoding class names. A type deleted upstream
     * -- or by us, as TheoreticalExam was -- then simply stops appearing
     * instead of breaking the seed.
     */
    private function seedTasks(): void
    {
        $types = collect(TaskController::getTypes())
            ->map(fn ($type) => $type::class)
            ->values();

        if ($types->isEmpty()) {
            return;
        }

        // Anybody who can actually receive a task. Mentors hold tasks.manage,
        // so this is a realistic spread rather than everything on one desk.
        $assignees = User::whereHas('roleAssignments')->get()
            ->filter(fn (User $user) => $user->hasPermission('tasks.suggested-recipient'))
            ->values();

        if ($assignees->isEmpty()) {
            return;
        }

        $trainings = Training::where('status', '>=', TrainingStatus::PRE_TRAINING)
            ->with('user')
            ->take(24)
            ->get();

        foreach ($trainings as $index => $training) {
            if ($training->user === null) {
                continue;
            }

            $assignee = $assignees[$index % $assignees->count()];

            // Every fourth is closed, so the Archived tab is not empty either.
            $closed = $index % 4 === 3;

            Task::updateOrCreate([
                'subject_training_id' => $training->id,
                'type' => $types[$index % $types->count()],
            ], [
                'status' => $closed ? TaskStatus::COMPLETED : TaskStatus::PENDING,
                'subject_user_id' => $training->user_id,
                'assignee_user_id' => $assignee->id,
                'creator_user_id' => $assignees[($index + 1) % $assignees->count()]->id,
                'message' => 'Seeded request for testing the pipeline pages.',
                'created_at' => now()->subDays(20 - ($index % 20)),
                'closed_at' => $closed ? now()->subDays(2) : null,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Keyed writes. Every one of these is safe to repeat.
    // -----------------------------------------------------------------

    /**
     * Give every open training the history that produced its current state.
     *
     * ## Why an empty timeline is worse than no timeline
     *
     * The timeline is the first thing a coordinator opens and the main thing
     * they judge a training by. On dev it was blank for every student, which
     * made every screen that reads it -- the activity filters, the comment
     * box, the permission checks on who may see which activity type -- untested
     * against anything except the one row somebody happened to add by hand.
     *
     * It also made a real bug invisible for weeks: MENTOR rows render the
     * mentor name by looking the user up, and nobody had a MENTOR row to
     * render.
     *
     * ## Backwards from the current status
     *
     * Every training gets the entries that MUST have happened for it to be
     * where it is: created, then theory, then a mentor, then an exam. Inventing
     * a plausible history is the point -- a timeline of five identical comments
     * would exercise the page without testing it.
     *
     * ## Dates go backwards from the training, not from today
     *
     * A timeline whose entries are all timestamped "now" sorts arbitrarily and
     * hides ordering bugs, which are exactly the bugs a timeline has.
     */
    /**
     * Practical exams: REMOVED 2026-09-02, with the workflow they seeded.
     *
     * `seedExams()` and `seedExamPoll()` built nine exams across the stages
     * plus their availability polls. Both are gone with the CPT workflow.
     * Availability polls that are not about an exam are seeded by
     * seedAvailability() instead.
     */

    /**
     * A populated automation log, both kinds of row.
     *
     * The warnings matter more than the actions: the page defaults to them, and
     * a page whose default view is empty on dev is one nobody looks at twice.
     */
    private function seedActionLog(): void
    {
        if (ActionLog::query()->exists()) {
            return;
        }

        $training = Training::with('user')->first();

        ActionLog::did('training.returned_to_queue',
            ($training?->user?->name ?? 'A student')
                . ' was in active training with no mentor, so they were returned to the queue.',
            $training?->id, $training?->user_id, [], ActionLog::ACTOR_SYSTEM, mirror: false);

        ActionLog::did('roster.expiry_warned',
            'Web Three was warned that their roster place lapses on '
                . now()->addDays(7)->toDateString() . '.',
            null, 10000003, [], ActionLog::ACTOR_SYSTEM, mirror: false);

        ActionLog::noticed('request.desk_empty',
            'A request was sent to the Membership desk, but nobody is assigned to it.',
            null, null, [], ActionLog::ACTOR_SYSTEM, mirror: false);

        ActionLog::noticed('theory.no_course',
            'S3 has no Moodle course configured, so its waiting students skip theory entirely.',
            null, null, [], ActionLog::ACTOR_BOT, mirror: false);

        ActionLog::noticed('exam.notice_breached',
            ($training?->user?->name ?? 'A student')
                . ' has an exam in 3 days that still needs a banner and a myVATSIM upload.',
            $training?->id, $training?->user_id, [], ActionLog::ACTOR_SYSTEM, mirror: false);

        $this->command?->info('VatssaPipelineSeeder: ' . ActionLog::count() . ' automation log rows.');
    }

    /**
     * The four kinds of deadline, across the cohort.
     *
     * Every state the Confirmations table can draw: met, missed, invalidated,
     * still open, and open-but-past-its-deadline. That last one is the reason
     * this is not three rows -- "overdue" is a state the sweep has not settled
     * yet, it appears nowhere else, and it is the row a coordinator most needs
     * to see today.
     */
    private function seedConfirmations(): void
    {
        if (Confirmation::query()->exists()) {
            return;
        }

        $trainings = Training::whereBetween('user_id',
            [self::FIRST_CID, self::FIRST_CID + count(self::COHORT) - 1])
            ->orderBy('id')
            ->take(8)
            ->get();

        if ($trainings->isEmpty()) {
            return;
        }

        // [type, days since sent, deadline in days from sent, outcome]
        // outcome: 'met' | 'missed' | 'invalidated' | 'open' | 'overdue'
        $plan = [
            [Confirmation::JOIN_DISCORD, 40, 7, 'met'],
            [Confirmation::JOIN_MOODLE, 38, 7, 'met'],
            [Confirmation::COMPLETE_MOODLE, 35, 90, 'open'],

            [Confirmation::JOIN_DISCORD, 30, 7, 'overdue'],
            [Confirmation::JOIN_MOODLE, 60, 7, 'missed'],
            [Confirmation::COMPLETE_MOODLE, 120, 90, 'missed'],

            // Leave was granted, so the theory clock stopped. Invalidated is
            // NOT a tidier word for missed: it means we stopped asking, and
            // using it for a real miss erases the evidence somebody was chased.
            [Confirmation::COMPLETE_MOODLE, 100, 90, 'invalidated'],

            [Confirmation::JOIN_DISCORD, 3, 7, 'open'],
        ];

        foreach ($trainings as $i => $training) {
            [$type, $sentDaysAgo, $window, $outcome] = $plan[$i] ?? $plan[0];

            $sent = now()->subDays($sentDaysAgo);

            $confirmation = Confirmation::create([
                'training_id' => $training->id,
                'type' => $type,
                'sent_at' => $sent,
                // 'overdue' needs a deadline in the past with nothing settled,
                // so it is built from the send date like every other row and
                // simply has a short window.
                'deadline' => $outcome === 'overdue'
                    ? now()->subDays(2)
                    : $sent->copy()->addDays($window),
                'confirmed_at' => $outcome === 'met' ? $sent->copy()->addDays(2) : null,
                // Chased twice before anything happened. The first question
                // asked when somebody appeals a removal.
                'reminders' => in_array($outcome, ['missed', 'overdue'], true) ? 2 : 0,
            ]);

            match ($outcome) {
                'missed' => $confirmation->miss(),
                'invalidated' => $confirmation->invalidate(),
                default => null,
            };
        }

        $this->command?->info('VatssaPipelineSeeder: ' . Confirmation::count()
            . ' confirmations across every outcome.');
    }

    /**
     * Availability polls that belong to nobody's exam.
     *
     * The availability tool is general: a mentoring session, a staff meeting,
     * anything somebody needs a group's times for. These are what it is
     * actually used for now that the exam workflow is gone.
     *
     * One of them is confirmed, so the settled list is not empty either.
     */
    private function seedStandalonePolls(): void
    {
        if (AvailabilityPoll::query()->exists()) {
            return;     // idempotent, like everything else here
        }

        $staff = User::find(10000009) ?? User::first();
        $cohort = User::whereBetween('id',
            [self::FIRST_CID, self::FIRST_CID + count(self::COHORT) - 1])->take(4)->get();

        if ($staff === null || $cohort->isEmpty()) {
            return;
        }

        $from = CarbonImmutable::now()->addDays(2);

        $mentoring = AvailabilityPoll::create([
            'purpose' => AvailabilityPoll::MENTORING,
            'title' => 'S2 radar sessions — week of ' . $from->format('j M'),
            'description' => 'Two hours, twice. Mark everything you could make.',
            'starts_on' => $from,
            'ends_on' => $from->addWeeks(2),
            'created_by' => $staff->id,
            'slot_minutes' => 30,
        ]);

        $meeting = AvailabilityPoll::create([
            'purpose' => AvailabilityPoll::MEETING,
            'title' => 'Training staff catch-up',
            'starts_on' => $from,
            'ends_on' => $from->addWeeks(1),
            'created_by' => $staff->id,
            'slot_minutes' => 60,
        ]);

        foreach ([$mentoring, $meeting] as $poll) {
            $evenings = $poll->slots()
                ->filter(fn ($slot) => (int) $slot->format('H') >= 17)
                ->take(20)
                ->map(fn ($slot) => $slot->toIso8601String())
                ->values();

            // Overlapping but not identical, so the grid shows a real
            // intersection. Identical answers make every cell look agreed and
            // hide the one thing the shading is for.
            foreach ($cohort as $offset => $member) {
                AvailabilityResponse::create([
                    'poll_id' => $poll->id,
                    'user_id' => $member->id,
                    'role' => AvailabilityPoll::ROLE_PARTICIPANT,
                    'slots' => $evenings->slice($offset, 12)->values()->all(),
                ]);
            }
        }

        // One settled, so the "Settled" section on the index is not dead.
        $meeting->forceFill([
            'confirmed_slot' => $from->addDays(3)->setTime(19, 0),
            'confirmed_at' => now()->subDay(),
            'confirmed_by' => $staff->id,
        ])->save();

        $this->command?->info('VatssaPipelineSeeder: 2 standalone polls, one settled.');
    }

    /**
     * A roster place about to lapse.
     *
     * The alert only renders for a future expiry, so a fixture dated in the
     * past shows nothing and reads as the panel being broken.
     */
    private function seedRosterWarnings(): void
    {
        if (RosterWarning::query()->exists()) {
            return;
        }

        $user = User::find(self::FIRST_CID + 2);

        if ($user === null) {
            return;
        }

        RosterWarning::updateOrCreate(['user_id' => $user->id], [
            'expires_on' => now()->addDays(5),
            'warned_at' => now()->subDays(2),
        ]);
    }

    /**
     * The membership desk, with something in it.
     *
     * Without this every membership queue on a dev box is empty, and an empty
     * queue is indistinguishable from a broken one -- which is exactly the
     * thing dev fixtures exist to prevent.
     *
     * ## What the set covers
     *
     * Every state of the full workflow, every one of the five types, and the
     * three disciplinary outcomes that behave differently: never checked,
     * checked and clean, checked and NOT clean. That last one is the case staff
     * actually have to think about, and it is the one a hand-made fixture
     * always forgets.
     *
     * ## Through the model's own API
     *
     * `open()` and `recordDisciplinaryCheck()` rather than raw inserts, so the
     * seeder exercises the same paths the application does. A fixture built by
     * writing columns directly can be in a state the code cannot produce, and
     * then a bug looks like bad data and bad data looks like a bug.
     */
    private function seedMembershipRequests(): void
    {
        if (MembershipRequest::query()->exists()) {
            return;
        }

        $staff = User::find(self::FIRST_CID) ?? User::first();

        if ($staff === null) {
            return;
        }

        // CID => [type, end state, disciplinary outcome, note]
        //
        // `null` for the disciplinary outcome means nobody has looked yet,
        // which is NOT the same as clean and is the state every request starts
        // in. See MembershipRequest::disciplinaryChecked().
        $plan = [
            [1, MembershipRequestType::TRANSFER, MembershipRequestState::PENDING_DISCIPLINARY, null,
                'Flying here most evenings and would rather control here too.'],
            [2, MembershipRequestType::VISITING, MembershipRequestState::PENDING_TRANSFER, true,
                'Would like to control our TMA positions at weekends.'],
            [3, MembershipRequestType::TRANSFER, MembershipRequestState::AWAITING_MEMBER, true,
                'Asked them which subdivision they meant. Waiting.'],
            [4, MembershipRequestType::VISITING, MembershipRequestState::PENDING_TRANSFER_COMPLETE, true,
                'Terminal actioned, waiting for it to land.'],
            [5, MembershipRequestType::TRANSFER, MembershipRequestState::PENDING_TRAINING, true,
                'Familiarisation running. Off the desk until it closes.'],
            [6, MembershipRequestType::VISITING, MembershipRequestState::COMPLETE, true,
                'Done. Visiting endorsement issued on completion.'],
            [7, MembershipRequestType::TRANSFER, MembershipRequestState::CLOSED_BY_MEMBER, null,
                'Withdrew before we got to it.'],

            // The one staff have to think about: a finding, with the context
            // that TVCP 5.5 requires them to be able to point at.
            [8, MembershipRequestType::TRANSFER, MembershipRequestState::PENDING_DISCIPLINARY, false,
                'Terminal shows a 2026 suspension. Needs a decision before anything else.'],

            // Terminal work. Nobody filed these; the desk recorded them.
            [9, MembershipRequestType::RATING_UPGRADE, MembershipRequestState::OPEN, null,
                'S2 sign-off came through, upgrade not yet actioned.'],
            [10, MembershipRequestType::RATING_UPGRADE, MembershipRequestState::COMPLETE, null,
                'Actioned on Terminal.'],
            [11, MembershipRequestType::STAFF_INQUIRY, MembershipRequestState::OPEN, null,
                'Eligibility check ahead of a mentor appointment.'],
            [0, MembershipRequestType::OTHER, MembershipRequestState::CLOSED, null,
                'Duplicate account query. Nothing to do.'],
        ];

        foreach ($plan as [$offset, $type, $state, $clean, $note]) {
            $about = User::find(self::FIRST_CID + $offset);

            if ($about === null) {
                continue;
            }

            $request = MembershipRequest::open(
                $type,
                $about,
                // Only the member-filed ones have somebody who filed them.
                $type->isMemberFiled() ? $about : $staff,
                [
                    'note' => $note,
                    'checks' => $type->carriesTvcpChecks() ? [
                        'Member of ' . config('app.owner_name_short') => false,
                        'Discord account linked' => true,
                        'Moodle account linked' => true,
                    ] : null,
                ],
            );

            if ($clean !== null) {
                $request->recordDisciplinaryCheck(
                    $clean,
                    $staff,
                    // A finding without context is refused by the model, and
                    // rightly -- so the fixture carries one.
                    $clean ? null : 'Suspended for 30 days in March 2026. Within the twelve-month window.',
                );
            }

            if ($request->state !== $state) {
                $request->update(['state' => $state]);
            }
        }
    }

    /**
     * The Terminal log, with something in it.
     *
     * An empty AUDIT log is worse than an empty queue: it reads exactly like a
     * log that is not being written, which is the one thing an audit surface
     * must never be mistaken for. Without this the page is blank on every dev
     * box.
     *
     * ## What the set has to cover
     *
     * All three types, because each renders differently. Both actor shapes --
     * a Control Center account, and a typed name for somebody who was never in
     * Control Center, which is the case the second column exists for. And all
     * three disciplinary outcomes: not a check at all, checked and clean, and
     * checked with a finding.
     *
     * `performed_at` is deliberately spread backwards. Every row landing on the
     * same day would hide the one thing the ordering is for.
     */
    private function seedTerminalLog(): void
    {
        if (TerminalLogEntry::query()->exists()) {
            return;
        }

        $recorder = User::find(self::FIRST_CID) ?? User::first();

        if ($recorder === null) {
            return;
        }

        $ratings = Rating::whereNotNull('vatsim_rating')->orderBy('vatsim_rating')->take(2)->get();
        $transfer = MembershipRequest::where('type', MembershipRequestType::TRANSFER)->first();

        // [offset, type, reason, days ago, actor name (null = the recorder),
        //  discipline outcome, note]
        $plan = [
            [1, TerminalLogType::QUERY, TerminalLogReason::TVCP_CHECK, 21, null, false,
                'Checked ahead of the transfer decision.'],
            [2, TerminalLogType::QUERY, TerminalLogReason::TVCP_CHECK, 18, null, true,
                'Finding is inside the twelve-month window.'],
            [3, TerminalLogType::QUERY, TerminalLogReason::STAFF_CHECK, 14, 'K. Mokoena', null,
                'Eligibility check before a staff appointment.'],
            [4, TerminalLogType::QUERY, TerminalLogReason::DUPE_CHECK, 11, null, null,
                'Two accounts with the same name. Not the same person.'],
            [5, TerminalLogType::CHANGE, TerminalLogReason::RATING_UPDATE, 9, null, null, null],
            [6, TerminalLogType::CHANGE, TerminalLogReason::RATING_UPDATE, 6, 'A. van Wyk', null,
                'Actioned on Terminal directly, recorded here afterwards.'],
            [7, TerminalLogType::TRANSFER_IN, TerminalLogReason::TRANSFER, 3, null, null,
                'Transfer accepted under TVCP.'],
        ];

        foreach ($plan as [$offset, $type, $reason, $daysAgo, $actorName, $discipline, $note]) {
            $about = User::find(self::FIRST_CID + $offset);

            if ($about === null) {
                continue;
            }

            TerminalLogEntry::create([
                'type' => $type,
                'reason' => $reason,
                'user_id' => $about->id,
                // One of the two, never both. The typed name is the case the
                // column exists for: the action happened on Terminal, by
                // somebody who was not in Control Center at the time.
                'actor_user_id' => $actorName === null ? $recorder->id : null,
                'actor_name' => $actorName,
                'recorded_by' => $recorder->id,
                'membership_request_id' => $type === TerminalLogType::TRANSFER_IN ? $transfer?->id : null,
                'comment_code' => $type === TerminalLogType::CHANGE ? 'SSA-VT-001' : null,
                'rating_from_id' => $type->carriesRatingChange() ? $ratings->first()?->id : null,
                'rating_to_id' => $type->carriesRatingChange() ? $ratings->last()?->id : null,
                'discipline_found' => $discipline,
                'discipline_context' => $discipline === true
                    ? 'Suspended for 30 days in March 2026.'
                    : null,
                'notes' => $note,
                'performed_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    private function backfillTimelines(): void
    {
        $written = 0;

        foreach (Training::with('ratings', 'mentors', 'user')->get() as $training) {
            // Idempotent, like everything else here: deploy-cc.sh runs this on
            // every dev and staging deploy, and a second run must not double
            // every timeline.
            if (TrainingActivity::where('training_id', $training->id)->exists()) {
                continue;
            }

            $opened = $training->created_at ?? now()->subDays(80);
            $step = 0;
            $at = fn () => (clone $opened)->addDays(++$step * 3);

            $rows = [];

            // Every training was in the queue once, whatever it says now.
            $rows[] = ['COMMENT', null, null, 'Application received.', clone $opened];

            $status = $training->status;

            // How far this training GOT, which is not the same as where it is.
            // Closed statuses order below the queue -- correct for a dropdown,
            // wrong here: somebody who passed their CPT went through every
            // stage, and a completed training whose timeline skips theory and
            // mentoring is a worse fixture than none.
            $reached = $status === TrainingStatus::COMPLETED
                ? TrainingStatus::AWAITING_EXAM->lifecycleOrder()
                : $status->lifecycleOrder();

            // Theory: anything that got past the queue went through it.
            if ($reached >= TrainingStatus::PRE_TRAINING->lifecycleOrder()) {
                $rows[] = ['STATUS', TrainingStatus::PRE_TRAINING->value,
                    TrainingStatus::IN_QUEUE->value, null, $at()];
                $rows[] = ['COMMENT', null, null,
                    'Enrolled in the theory course.', $at()];
            }

            if ($reached >= TrainingStatus::AWAITING_MENTOR->lifecycleOrder()) {
                $rows[] = ['COMMENT', null, null,
                    'Theory passed. Waiting for a mentor.', $at()];
                $rows[] = ['STATUS', TrainingStatus::AWAITING_MENTOR->value,
                    TrainingStatus::PRE_TRAINING->value, null, $at()];
            }

            // A MENTOR row for each mentor actually attached, so the renderer
            // that looks up the name by id is exercised.
            foreach ($training->mentors as $mentor) {
                $rows[] = ['MENTOR', $mentor->id, null, null, $at()];
                $rows[] = ['STATUS', TrainingStatus::ACTIVE_TRAINING->value,
                    TrainingStatus::AWAITING_MENTOR->value, null, $at()];
            }

            if ($status === TrainingStatus::AWAITING_EXAM) {
                $rows[] = ['COMMENT', null, null,
                    'Ready for a CPT. Examiner to be arranged.', $at()];
                $rows[] = ['STATUS', TrainingStatus::AWAITING_EXAM->value,
                    TrainingStatus::ACTIVE_TRAINING->value, null, $at()];
            }

            if ($status === TrainingStatus::COMPLETED) {
                $rows[] = ['COMMENT', null, null, 'CPT passed.', $at()];
                $rows[] = ['STATUS', TrainingStatus::COMPLETED->value,
                    TrainingStatus::AWAITING_EXAM->value, null, $at()];
            }

            if ($status->isClosed() && $status !== TrainingStatus::COMPLETED) {
                $rows[] = ['STATUS', $status->value, TrainingStatus::IN_QUEUE->value,
                    'Closed.', $at()];
            }

            if ($training->paused_at !== null) {
                $rows[] = ['PAUSE', 1, 0, 'Leave of absence granted.', $at()];
            }

            foreach ($rows as [$type, $new, $old, $comment, $when]) {
                // Assigned field by field rather than through create(). The
                // model's $fillable lists five columns and does NOT include
                // training_id or the timestamps, so a mass assignment would
                // drop them silently -- every row orphaned, with no error and
                // a timeline that looks empty for a reason nobody could see.
                $activity = new TrainingActivity();
                $activity->training_id = $training->id;
                $activity->type = $type;
                $activity->new_data = $new;
                $activity->old_data = $old;
                // Null actor on the automated rows, a real staff member on the
                // decisions. Both render differently and both need to exist for
                // anybody to notice if one breaks.
                $activity->triggered_by_id = $type === 'COMMENT' ? null : 10000008;
                $activity->comment = $comment;
                $activity->created_at = $when;
                $activity->updated_at = $when;
                $activity->save();

                $written++;
            }
        }

        $this->command?->info("VatssaPipelineSeeder: {$written} timeline entries written.");
    }

    private function writePlatforms(int $userId, bool $discord, bool $moodle, bool $vatsimMember,
        ?string $enrolment = 'active'): void
    {
        UserPlatform::updateOrCreate(['user_id' => $userId], [
            // Registered but never enrolled is a real and common state, so the
            // fixtures have to contain it or the panel that exists to show it
            // never gets looked at.
            'moodle_enrolment' => $moodle ? $enrolment : null,
            'moodle_course' => $moodle && $enrolment ? 'S2' : null,
            // A plausible snowflake so the panel renders one. Not a real
            // account: these CIDs do not exist on VATSIM either.
            'discord_user_id' => $discord ? 900000000000000000 + $userId : null,
            'on_discord' => $discord,
            // WHEN they joined, and deliberately not for everybody.
            //
            // Every row written before the sweep started asking for these has
            // no date and never will, so the panel has to render "?" as a
            // normal state rather than as a gap. Seeding it for all of them
            // would mean the one thing the view does specially is the one thing
            // dev never shows.
            //
            // Every third member keeps a null date, which is roughly the real
            // proportion after a backfill.
            'discord_joined_at' => $discord && $userId % 3 !== 0
                ? now()->subDays(($userId % 400) + 20)
                : null,
            'moodle_user_id' => $moodle ? $userId : null,
            'on_moodle' => $moodle,
            'moodle_registered_at' => $moodle && $userId % 3 !== 0
                ? now()->subDays(($userId % 300) + 10)
                : null,
            'vatsim_member' => $vatsimMember,
            // Recent, so nothing reads as stale. Push this back two days to see
            // what the staleness warning looks like.
            'checked_at' => now()->subHours(3),
        ]);
    }

    private function writeAttempt(int $userId, string $rating, int $attempt,
        float $grade, bool $passed, int $daysAgo): void
    {
        TheoryAttempt::updateOrCreate([
            'user_id' => $userId,
            'moodle_quiz_id' => 52,
            'moodle_attempt_id' => $attempt,
        ], [
            'rating' => strtoupper($rating),
            'moodle_course_id' => 14,
            'grade' => $grade,
            'passed' => $passed,
            'taken_at' => now()->subDays($daysAgo),
        ]);
    }

    private function writeMessages(Training $training, bool $passedTheory): void
    {
        $log = [
            ['Training request received', 'other', 'control-center', 80],
            ['VATSSA ATC Training — you are enrolled', 'other', 'bot', 79],
        ];

        if ($passedTheory) {
            $log[] = ['VATSSA ATC Training — theory passed', 'other', 'bot', 20];
        }

        if ($training->status === TrainingStatus::PRE_TRAINING) {
            $log[] = ['VATSSA ATC Training — 14 days left on your course', 'other', 'bot', 4];
        }

        if ($training->status === TrainingStatus::AWAITING_MENTOR) {
            $log[] = ['Confirm your continued interest in training', 'interest', 'control-center', 9];
        }

        if ($training->status->isClosed()) {
            $log[] = ['Your training has been closed', 'closed', 'control-center', 5];
        }

        foreach ($log as $index => [$subject, $kind, $source, $daysAgo]) {
            MessageLog::updateOrCreate([
                'user_id' => $training->user_id,
                'message_id' => "seed-{$training->id}-{$index}",
            ], [
                'training_id' => $training->id,
                'subject' => $subject,
                'kind' => $kind,
                'source' => $source,
                'sent_at' => now()->subDays($daysAgo),
            ]);
        }
    }
}
