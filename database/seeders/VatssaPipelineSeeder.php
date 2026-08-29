<?php

namespace Database\Seeders;

use App\Helpers\TrainingStatus;
use App\Models\Rating;
use App\Models\Training;
use App\Models\User;
use App\Models\Vatssa\MessageLog;
use App\Models\Vatssa\TheoryAttempt;
use App\Models\Vatssa\UserPlatform;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: a cohort that walks the whole training pipeline, with no bot running.
 *
 *   php artisan db:seed --class=VatssaPipelineSeeder
 *
 * ## What this is for
 *
 * Everything the pipeline writes into Control Center normally arrives over the
 * bridge, from a bot that polls Moodle and Discord. That is a lot of moving
 * parts to stand up before you can answer a simple question: does the training
 * page look right for somebody waiting on a mentor?
 *
 * This writes the same rows directly. **No bridge, no bot, no Moodle, no
 * Discord.** Log in as any of the fixed accounts and click through.
 *
 * ## The cohort
 *
 * Ten students, one per situation worth looking at, on CIDs 10000301-10000310
 * so they never collide with `VatssaSeeder`'s fixtures.
 *
 *   301  In queue          nothing yet -- the day they registered
 *   302  Pre-training      on both platforms, no attempt yet
 *   303  Pre-training      failed once, still inside the window
 *   304  Awaiting mentor   passed -- THE stage upstream cannot express
 *   305  Awaiting mentor   passed, failed a retake, passed again
 *   306  Active training   mentored, past the gate
 *   307  Awaiting exam     ready for a CPT
 *   308  Completed         passed the CPT
 *   309  Pre-training      NOT on Discord -- the chase case
 *   310  Pre-training      not a VATSIM member -- a bot or test account
 *
 * ## Two of these exist to be looked at closely
 *
 * **305** is the case the whole "latest, not best" rule is for. Three attempts:
 * pass, fail, pass. Its panel must show all three, and the person must read as
 * currently passed -- and would read as failed if the middle one were last.
 *
 * **310** is not a missing tick. A Discord account that resolves to no CID is a
 * bot or a test account, and the panel says so rather than showing a broken
 * cross.
 *
 * ## Guards
 *
 * Refuses in production, like `VatssaSeeder`. Re-running is safe: every write
 * is keyed, so it updates in place rather than duplicating.
 */
class VatssaPipelineSeeder extends Seeder
{
    private const FIRST_CID = 10000301;

    /**
     * name, status, and what to write. `theory` is a list of
     * [rating, grade, passed, days ago].
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
            'platforms' => ['discord' => true, 'moodle' => true],
            'theory' => [],
            'note' => 'On both platforms, inside the 90-day window, no attempt yet.',
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
                . 'It invents students, attempts and emails that never happened.'
            );
        }

        $this->assertTablesExist();

        $rating = Rating::where('name', 'S2')->first()
            ?? Rating::whereNotNull('vatsim_rating')->orderBy('vatsim_rating')->first();

        foreach (self::COHORT as $index => $spec) {
            $cid = self::FIRST_CID + $index;

            $user = User::updateOrCreate(['id' => $cid], [
                'first_name' => explode(' ', $spec['name'])[0],
                'last_name' => explode(' ', $spec['name'])[1],
                'email' => strtolower(str_replace(' ', '.', $spec['name'])) . '@example.com',
                'rating' => 2,
                'region' => 'EMEA',
                'division' => 'SSA',
                'subdivision' => null,
            ]);

            $this->platforms($user, $spec['platforms']);
            $this->theory($user, $spec['theory']);
            $training = $this->training($user, $spec, $rating);
            $this->messages($user, $training, $spec);
        }

        $this->command?->info(sprintf(
            'VatssaPipelineSeeder: %d students on CIDs %d-%d. No bridge, bot, Moodle or Discord needed.',
            count(self::COHORT), self::FIRST_CID, self::FIRST_CID + count(self::COHORT) - 1
        ));
    }

    /**
     * Fail loudly rather than half-seeding. Without the pipeline tables this
     * would insert users and trainings and then throw, leaving a database that
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

    private function platforms(User $user, array $spec): void
    {
        UserPlatform::updateOrCreate(['user_id' => $user->id], [
            // A plausible snowflake, so the panel renders one. Not a real
            // account: these CIDs do not exist on VATSIM either.
            'discord_user_id' => $spec['discord'] ? 900000000000000000 + $user->id : null,
            'on_discord' => $spec['discord'],
            'moodle_user_id' => $spec['moodle'] ? $user->id : null,
            'on_moodle' => $spec['moodle'],
            'vatsim_member' => $spec['vatsim_member'] ?? true,
            // Recent, so nothing shows as stale. Flip this back a week to see
            // what the staleness warning looks like.
            'checked_at' => now()->subHours(3),
        ]);
    }

    private function theory(User $user, array $attempts): void
    {
        foreach ($attempts as $index => [$rating, $grade, $passed, $daysAgo]) {
            TheoryAttempt::updateOrCreate([
                'user_id' => $user->id,
                'moodle_quiz_id' => 52,
                'moodle_attempt_id' => $index + 1,
            ], [
                'rating' => $rating,
                'moodle_course_id' => 14,
                'grade' => $grade,
                'passed' => $passed,
                'taken_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    private function training(User $user, array $spec, ?Rating $rating): Training
    {
        $status = $spec['status'];

        $training = Training::updateOrCreate(
            ['user_id' => $user->id, 'area_id' => 1],
            [
                'status' => $status,
                'type' => 1,
                // country_id was renamed to area_id in 2021; there is no
                // countries table any more. english_only_training is NOT NULL
                // with no default, so it has to be given.
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
                $training->mentors()->attach($mentor);
            }
        }

        return $training;
    }

    /**
     * A plausible email history, so the message log panel has something in it.
     *
     * Both sources appear on purpose: the panel exists to show that Control
     * Center and the pipeline both write to a student, which is the thing
     * nobody could see before.
     */
    private function messages(User $user, Training $training, array $spec): void
    {
        $log = [
            ['Training request received', 'other', 'control-center', 80],
            ['VATSSA ATC Training — you are enrolled', 'other', 'bot', 79],
        ];

        if ($spec['theory'] !== []) {
            $log[] = ['VATSSA ATC Training — theory passed', 'other', 'bot', 20];
        }

        if ($spec['status'] === TrainingStatus::PRE_TRAINING) {
            $log[] = ['VATSSA ATC Training — 14 days left on your course', 'other', 'bot', 4];
        }

        if ($spec['status']->isClosed()) {
            $log[] = ['Your training has been closed', 'closed', 'control-center', 5];
        }

        foreach ($log as $index => [$subject, $kind, $source, $daysAgo]) {
            MessageLog::updateOrCreate([
                'user_id' => $user->id,
                'message_id' => "seed-{$user->id}-{$index}",
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
