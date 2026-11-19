<?php

namespace App\Http\Controllers;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\LogName;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: the Discord role sync, CC side.
 *
 * CC never calls the bot. The profile button and the model listeners in
 * VatssaServiceProvider write rows; the bot reads them with its own token and a
 * cursor (the last id it handled), syncs the member, and reports the outcome and
 * the server's member list back with the same token. The bot always recomputes
 * roles from scratch, so a request handled twice does nothing extra.
 */
class VatssaDiscordSyncController extends Controller
{
    private const TABLE = 'vatssa_discord_sync_requests';

    private const PRESENCE = 'vatssa_discord_presence';

    /**
     * An automatic request is queued from inside a save, before that request has
     * attached the endorsement's ratings, areas or positions. The feed holds it back
     * this long, so the bot never reads half-written data.
     */
    public const AUTO_SETTLE_SECONDS = 5;

    /**
     * CC's pseudonymised users carry this address (UserDelete).
     */
    public const PSEUDONYMISED_EMAIL = 'void@void.void';

    /**
     * Every status the bot may report, with the words the profile shows.
     */
    public const STATUSES = [
        'done' => 'Done',
        'partial' => 'Partly done',
        'no_change' => 'No change needed',
        'failed' => 'Failed',
        'held' => 'Held',
        'unknown' => 'No answer from VATSIM',
        'not_on_discord' => 'Not on Discord',
        'paused' => 'Paused by a safety limit',
        'report' => 'Report mode, nothing applied',
    ];

    public function store(User $user): RedirectResponse
    {
        $this->authorize('vatssaDiscordSync', $user);

        self::prune();

        // Check and insert under one lock per member, so two clicks cannot both pass the check.
        Cache::lock('vatssa-discord-sync:' . $user->id, 5)->block(3, function () use ($user) {
            $outstanding = DB::table(self::TABLE)->where('user_id', $user->id)->where('source', 'button')
                ->whereNull('finished_at')->where('created_at', '>', now()->subMinute())->exists();

            if (! $outstanding) {
                DB::table(self::TABLE)->insert(['user_id' => $user->id, 'requested_by' => Auth::id(), 'source' => 'button', 'created_at' => now()]);
                ActivityLogService::info(LogName::Role, 'Discord role sync requested for ' . $user->id);
            }
        });

        return redirect()->route('user.show', $user->id)
            ->withSuccess('Discord role sync requested. If ' . $user->first_name . ' is on Discord, their roles update within a minute.');
    }

