<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Models\User;
use App\Services\ActivityLogService;
use anlutro\LaravelSettings\Facade as Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: the "Sync Discord roles" button on a user's profile.
 *
 * CC never calls the bot. The button writes a row; the bot polls index() with
 * its read-only API key and a cursor (the last id it handled), syncs the member,
 * then reports the outcome to result() with a separate bot token that can do
 * nothing else. The bot always recomputes the roles from scratch, so a request
 * handled twice does nothing extra.
 */
class VatssaDiscordSyncController extends Controller
{
    private const TABLE = 'vatssa_discord_sync_requests';

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
        $this->authorize('vatssaDiscordSync');

        self::prune();

        // A double click or a refresh within a minute adds nothing.
        $recent = DB::table(self::TABLE)->where('user_id', $user->id)
            ->where('created_at', '>', now()->subMinute())->exists();

        if (! $recent) {
            DB::table(self::TABLE)->insert(['user_id' => $user->id, 'requested_by' => Auth::id(), 'source' => 'button', 'created_at' => now()]);
            ActivityLogService::info(LogName::Role, 'Discord role sync requested for ' . $user->id);
        }

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
    public static function queueAuto(int $userId): void
    {
        try {
            self::prune();
            DB::table(self::TABLE)->insert(['user_id' => $userId, 'requested_by' => null, 'source' => 'auto', 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Keep the table short: requests older than 30 days go, except the newest row.
     * The bot treats max(id) going down as a database restore, so pruning must
     * never empty the table.
     */
    private static function prune(): void
    {
        $newest = DB::table(self::TABLE)->max('id');
        DB::table(self::TABLE)->where('created_at', '<', now()->subDays(30))
            ->where('id', '<', (int) $newest)->delete();
    }

    /**
     * Bot API: requests after the cursor, oldest first. Older than a day is dropped:
     * the bot's own sweep has long since caught up with those. `max_id` lets the bot
     * notice the table going backwards (a restore) and reset its cursor.
     */
    public function index(Request $request): JsonResponse
    {
        $after = (int) ($request->validate(['after' => 'sometimes|integer|min:0'])['after'] ?? 0);

        $rows = DB::table(self::TABLE)->where('id', '>', $after)
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
     * Bot API: who is in the Discord server, sent after every sweep.
     *
     * complete = true: VATSIM answered for every member, so the list replaces what
     * CC had and anyone missing has left. complete = false: some lookups failed, so
     * the rows are added or refreshed and nobody is removed. Only a complete list
     * moves "checked at", which is what the profile trusts.
     */
    public function presence(Request $request): Response
    {
        $data = $request->validate([
            'complete' => 'required|boolean',
            'members' => 'present|array|max:50000',
            'members.*.cid' => 'required|integer|min:1',
            'members.*.discord_id' => ['required', 'string', 'regex:/^\d{15,21}$/'],
            'members.*.name' => 'nullable|string|max:100',
        ]);

        // An empty complete list would mark everyone as gone: a fault, not a fact.
        abort_if($data['complete'] && empty($data['members']), 422, 'A complete list cannot be empty.');

        $now = now();
        $rows = array_map(fn ($m) => [
            'discord_id' => $m['discord_id'], 'user_id' => $m['cid'], 'name' => $m['name'] ?? null, 'seen_at' => $now,
        ], $data['members']);

        DB::transaction(function () use ($data, $rows) {
            if ($data['complete']) {
                DB::table('vatssa_discord_presence')->delete();
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('vatssa_discord_presence')->upsert($chunk, ['discord_id'], ['user_id', 'name', 'seen_at']);
            }
        });

        if ($data['complete']) {
            Setting::set('vatssaDiscordPresenceAt', $now->toIso8601String());
            Setting::save();
        }

        return response()->noContent();
    }

    /**
     * For the profile: the member's Discord accounts and when the bot last sent a
     * complete list. 'at' is null when it never has, or not in the last two hours:
     * then CC does not know, and says so.
     */
    public static function presenceFor(int $userId): array
    {
        $at = Setting::get('vatssaDiscordPresenceAt');
        $at = $at ? \Illuminate\Support\Carbon::parse($at) : null;

        return [
            'at' => $at && $at->gt(now()->subHours(2)) ? $at : null,
            'accounts' => DB::table('vatssa_discord_presence')->where('user_id', $userId)->orderBy('name')->get(),
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
