<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Sweatbook;
use App\Models\User;
use App\Support\Calendar\IcsCalendar;
use App\Support\Calendar\IcsEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds a user's personal calendar feed from their ATC and sweatbox bookings.
 */
class CalendarFeedService
{
    /**
     * How many days back the feed reaches, so recent sessions stay visible.
     */
    public const PAST_DAYS = 30;

    /**
     * How many days ahead the feed reaches.
     */
    public const FUTURE_DAYS = 365;

    public function forUser(User $user): string
    {
        return IcsCalendar::render(
            name: config('app.owner_name_short') . ' bookings',
            events: $this->events($user),
        );
    }

    /**
     * The feed URL and the one-click subscribe links for each calendar app.
     *
     * @return array{feed: string, webcal: string, google: string, outlook: string}
     */
    public function subscriptionLinks(User $user): array
    {
        $feed = route('calendar.feed', ['token' => $user->ensureCalendarFeedToken()]);
        $webcal = preg_replace('#^https?://#', 'webcal://', $feed);

        return [
            'feed' => $feed,
            'webcal' => $webcal,
            'google' => 'https://calendar.google.com/calendar/render?cid=' . rawurlencode($webcal),
            'outlook' => 'https://outlook.live.com/owa/?path=%2Fcalendar%2Faction%2Fcompose&rru=addsubscription'
                . '&url=' . rawurlencode($feed)
                . '&name=' . rawurlencode(config('app.owner_name_short') . ' bookings'),
        ];
    }

    /**
     * @return Collection<int, IcsEvent>
     */
    public function events(User $user): Collection
    {
        $from = Carbon::now()->subDays(self::PAST_DAYS);
        $to = Carbon::now()->addDays(self::FUTURE_DAYS);

        $bookings = Booking::query()
            ->with('position')
            ->where('user_id', $user->id)
            ->where('deleted', false)
            ->where('time_end', '>=', $from)
            ->where('time_start', '<=', $to)
            ->get()
            ->map(fn (Booking $booking): IcsEvent => $this->bookingEvent($booking));

        $sweatbooks = Sweatbook::query()
            ->with('position')
            ->where('user_id', $user->id)
            ->where('time_end', '>=', $from)
            ->where('time_start', '<=', $to)
            ->get()
            ->map(fn (Sweatbook $sweatbook): IcsEvent => $this->sweatbookEvent($sweatbook));

        return $bookings
            ->concat($sweatbooks)
            ->sortBy(fn (IcsEvent $event): int => $event->startsAt->getTimestamp())
            ->values();
    }

    private function bookingEvent(Booking $booking): IcsEvent
    {
        $tags = array_keys(array_filter([
            'Training' => (bool) $booking->training,
            'Event' => (bool) $booking->event,
            'Exam' => (bool) $booking->exam,
        ]));

        $summary = $booking->callsign . ($tags === [] ? '' : ' (' . implode(', ', $tags) . ')');

        return new IcsEvent(
            uid: 'booking-' . $booking->id . '@' . $this->host(),
            summary: $summary,
            startsAt: $booking->starts_at,
            endsAt: $booking->ends_at,
            description: 'ATC booking on ' . $booking->callsign . '.',
            location: $this->positionLabel($booking->callsign, $booking->position?->name),
            url: route('booking'),
        );
    }

    private function sweatbookEvent(Sweatbook $sweatbook): IcsEvent
    {
        $callsign = $sweatbook->position?->callsign ?? 'Sweatbox';

        return new IcsEvent(
            uid: 'sweatbook-' . $sweatbook->id . '@' . $this->host(),
            summary: 'Sweatbox: ' . $callsign,
            startsAt: $sweatbook->time_start,
            endsAt: $sweatbook->time_end,
            description: (string) $sweatbook->mentor_notes,
            location: $this->positionLabel($callsign, $sweatbook->position?->name),
            url: route('sweatbook'),
        );
    }

    private function positionLabel(string $callsign, ?string $name): string
    {
        return $name ? $callsign . ' - ' . $name : $callsign;
    }

    /**
     * The domain that makes event UIDs globally unique.
     */
    private function host(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'controlcenter';
    }
}
