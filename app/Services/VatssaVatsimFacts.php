<?php

namespace App\Services;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\VatsimRating;
use App\Http\Controllers\VatssaDiscordSyncController as Sync;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * VATSSA: keeps every member's VATSIM rating and division current, in `users` and, for
 * people with no CC account, in this table.
 *
 * Nightly, the whole division list comes in with the Core API token. Anyone who claims
 * the division but is missing from it, and anyone else the bot asks about, is checked
 * one at a time. No answer never changes anything. Being off the list is not proof of
 * leaving: only an individual answer naming another division is. That change makes
 * upstream's update:member:details (05:00) close their trainings and remove mentor
 * roles, so at most MAX_LEAVES_PER_DAY are applied a day and the rest wait.
 */
class VatssaVatsimFacts
{
    private const TABLE = 'vatssa_vatsim_members';

    private const API = 'https://api.vatsim.net/v2';

    private const USER_AGENT = 'VATSSA-ControlCenter/1.0 (+https://vatssa.com)';

    private const MAX_DROP = 0.2;

    public const MAX_LEAVES_PER_DAY = 45;

    private const RATING_NAMES = [
        'INA' => 'Inactive', 'SUS' => 'Suspended', 'OBS' => 'Pilot/Observer', 'S1' => 'Tower Trainee', 'S2' => 'Tower Controller',
        'S3' => 'TMA Controller', 'C1' => 'Enroute Controller', 'C3' => 'Senior Controller', 'I1' => 'Instructor',
        'I3' => 'Senior Instructor', 'SUP' => 'Supervisor', 'ADM' => 'Administrator',
    ];

    /**
     * The nightly pull. Throws, changing nothing, on any doubt about the list.
     */
    public function pull(): int
    {
        $owner = config('app.owner_code');
        $items = $this->fetchDivision($owner);

        $last = (int) Setting::get('vatssaVatsimPullCount', 0);
        if ($last && $items->count() < $last * (1 - self::MAX_DROP)) {
            throw new RuntimeException("Division list fell from {$last} to {$items->count()}; refused.");
        }

        $erased = DB::table('users')->where('email', Sync::PSEUDONYMISED_EMAIL)->pluck('id')->flip();
        $now = now();
        $before = DB::table(self::TABLE)->get(['cid', 'rating', 'division'])->keyBy('cid');
        $rows = $items->reject(fn ($item) => $erased->has((int) $item['id']))->map(fn ($item) => [
            'cid' => (int) $item['id'], 'rating' => (int) $item['rating'], 'division' => (string) ($item['division_id'] ?? $owner),
            'in_org' => true, 'source' => 'org', 'needs_lookup' => false, 'fetched_at' => $now, 'checked_at' => $now,
        ])->values();
        $onList = $rows->pluck('cid')->flip();

        // Before the facts below: saving a user also writes its fact, with source `cc`.
        $ccUsers = collect();
        foreach ($items->reject(fn ($item) => $erased->has((int) $item['id']))->chunk(500) as $chunk) {
            $byId = $chunk->keyBy(fn ($item) => (int) $item['id']);
            foreach (User::whereIn('id', $byId->keys())->get() as $user) {
                $ccUsers->put($user->id, true);
                $this->applyToUser($user, $byId[$user->id]);
            }
        }

        DB::transaction(function () use ($rows, $onList, $owner, $now) {
            foreach ($rows->chunk(500) as $chunk) {
                DB::table(self::TABLE)->upsert($chunk->values()->all(), ['cid'], ['rating', 'division', 'in_org', 'source', 'needs_lookup', 'fetched_at', 'checked_at']);
            }
            // Off the list since last night: keep the old fact until VATSIM confirms.
            DB::table(self::TABLE)->where('in_org', true)->where('fetched_at', '<', $now)->update(['in_org' => false, 'needs_lookup' => true]);

            $users = DB::table('users')->where('email', '!=', Sync::PSEUDONYMISED_EMAIL)->get(['id', 'rating', 'division', 'refresh_token'])
                ->reject(fn ($user) => $onList->has((int) $user->id));

            // CC says they are ours but VATSIM's list does not: ask about each one.
            $claimers = $users->where('division', $owner)->pluck('id')->map(fn ($id) => (int) $id)->values();
            $this->markForLookup($claimers);

            // Others with a live login: this morning's refresh already has their data.
            $live = $users->filter(fn ($user) => $user->division !== $owner && $user->refresh_token !== null && $user->rating !== null && $user->division !== null)
                ->map(fn ($user) => ['cid' => (int) $user->id, 'rating' => (int) $user->rating, 'division' => $user->division, 'in_org' => false,
                    'source' => 'cc', 'needs_lookup' => false, 'fetched_at' => $now, 'checked_at' => $now])->values();
            foreach ($live->chunk(500) as $chunk) {
                DB::table(self::TABLE)->upsert($chunk->values()->all(), ['cid'], ['rating', 'division', 'in_org', 'source', 'needs_lookup', 'fetched_at', 'checked_at']);
            }
        });

        Setting::set('vatssaVatsimPullCount', $items->count());
        Setting::set('vatssaVatsimPullAt', $now->toIso8601String());
        Setting::save();

        // A saved user queues itself; this is for people with no CC account.
        $changed = $rows->filter(fn ($row) => ! $ccUsers->has($row['cid']) && ($old = $before->get($row['cid']))
            && ((int) $old->rating !== $row['rating'] || $old->division !== $row['division']))->pluck('cid')->all();
        Sync::queue($changed, 'daily');

        return $rows->count();
    }

