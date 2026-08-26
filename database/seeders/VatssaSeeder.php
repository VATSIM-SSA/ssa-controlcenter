<?php

namespace Database\Seeders;

use App\Helpers\FactoryHelper;
use App\Helpers\TrainingStatus;
use App\Helpers\VatsimRating;
use App\Models\Endorsement;
use App\Models\Feedback;
use App\Models\Position;
use App\Models\Rating;
use App\Models\Training;
use App\Models\TrainingExamination;
use App\Models\TrainingReport;
use App\Models\User;
use Carbon\Carbon;
use Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Lottery;

/**
 * VATSSA dev and staging fixtures.
 *
 *   php artisan db:seed --class=VatssaSeeder
 *
 * An ADDED file. Upstream's `DatabaseSeeder.php` is left untouched so it can
 * never conflict on an upstream absorption. Because git therefore gives no
 * signal when upstream changes underneath this file, `VatssaFixturesTest`
 * exercises it in CI. That test is this file's drift detector; do not delete it.
 *
 * DEV AND STAGING ONLY, guarded against APP_ENV=production below. Never remove
 * that guard: production data comes from the DigitalOcean dump.
 *
 * Fixtures only. Areas and positions are division reference data and live in
 * `database/migrations-vatssa/2026_08_26_100000_vatssa_reference_data.php`,
 * which runs in every environment including production. This seeder asserts
 * that migration has run rather than duplicating it.
 *
 * The eleven fixed accounts are the VATSIM Connect dev sandbox CIDs. Log in
 * through Handover-dev as whichever role you want to test:
 *
 *   10000001  Web One         S1   no role
 *   10000002  Web Two         S2   no role
 *   10000003  Web Three       S3   no role
 *   10000004  Web Four        C1   nav-editor (area 1) + feedback-team (global)
 *   10000005  Web Five        C3   pipeline-coordinator (GLOBAL)
 *   10000006  Web Six         I1   mentor (area 1)
 *   10000007  Web Seven       I3   mentor (area 2)
 *   10000008  Web Eight       SUP  pipeline-coordinator (area 1)
 *   10000009  Web Nine        ADM  atc-training-manager (global)
 *   10000010  Team Web        ADM  admin (global)
 *   10000011  Suspended User   -   no role
 *
 * Account 4 carries two roles on purpose: role stacking is how the matrix
 * resolves a union, and it is the case most likely to break on a bump.
 *
 * Accounts 5 and 8 hold the SAME role at different scopes on purpose. v7.0.0
 * resolves grant authority through `roles.<role>.manage` and treats a global
 * holder differently from an area holder, so having both is what makes that
 * behaviour testable at all.
 */
class VatssaSeeder extends Seeder
{
    /**
     * VATSIM structure written onto seeded members. Taken from production
     * 2026-08-06: 199 of the members are EMEA / SSA with no subdivision.
     */
    private const REGION = 'EMEA';

    private const DIVISION = 'SSA';

    private const SUBDIVISION = null;

