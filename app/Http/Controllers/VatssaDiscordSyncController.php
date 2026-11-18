<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Models\User;
use App\Services\ActivityLogService;
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

        // Housekeeping: the table only grows when someone presses the button,
        // so this is where it is kept short.
        DB::table(self::TABLE)->where('created_at', '<', now()->subDays(30))->delete();

        // A double click or a refresh within a minute adds nothing.
        $recent = DB::table(self::TABLE)->where('user_id', $user->id)
            ->where('created_at', '>', now()->subMinute())->exists();

        if (! $recent) {
            DB::table(self::TABLE)->insert(['user_id' => $user->id, 'requested_by' => Auth::id(), 'created_at' => now()]);
            ActivityLogService::info(LogName::Role, 'Discord role sync requested for ' . $user->id);
        }

        return redirect()->route('user.show', $user->id)
            ->withSuccess('Discord role sync requested. If ' . $user->first_name . ' is on Discord, their roles update within a minute.');
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
            ->orderBy('id')->limit(100)->get(['id', 'user_id']);

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
     * The newest request for a member, for the profile panel. Null if none.
     */
    public static function latestFor(int $userId): ?object
    {
        return DB::table(self::TABLE)->where('user_id', $userId)->orderByDesc('id')->first();
    }
}
