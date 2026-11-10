<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Controllers connected to VATSIM right now, read from the public data feed.
 */
class VatsimOnlineControllers
{
    /**
     * Callsign by CID for every connected controller. Observers and ATIS
     * stations are left out. A feed failure yields an empty list, so a page
     * using this never breaks because VATSIM is unreachable.
     *
     * @return Collection<int, string>
     */
    public function callsignsByCid(): Collection
    {
        return Cache::remember('vatsim.online-controllers', (int) config('vatsim.data_feed_cache_seconds', 60), function (): Collection {
            try {
                $response = Http::timeout(5)->acceptJson()->get(config('vatsim.data_feed_url'));
            } catch (Throwable) {
                return collect();
            }

            if (! $response->successful()) {
                return collect();
            }

            $controllers = $response->json('controllers');

            // Skip anything malformed rather than fail: the feed is outside our control.
            return collect(is_array($controllers) ? $controllers : [])
                ->filter(fn (mixed $controller): bool => is_array($controller)
                    && is_numeric($controller['cid'] ?? null)
                    && is_string($controller['callsign'] ?? null)
                    && (int) ($controller['facility'] ?? 0) > 0
                    && ! str_ends_with($controller['callsign'], '_ATIS'))
                ->mapWithKeys(fn (array $controller): array => [(int) $controller['cid'] => $controller['callsign']]);
        });
    }
}