    /**
     * Check waiting members one at a time, about one a second. Stops on a rate limit.
     */
    public function runLookups(int $limit = 40): int
    {
        $done = 0;
        $owner = config('app.owner_code');
        $rows = DB::table(self::TABLE)->where('needs_lookup', true)
            ->when(config('vatssa.vatsim_write_users') && $this->leavesLeft() <= 0, fn ($q) => $q->whereNotIn('cid', DB::table('users')->where('division', $owner)->select('id')))
            ->orderByRaw('checked_at IS NOT NULL')->orderBy('checked_at')->limit($limit)->get();

        foreach ($rows as $row) {
            Sleep::for(1)->second();
            $now = now();
            try {
                $response = Http::withUserAgent(self::USER_AGENT)->acceptJson()->timeout(10)->get(self::API . "/members/{$row->cid}");
            } catch (\Throwable $e) {
                report($e);
                DB::table(self::TABLE)->where('cid', $row->cid)->update(['checked_at' => $now]);

                continue;
            }

            if ($response->status() === 429) {
                break;
            }
            if ($response->status() === 404) {
                DB::table(self::TABLE)->where('cid', $row->cid)->update(['rating' => null, 'division' => null, 'in_org' => false,
                    'source' => 'lookup', 'needs_lookup' => false, 'fetched_at' => $now, 'checked_at' => $now]);
                $done++;

                continue;
            }
            $body = $response->successful() ? $response->json() : null;
            if (! is_array($body) || (int) ($body['id'] ?? 0) !== (int) $row->cid || ! is_numeric($body['rating'] ?? null) || empty($body['division_id'])) {
                DB::table(self::TABLE)->where('cid', $row->cid)->update(['checked_at' => $now]);

                continue;
            }

            $user = User::where('id', $row->cid)->where('email', '!=', Sync::PSEUDONYMISED_EMAIL)->first();
            if ($user && config('vatssa.vatsim_write_users') && $user->division === $owner && $body['division_id'] !== $owner) {
                if ($this->leavesLeft() <= 0) {
                    DB::table(self::TABLE)->where('cid', $row->cid)->update(['checked_at' => $now]);

                    continue;
                }
                $this->countLeave();
            }
            if ($user) {
                $this->applyToUser($user, $body);
            }

            DB::table(self::TABLE)->where('cid', $row->cid)->update(['rating' => (int) $body['rating'], 'division' => (string) $body['division_id'],
                'source' => 'lookup', 'needs_lookup' => false, 'fetched_at' => $now, 'checked_at' => $now]);
            if ($row->rating === null || (int) $row->rating !== (int) $body['rating'] || $row->division !== (string) $body['division_id']) {
                Sync::queueAuto((int) $row->cid);
            }
            $done++;
        }

        return $done;
    }