    /**
     * CID => [[role, area_id], ...]. area_id null = global.
     *
     * Six roles since 2026-08-26. `membership-manager` was deleted; account 5
     * now carries a global pipeline-coordinator instead.
     */
    private const FIXED_ACCOUNTS = [
        1 => ['last' => 'One', 'rating' => 1, 'roles' => []],
        2 => ['last' => 'Two', 'rating' => 2, 'roles' => []],
        3 => ['last' => 'Three', 'rating' => 3, 'roles' => []],
        4 => ['last' => 'Four', 'rating' => 4, 'roles' => [['nav-editor', 1], ['feedback-team', null]]],
        5 => ['last' => 'Five', 'rating' => 5, 'roles' => [['pipeline-coordinator', null]]],
        6 => ['last' => 'Six', 'rating' => 7, 'roles' => [['mentor', 1]]],
        7 => ['last' => 'Seven', 'rating' => 8, 'roles' => [['mentor', 2]]],
        8 => ['last' => 'Eight', 'rating' => 10, 'roles' => [['pipeline-coordinator', 1]]],
        9 => ['last' => 'Nine', 'rating' => 11, 'roles' => [['atc-training-manager', null]]],
        10 => ['last' => 'Web', 'first' => 'Team', 'rating' => 12, 'email' => 'noreply@vatsim.net', 'roles' => [['admin', null]]],
        11 => ['last' => 'User', 'first' => 'Suspended', 'rating' => 0, 'email' => 'suspended@vatsim.net', 'roles' => []],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'VatssaSeeder refuses to run with APP_ENV=production. '
                .'Production data comes from the database dump, never from a seeder.'
            );
        }

        $this->assertReferenceDataPresent();
        $this->assertRolesExist();

        $faker = Faker\Factory::create();

        $this->seedFixedAccounts();

        // Random VATSSA members
        for ($i = 12; $i <= 125; $i++) {
            User::factory()->create([
                'id' => 10000000 + $i,
                'region' => self::REGION,
                'division' => self::DIVISION,
                'subdivision' => self::SUBDIVISION,
            ]);
        }

        // Random members from elsewhere, so the visiting-controller screens
        // have something to show
        for ($i = 126; $i <= 250; $i++) {
            User::factory()->create([
                'id' => 10000000 + $i,
            ]);
        }

        $this->seedFeedback();
        $this->seedTrainings($faker);
    }

    /**
     * Fail loudly if the reference-data migration has not run.
     *
     * Without it there are no positions to attach feedback, endorsements and
     * examinations to, and the areas still carry upstream's Scandinavian names.
     * The failure without this check is a confusing null further down.
     */
    private function assertReferenceDataPresent(): void
    {
        if (DB::table('positions')->count() === 0) {
            throw new \RuntimeException(
                'No positions in the database. Run the VATSSA reference-data migration first: '
                .'php artisan migrate --path=database/migrations-vatssa'
            );
        }

        if (DB::table('areas')->where('id', 1)->value('name') !== 'Southern Africa') {
            throw new \RuntimeException(
                'Area 1 is not "Southern Africa". The VATSSA reference-data migration has not run, '
                .'or upstream changed the seeded areas. Check database/migrations-vatssa.'
            );
        }
    }

    /**
     * Fail loudly if a role key here has drifted from config/roles.php. Silent
     * drift produces role_user rows the matrix cannot resolve, which looks
     * identical to "the permission is wrong" when you test it.
     */
    private function assertRolesExist(): void
    {
        $configured = array_keys(config('roles.roles', []));

        $used = collect(self::FIXED_ACCOUNTS)
            ->flatMap(fn (array $account) => array_column($account['roles'], 0))
            ->unique();

        $missing = $used->diff($configured);

        if ($missing->isNotEmpty()) {
            throw new \RuntimeException(
                'Seeder uses role(s) not present in config/roles.php: '.$missing->implode(', ')
            );
        }
    }

    private function seedFixedAccounts(): void
    {
        foreach (self::FIXED_ACCOUNTS as $i => $account) {
            $ratingId = $account['rating'];

            $user = User::factory()->create([
                'id' => 10000000 + $i,
                'email' => $account['email'] ?? 'auth.dev'.$i.'@vatsim.net',
                'first_name' => $account['first'] ?? 'Web',
                'last_name' => $account['last'],
                'rating' => $ratingId,
                'rating_short' => FactoryHelper::shortRating($ratingId),
                'rating_long' => FactoryHelper::longRating(VatsimRating::from($ratingId)),
                'region' => self::REGION,
                'division' => self::DIVISION,
                'subdivision' => self::SUBDIVISION,
            ]);

            foreach ($account['roles'] as [$role, $areaId]) {
                $user->roleAssignments()->create(['role' => $role, 'area_id' => $areaId]);
            }
        }
    }

    private function seedFeedback(): void
    {
        $users = User::inRandomOrder()->limit(30)->get();
        $positions = Position::inRandomOrder()->limit(10)->get();

        for ($i = 0; $i < 20; $i++) {
            $submitter = $users->random();
            $referenceUser = $users->random();

            if ($positions->isNotEmpty() && rand(0, 3) > 0) {
                Feedback::factory()->create([
                    'submitter_user_id' => $submitter->id,
                    'reference_user_id' => $referenceUser->id,
                    'reference_position_id' => $positions->random()->id,
                ]);
            } else {
                Feedback::factory()->uncorrelated()->create([
                    'submitter_user_id' => $submitter->id,
                    'reference_user_id' => $referenceUser->id,
                ]);
            }
        }
    }

    private function seedTrainings(Faker\Generator $faker): void
    {
        for ($i = 1; $i <= rand(100, 125); $i++) {
            $training = Training::factory()->create();
            $training->ratings()->attach(Rating::where('vatsim_rating', '>', 1)->inRandomOrder()->first());

            // Give all non-queued trainings a mentor
            if ($training->status != TrainingStatus::IN_QUEUE->value) {
                $training->mentors()->attach(
                    User::whereHas('roleAssignments', function ($query) {
                        $query->where('role', 'mentor');
                    })->inRandomOrder()->first(),
                    ['expire_at' => now()->addYears(5)]
                );
                TrainingReport::factory()->create([
                    'training_id' => $training->id,
                    'written_by_id' => $training->mentors()->inRandomOrder()->first(),
                ]);
            }

            // Give all exam awaiting trainings a solo endorsement
            if ($training->status == TrainingStatus::AWAITING_EXAM->value
                || $training->status == TrainingStatus::COMPLETED->value) {
                if (! Endorsement::where('user_id', $training->user_id)->exists()) {
                    $soloEndorsement = Endorsement::factory()->create([
                        'user_id' => $training->user_id,
                        'type' => 'SOLO',
                        'valid_to' => Carbon::now()->addWeeks(4),
                    ]);

                    $soloEndorsement->positions()->save(Position::where('rating', '>', 1)->inRandomOrder()->first());
                }

                Lottery::odds(3, 1)
                    ->winner(fn () => TrainingExamination::factory()->create([
                        'training_id' => $training->id,
                        'examiner_id' => User::where('id', '!=', $training->user_id)
                            ->inRandomOrder()->first(),
                        'created_at' => $faker->dateTimeBetween(
                            $startDate = $training->started_at,
                            $endDate = 'now'),
                        'position_id' => Position::inRandomOrder()->first()->id,
                    ])
                    )
                    ->choose();
            }
        }
    }
}
