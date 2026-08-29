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
 * 2. **The named cohort**, on CIDs 10000301-10000310. Ten students built by
 *    hand, one per situation worth looking at on purpose.
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
 * Refuses in production. Every write is keyed, so re-running updates in place
 * rather than duplicating -- which is why `deploy-cc.sh` can call it on every
 * dev and staging deploy without a conditional.
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
                . 'It invents students, exam results and emails that never happened.'
            );
        }

        $this->assertTablesExist();

        $this->seedCohort();
        $this->promoteSomeToAwaitingMentor();
        $this->backfillPlatforms();
        $this->backfillTheory();
        $this->backfillMessageLog();

        $this->command?->info(sprintf(
            'VatssaPipelineSeeder: %d platform rows, %d theory attempts, %d logged emails. '
            . 'Named cohort on CIDs %d-%d.',
            UserPlatform::count(),
            TheoryAttempt::count(),
            MessageLog::count(),
            self::FIRST_CID,
            self::FIRST_CID + count(self::COHORT) - 1
        ));
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
        $rating = Rating::where('name', 'S2')->first()
            ?? Rating::whereNotNull('vatsim_rating')->orderBy('vatsim_rating')->first();

        foreach (self::COHORT as $index => $spec) {
            [$first, $last] = explode(' ', $spec['name']);

            $user = User::updateOrCreate(['id' => self::FIRST_CID + $index], [
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower("{$first}.{$last}") . '@example.com',
                'rating' => 2,
                'region' => 'EMEA',
                'division' => 'SSA',
                'subdivision' => null,
            ]);

            $this->writePlatforms(
                $user->id,
                $spec['platforms']['discord'],
                $spec['platforms']['moodle'],
                $spec['platforms']['vatsim_member'] ?? true,
            );

            foreach ($spec['theory'] as $attemptIndex => [$forRating, $grade, $passed, $daysAgo]) {
                $this->writeAttempt($user->id, $forRating, $attemptIndex + 1, $grade, $passed, $daysAgo);
            }

            $training = $this->cohortTraining($user, $spec, $rating);
            $this->writeMessages($training, $spec['theory'] !== []);
        }
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

    // -----------------------------------------------------------------
    // Keyed writes. Every one of these is safe to repeat.
    // -----------------------------------------------------------------

    private function writePlatforms(int $userId, bool $discord, bool $moodle, bool $vatsimMember): void
    {
        UserPlatform::updateOrCreate(['user_id' => $userId], [
            // A plausible snowflake so the panel renders one. Not a real
            // account: these CIDs do not exist on VATSIM either.
            'discord_user_id' => $discord ? 900000000000000000 + $userId : null,
            'on_discord' => $discord,
            'moodle_user_id' => $moodle ? $userId : null,
            'on_moodle' => $moodle,
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
