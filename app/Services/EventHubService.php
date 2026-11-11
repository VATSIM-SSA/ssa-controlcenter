<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads published event dates from the VATSIM Event Hub's public API (/api/v1/occurrences).
 *
 * The list is the same for every member, so it is fetched once and cached. When the Hub
 * can't be reached, the last list that loaded is served, and the Hub is left alone for a
 * minute so a slow Hub doesn't hold up every calendar request.
 */
class EventHubService
{
    /**
     * How long a fetched list is reused before the Hub is asked again.
     */
    private const CACHE_MINUTES = 15;

    /**
     * How long to wait before trying the Hub again after it failed.
     */
    private const RETRY_MINUTES = 1;

    /**
     * The Hub serves at most 100 dates a page; this caps a runaway listing.
     */
    private const MAX_PAGES = 20;

    private const LAST_KNOWN_KEY = 'event-hub:occurrences:last';

    public function isConfigured(): bool
    {
        return filled(config('services.event_hub.url'));
    }

    /**
     * Dates of published, not cancelled, events that overlap the given range. Null when the
     * Hub can't be reached and no earlier list is known, so callers can tell "no events"
     * apart from "don't know".
     *
     * Each date has: id, startsAt and endsAt (Carbon, UTC), name, summary, url, fir and airports.
     *
     * @return Collection<array-key, non-empty-array>|null
     */
    public function occurrences(Carbon $from, Carbon $to): ?Collection
    {
        if (! $this->isConfigured()) {
            return collect();
        }

        $query = ['from' => $from->copy()->utc()->toDateString(), 'to' => $to->copy()->utc()->toDateString()];
        $key = 'event-hub:occurrences:' . implode(':', $query);

        $occurrences = Cache::get($key);

        if ($occurrences === null && ! Cache::has($key . ':retry')) {
            $occurrences = $this->fetch($query);

            if ($occurrences !== null) {
                Cache::put($key, $occurrences, now()->addMinutes(self::CACHE_MINUTES));
                Cache::forever(self::LAST_KNOWN_KEY, $occurrences);
            } else {
                Cache::put($key . ':retry', true, now()->addMinutes(self::RETRY_MINUTES));
            }
        }

        $occurrences ??= Cache::get(self::LAST_KNOWN_KEY);

        if ($occurrences === null) {
            return null;
        }

        return collect($occurrences)->map(fn (array $occurrence): array => [
            ...$occurrence,
            'startsAt' => Carbon::parse($occurrence['startsAt']),
            'endsAt' => Carbon::parse($occurrence['endsAt']),
        ]);
    }

    /**
     * Fetch every page of the listing, or null when the Hub fails to answer.
     *
     * @param  array{from: string, to: string}  $query
     * @return list<array<string, mixed>>|null
     */
    private function fetch(array $query): ?array
    {
        $url = rtrim((string) config('services.event_hub.url'), '/') . '/api/v1/occurrences';
        $occurrences = [];

        try {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $response = Http::acceptJson()->connectTimeout(3)->timeout(5)
                    ->get($url, [...$query, 'per_page' => 100, 'page' => $page]);

                if (! $response->successful()) {
                    Log::warning('Event Hub API error', ['status' => $response->status(), 'url' => $url]);

                    return null;
                }

                foreach ((array) $response->json('data', []) as $occurrence) {
                    if (is_array($occurrence) && ($parsed = $this->parse($occurrence)) !== null) {
                        $occurrences[] = $parsed;
                    }
                }

                if ($response->json('links.next') === null) {
                    break;
                }
            }
        } catch (ConnectionException $e) {
            Log::warning('Event Hub API unreachable', ['message' => $e->getMessage(), 'url' => $url]);

            return null;
        }

        return $occurrences;
    }

    /**
     * Keep the fields the calendar uses, dropping cancelled dates and malformed entries.
     *
     * @param  array<string, mixed>  $occurrence
     * @return array<string, mixed>|null
     */
    private function parse(array $occurrence): ?array
    {
        $event = $occurrence['event'] ?? null;

        if (! is_array($event) || ! isset($occurrence['id'], $occurrence['startsAt'], $occurrence['endsAt'], $event['name'])) {
            return null;
        }

        if (! empty($occurrence['cancelledAt'])) {
            return null;
        }

        try {
            $startsAt = Carbon::parse((string) $occurrence['startsAt'])->utc();
            $endsAt = Carbon::parse((string) $occurrence['endsAt'])->utc();
        } catch (\Throwable) {
            return null;
        }

        if ($endsAt->lte($startsAt)) {
            return null;
        }

        return [
            'id' => (int) $occurrence['id'],
            'startsAt' => $startsAt->toIso8601String(),
            'endsAt' => $endsAt->toIso8601String(),
            'name' => (string) $event['name'],
            'summary' => (string) ($event['summary'] ?? ''),
            'url' => (string) ($event['url'] ?? ''),
            'fir' => (string) ($event['fir']['name'] ?? $event['fir']['icao'] ?? ''),
            'airports' => array_values(array_map('strval', (array) ($event['airports'] ?? []))),
        ];
    }
}
