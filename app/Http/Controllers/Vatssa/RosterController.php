<?php

namespace App\Http\Controllers\Vatssa;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Endorsement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * VATSSA: one roster, not four.
 *
 * Upstream models activity per area, and Control Center's roster pages follow
 * that: one list per area, each answering "is this person active HERE".
 *
 * VATSSA's rule is different and simpler. **Active in one area is active
 * everywhere.** Somebody who has controlled their hours in Johannesburg is a
 * current controller, full stop, and four lists showing four views of the same
 * set is a misleading answer to the question the public is asking.
 *
 * So this consolidates: a person appears once, active if `atc_active` is true
 * in ANY area, with the areas they are active in listed alongside.
 *
 * Excludes nobody for being staff. Mentors and examiners who still control are
 * still controllers.
 *
 * WHAT IT DOES NOT PUBLISH: who holds an examiner endorsement. That is a staff
 * fact, not a public one, and it is deliberately absent from this payload
 * rather than filtered by the caller -- an endpoint that hands out something
 * sensitive and trusts every consumer to drop it will eventually meet one that
 * does not. Solo and visiting endorsements ARE published: they say what
 * somebody may control, which is the question the roster exists to answer.
 */
class RosterController extends Controller
{
    public function index(): JsonResponse
    {
        $seconds = max(0, (int) config('vatssa.roster_cache_seconds', 300));

        // The underlying data changes hourly at most, and the homepage asks
        // every few minutes. Caching is the difference between one query and
        // several thousand a day for an identical answer.
        $roster = $seconds > 0
            ? Cache::remember('vatssa.roster', $seconds, fn () => $this->build())
            : $this->build();

        return response()->json(['data' => $roster]);
    }

    private function build(): array
    {
        $areas = Area::all()->keyBy('id');

        $users = User::whereHas('atcActivity', fn ($query) => $query->where('atc_active', true))
            ->with(['atcActivity', 'endorsements.ratings', 'endorsements.areas'])
            ->get();

        return $users->map(function (User $user) use ($areas) {
            $active = $user->atcActivity
                ->where('atc_active', true)
                ->map(fn ($activity) => $areas[$activity->area_id]->name ?? null)
                ->filter()
                ->values();

            $live = $user->endorsements->filter(
                fn (Endorsement $e) => ! $e->revoked && ! $e->expired
            );

            return [
                'id' => $user->id,
                'name' => $user->name,
                'rating' => $user->rating?->name,
                'rating_id' => $user->rating?->value,
                // True by construction -- the query only returns active users.
                // Present anyway so a consumer never has to infer it.
                'atc_active' => true,
                'areas' => $active->all(),
                'endorsements' => [
                    'facility' => $this->named($live, 'FACILITY'),
                    'solo' => $this->named($live, 'SOLO'),
                    'visiting' => $this->named($live, 'VISITING'),
                    // No 'examiner' key, on purpose. See the class docblock.
                ],
                'last_online' => $user->atcActivity->max('last_online')?->toIso8601String(),
            ];
        })->sortBy('name')->values()->all();
    }

    /**
     * Endorsement names of one type, flattened.
     *
     * The homepage reads `endorsements.facility` as a list of names, so the
     * shape here is fixed by that: change it and the public roster silently
     * loses a column.
     */
    private function named($endorsements, string $type): array
    {
        return $endorsements->where('type', $type)
            ->flatMap(fn (Endorsement $e) => $e->ratings->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }
}
