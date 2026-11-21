<?php

namespace App\Http\Controllers;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\LogName;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\VatssaDiscordRules;
use App\Services\VatssaVatsimFacts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * VATSSA: the Discord role sync, CC side.
 *
 * CC never calls the bot. CC queues a member; the bot claims rows with a lease, syncs,
 * and reports each result. A crashed bot's lease runs out and the row comes back. The
 * bot also reports who is in the server, which feeds the members table and its history.
 */
class VatssaDiscordSyncController extends Controller
{
    private const QUEUE = 'vatssa_discord_queue';

    private const MEMBERS = 'vatssa_discord_members';

    private const HISTORY = 'vatssa_discord_member_history';

    /**
     * An automatic request is queued from inside a save, before that request has
     * attached the endorsement's ratings, areas or positions. It waits this long, so
     * the bot never reads half-written data.
     */
    public const AUTO_SETTLE_SECONDS = 5;

    public const LEASE_SECONDS = 120;

    public const MAX_ATTEMPTS = 5;

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
        'unknown' => 'No answer from VATSIM',
        'not_on_discord' => 'Not on Discord',
        'paused' => 'Paused by a safety limit',
        'report' => 'Report mode, nothing applied',
    ];

    public const EVENTS = [
        'joined' => 'Joined the server',
        'left' => 'Left the server',
        'linked' => 'Linked to CID',
        'unlinked' => 'Unlinked from CID',
        'relinked' => 'Linked to another CID',
        'roles_changed' => 'Roles changed',
    ];

    public function store(User $user): RedirectResponse
    {
        $this->authorize('vatssaDiscordSync', $user);

        self::queue([$user->id], 'button', Auth::id(), 0, true);
        VatssaVatsimFacts::refresh($user->id);
        ActivityLogService::info(LogName::Role, 'Discord role sync requested for ' . $user->id);

        return redirect()->route('user.show', $user->id)
            ->withSuccess('Discord role sync requested. If ' . $user->first_name . ' is on Discord, their roles update within a minute.');
    }

    /**
     * Put members in the queue. Atomic per row, so a change that lands while the bot is
     * working on the member is never lost: `generation` moves and the row goes round again.
     * MySQL applies these assignments left to right, so `fresh` must read `state` first.
     *
     * @param  array<int, int>  $userIds
     */
    public static function queue(array $userIds, string $reason, ?int $by = null, int $settle = 0, bool $fresh = false): void
    {
        $now = now();
        $table = self::QUEUE;
        $wantsFresh = (int) $fresh;
        $rows = array_map(fn (int $id) => [
            'user_id' => $id, 'reason' => $reason, 'requested_by' => $by, 'fresh' => $wantsFresh, 'state' => 'pending',
            'generation' => 1, 'attempts' => 0, 'available_at' => $now->copy()->addSeconds($settle), 'queued_at' => $now,
        ], $userIds);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->upsert($chunk, ['user_id'], [
                'fresh' => DB::raw("CASE WHEN {$table}.state IN ('pending', 'leased') AND {$table}.fresh = 1 THEN 1 ELSE {$wantsFresh} END"),
                'state' => DB::raw("CASE WHEN {$table}.state = 'leased' THEN 'leased' ELSE 'pending' END"),
                'generation' => DB::raw("{$table}.generation + 1"),
                'attempts' => DB::raw('0'),
                'reason', 'requested_by', 'available_at', 'queued_at',
            ]);
        }
    }

    /**
     * Queue a member from a save, a login or a page view. Never throws: a failure here
     * must not break whatever triggered it, and the bot's sweep covers the member anyway.
     */
    public static function queueAuto(?int $userId, string $reason = 'change', bool $fresh = false): void
    {
        if (! $userId) {
            return;
        }
        try {
            self::queue([$userId], $reason, null, $reason === 'change' ? self::AUTO_SETTLE_SECONDS : 0, $fresh);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Staff opened a profile: at most once a minute per member, and never over a
     * request that is still waiting.
     */
    public static function queueOnView(int $userId): void
    {
        try {
            $row = DB::table(self::QUEUE)->where('user_id', $userId)->first();
            if ($row && ($row->state !== 'idle' || Carbon::parse($row->queued_at)->gt(now()->subMinute()))) {
                return;
            }
        } catch (\Throwable $e) {
            report($e);

            return;
        }
        self::queueAuto($userId, 'profile');
    }

    /**
     * A member was deleted or pseudonymised: drop their queue row, Discord link and
     * history. members() refuses to link them again. Never throws.
     */
    public static function forgetUser(int $userId): void
    {
        try {
            $accounts = DB::table(self::MEMBERS)->where('user_id', $userId)->pluck('discord_id');
            DB::table(self::QUEUE)->where('user_id', $userId)->delete();
            DB::table(self::HISTORY)->where('user_id', $userId)->orWhereIn('discord_id', $accounts)->delete();
            DB::table(self::MEMBERS)->whereIn('discord_id', $accounts)->delete();
            VatssaVatsimFacts::forget($userId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Bot API: lease the next rows. The daily round goes last, so it never delays a button.
     */
    public function claim(Request $request): JsonResponse
    {
        $limit = (int) ($request->validate(['limit' => 'sometimes|integer|min:1|max:50'])['limit'] ?? 20);
        $now = now();

        $claimed = DB::transaction(function () use ($limit, $now) {
            $rows = DB::table(self::QUEUE)
                ->where(fn ($q) => $q->where(fn ($p) => $p->where('state', 'pending')->where('available_at', '<=', $now))
                    ->orWhere(fn ($l) => $l->where('state', 'leased')->where('lease_until', '<', $now)))
                ->orderByRaw("reason IN ('daily', 'rule')")->orderBy('available_at')->limit($limit)->lockForUpdate()->get();

            $out = [];
            foreach ($rows as $row) {
                $where = DB::table(self::QUEUE)->where('user_id', $row->user_id);
                if ($row->attempts >= self::MAX_ATTEMPTS) {
                    $where->update(['state' => 'idle', 'lease_until' => null, 'result' => 'failed',
                        'detail' => 'The bot took this ' . self::MAX_ATTEMPTS . ' times without answering.', 'finished_at' => $now]);

                    continue;
                }
                $where->update(['state' => 'leased', 'lease_until' => $now->copy()->addSeconds(self::LEASE_SECONDS), 'attempts' => $row->attempts + 1]);
                $out[] = ['cid' => (int) $row->user_id, 'generation' => (int) $row->generation, 'fresh' => (bool) $row->fresh, 'reason' => $row->reason];
            }

            return $out;
        });

        // Accounts with no CID have no queue row. Sent once; the sweep is the safety net.
        $accounts = DB::transaction(function () use ($limit) {
            $ids = DB::table(self::MEMBERS)->where('needs_sync', true)->where('in_server', true)
                ->limit($limit)->lockForUpdate()->pluck('discord_id')->map(fn ($id) => (string) $id)->all();
            DB::table(self::MEMBERS)->whereIn('discord_id', $ids)->update(['needs_sync' => false]);

            return $ids;
        });

        return response()->json(['data' => $claimed, 'accounts' => $accounts]);
    }

    /**
     * Bot API: how a claimed row ended. If the member changed meanwhile, the row goes
     * round again. A repeat of an answer already recorded changes nothing.
     */
    public function result(Request $request, int $cid): Response
    {
        $data = $request->validate([
            'generation' => 'required|integer|min:1',
            'status' => 'required|string|in:' . implode(',', array_keys(self::STATUSES)),
            'detail' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($cid, $data) {
            $row = DB::table(self::QUEUE)->where('user_id', $cid)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->state !== 'leased') {
                return;
            }

            $now = now();
            $current = (int) $row->generation === (int) $data['generation'];
            DB::table(self::QUEUE)->where('user_id', $cid)->update([
                'state' => $current ? 'idle' : 'pending', 'lease_until' => null, 'attempts' => 0, 'fresh' => $current ? false : $row->fresh,
                'result' => $data['status'], 'detail' => $data['detail'] ?? null, 'finished_at' => $now,
            ]);

            if (in_array($data['status'], ['done', 'partial', 'no_change'], true)) {
                $account = DB::table(self::MEMBERS)->where('user_id', $cid)->first();
                if ($account) {
                    DB::table(self::MEMBERS)->where('discord_id', $account->discord_id)->update(['last_synced_at' => $now]);
                    if ($data['status'] !== 'no_change') {
                        DB::table(self::HISTORY)->insert(['discord_id' => $account->discord_id, 'user_id' => $cid, 'event' => 'roles_changed',
                            'detail' => Str::limit($data['detail'] ?? '', 250), 'created_at' => $now]);
                    }
                }
            }
        });

        return response()->noContent();
    }

    /**
     * Bot API: who is in the Discord server.
     *
     * complete = true (a sweep): anyone not listed has left. complete = false (a join, a
     * leave, a button): only the rows sent change. `at` is when the bot started building
     * the list; an older complete list than the last one applied is refused. An empty
     * complete list is refused too: far likelier a bot fault than a fact.
     *
     * cid: a number is the link, 0 is "VATSIM says not linked", null is "no answer", which
     * keeps what we had. Only CIDs of CC users who are neither deleted nor pseudonymised
     * are stored; anyone else counts as linked with no CID kept, so an erased person
     * stays erased. A CID sent for two accounts changes nothing for either.
     */
    public function members(Request $request): Response
    {
        $data = $request->validate([
            'complete' => 'required|boolean',
            'at' => 'required|numeric|min:0',
            'members' => 'present|array|max:50000',
            'members.*.discord_id' => ['required', 'string', 'regex:/^\d{15,21}$/'],
            'members.*.cid' => 'present|nullable|integer|min:0',
            'members.*.name' => 'nullable|string|max:100',
            'members.*.bot' => 'sometimes|boolean',
            'members.*.joined_at' => 'nullable|numeric|min:0',
            'members.*.roles' => 'sometimes|array|max:300',
            'members.*.roles.*' => ['string', 'regex:/^\d{15,21}$/'],
            'left' => 'sometimes|array|max:50000',
            'left.*' => ['string', 'regex:/^\d{15,21}$/'],
        ]);

        abort_if($data['complete'] && empty($data['members']), 422, 'A complete list cannot be empty.');

        $generatedAt = (float) $data['at'];
        if ($data['complete'] && $generatedAt < (float) Setting::get('vatssaDiscordPresenceGeneration', 0)) {
            abort(409, 'An older list than the one already applied.');
        }

        // PHP turns these array keys into integers. Always cast back before a query: MySQL
        // compares an integer with a string column as a float, which matches neighbouring IDs.
        $sent = collect($data['members'])->keyBy('discord_id');
        $counts = $sent->pluck('cid')->filter()->countBy();
        $known = User::whereIn('id', $counts->keys())->where('email', '!=', self::PSEUDONYMISED_EMAIL)->pluck('id')->flip();

        $toQueue = [];
        DB::transaction(function () use ($data, $sent, $counts, $known, &$toQueue) {
            $now = now();
            $existing = DB::table(self::MEMBERS)
                ->when(! $data['complete'], fn ($q) => $q->whereIn('discord_id', $sent->keys()->merge($data['left'] ?? [])->map(fn ($id) => (string) $id)))
                ->get()->keyBy('discord_id');
            $history = [];
            $log = function (string $id, ?int $cid, string $event, ?string $detail = null, $at = null) use (&$history, $now) {
                $history[] = ['discord_id' => $id, 'user_id' => $cid, 'event' => $event, 'detail' => $detail, 'created_at' => $at ?? $now];
            };

            // What each account should hold: [user_id, vatsim_linked]. Null cid or a clash keeps what we had.
            $targets = $sent->map(function (array $m, string $id) use ($counts, $known, $existing) {
                $cid = $m['cid'];
                if ($cid === null || ($cid > 0 && $counts[$cid] > 1)) {
                    return [$existing[$id]->user_id ?? null, $existing[$id]->vatsim_linked ?? null];
                }

                return $cid === 0 ? [null, false] : [$known->has($cid) ? (int) $cid : null, true];
            });

            // One account per CID: whoever holds a CID that is moving lets go first.
            $moving = $targets->map(fn ($t) => $t[0])->filter();
            foreach (DB::table(self::MEMBERS)->whereIn('user_id', $moving->values())->get() as $holder) {
                if ($moving->get($holder->discord_id) !== (int) $holder->user_id) {
                    DB::table(self::MEMBERS)->where('discord_id', $holder->discord_id)->update(['user_id' => null]);
                    $log($holder->discord_id, (int) $holder->user_id, 'unlinked', 'CID moved to another Discord account');
                    if ($existing->has($holder->discord_id)) {
                        $existing[$holder->discord_id]->user_id = null;
                    }
                }
            }

            $rows = [];
            foreach ($sent as $id => $m) {
                $id = (string) $id; // see the note on $sent
                $old = $existing->get($id);
                [$user, $linked] = $targets[$id];
                $joined = isset($m['joined_at']) ? Carbon::createFromTimestamp((float) $m['joined_at']) : ($old->joined_at ?? null);

                if (! $old) {
                    $log($id, $user, 'joined', null, $joined);
                } elseif (! $old->in_server) {
                    $log($id, $user, 'joined');
                }
                $was = $old?->user_id ? (int) $old->user_id : null;

                // The join flow: the bot sends the CID, CC queues the member, the bot applies.
                // A sweep has just applied everyone, so only a partial list queues.
                $arrived = ! $old || ! $old->in_server || $user !== $was;
                $unlinkedArrival = false;
                if (! $data['complete'] && $arrived && empty($m['bot'])) {
                    if (($m['cid'] ?? null) > 0) {
                        $toQueue[] = (int) $m['cid'];
                    } elseif (($m['cid'] ?? null) === 0) {
                        $unlinkedArrival = true;
                    }
                }

                if ($user !== $was) {
                    if ($was && $user) {
                        $log($id, $user, 'relinked', "CID {$was} to {$user}");
                    } elseif ($was) {
                        $log($id, $was, 'unlinked');
                    } else {
                        $log($id, $user, 'linked', "CID {$user}");
                    }
                }

                $rows[] = [
                    'discord_id' => $id, 'user_id' => $user, 'vatsim_linked' => $linked, 'name' => $m['name'] ?? $old?->name,
                    'is_bot' => (bool) ($m['bot'] ?? $old?->is_bot ?? false), 'in_server' => true, 'joined_at' => $joined,
                    'left_at' => null, 'last_synced_at' => $old?->last_synced_at, 'last_seen_at' => $now,
                    'role_ids' => array_key_exists('roles', $m) ? json_encode(array_values($m['roles'])) : ($old?->role_ids),
                    'needs_sync' => $unlinkedArrival || (bool) ($old?->needs_sync),
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table(self::MEMBERS)->upsert($chunk, ['discord_id'], ['user_id', 'vatsim_linked', 'name', 'is_bot', 'in_server', 'joined_at', 'left_at', 'last_seen_at', 'role_ids', 'needs_sync']);
            }

            $gone = $data['complete']
                ? $existing->keys()->diff($sent->keys())
                : collect($data['left'] ?? [])->diff($sent->keys());
            foreach ($gone as $id) {
                $id = (string) $id;
                if (($row = $existing->get($id)) && $row->in_server) {
                    DB::table(self::MEMBERS)->where('discord_id', $id)->update(['in_server' => false, 'left_at' => $now]);
                    $log($id, $row->user_id ? (int) $row->user_id : null, 'left');
                }
            }
            foreach (array_chunk($history, 500) as $chunk) {
                DB::table(self::HISTORY)->insert($chunk);
            }
        }, 3);

        if ($toQueue) {
            self::queue(array_values(array_unique($toQueue)), 'join');
        }

        if ($data['complete']) {
            Setting::set('vatssaDiscordPresenceAt', now()->toIso8601String());
            Setting::set('vatssaDiscordPresenceGeneration', $generatedAt);
            Setting::save();
        }

        return response()->noContent();
    }

    /**
     * Bot API: the Discord roles each account should have. CC decides; the bot applies.
     * `unknown` accounts have no VATSIM answer yet and must be left as they are.
     */
    public function roles(Request $request, VatssaDiscordRules $rules): JsonResponse
    {
        $data = $request->validate([
            'accounts' => 'present|array|max:5000',
            'accounts.*.discord_id' => ['required', 'string', 'regex:/^\d{15,21}$/'],
            'accounts.*.cid' => 'required|integer|min:0',
        ]);

        $answer = $rules->evaluate($data['accounts']);

        return response()->json([
            'managed' => $rules->managed(),
            'roles' => (object) $answer['roles'],
            'unknown' => $answer['unknown'],
            'users_count' => User::count(),
            ...$answer['facts'],
        ]);
    }

    /**
     * Bot API: the server's roles, so the rule page shows names. An empty list is a fault.
     */
    public function guildRoles(Request $request): Response
    {
        $data = $request->validate([
            'roles' => 'required|array|min:1|max:300',
            'roles.*.id' => ['required', 'string', 'regex:/^\d{15,21}$/'],
            'roles.*.name' => 'required|string|max:100',
            'roles.*.colour' => 'required|integer|min:0',
            'roles.*.position' => 'required|integer',
            'roles.*.assignable' => 'required|boolean',
        ]);

        $now = now();
        $rows = array_map(fn ($r) => ['discord_role_id' => (string) $r['id'], 'name' => $r['name'], 'colour' => $r['colour'],
            'position' => $r['position'], 'assignable' => $r['assignable'], 'retired' => false, 'seen_at' => $now], $data['roles']);

        DB::transaction(function () use ($rows) {
            DB::table('vatssa_discord_guild_roles')->upsert($rows, ['discord_role_id'], ['name', 'colour', 'position', 'assignable', 'seen_at']);
            // Gone from Discord. A retired role goes too: there is nothing left to remove.
            DB::table('vatssa_discord_guild_roles')->whereNotIn('discord_role_id', array_column($rows, 'discord_role_id'))->delete();
        });

        return response()->noContent();
    }

    /**
     * Staff page: every Discord account the bot has seen.
     */
    public function index(Request $request): View
    {
        abort_unless($request->user()->hasPermission('vatssa.discord.members.view'), 403);

        $filters = $request->validate(['q' => 'nullable|string|max:100', 'show' => 'nullable|in:server,not_vatsim,bots,left']);
        $show = $filters['show'] ?? 'server';
        $search = trim($filters['q'] ?? '');

        $members = DB::table(self::MEMBERS)
            ->when($show === 'server', fn ($q) => $q->where('in_server', true))
            ->when($show === 'not_vatsim', fn ($q) => $q->where('in_server', true)->where('is_bot', false)->where('vatsim_linked', false))
            ->when($show === 'bots', fn ($q) => $q->where('in_server', true)->where('is_bot', true))
            ->when($show === 'left', fn ($q) => $q->where('in_server', false))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%' . addcslashes($search, '%_\\') . '%')
                ->orWhere('discord_id', $search)->orWhere('user_id', ctype_digit($search) ? $search : -1)))
            ->orderBy('name')->paginate(50)->withQueryString();

        $history = DB::table(self::HISTORY)->whereIn('discord_id', $members->pluck('discord_id'))
            ->orderByDesc('id')->get()->groupBy('discord_id');

        return view('vatssa.discord.members', compact('members', 'history', 'show', 'search'));
    }

    /**
     * For the profile: the member's Discord account, its history, and when the bot last
     * sent a complete list. 'at' is null when it never has, or not in the last two hours:
     * then CC does not know, and says so.
     *
     * @return array{at: Carbon|null, account: object|null, history: Collection<int, object>}
     */
    public static function presenceFor(int $userId): array
    {
        $at = Setting::get('vatssaDiscordPresenceAt');
        $at = $at ? Carbon::parse($at) : null;

        return [
            'at' => $at && $at->gt(now()->subHours(2)) ? $at : null,
            'account' => DB::table(self::MEMBERS)->where('user_id', $userId)->first(),
            'history' => DB::table(self::HISTORY)->where('user_id', $userId)->orderByDesc('id')->limit(30)->get(),
        ];
    }

    /**
     * The member's queue row, for the profile panel. Null if never queued.
     */
    public static function latestFor(int $userId): ?object
    {
        return DB::table(self::QUEUE)->where('user_id', $userId)->first();
    }
}