    /**
     * Write VATSIM's answer to the user, through the model so every listener runs. A rating
     * CC has no case for (C2, I2) is left as it was: the model could not read it back.
     *
     * @param  array<string, mixed>  $vatsim
     */
    private function applyToUser(User $user, array $vatsim): void
    {
        if (! config('vatssa.vatsim_write_users')) {
            return;
        }
        if ($rating = VatsimRating::tryFrom((int) $vatsim['rating'])) {
            $user->rating = $rating;
            $user->rating_short = $rating->name;
            $user->rating_long = self::RATING_NAMES[$rating->name];
        }
        if (! empty($vatsim['division_id'])) {
            $user->division = $vatsim['division_id'];
        }
        if (! empty($vatsim['region_id'])) {
            $user->region = $vatsim['region_id'];
        }
        if (array_key_exists('subdivision_id', $vatsim)) {
            $user->subdivision = $vatsim['subdivision_id'];
        }
        try {
            $user->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function leavesLeft(): int
    {
        [$day, $count] = array_pad(explode(':', (string) Setting::get('vatssaVatsimLeaves', '')), 2, 0);

        return $day === now()->toDateString() ? self::MAX_LEAVES_PER_DAY - (int) $count : self::MAX_LEAVES_PER_DAY;
    }

    private function countLeave(): void
    {
        Setting::set('vatssaVatsimLeaves', now()->toDateString() . ':' . (self::MAX_LEAVES_PER_DAY - $this->leavesLeft() + 1));
        Setting::save();
    }

    /**
     * The bot's question: rating and division for these CIDs. Unknown or day-old
     * individual answers are put in line for a check. Erased members get nothing.
     *
     * @param  array<int, int>  $cids
     * @return array{data: array<int, array{rating: int, division: string}>, pulled_at: string|null, leaves_waiting: int}
     */
    public function forBot(array $cids): array
    {
        $cids = array_values(array_unique(array_map('intval', $cids)));
        $erased = collect();
        foreach (array_chunk($cids, 500) as $chunk) {
            $erased = $erased->merge(DB::table('users')->whereIn('id', $chunk)->where('email', Sync::PSEUDONYMISED_EMAIL)->pluck('id'));
        }
        $cids = array_values(array_diff($cids, $erased->map(fn ($id) => (int) $id)->all()));

        $rows = collect();
        foreach (array_chunk($cids, 500) as $chunk) {
            $rows = $rows->merge(DB::table(self::TABLE)->whereIn('cid', $chunk)->get());
        }
        $rows = $rows->keyBy('cid');

        $stale = $rows->filter(fn ($row) => ! $row->needs_lookup && $row->source === 'lookup' && (! $row->fetched_at || Carbon::parse($row->fetched_at)->lt(now()->subDay())))->keys();
        $missing = collect($cids)->reject(fn ($cid) => $rows->has($cid));
        $this->markForLookup($stale->merge($missing)->map(fn ($cid) => (int) $cid)->values());

        return [
            'data' => $rows->filter(fn ($row) => $row->rating !== null && $row->division !== null)
                ->map(fn ($row) => ['rating' => (int) $row->rating, 'division' => $row->division])->all(),
            'pulled_at' => Setting::get('vatssaVatsimPullAt'),
            // Members whose check is on hold because today's limit on leavers is used up.
            'leaves_waiting' => ! config('vatssa.vatsim_write_users') || $this->leavesLeft() > 0 ? 0 : DB::table(self::TABLE)->where('needs_lookup', true)
                ->whereIn('cid', DB::table('users')->where('division', config('app.owner_code'))->select('id'))->count(),
        ];
    }

    /**
     * Login and CC's morning refresh bring VATSIM's answer for this user.
     */
    public static function fromUser(User $user): void
    {
        $rating = $user->getAttributes()['rating'] ?? null; // during `updated` the original is still the old value
        if ($rating === null || ! $user->division || $user->email === Sync::PSEUDONYMISED_EMAIL) {
            return;
        }
        try {
            $now = now();
            DB::table(self::TABLE)->upsert([[
                'cid' => $user->id, 'rating' => (int) $rating, 'division' => $user->division, 'in_org' => false,
                'source' => 'cc', 'needs_lookup' => false, 'fetched_at' => $now, 'checked_at' => $now,
            ]], ['cid'], ['rating', 'division', 'source', 'needs_lookup', 'fetched_at', 'checked_at']);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Someone pressed the sync button: check this member at VATSIM on the next run.
     */
    public static function refresh(int $cid): void
    {
        try {
            (new self)->markForLookup(collect([$cid]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function forget(int $cid): void
    {
        try {
            DB::table(self::TABLE)->where('cid', $cid)->delete();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  Collection<int, int>  $cids
     */
    private function markForLookup(Collection $cids): void
    {
        foreach ($cids->chunk(500) as $chunk) {
            DB::table(self::TABLE)->insertOrIgnore($chunk->map(fn ($cid) => ['cid' => $cid, 'needs_lookup' => true])->values()->all());
            DB::table(self::TABLE)->whereIn('cid', $chunk->values())->update(['needs_lookup' => true]);
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchDivision(string $owner): Collection
    {
        $token = config('vatsim.core_api_token');
        if (! $token) {
            throw new RuntimeException('VATSIM_CORE_API_TOKEN is not set.');
        }

        $url = sprintf('%s/orgs/%s/%s', self::API, config('app.mode'), $owner);
        $items = collect();
        do {
            $response = Http::withUserAgent(self::USER_AGENT)->withHeaders(['X-API-Key' => $token])->acceptJson()->timeout(60)
                ->get($url, ['include_inactive' => 1, 'limit' => 1000, 'offset' => $items->count()]);
            $body = $response->successful() ? $response->json() : null;
            if (! is_array($body) || ! isset($body['count']) || ! is_array($body['items'] ?? null)) {
                throw new RuntimeException('VATSIM gave no usable division list (HTTP ' . $response->status() . ').');
            }
            if ($body['items'] === [] && $items->count() < (int) $body['count']) {
                throw new RuntimeException('VATSIM ended the division list early.');
            }
            $items = $items->merge($body['items']);
        } while ($items->count() < (int) $body['count']);

        if ($items->isEmpty()) {
            throw new RuntimeException('VATSIM returned an empty division list.');
        }
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) <= 0 || ! is_numeric($item['rating'] ?? null) || (isset($item['division_id']) && $item['division_id'] !== $owner)) {
                throw new RuntimeException('VATSIM division list has a row that does not fit.');
            }
        }

        return $items->unique('id')->values();
    }
}
