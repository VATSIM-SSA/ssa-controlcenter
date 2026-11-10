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
 * Builds a user's personal calendar feed from their ATC and sweatbox bookings, and,
 * when they opt in, every published event on the VATSIM Event Hub.
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

    public function __construct(private EventHubService $eventHub) {}

    public function forUser(User $user): string
    {
        return IcsCalendar::render(
            name: $this->calendarName(),
            events: $this->events($user),
        );
    }

    /**
     * Whether members can choose to add the Event Hub's events to their feed.
     */
    public function canIncludeEvents(): bool
    {
        return $this->eventHub->isConfigured();
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
                . '&name=' . rawurlencode($this->calendarName()),
        ];
    }

    /**
     * @return Collection<int, IcsEvent>
     */
    public function events(User $user): Collection
    {
        $from = Carbon::now()->subDays(self::PAST_DAYS);
        $to = Carbon::now()->addDays(self::FUTURE_DAYS);

        $occurrences = $user->setting_calendar_include_events && $this->canIncludeEvents()
            ? $this->eventHub->occurrences($from, $to)
            : collect();

        $bookings = Booking::query()
            ->with('position')
            ->where('user_id', $user->id)
            ->where('deleted', false)
            ->where('time_end', '>=', $from)
            ->where('time_start', '<=', $to)
            ->get()
            ->map(fn (Booking $booking): IcsEvent => $this->bookingEvent($booking, $occurrences));

        $sweatbooks = Sweatbook::query()
            ->with('position')
            ->where('user_id', $user->id)
            ->where('time_end', '>=', $from)
            ->where('time_start', '<=', $to)
            ->get()
            ->map(fn (Sweatbook $sweatbook): IcsEvent => $this->sweatbookEvent($sweatbook));

        return $bookings
            ->concat($sweatbooks)
            ->concat($occurrences->map(fn (array $occurrence): IcsEvent => $this->occurrenceEvent($occurrence)))
            ->sortBy(fn (IcsEvent $event): int => $event->startsAt->getTimestamp())
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $occurrences
     */
    private function bookingEvent(Booking $booking, Collection $occurrences): IcsEvent
    {
        $occurrence = $booking->event ? $this->occurrenceFor($booking, $occurrences) : null;

        $tags = array_keys(array_filter([
            'Training' => (bool) $booking->training,
            'Event' => (bool) $booking->event && $occurrence === null,
            'Exam' => (bool) $booking->exam,
        ]));

        $summary = $booking->callsign
            . ($tags === [] ? '' : ' (' . implode(', ', $tags) . ')')
            . ($occurrence === null ? '' : ' - ' . $occurrence['name']);

        return new IcsEvent(
            uid: 'booking-' . $booking->id . '@' . $this->host(),
            summary: $summary,
            startsAt: $booking->starts_at,
            endsAt: $booking->ends_at,
            description: 'ATC booking on ' . $booking->callsign . '.'
                . ($occurrence === null ? '' : "\nPart of " . $occurrence['name'] . ($occurrence['url'] !== '' ? ': ' . $occurrence['url'] : '.')),
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

    /**
     * @param  array<string, mixed>  $occurrence
     */
    private function occurrenceEvent(array $occurrence): IcsEvent
    {
        $location = implode(', ', $occurrence['airports']);

        return new IcsEvent(
            uid: 'event-occurrence-' . $occurrence['id'] . '@' . $this->eventHubHost(),
            summary: 'Event: ' . $occurrence['name'],
            startsAt: $occurrence['startsAt'],
            endsAt: $occurrence['endsAt'],
            description: $occurrence['summary'],
            location: $location !== '' ? $location : $occurrence['fir'],
            url: $occurrence['url'],
        );
    }

    /**
     * The event date an event booking belongs to: the one its start falls inside. When two
     * events overlap, the one listing the booking's airport wins; if that doesn't settle
     * it, the booking is left unlabelled rather than guessed.
     *
     * @param  Collection<int, array<string, mixed>>  $occurrences
     * @return array<string, mixed>|null
     */
    private function occurrenceFor(Booking $booking, Collection $occurrences): ?array
    {
        $start = $booking->starts_at;

        $candidates = $occurrences
            ->filter(fn (array $occurrence): bool => $start->gte($occurrence['startsAt']) && $start->lt($occurrence['endsAt']))
            ->values();

        if ($candidates->count() > 1) {
            $airport = strtoupper(strtok($booking->callsign, '_'));
            $candidates = $candidates
                ->filter(fn (array $occurrence): bool => in_array($airport, array_map('strtoupper', $occurrence['airports']), true))
                ->values();
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function calendarName(): string
    {
        return config('app.owner_name_short') . ' bookings';
    }

    private function positionLabel(string $callsign, ?string $name): string
    {
        return $name ? $callsign . ' - ' . $name : $callsign;
    }

    /**
     * The domain that makes booking UIDs globally unique.
     */
    private function host(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'controlcenter';
    }

    /**
     * Event UIDs carry the Hub's domain, so the same event keeps one identity in any feed.
     */
    private function eventHubHost(): string
    {
        return parse_url((string) config('services.event_hub.url'), PHP_URL_HOST) ?: 'eventhub';
    }
}