    /**
     * Instant sync: queue a member because CC data that decides one of their roles
     * just changed. Called from the model listeners in VatssaServiceProvider.
     *
     * No coalescing on purpose: the bot may already have read an earlier row, and a
     * skipped row would leave this change waiting for the 15-minute sweep. A
     * duplicate costs one "no change" sync. Never throws: a failure here must not
     * break the endorsement, training or role save that triggered it.
     */
    public static function queueAuto(?int $userId): void
    {
        if (! $userId) {
            return;
        }
        try {
            self::prune();
            DB::table(self::TABLE)->insert(['user_id' => $userId, 'requested_by' => null, 'source' => 'auto', 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * A member was deleted or pseudonymised: drop their sync history and their
     * Discord link. presence() refuses to store them again. Never throws.
     */
    public static function forgetUser(int $userId): void
    {
        try {
            DB::table(self::TABLE)->where('user_id', $userId)->delete();
            DB::table(self::PRESENCE)->where('user_id', $userId)->delete();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Requests older than 30 days go, except the newest row: the bot treats max(id)
     * going down as a database restore, so pruning must never empty the table.
     */
    private static function prune(): void
    {
        $newest = DB::table(self::TABLE)->max('id');
        DB::table(self::TABLE)->where('created_at', '<', now()->subDays(30))
            ->where('id', '<', (int) $newest)->delete();
    }

    /**
     * Bot API: requests after the cursor, oldest first, up to the first automatic
     * request younger than AUTO_SETTLE_SECONDS. Stopping there, rather than skipping
     * it, keeps the cursor from jumping past it. Older than a day is dropped: the
     * bot's sweep has long since caught up. `max_id` lets the bot notice a restore.
     */
    public function index(Request $request): JsonResponse
    {
        $after = (int) ($request->validate(['after' => 'sometimes|integer|min:0'])['after'] ?? 0);

        $settling = DB::table(self::TABLE)->where('id', '>', $after)->where('source', 'auto')
            ->where('created_at', '>', now()->subSeconds(self::AUTO_SETTLE_SECONDS))->min('id');

        $rows = DB::table(self::TABLE)->where('id', '>', $after)
            ->when($settling, fn ($query) => $query->where('id', '<', $settling))
            ->where('created_at', '>', now()->subDay())
            ->orderBy('id')->limit(100)->get(['id', 'user_id', 'source']);

        return response()->json(['data' => $rows, 'max_id' => (int) DB::table(self::TABLE)->max('id')]);
    }

    /**
     * Bot API: how a request ended. Sending it twice overwrites the first.
     */
    public function result(Request $request, int $id): Response
    {
        $data = $request->validate([
            'status' => 'required|string|in:' . implode(',', array_keys(self::STATUSES)),
            'detail' => 'nullable|string|max:500',
        ]);

        $updated = DB::table(self::TABLE)->where('id', $id)
            ->update(['status' => $data['status'], 'detail' => $data['detail'] ?? null, 'finished_at' => now()]);

        // update() counts changed rows; MySQL reports 0 for an identical repeat, so check existence.
        abort_unless($updated || DB::table(self::TABLE)->where('id', $id)->exists(), 404);

        return response()->noContent();
    }

    /**
     * Bot API: who is in the Discord server.
     *
     * complete = true (after a sweep, VATSIM answered for everyone): the list replaces
     * the table. complete = false (a join, a button press, or a sweep with failed
     * lookups): rows are added or refreshed, nobody is removed. `at` is when the bot
     * started building the list; a complete list older than the last one applied is
     * refused, so a slow or retried sweep can never overwrite a newer one. Only CIDs of
     * CC users who are neither deleted nor pseudonymised are stored. An empty complete
     * list is refused on purpose: it is far likelier a bot fault than a fact.
     */
    public function presence(Request $request): Response
    {
        $data = $request->validate([
            'complete' => 'required|boolean',
            'at' => 'required|numeric|min:0',
            'members' => 'present|array|max:50000',
            'members.*.cid' => 'required|integer|min:1',
            'members.*.discord_id' => ['required', 'string', 'regex:/^\d{15,21}$/'],
            'members.*.name' => 'nullable|string|max:100',
        ]);

        abort_if($data['complete'] && empty($data['members']), 422, 'A complete list cannot be empty.');

        $generatedAt = (float) $data['at'];
        if ($data['complete'] && $generatedAt < (float) Setting::get('vatssaDiscordPresenceGeneration', 0)) {
            abort(409, 'An older list than the one already applied.');
        }

        $known = User::whereIn('id', collect($data['members'])->pluck('cid')->unique())
            ->where('email', '!=', self::PSEUDONYMISED_EMAIL)->pluck('id')->flip();

        $now = now();
        $rows = collect($data['members'])
            ->filter(fn (array $member) => $known->has($member['cid']))
            ->map(fn (array $member) => [
                'discord_id' => $member['discord_id'], 'user_id' => $member['cid'], 'name' => $member['name'] ?? null, 'seen_at' => $now,
            ])->values()->all();

        DB::transaction(function () use ($data, $rows) {
            if ($data['complete']) {
                DB::table(self::PRESENCE)->delete();
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table(self::PRESENCE)->upsert($chunk, ['discord_id'], ['user_id', 'name', 'seen_at']);
            }
        });

        if ($data['complete']) {
            Setting::set('vatssaDiscordPresenceAt', $now->toIso8601String());
            Setting::set('vatssaDiscordPresenceGeneration', $generatedAt);
            Setting::save();
        }

        return response()->noContent();
    }

    /**
     * For the profile: the member's Discord accounts and when the bot last sent a
     * complete list. 'at' is null when it never has, or not in the last two hours:
     * then CC does not know, and says so.
     *
     * @return array{at: Carbon|null, accounts: Collection<int, object{discord_id: string, user_id: int, name: string|null, seen_at: string}>}
     */
    public static function presenceFor(int $userId): array
    {
        $at = Setting::get('vatssaDiscordPresenceAt');
        $at = $at ? Carbon::parse($at) : null;

        return [
            'at' => $at && $at->gt(now()->subHours(2)) ? $at : null,
            'accounts' => DB::table(self::PRESENCE)->where('user_id', $userId)->orderBy('name')->get(),
        ];
    }

    /**
     * The newest request for a member, for the profile panel. Null if none.
     */
    public static function latestFor(int $userId): ?object
    {
        return DB::table(self::TABLE)->where('user_id', $userId)->orderByDesc('id')->first();
    }
}
