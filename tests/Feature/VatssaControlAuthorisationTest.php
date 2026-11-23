<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AtcActivity;
use App\Models\Endorsement;
use App\Models\Rating;
use App\Models\Training;
use App\Models\User;
use App\Services\VatssaControlAuthorisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VatssaControlAuthorisationTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['DEL', 'GND', 'TWR', 'APP', 'CTR', 'FSS'];

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.owner_code' => 'SSA']);
        $this->area = Area::factory()->create();
    }

    private function person(int $rating, bool $roster = true, string $division = 'SSA'): User
    {
        $user = User::factory()->create(['rating' => $rating, 'division' => $division]);
        if ($roster) {
            AtcActivity::create(['user_id' => $user->id, 'area_id' => $this->area->id, 'hours' => 50, 'atc_active' => true]);
        }

        return $user;
    }

    private function train(User $user, int $status, int $type = 1): Training
    {
        return Training::factory()->create(['user_id' => $user->id, 'area_id' => $this->area->id, 'status' => $status, 'type' => $type]);
    }

    private function endorse(User $user, string $type, ?string $rating = null): void
    {
        $endorsement = Endorsement::factory()->create(['user_id' => $user->id, 'type' => $type, 'revoked' => false, 'expired' => false, 'valid_to' => now()->addMonth()]);
        if ($rating) {
            $endorsement->ratings()->attach((Rating::where('name', $rating)->first() ?? Rating::forceCreate(['name' => $rating, 'description' => $rating]))->id);
        }
    }

    private function positions(User $user): array
    {
        $check = app(VatssaControlAuthorisation::class);
        $user = $user->fresh(['endorsements.ratings', 'trainings', 'atcActivity']);

        return array_values(array_filter(self::ALL, fn ($type) => $check->problem($user, $type) === null));
    }

    #[Test]
    public function each_rating_covers_everything_below_it(): void
    {
        foreach ([1 => 'GND', 2 => 'GND', 3 => 'TWR', 4 => 'APP', 5 => 'CTR', 12 => 'CTR'] as $rating => $top) {
            $expected = array_slice(self::ALL, 0, array_search($top, self::ALL, true) + 1);
            $this->assertSame($expected, $this->positions($this->person($rating)), "rating {$rating}");
        }
    }

    #[Test]
    public function only_standard_and_fast_track_control_one_rating_higher(): void
    {
        foreach ([1 => true, 4 => true, 2 => false, 3 => false, 5 => false] as $type => $higher) {
            foreach ([2, 3] as $status) {
                $user = $this->person(3, false); // S2
                $this->train($user, $status, $type);
                $this->assertSame($higher ? ['DEL', 'GND', 'TWR', 'APP'] : ['DEL', 'GND', 'TWR'], $this->positions($user), "type {$type} status {$status}");
            }
        }
    }

    #[Test]
    public function training_outside_active_or_awaiting_exam_is_ignored_for_every_type(): void
    {
        foreach ([1, 2, 3, 4, 5] as $type) {
            foreach ([0, 1, -1, -2] as $status) {
                $user = $this->person(3);
                $this->train($user, $status, $type);
                $this->assertSame(['DEL', 'GND', 'TWR'], $this->positions($user), "type {$type} status {$status}");
            }
        }
    }

    #[Test]
    public function active_training_lifts_s1_to_s3_one_step(): void
    {
        foreach ([1 => 'GND', 2 => 'TWR', 3 => 'APP', 4 => 'CTR', 5 => 'CTR'] as $rating => $top) {
            $user = $this->person($rating, false);
            $this->train($user, 2);
            $expected = array_slice(self::ALL, 0, array_search($top, self::ALL, true) + 1);
            $this->assertSame($expected, $this->positions($user), "rating {$rating}");
        }
    }

    #[Test]
    public function only_active_training_and_awaiting_exam_authorise(): void
    {
        foreach ([2 => true, 3 => true, 1 => false, 0 => false, -1 => false, -2 => false] as $status => $allowed) {
            $user = $this->person(3, false);
            $this->train($user, $status);
            $this->assertSame($allowed, $this->positions($user) !== [], "status {$status}");
        }
    }

    #[Test]
    public function visitors_stop_at_their_endorsement_and_solo_alone_adds_no_step(): void
    {
        $visitor = $this->person(5, false, 'EUD'); // C1 with an S3 visiting endorsement
        $this->endorse($visitor, 'VISITING', 'S3');
        $this->assertSame(['DEL', 'GND', 'TWR', 'APP'], $this->positions($visitor));
        $this->assertSame('Visiting endorsement does not cover CTR', app(VatssaControlAuthorisation::class)->problem($visitor->fresh(['endorsements.ratings', 'trainings', 'atcActivity']), 'CTR'));

        $solo = $this->person(2, false);
        $this->endorse($solo, 'SOLO', 'S2');
        $this->assertSame(['DEL', 'GND'], $this->positions($solo));
        $this->train($solo, 2);
        $this->assertSame(['DEL', 'GND', 'TWR'], $this->positions($solo));
    }

    #[Test]
    public function fss_needs_an_fss_endorsement(): void
    {
        $user = $this->person(5);
        $this->assertNotContains('FSS', $this->positions($user));
        $this->endorse($user, 'FACILITY', 'AFRx FSS');
        $this->assertContains('FSS', $this->positions($user));
    }

    #[Test]
    public function nobody_without_a_cc_account_or_authorisation_may_control(): void
    {
        $check = app(VatssaControlAuthorisation::class);
        $this->assertSame('No Control Center account', $check->problem(null, 'TWR'));
        $this->assertNotNull($check->problem($this->person(5, false)->fresh(), 'DEL'));
        $this->assertNull($check->problem($this->person(5)->fresh()));
    }

    #[Test]
    public function the_bot_gets_only_the_problems(): void
    {
        config(['vatssa.discord_bot_token' => str_repeat('a', 32)]);
        $s2 = $this->person(3);
        $url = route('api.vatssa.controller-authorisation');
        $body = ['controllers' => [
            ['callsign' => 'FACT_TWR', 'cid' => $s2->id, 'type' => 'TWR'],
            ['callsign' => 'FACT_APP', 'cid' => $s2->id, 'type' => 'APP'],
            ['callsign' => 'FALE_GND', 'cid' => 9999999, 'type' => 'GND'],
        ]];

        $this->postJson($url, $body)->assertUnauthorized();
        $answer = $this->postJson($url, $body, ['Authorization' => 'Bearer ' . str_repeat('a', 32)])->assertOk();
        $this->assertSame(['FACT_APP', 'FALE_GND'], array_keys($answer->json('problems')));
        $this->assertSame('No Control Center account', $answer->json('problems.FALE_GND'));
    }
}
