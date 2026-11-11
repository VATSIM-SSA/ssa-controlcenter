<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Position;
use App\Models\Sweatbook;
use App\Models\User;
use App\Models\Vote;
use App\Services\BookingService;
use App\Support\DisplayTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocalTimeTest extends TestCase
{
    use RefreshDatabase;

    private function mentor(?string $timezone = null): User
    {
        $mentor = User::factory()->create(['setting_timezone' => $timezone]);
        $mentor->roleAssignments()->create(['role' => 'mentor', 'area_id' => Area::factory()->create()->id]);

        return $mentor;
    }

    private function sweatbook(User $user, Position $position, Carbon $start, Carbon $end): Sweatbook
    {
        $booking = new Sweatbook();
        $booking->user_id = $user->id;
        $booking->position_id = $position->id;
        $booking->time_start = $start;
        $booking->time_end = $end;
        $booking->save();

        return $booking;
    }

    #[Test]
    public function times_stay_zulu_by_default(): void
    {
        $mentor = $this->mentor();
        $position = Position::factory()->create();
        $this->sweatbook($mentor, $position, Carbon::tomorrow()->setTime(17, 0), Carbon::tomorrow()->setTime(19, 0));

        $this->actingAs($mentor)->get('/sweatbook')
            ->assertOk()
            ->assertSee('Start (Zulu)')
            ->assertSee('17:00z')
            ->assertSee('19:00z');
    }

    #[Test]
    public function times_show_in_the_users_zone(): void
    {
        $mentor = $this->mentor('Africa/Johannesburg');
        $position = Position::factory()->create();
        $this->sweatbook($mentor, $position, Carbon::tomorrow()->setTime(17, 0), Carbon::tomorrow()->setTime(19, 0));

        $this->actingAs($mentor)->get('/sweatbook')
            ->assertOk()
            ->assertSee('Start (local, SAST)')
            ->assertSee('19:00 SAST')
            ->assertSee('21:00 SAST')
            ->assertDontSee('17:00z');
    }

    #[Test]
    public function a_late_zulu_booking_shows_on_the_next_local_day(): void
    {
        $mentor = $this->mentor('Africa/Johannesburg');
        $position = Position::factory()->create();
        $day = Carbon::now()->addDays(3);
        $this->sweatbook($mentor, $position, $day->copy()->setTime(23, 0), $day->copy()->addDay()->setTime(1, 0));

        $this->actingAs($mentor)->get('/sweatbook')
            ->assertOk()
            ->assertSee($day->copy()->addDay()->format('D. d/m/Y'))
            ->assertSee('01:00 SAST');
    }

    #[Test]
    public function booking_forms_take_times_in_the_users_zone(): void
    {
        $mentor = $this->mentor('Africa/Johannesburg');
        $position = Position::factory()->create();
        $day = Carbon::now()->addDays(2);

        $this->actingAs($mentor)->post('/sweatbook/store', [
            'date' => $day->format('d/m/Y'),
            'start_at' => '19:00',
            'end_at' => '21:30',
            'position' => $position->callsign,
        ])->assertSessionHasNoErrors();

        $booking = Sweatbook::sole();
        $this->assertSame($day->format('Y-m-d') . ' 17:00:00', $booking->time_start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($day->format('Y-m-d') . ' 19:30:00', $booking->time_end->utc()->format('Y-m-d H:i:s'));

        $this->actingAs($mentor)->get('/sweatbook/' . $booking->id)
            ->assertSee('value="19:00"', false)
            ->assertSee('value="21:30"', false);
    }

    #[Test]
    public function an_early_local_booking_lands_on_the_previous_zulu_day(): void
    {
        $mentor = $this->mentor('Africa/Johannesburg');
        $position = Position::factory()->create();
        $day = Carbon::now()->addDays(2);

        $this->actingAs($mentor)->post('/sweatbook/store', [
            'date' => $day->format('d/m/Y'),
            'start_at' => '01:00',
            'end_at' => '03:00',
            'position' => $position->callsign,
        ])->assertSessionHasNoErrors();

        $booking = Sweatbook::sole();
        $this->assertSame($day->copy()->subDay()->format('Y-m-d') . ' 23:00:00', $booking->time_start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($day->format('Y-m-d') . ' 01:00:00', $booking->time_end->utc()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_local_atc_booking_over_midnight_ends_on_the_right_day(): void
    {
        $this->actingAs(User::factory()->create(['setting_timezone' => 'Africa/Johannesburg']));
        $service = app(BookingService::class);

        [$start, $end] = $service->parsePeriod('01/12/2026', '23:00', '01:00');
        $service->adjustForOvernight($start, $end);

        $this->assertSame('2026-12-01 21:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-01 23:00:00', $end->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $start->getTimezone()->getName());
    }

    #[Test]
    public function user_can_choose_a_zone_and_go_back_to_zulu(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('user.settings'))->assertOk()->assertSee('Show times in');

        $this->actingAs($user)->post(route('user.settings.store'), ['setting_theme' => 'system', 'setting_timezone' => 'Europe/Copenhagen'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Europe/Copenhagen', $user->fresh()->setting_timezone);

        $this->actingAs($user)->post(route('user.settings.store'), ['setting_theme' => 'system', 'setting_timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('setting_timezone');
        $this->assertSame('Europe/Copenhagen', $user->fresh()->setting_timezone);

        $this->actingAs($user)->post(route('user.settings.store'), ['setting_theme' => 'system', 'setting_timezone' => '']);
        $this->assertNull($user->fresh()->setting_timezone);
    }

    #[Test]
    public function zulu_macros_used_by_emails_ignore_the_users_zone(): void
    {
        $this->actingAs(User::factory()->create(['setting_timezone' => 'Africa/Johannesburg']));
        $moment = Carbon::parse('2026-12-01 17:00:00', 'UTC');

        $this->assertSame('17:00z', $moment->toEuropeanTime());
        $this->assertSame('01/12/2026 17:00z', $moment->toEuropeanDateTime());
        $this->assertSame('19:00 SAST', $moment->toUserTime());
        $this->assertSame('01/12/2026 19:00 SAST', $moment->toUserDateTime());
        $this->assertSame('17:00:00', $moment->format('H:i:s'));
    }

    #[Test]
    public function the_header_clock_shows_zulu_and_local_time(): void
    {
        $mentor = $this->mentor('Africa/Johannesburg');
        $this->travelTo(Carbon::parse('2026-12-01 14:48:00', 'UTC'));

        $this->actingAs($mentor)->get('/sweatbook')->assertOk()->assertSee('14:48z · 16:48 SAST');

        $mentor->forceFill(['setting_timezone' => null])->save();
        $this->actingAs($mentor->fresh())->get('/sweatbook')->assertOk()->assertSee('14:48z<', false);
    }

    #[Test]
    public function a_vote_end_time_is_typed_in_the_users_zone(): void
    {
        $admin = User::factory()->create(['setting_timezone' => 'Africa/Johannesburg']);
        $admin->roleAssignments()->create(['role' => 'admin', 'area_id' => null]);
        $day = Carbon::now()->addDays(3);

        $this->actingAs($admin)->get(route('vote.create'))->assertSee('End Time (local, SAST)');

        $this->actingAs($admin)->post(route('vote.store'), [
            'expire_date' => $day->format('d/m/Y'),
            'expire_time' => '20:00',
            'question' => 'Pizza or braai?',
            'vote_options' => "Pizza\nBraai",
        ])->assertSessionHasNoErrors();

        $this->assertSame($day->format('Y-m-d') . ' 18:00:00', Carbon::parse(Vote::sole()->end_at)->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function today_is_judged_in_the_users_zone(): void
    {
        // 02:00z on 11 November is still 21:00 on 10 November in New York.
        $this->travelTo(Carbon::parse('2026-11-11 02:00:00', 'UTC'));
        $mentor = $this->mentor('America/New_York');
        $position = Position::factory()->create();

        $this->actingAs($mentor)->post('/sweatbook/store', [
            'date' => '10/11/2026',
            'start_at' => '22:00',
            'end_at' => '23:30',
            'position' => $position->callsign,
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-11-11 03:00:00', Sweatbook::sole()->time_start->utc()->format('Y-m-d H:i:s'));

        $this->actingAs($mentor)->post('/sweatbook/store', [
            'date' => '09/11/2026',
            'start_at' => '22:00',
            'end_at' => '23:30',
            'position' => $position->callsign,
        ])->assertSessionHasErrors('date');
    }

    #[Test]
    public function an_unknown_stored_zone_falls_back_to_zulu(): void
    {
        $this->actingAs(User::factory()->create(['setting_timezone' => 'Not/AZone']));

        $this->assertSame(DisplayTime::ZULU, DisplayTime::zone());
        $this->assertSame('17:00z', Carbon::parse('2026-12-01 17:00:00', 'UTC')->toUserTime());
    }
}
