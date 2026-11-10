<?php

namespace Tests\Feature;

use App\Helpers\VatsimRating;
use App\Models\Booking;
use App\Models\Position;
use App\Models\Sweatbook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    private function createBooking(User $user, Position $position, Carbon $start, array $attributes = []): Booking
    {
        return Booking::forceCreate([
            'callsign' => $position->callsign,
            'position_id' => $position->id,
            'name' => $user->name,
            'time_start' => $start->format('Y-m-d H:i:s'),
            'time_end' => $start->copy()->addHours(2)->format('Y-m-d H:i:s'),
            'user_id' => $user->id,
            ...$attributes,
        ]);
    }

    private function createSweatbook(User $user, Position $position, Carbon $start): Sweatbook
    {
        $sweatbook = new Sweatbook();
        $sweatbook->user_id = $user->id;
        $sweatbook->position_id = $position->id;
        $sweatbook->time_start = $start;
        $sweatbook->time_end = $start->copy()->addHours(2);
        $sweatbook->mentor_notes = 'Radar vectoring drill';
        $sweatbook->save();

        return $sweatbook;
    }

    #[Test]
    public function unknown_token_returns_not_found(): void
    {
        $this->get('/api/calendar/' . str_repeat('a', 48) . '.ics')->assertNotFound();
    }

    #[Test]
    public function feed_contains_only_the_users_active_bookings(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $position = Position::factory()->create(['callsign' => 'FAOR_APP', 'name' => 'Johannesburg Approach']);
        $start = Carbon::tomorrow()->setHour(18);

        $booking = $this->createBooking($user, $position, $start, ['training' => true]);
        $deleted = $this->createBooking($user, $position, $start->copy()->addDay(), ['deleted' => true]);
        $old = $this->createBooking($user, $position, Carbon::now()->subDays(60));
        $othersBooking = $this->createBooking($other, $position, $start->copy()->addDays(2));
        $sweatbook = $this->createSweatbook($user, $position, $start->copy()->addDays(3));

        $response = $this->get(route('calendar.feed', ['token' => $user->ensureCalendarFeedToken()]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

        $body = $response->getContent();
        $this->assertStringContainsString('UID:booking-' . $booking->id . '@', $body);
        $this->assertStringContainsString('SUMMARY:FAOR_APP (Training)', $body);
        $this->assertStringContainsString('LOCATION:FAOR_APP - Johannesburg Approach', $body);
        $this->assertStringContainsString('DTSTART:' . $start->copy()->utc()->format('Ymd\THis\Z'), $body);
        $this->assertStringContainsString('UID:sweatbook-' . $sweatbook->id . '@', $body);
        $this->assertStringContainsString('SUMMARY:Sweatbox: FAOR_APP', $body);
        $this->assertStringContainsString('DESCRIPTION:Radar vectoring drill', $body);
        $this->assertStringNotContainsString('UID:booking-' . $deleted->id . '@', $body);
        $this->assertStringNotContainsString('UID:booking-' . $old->id . '@', $body);
        $this->assertStringNotContainsString('UID:booking-' . $othersBooking->id . '@', $body);
        $this->assertSame(2, substr_count($body, 'BEGIN:VEVENT'));
    }

    #[Test]
    public function suspended_users_feed_returns_not_found(): void
    {
        $user = User::factory()->create(['rating' => VatsimRating::SUS]);

        $this->get(route('calendar.feed', ['token' => $user->ensureCalendarFeedToken()]))->assertNotFound();
    }

    #[Test]
    public function settings_page_shows_a_stable_subscription_link(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('user.settings'))
            ->assertOk()
            ->assertSee('Calendar subscription')
            ->assertSee('calendar.google.com', false)
            ->assertSee('webcal://', false);

        $token = $user->fresh()->calendar_feed_token;
        $this->assertSame(48, strlen($token));

        $this->actingAs($user)->get(route('user.settings'))->assertSee($token);
        $this->assertSame($token, $user->fresh()->calendar_feed_token);
    }

    #[Test]
    public function resetting_the_link_revokes_the_old_one(): void
    {
        $user = User::factory()->create();
        $oldToken = $user->ensureCalendarFeedToken();

        $this->actingAs($user)->post(route('user.settings.calendar.reset'))
            ->assertRedirect(route('user.settings') . '#calendar-subscription');

        $newToken = $user->fresh()->calendar_feed_token;
        $this->assertNotSame($oldToken, $newToken);

        $this->get(route('calendar.feed', ['token' => $oldToken]))->assertNotFound();
        $this->get(route('calendar.feed', ['token' => $newToken]))->assertOk();
    }

    #[Test]
    public function reset_requires_login(): void
    {
        $this->post(route('user.settings.calendar.reset'))->assertRedirect(route('login'));
    }

    #[Test]
    public function token_is_never_serialized(): void
    {
        $user = User::factory()->create();
        $user->ensureCalendarFeedToken();

        $this->assertArrayNotHasKey('calendar_feed_token', $user->fresh()->toArray());
    }
}
