<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Position;
use App\Models\User;
use App\Services\CalendarFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarFeedEventsTest extends TestCase
{
    use RefreshDatabase;

    private const HUB = 'https://events.example.org';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.event_hub.url' => self::HUB]);
    }

    /**
     * @return array<string, mixed>
     */
    private function occurrence(int $id, Carbon $start, int $hours, string $name, array $airports = ['FAOR'], ?string $cancelledAt = null): array
    {
        return [
            'id' => $id,
            'startsAt' => $start->copy()->utc()->toIso8601String(),
            'endsAt' => $start->copy()->addHours($hours)->utc()->toIso8601String(),
            'cancelledAt' => $cancelledAt,
            'rosterPublished' => true,
            'staffingMode' => 'roster',
            'event' => [
                'id' => $id,
                'name' => $name,
                'summary' => 'Busy evening at ' . implode(', ', $airports) . '.',
                'url' => self::HUB . '/events/' . $id,
                'fir' => ['icao' => 'FAJA', 'name' => 'Johannesburg'],
                'airports' => $airports,
            ],
        ];
    }

    private function fakeHub(array $occurrences): void
    {
        Http::fake([self::HUB . '/api/v1/occurrences*' => Http::response(['data' => $occurrences, 'links' => ['next' => null]])]);
    }

    private function eventBooking(User $user, string $callsign, Carbon $start): Booking
    {
        $position = Position::factory()->create(['callsign' => $callsign]);

        return Booking::forceCreate([
            'callsign' => $callsign,
            'position_id' => $position->id,
            'name' => $user->name,
            'time_start' => $start->format('Y-m-d H:i:s'),
            'time_end' => $start->copy()->addHours(2)->format('Y-m-d H:i:s'),
            'user_id' => $user->id,
            'event' => true,
        ]);
    }

    private function feed(User $user): string
    {
        return $this->get(route('calendar.feed', ['token' => app(CalendarFeedService::class)->tokenFor($user)]))->assertOk()->getContent();
    }

    #[Test]
    public function toggle_is_hidden_and_refused_without_an_event_hub(): void
    {
        config(['services.event_hub.url' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('user.settings'))->assertOk()->assertDontSee('setting_calendar_include_events');
        $this->actingAs($user)->post(route('user.settings.calendar'), ['setting_calendar_include_events' => '1'])->assertNotFound();
    }

    #[Test]
    public function member_can_switch_events_on_and_off(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('user.settings'))->assertOk()->assertSee('setting_calendar_include_events');

        $this->actingAs($user)->post(route('user.settings.calendar'), ['setting_calendar_include_events' => '1'])
            ->assertRedirect(route('user.settings') . '#calendar-subscription');
        $this->assertTrue((bool) $user->fresh()->setting_calendar_include_events);

        $this->actingAs($user)->post(route('user.settings.calendar'));
        $this->assertFalse((bool) $user->fresh()->setting_calendar_include_events);
    }

    #[Test]
    public function events_stay_out_by_default_but_event_bookings_are_still_named(): void
    {
        $start = Carbon::tomorrow()->setHour(16);
        $this->fakeHub([$this->occurrence(7, $start, 5, 'Joburg Rush')]);
        $user = User::factory()->create();
        $this->eventBooking($user, 'FAOR_APP', $start->copy()->addHour());

        $body = $this->feed($user);

        $this->assertStringNotContainsString('SUMMARY:Event:', $body);
        $this->assertStringContainsString('SUMMARY:FAOR_APP - Joburg Rush', $body);
    }

    #[Test]
    public function without_an_event_hub_the_feed_never_calls_out(): void
    {
        config(['services.event_hub.url' => null]);
        Http::fake();

        $this->feed(User::factory()->create(['setting_calendar_include_events' => true]));

        Http::assertNothingSent();
    }

    #[Test]
    public function the_events_calendar_is_public_and_holds_only_events(): void
    {
        $start = Carbon::tomorrow()->setHour(16);
        $this->fakeHub([$this->occurrence(7, $start, 5, 'Joburg Rush')]);
        $this->eventBooking(User::factory()->create(), 'FAOR_APP', $start->copy()->addHour());

        $response = $this->get(route('calendar.events'))->assertOk();

        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertStringContainsString('X-WR-CALNAME:' . config('app.owner_name_short') . ' events', $response->getContent());
        $this->assertStringContainsString('SUMMARY:Event: Joburg Rush', $response->getContent());
        $this->assertStringNotContainsString('FAOR_APP', $response->getContent());
        $this->assertSame(1, substr_count($response->getContent(), 'BEGIN:VEVENT'));
    }

    #[Test]
    public function the_events_calendar_is_not_found_without_an_event_hub(): void
    {
        config(['services.event_hub.url' => null]);

        $this->get('/api/calendar/events.ics')->assertNotFound();
    }

    #[Test]
    public function settings_offer_both_calendars(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('user.settings'))
            ->assertOk()
            ->assertSee('Your calendar link')
            ->assertSee('Events calendar link')
            ->assertSee(route('calendar.events'))
            ->assertSee('webcal://', false);
    }

    #[Test]
    public function opted_in_feed_has_every_event_and_names_the_members_event_booking(): void
    {
        $start = Carbon::tomorrow()->setHour(16);
        $this->fakeHub([
            $this->occurrence(7, $start, 5, 'Joburg Rush'),
            $this->occurrence(8, $start->copy()->addDays(7), 4, 'Cape Town Fly-in', ['FACT']),
            $this->occurrence(9, $start->copy()->addDays(14), 4, 'Called Off', ['FALE'], now()->toIso8601String()),
        ]);

        $user = User::factory()->create(['setting_calendar_include_events' => true]);
        $this->eventBooking($user, 'FAOR_APP', $start->copy()->addHour());

        $body = $this->feed($user);

        $this->assertStringContainsString('SUMMARY:Event: Joburg Rush', $body);
        $this->assertStringContainsString('SUMMARY:Event: Cape Town Fly-in', $body);
        $this->assertStringNotContainsString('Called Off', $body);
        $this->assertStringContainsString('UID:event-occurrence-7@events.example.org', $body);
        $this->assertStringContainsString('SUMMARY:FAOR_APP - Joburg Rush', $body);
        $this->assertSame(3, substr_count($body, 'BEGIN:VEVENT'));
    }

    #[Test]
    public function every_page_of_the_hub_listing_is_read(): void
    {
        $start = Carbon::tomorrow()->setHour(16);
        Http::fake(function (Request $request) use ($start) {
            $page = (int) ($request->data()['page'] ?? 1);

            return Http::response([
                'data' => [$this->occurrence($page, $start->copy()->addDays($page), 3, 'Event ' . $page)],
                'links' => ['next' => $page < 3 ? self::HUB . '/api/v1/occurrences?page=' . ($page + 1) : null],
            ]);
        });

        $user = User::factory()->create(['setting_calendar_include_events' => true]);

        $this->assertSame(3, substr_count($this->feed($user), 'SUMMARY:Event: '));
    }

    #[Test]
    public function the_hub_is_called_once_for_many_members(): void
    {
        $this->fakeHub([$this->occurrence(1, Carbon::tomorrow()->setHour(16), 3, 'Shared')]);

        foreach (User::factory()->count(3)->create(['setting_calendar_include_events' => true]) as $user) {
            $this->assertStringContainsString('SUMMARY:Event: Shared', $this->feed($user));
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_hub_outage_serves_the_last_known_events_and_never_breaks_the_feed(): void
    {
        $user = User::factory()->create(['setting_calendar_include_events' => true]);
        $this->eventBooking($user, 'FAOR_TWR', Carbon::tomorrow()->setHour(12));

        Http::fake([self::HUB . '/*' => Http::response('down', 503)]);
        $body = $this->feed($user);
        $this->assertStringContainsString('SUMMARY:FAOR_TWR (Event)', $body);
        $this->assertStringNotContainsString('SUMMARY:Event:', $body);

        Cache::forever('event-hub:occurrences:last', [[
            'id' => 4, 'startsAt' => Carbon::tomorrow()->setHour(18)->toIso8601String(), 'endsAt' => Carbon::tomorrow()->setHour(21)->toIso8601String(),
            'name' => 'Remembered', 'summary' => '', 'url' => '', 'fir' => 'Johannesburg', 'airports' => [],
        ]]);

        $this->assertStringContainsString('SUMMARY:Event: Remembered', $this->feed($user));
    }

    #[Test]
    public function overlapping_events_are_told_apart_by_airport(): void
    {
        $start = Carbon::tomorrow()->setHour(16);
        $this->fakeHub([
            $this->occurrence(1, $start, 5, 'Joburg Rush', ['FAOR']),
            $this->occurrence(2, $start, 5, 'Cape Town Fly-in', ['FACT']),
        ]);

        $user = User::factory()->create(['setting_calendar_include_events' => true]);
        $this->eventBooking($user, 'FACT_TWR', $start->copy()->addHour());

        $this->assertStringContainsString('SUMMARY:FACT_TWR - Cape Town Fly-in', $this->feed($user));
    }

    #[Test]
    public function a_position_opening_before_the_event_still_belongs_to_it(): void
    {
        $start = Carbon::tomorrow()->setHour(16);
        $this->fakeHub([$this->occurrence(7, $start, 5, 'Joburg Rush')]);

        $user = User::factory()->create();
        $this->eventBooking($user, 'FAOR_APP', $start->copy()->subMinutes(30));

        $this->assertStringContainsString('SUMMARY:FAOR_APP - Joburg Rush', $this->feed($user));
    }

    #[Test]
    public function the_events_calendar_asks_to_retry_rather_than_serving_nothing(): void
    {
        Http::fake([self::HUB . '/*' => Http::response('down', 503)]);

        $this->get(route('calendar.events'))->assertStatus(503)->assertHeader('Retry-After', '300');
    }

    #[Test]
    public function a_failing_hub_is_left_alone_for_a_minute(): void
    {
        Http::fake([self::HUB . '/*' => Http::response('down', 503)]);
        $user = User::factory()->create();

        $this->feed($user);
        $this->feed($user);
        Http::assertSentCount(1);

        $this->travel(2)->minutes();
        $this->feed($user);
        Http::assertSentCount(2);
    }

    #[Test]
    public function malformed_hub_dates_are_skipped_instead_of_breaking_the_feed(): void
    {
        $good = $this->occurrence(1, Carbon::tomorrow()->setHour(16), 3, 'Good one');
        $bad = [...$this->occurrence(2, Carbon::tomorrow()->setHour(18), 3, 'Bad one'), 'startsAt' => 'not a date'];
        $backwards = [...$this->occurrence(3, Carbon::tomorrow()->setHour(20), 3, 'Backwards'), 'endsAt' => Carbon::tomorrow()->setHour(19)->toIso8601String()];
        $this->fakeHub([$good, $bad, $backwards]);

        $body = $this->get(route('calendar.events'))->assertOk()->getContent();

        $this->assertStringContainsString('SUMMARY:Event: Good one', $body);
        $this->assertSame(1, substr_count($body, 'BEGIN:VEVENT'));
    }
}
