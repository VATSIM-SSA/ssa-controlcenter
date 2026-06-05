<?php

namespace Database\Seeders;

use App\Helpers\FactoryHelper;
use App\Helpers\TrainingStatus;
use App\Models\Endorsement;
use App\Models\Group;
use App\Models\Position;
use App\Models\Rating;
use App\Models\Training;
use App\Models\TrainingExamination;
use App\Models\TrainingReport;
use App\Models\User;
use Carbon\Carbon;
use Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Lottery;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $faker = Faker\Factory::create();

        // Create the default dev accounts corresponding to VATSIM Connect
        for ($i = 1; $i <= 11; $i++) {
            $name_first = 'Web';
            $name_last = 'X';
            $email = 'auth.dev' . $i . '@vatsim.net';

            $rating_id = 1;
            $group = null;

            switch ($i) {
                case 1:
                    $name_last = 'One';
                    break;
                case 2:
                    $name_last = 'Two';
                    $rating_id = 2;
                    break;
                case 3:
                    $name_last = 'Three';
                    $rating_id = 3;
                    break;
                case 4:
                    $name_last = 'Four';
                    $rating_id = 4;
                    break;
                case 5:
                    $name_last = 'Five';
                    $rating_id = 5;
                    break;
                case 6:
                    $name_last = 'Six';
                    $rating_id = 7;
                    break;
                case 7:
                    $name_last = 'Seven';
                    $rating_id = 8;
                    $group = 3;
                    break;
                case 8:
                    $name_last = 'Eight';
                    $rating_id = 10;
                    $group = 3;
                    break;
                case 9:
                    $name_last = 'Nine';
                    $rating_id = 11;
                    $group = 2;
                    break;
                case 10:
                    $name_first = 'Team';
                    $name_last = 'Web';
                    $rating_id = 12;
                    $email = 'noreply@vatsim.net';
                    $group = 1;
                    break;
                case 11:
                    $name_first = 'Suspended';
                    $name_last = 'User';
                    $rating_id = 0;
                    $email = 'suspended@vatsim.net';
                    break;
            }

            User::factory()->create([
                'id' => 10000000 + $i,
                'email' => $email,
                'first_name' => $name_first,
                'last_name' => $name_last,
                'rating' => $rating_id,
                'rating_short' => FactoryHelper::shortRating($rating_id),
                'rating_long' => FactoryHelper::longRating($rating_id),
                'region' => 'EMEA',
                'division' => 'SSA',
                'subdivision' => 'SSA',
            ])->groups()->attach(Group::find($group), ['area_id' => 1]);
        }

        // Create random regional users
        for ($i = 12; $i <= 125; $i++) {
            User::factory()->create([
                'id' => 10000000 + $i,
                'region' => 'EMEA',
                'division' => 'SSA',
                'subdivision' => 'SSA',
            ]);
        }

        // Create random users
        for ($i = 126; $i <= 250; $i++) {
            User::factory()->create([
                'id' => 10000000 + $i,
            ]);
        }

        // Target the regional users we just generated to safely build training profiles
        $regionalUsers = User::whereBetween('id', [10000012, 10000125])->get();

        // Randomly select users to fulfill roughly the 100-125 count allocation safely
        $usersToTrain = $regionalUsers->random(min(rand(100, 110), $regionalUsers->count()));

        foreach ($usersToTrain as $user) {
            // Explicitly associate the training with the correct user
            // We force area_id to 1 to match the group instantiation above, stopping FK constraint crashes
            $training = Training::factory()->create([
                'user_id' => $user->id,
                'area_id' => 1,
            ]);

            $randomRating = Rating::where('vatsim_rating', '>', 1)->inRandomOrder()->first();
            if ($randomRating) {
                $training->ratings()->attach($randomRating);
            }

            // Give all non-queued trainings a mentor
            if ($training->status != TrainingStatus::IN_QUEUE->value) {
                $mentor = User::whereHas('groups', function ($query) {
                    $query->where('id', 3);
                })->inRandomOrder()->first();

                if ($mentor) {
                    $training->mentors()->attach($mentor->id, ['expire_at' => now()->addYears(5)]);

                    TrainingReport::factory()->create([
                        'training_id' => $training->id,
                        'written_by_id' => $mentor->id,
                    ]);
                }
            }

            // Give all exam awaiting or completed trainings a solo endorsement
            if ($training->status == TrainingStatus::AWAITING_EXAM->value
                || $training->status == TrainingStatus::COMPLETED->value) {

                if (! Endorsement::where('user_id', $training->user_id)->exists()) {
                    $soloEndorsement = Endorsement::factory()->create([
                        'user_id' => $training->user_id,
                        'type' => 'SOLO',
                        'valid_to' => Carbon::now()->addWeeks(4),
                    ]);

                    // Safe lookup for positions table reference
                    $position = Position::where('rating', '>', 1)->inRandomOrder()->first();
                    if ($position) {
                        $soloEndorsement->positions()->save($position);
                    }
                }

                // Append an examination result via Lottery
                $examiner = User::where('id', '!=', $training->user_id)->inRandomOrder()->first();
                $examPosition = Position::inRandomOrder()->first();

                if ($examiner && $examPosition) {
                    Lottery::odds(3, 1)
                        ->winner(fn () => TrainingExamination::factory()->create([
                            'training_id' => $training->id,
                            'examiner_id' => $examiner->id,
                            'created_at' => $faker->dateTimeBetween($training->started_at, 'now'),
                            'position_id' => $examPosition->id,
                        ]))
                        ->choose();
                }
            }
        }
    }
}
