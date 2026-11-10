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
 * can't be reached, the last list that loaded is served instead of none.
 */
class EventHubService
{
    /**
     * The Hub serves at most 100 dates a page; this caps a runaway listing.
     */
    private const MAX_PAGES = 20;

    public function isConfigured(): bool
    {
        return filled(config('services.event_hub.url'));
    }

    /**
     * Dates of published, not cancelled, events that overlap the given range.
     *
     * @return Collection<int, array{id: int, startsAt: Carbon, endsAt: Carbon, name: string, summary: string, url: string, fir: string, airports: list<string>}>
     */
    public function occurrences(Carbon $from, Carbon $to): Collection
    {
        if (! $this->isConfigured()) {
            return collect();
        }

        $query = ['from' => $from->copy()->utc()->toDateString(), 'to' => $to->copy()->utc()->toDateString()];
        $key = 'event-hub:occurrences:' . implode(':', $query);

        $occurrences = Cache::get($key);

        if ($occurrences === null) {
            $occurrences = $this->fetch($query);

            if ($occurrences !== null) {
                Cache::put($key, $occurrences, now()->addMinutes((int) config('services.event_hub.cache_ttl_minutes')));
                Cache::forever('event-hub:occurrences:last', $occurrences);
            } else {
                $occurrences = Cache::get('event-hub:occurrences:last', []);
            }
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
                $response = Http::acceptJson()->timeout(10)->get($url, [...$query, 'per_page' => 100, 'page' => $page]);

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

        return [
            'id' => (int) $occurrence['id'],
            'startsAt' => (string) $occurrence['startsAt'],
            'endsAt' => (string) $occurrence['endsAt'],
            'name' => (string) $event['name'],
            'summary' => (string) ($event['summary'] ?? ''),
            'url' => (string) ($event['url'] ?? ''),
            'fir' => (string) ($event['fir']['name'] ?? $event['fir']['icao'] ?? ''),
            'airports' => array_values(array_map('strval', (array) ($event['airports'] ?? []))),
        ];
    }
}
