<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: the "Sync Discord roles" button on a user's profile.
 *
 * CC never calls the bot. The button writes a row; the bot polls index() with
 * its read-only API key and a cursor (the last id it handled), so the bot needs
 * no write access and nothing listens on a port. The bot always recomputes the
 * roles from scratch, so a request handled twice does nothing extra.
 */
class VatssaDiscordSyncController extends Controller
{
    private const TABLE = 'vatssa_discord_sync_requests';

    public function store(User $user): RedirectResponse
    {
        $this->authorize('vatssaDiscordSync');

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
     * the bot's own sweep has long since caught up with those.
     */
    public function index(Request $request): JsonResponse
    {
        $after = (int) ($request->validate(['after' => 'sometimes|integer|min:0'])['after'] ?? 0);

        $rows = DB::table(self::TABLE)->where('id', '>', $after)
            ->where('created_at', '>', now()->subDay())
            ->orderBy('id')->limit(100)->get(['id', 'user_id']);

        return response()->json(['data' => $rows]);
    }
}
