<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Sweatbook;
use App\Models\User;
use App\Support\Calendar\IcsCalendar;
use App\Support\Calendar\IcsEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the calendar feeds: a user's personal feed of their ATC and sweatbox bookings
 * (optionally with every Event Hub event), and the shared feed of Event Hub events.
 */
class CalendarFeedService
{
    /**
     * How many days back the feeds reach, so recent sessions stay visible.
     */
    public const PAST_DAYS = 30;

    /**
     * How many days ahead the feeds reach.
     */
    public const FUTURE_DAYS = 365;

    public function __construct(private EventHubService $eventHub) {}

    /**
     * Return the user's feed token, creating one on first use.
     */
    public function tokenFor(User $user): string
    {
        return filled($user->calendar_feed_token) ? $user->calendar_feed_token : $this->resetToken($user);
    }

    /**
     * Replace the user's feed token, which stops every calendar subscribed to the old link.
     */
    public function resetToken(User $user): string
    {
        $user->forceFill(['calendar_feed_token' => Str::random(48)])->save();

        return $user->calendar_feed_token;
    }

    public function forUser(User $user): string
    {
        return IcsCalendar::render(
            name: $this->calendarName(),
            events: $this->events($user),
        );
    }

    /**
     * The shared calendar of every published Event Hub event, or null when the Hub can't be
     * reached and no earlier list is known. Serving an empty calendar instead would make
     * calendar apps delete every event they already show.
     */
    public function forEvents(): ?string
    {
        $occurrences = $this->eventHub->occurrences(...$this->window());

        if ($occurrences === null) {
            return null;
        }

        return IcsCalendar::render(
            name: $this->eventsCalendarName(),
            events: $occurrences->map(fn (array $occurrence): IcsEvent => $this->occurrenceEvent($occurrence))->values(),
        );
    }

    /**
     * Whether an Event Hub is connected, so members can add its events to their calendar.
     */
    public function hasEventHub(): bool
    {
        return $this->eventHub->isConfigured();
    }

    /**
     * The user's personal feed URL and the one-click subscribe links for each calendar app.
     *
     * @return array{feed: string, webcal: string, google: string, outlook: string}
     */
    public function subscriptionLinks(User $user): array
    {
        return $this->linksFor(route('calendar.feed', ['token' => $this->tokenFor($user)]), $this->calendarName());
    }

    /**
     * The shared events feed URL and its subscribe links, or null without an Event Hub.
     *
     * @return array{feed: string, webcal: string, google: string, outlook: string}|null
     */
    public function eventsSubscriptionLinks(): ?array
    {
        return $this->hasEventHub() ? $this->linksFor(route('calendar.events'), $this->eventsCalendarName()) : null;
    }

    /**
     * @return Collection<int, IcsEvent>
     */
    public function events(User $user): Collection
    {
        [$from, $to] = $this->window();

        // Loaded whenever the Hub is connected, so event bookings carry their event's name
        // even for members who keep the events in a calendar of their own. If the Hub is
        // unavailable, the user's own bookings are still served.
        $occurrences = $this->eventHub->occurrences($from, $to) ?? collect();

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

        $includedOccurrences = $user->setting_calendar_include_events
            ? $occurrences->map(fn (array $occurrence): IcsEvent => $this->occurrenceEvent($occurrence))
            : collect();

        return $bookings
            ->concat($sweatbooks)
            ->concat($includedOccurrences)
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

        $description = 'ATC booking on ' . $booking->callsign . '.';

        if ($occurrence !== null) {
            $description .= "\nPart of " . $occurrence['name'] . ($occurrence['url'] !== '' ? ': ' . $occurrence['url'] : '.');
        }

        return new IcsEvent(
            uid: 'booking-' . $booking->id . '@' . $this->host(),
            summary: $summary,
            startsAt: $booking->starts_at,
            endsAt: $booking->ends_at,
            description: $description,
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
     * The event date an event booking belongs to: the one it overlaps. Rostered positions
     * often open before the event's advertised start, so overlap is used, not containment.
     * When several events overlap, the one listing the booking's airport wins; if that
     * doesn't settle it, the booking is left unlabelled rather than guessed.
     *
     * @param  Collection<int, array<string, mixed>>  $occurrences
     * @return array<string, mixed>|null
     */
    private function occurrenceFor(Booking $booking, Collection $occurrences): ?array
    {
        $start = $booking->starts_at;
        $end = $booking->ends_at;

        $candidates = $occurrences
            ->filter(fn (array $occurrence): bool => $start->lt($occurrence['endsAt']) && $end->gt($occurrence['startsAt']))
            ->values();

        if ($candidates->count() > 1) {
            $airport = strtoupper(Str::before($booking->callsign, '_'));
            $candidates = $candidates
                ->filter(fn (array $occurrence): bool => in_array($airport, array_map('strtoupper', $occurrence['airports']), true))
                ->values();
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(): array
    {
        return [Carbon::now()->subDays(self::PAST_DAYS), Carbon::now()->addDays(self::FUTURE_DAYS)];
    }

    /**
     * @return array{feed: string, webcal: string, google: string, outlook: string}
     */
    private function linksFor(string $feed, string $name): array
    {
        $webcal = preg_replace('#^https?://#', 'webcal://', $feed);

        return [
            'feed' => $feed,
            'webcal' => $webcal,
            'google' => 'https://calendar.google.com/calendar/render?cid=' . rawurlencode($webcal),
            'outlook' => 'https://outlook.live.com/owa/?path=%2Fcalendar%2Faction%2Fcompose&rru=addsubscription'
                . '&url=' . rawurlencode($feed)
                . '&name=' . rawurlencode($name),
        ];
    }

    private function calendarName(): string
    {
        return config('app.owner_name_short') . ' bookings';
    }

    private function eventsCalendarName(): string
    {
        return config('app.owner_name_short') . ' events';
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
