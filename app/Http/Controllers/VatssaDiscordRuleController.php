<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Helpers\VatsimRating;
use App\Http\Controllers\VatssaDiscordSyncController as Sync;
use App\Services\ActivityLogService;
use App\Services\VatssaDiscordRules;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * VATSSA: the Discord rule page. Matrix: vatssa.discord.rules.view and .manage.
 */
class VatssaDiscordRuleController extends Controller
{
    private const RULES = 'vatssa_discord_rules';

    private const AUDIT = 'vatssa_discord_rule_audit';

    public function __construct(private VatssaDiscordRules $engine) {}

    private function need(string $permission): void
    {
        abort_unless(Auth::user()->hasPermission($permission), 403);
    }

    public function index(): View
    {
        $this->need('vatssa.discord.rules.view');
        $rules = $this->engine->all();
        $groups = DB::table('vatssa_groups')->pluck('name', 'id');
        $areas = DB::table('areas')->pluck('name', 'id');
        foreach ($rules as $rule) {
            $rule->words = collect($rule->conditions)->map(fn ($c) => $this->engine->describe($c, $groups, $areas))->all();
        }

        // One line per Discord role: a role is given if any of its rules holds.
        $known = DB::table('vatssa_discord_guild_roles')->get()->mapWithKeys(fn ($r) => ['r' . $r->discord_role_id => $r]);
        $byRole = $rules->flatMap(fn ($rule) => array_map(fn ($role) => ['role' => (string) $role, 'rule' => $rule], $rule->roles))
            ->groupBy(fn ($row) => 'r' . $row['role'])
            ->map(fn ($rows, $key) => [
                'name' => $known->get($key)?->name ?? $rows[0]['role'],
                'position' => (int) ($known->get($key)?->position ?? 0),
                'rules' => $rows->pluck('rule')->sortByDesc('enabled')->values(),
            ])->sortBy([['position', 'desc'], ['name', 'asc']])->values();

        return view('vatssa.discord.rules.index', [
            'rules' => $rules,
            'byRole' => $byRole,
            'bot' => Sync::botStatus(),
            'audit' => DB::table(self::AUDIT)->orderByDesc('id')->limit(30)->get(),
            'canManage' => Auth::user()->hasPermission('vatssa.discord.rules.manage'),
        ]);
    }

    /**
     * Release a batch the hourly limits held back. The bot's next poll clears the pause and
     * runs one sweep without the limits.
     */
    public function approve(): RedirectResponse
    {
        $this->need('vatssa.discord.rules.manage');
        Cache::put(Sync::BOT_APPROVE, Auth::id(), now()->addHour());
        ActivityLogService::warning(LogName::Role, 'Discord role sync: held changes approved');

        return back()->withSuccess('Approved. The bot applies the held changes within a few seconds.');
    }

    public function create(): View
    {
        $this->need('vatssa.discord.rules.manage');

        return $this->form((object) ['id' => null, 'name' => '', 'description' => '', 'enabled' => true, 'conditions' => [], 'roles' => []]);
    }

    public function edit(int $rule): View
    {
        $this->need('vatssa.discord.rules.manage');

        return $this->form($this->engine->all()->firstWhere('id', $rule) ?? abort(404));
    }

    private function form(object $rule): View
    {
        return view('vatssa.discord.rules.form', [
            'rule' => $rule,
            'types' => VatssaDiscordRules::TYPES,
            'endorsements' => VatssaDiscordRules::ENDORSEMENTS,
            'ratings' => collect(VatsimRating::cases())->map(fn ($r) => ['value' => $r->value, 'name' => $r->name])->all(),
            'ratingNames' => DB::table('ratings')->orderBy('name')->pluck('name')->all(),
            'ccRoles' => array_keys(config('roles.roles')),
            'areas' => DB::table('areas')->orderBy('name')->get(['id', 'name']),
            'groups' => DB::table('vatssa_groups')->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'guildRoles' => DB::table('vatssa_discord_guild_roles')->where('assignable', true)->orderByDesc('position')->get(['discord_role_id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->need('vatssa.discord.rules.manage');
        $data = $this->validated($request);
        $this->write(null, $data, 'created', $request->input('dropped') === 'remove');

        return redirect()->route('vatssa.discord.rules.index')->withSuccess('Rule created. Affected members are queued.');
    }

    public function update(Request $request, int $rule): RedirectResponse
    {
        $this->need('vatssa.discord.rules.manage');
        abort_unless(DB::table(self::RULES)->where('id', $rule)->exists(), 404);
        $data = $this->validated($request, $rule);
        $this->write($rule, $data, 'updated', $request->input('dropped') === 'remove');

        return redirect()->route('vatssa.discord.rules.index')->withSuccess('Rule saved. Affected members are queued.');
    }

    public function toggle(Request $request, int $rule): RedirectResponse
    {
        $this->need('vatssa.discord.rules.manage');
        $current = $this->engine->all()->firstWhere('id', $rule) ?? abort(404);
        $this->write($rule, ['name' => $current->name, 'description' => $current->description, 'enabled' => ! $current->enabled,
            'conditions' => $current->conditions, 'roles' => $current->roles], $current->enabled ? 'disabled' : 'enabled', $request->input('dropped') === 'remove');

        return back()->withSuccess($current->enabled ? 'Rule switched off.' : 'Rule switched on.');
    }

    public function destroy(Request $request, int $rule): RedirectResponse
    {
        $this->need('vatssa.discord.rules.manage');
        abort_unless(DB::table(self::RULES)->where('id', $rule)->exists(), 404);
        $this->write($rule, null, 'deleted', $request->input('dropped') === 'remove');

        return redirect()->route('vatssa.discord.rules.index')->withSuccess('Rule deleted.');
    }

    /**
     * Put a rule back as it was before an audit entry.
     */
    public function restore(int $audit): RedirectResponse
    {
        $this->need('vatssa.discord.rules.manage');
        $entry = DB::table(self::AUDIT)->where('id', $audit)->first() ?? abort(404);
        $before = $entry->before ? json_decode($entry->before, true) : null;
        $exists = DB::table(self::RULES)->where('id', $entry->rule_id)->exists();
        if ($before && DB::table(self::RULES)->where('name', $before['name'])->where('id', '!=', $entry->rule_id)->exists()) {
            return back()->withErrors('Another rule now has the name ' . $before['name'] . '.');
        }
        if (! $before && ! $exists) {
            return back()->withErrors('That rule no longer exists.');
        }

        $this->write($exists ? (int) $entry->rule_id : null, $before, 'restored', false, (int) $entry->rule_id);

        return back()->withSuccess('Rule restored. Affected members are queued.');
    }

    /**
     * What a save would do, using the real evaluator against the roles the bot last saw.
     * People with no CC account are not counted: CC keeps no CID for them.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->need('vatssa.discord.rules.manage');
        $id = $request->integer('rule_id') ?: null;
        $delete = $request->boolean('delete');
        $data = $delete ? null : $this->validated($request, $id);

        $current = $this->engine->all();
        $proposed = $current->reject(fn ($r) => $r->id === $id)->values();
        if ($data) {
            $proposed->push((object) [...$data, 'id' => $id ?? 0]);
        }

        $before = $this->engine->managed($current);
        $after = $this->engine->managed($proposed);
        $members = DB::table('vatssa_discord_members')->where('in_server', true)->where('is_bot', false)->whereNotNull('role_ids')
            ->where(fn ($q) => $q->whereNotNull('user_id')->orWhere('vatsim_linked', false))->get(['discord_id', 'user_id', 'role_ids']);
        $have = $members->mapWithKeys(fn ($m) => ['d' . $m->discord_id => array_map('strval', json_decode($m->role_ids, true) ?: [])]);
        // Only what this edit changes: a member already out of step for another reason is not its doing.
        $accounts = $members->map(fn ($m) => ['discord_id' => (string) $m->discord_id, 'cid' => (int) $m->user_id])->all();
        $was = $this->engine->evaluate($accounts, $current)['roles'];
        $wanted = $this->engine->evaluate($accounts, $proposed);

        $adds = $removes = [];
        foreach ($wanted['roles'] as $discordId => $want) {
            $discordId = (string) $discordId;
            $roles = $have['d' . $discordId] ?? [];
            $old = $was[$discordId] ?? [];
            foreach (array_diff($want, $old, $roles) as $role) {
                $adds[$role] = ($adds[$role] ?? 0) + 1;
            }
            foreach (array_intersect(array_diff($old, $want), $roles, $after) as $role) {
                $removes[$role] = ($removes[$role] ?? 0) + 1;
            }
        }

        $names = DB::table('vatssa_discord_guild_roles')->pluck('name', 'discord_role_id')->mapWithKeys(fn ($n, $k) => ['r' . $k => $n]);
        $label = fn ($role) => $names->get('r' . $role) ?? (string) $role;
        $list = fn (array $counts) => collect($counts)->map(fn ($n, $role) => ['role' => $label((string) $role), 'members' => $n])->values();
        $dropped = collect(array_diff($before, $after))->map(fn ($role) => [
            'role' => $label($role), 'members' => $have->filter(fn ($r) => in_array($role, $r, true))->count(),
        ])->values();

        return response()->json(['adds' => $list($adds), 'removes' => $list($removes), 'dropped' => $dropped, 'counted' => $members->count()]);
    }

    /**
     * @return array{name: string, description: ?string, enabled: bool, conditions: array, roles: array<int, string>}
     */
    private function validated(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique(self::RULES, 'name')->ignore($id)],
            'description' => 'nullable|string|max:255',
            'enabled' => 'sometimes|boolean',
            'conditions' => 'required|array|min:1|max:10',
            'conditions.*.type' => ['required', Rule::in(array_keys(VatssaDiscordRules::TYPES))],
            'conditions.*.negated' => 'sometimes|boolean',
            'roles' => 'required|array|min:1|max:20',
            'roles.*' => ['required', 'string', 'regex:/^\d{15,21}$/'],
        ]);

        $assignable = DB::table('vatssa_discord_guild_roles')->where('assignable', true)
            ->whereIn('discord_role_id', array_map('strval', $data['roles']))->pluck('discord_role_id')->map(fn ($r) => (string) $r)->all();
        // A role already on this rule may stay even if the bot has not listed it yet.
        $kept = $id ? DB::table('vatssa_discord_rule_roles')->where('rule_id', $id)->pluck('discord_role_id')->map(fn ($r) => (string) $r)->all() : [];
        if (array_diff(array_map('strval', $data['roles']), $assignable, $kept)) {
            throw ValidationException::withMessages(['roles' => 'Pick roles the bot can assign.']);
        }

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'enabled' => (bool) ($data['enabled'] ?? true),
            'conditions' => array_map(fn ($i) => $this->condition($request->input("conditions.{$i}")), array_keys($data['conditions'])),
            'roles' => array_values(array_unique(array_map('strval', $data['roles']))),
        ];
    }

    /**
     * Keep only the parameters this type uses, each checked.
     */
    private function condition(array $row): array
    {
        $fail = fn (string $why) => throw ValidationException::withMessages(['conditions' => $why]);
        $area = function ($value, bool $global = false) use ($fail) {
            $value = (string) ($value ?? 'any');
            if ($value === 'any' || ($global && $value === 'global')) {
                return $value;
            }
            ctype_digit($value) && DB::table('areas')->where('id', $value)->exists() || $fail('Pick a valid area.');

            return (int) $value;
        };
        $ratings = array_column(VatsimRating::cases(), 'value');

        $params = match ($row['type']) {
            'division' => in_array($row['division'] ?? '', ['home', 'visitor'], true) ? ['value' => $row['division']] : $fail('Pick home or visitor.'),
            'rating' => $this->ratingParams($row, $ratings, $fail),
            'cc_role' => array_key_exists($row['role'] ?? '', config('roles.roles'))
                ? ['role' => $row['role'], 'area' => $area($row['area'] ?? 'any', true)] : $fail('Pick a CC role.'),
            'endorsement' => $this->endorsementParams($row, $fail),
            'roster', 'training' => ['area' => $area($row['area'] ?? 'any')],
            'group' => DB::table('vatssa_groups')->where('id', (int) ($row['group'] ?? 0))->exists() ? ['group' => (int) $row['group']] : $fail('Pick a group.'),
            default => [],
        };

        return ['type' => $row['type'], 'params' => $params, 'negated' => filter_var($row['negated'] ?? false, FILTER_VALIDATE_BOOLEAN)];
    }

    private function ratingParams(array $row, array $ratings, callable $fail): array
    {
        $mode = $row['rating_mode'] ?? 'in';
        if ($mode === 'in') {
            $values = array_values(array_map('intval', (array) ($row['ratings'] ?? [])));

            return $values && ! array_diff($values, $ratings) ? ['mode' => 'in', 'values' => $values] : $fail('Pick at least one rating.');
        }

        return in_array($mode, ['min', 'max'], true) && is_numeric($row['rating_value'] ?? null) && in_array((int) $row['rating_value'], $ratings, true)
            ? ['mode' => $mode, 'value' => (int) $row['rating_value']] : $fail('Pick a rating.');
    }

    private function endorsementParams(array $row, callable $fail): array
    {
        in_array($row['endorsement'] ?? '', VatssaDiscordRules::ENDORSEMENTS, true) || $fail('Pick an endorsement type.');
        $params = ['type' => $row['endorsement']];
        if (! empty($row['endorsement_rating'])) {
            DB::table('ratings')->where('name', $row['endorsement_rating'])->exists() || $fail('Pick a valid endorsement rating.');
            in_array($row['compare'] ?? 'any', ['any', 'highest', 'at_least'], true) || $fail('Pick how to compare the rating.');
            $params += ['rating' => $row['endorsement_rating'], 'compare' => $row['compare'] ?? 'any'];
        }

        return $params;
    }

    /**
     * Save, audit, settle which roles stay managed, and queue everyone. `$data` null deletes.
     */
    private function write(?int $id, ?array $data, string $action, bool $removeDropped, ?int $keepId = null): void
    {
        DB::transaction(function () use (&$id, $data, $action, $removeDropped, $keepId) {
            $all = $this->engine->all();
            $managedBefore = $this->engine->managed($all);
            $old = $id ? $all->firstWhere('id', $id) : null;
            $now = now();

            if ($data === null) {
                DB::table(self::RULES)->where('id', $id)->delete();
            } else {
                $row = ['name' => $data['name'], 'description' => $data['description'] ?? null, 'enabled' => $data['enabled'], 'updated_by' => Auth::id(), 'updated_at' => $now];
                if ($id) {
                    DB::table(self::RULES)->where('id', $id)->update($row);
                } else {
                    $id = DB::table(self::RULES)->insertGetId([...$row, 'id' => $keepId, 'created_by' => Auth::id(), 'created_at' => $now]);
                }
                DB::table('vatssa_discord_rule_conditions')->where('rule_id', $id)->delete();
                DB::table('vatssa_discord_rule_roles')->where('rule_id', $id)->delete();
                foreach ($data['conditions'] as $c) {
                    DB::table('vatssa_discord_rule_conditions')->insert(['rule_id' => $id, 'type' => $c['type'], 'params' => json_encode($c['params']), 'negated' => $c['negated']]);
                }
                foreach ($data['roles'] as $role) {
                    DB::table('vatssa_discord_rule_roles')->insert(['rule_id' => $id, 'discord_role_id' => (string) $role]);
                }
            }

            $snapshot = fn (?object $r) => $r ? ['name' => $r->name, 'description' => $r->description, 'enabled' => (bool) $r->enabled, 'conditions' => $r->conditions, 'roles' => $r->roles] : null;
            DB::table(self::AUDIT)->insert(['rule_id' => $id, 'rule_name' => $data['name'] ?? $old->name, 'action' => $action,
                'before' => $old ? json_encode($snapshot($old)) : null, 'after' => $data ? json_encode($data) : null, 'by' => Auth::id(), 'created_at' => $now]);

            $inRules = $this->engine->all()->filter(fn ($r) => $r->enabled)->flatMap(fn ($r) => $r->roles)->map(fn ($r) => (string) $r)->unique()->values();
            DB::table('vatssa_discord_guild_roles')->whereIn('discord_role_id', $inRules)->update(['retired' => false]);
            if ($removeDropped) {
                foreach (array_diff($managedBefore, $inRules->all()) as $role) {
                    DB::table('vatssa_discord_guild_roles')->updateOrInsert(['discord_role_id' => (string) $role], ['retired' => true]);
                }
            }
        });

        $this->queueEveryone();
    }

    private function queueEveryone(): void
    {
        try {
            $cids = DB::table('vatssa_discord_members')->where('in_server', true)->whereNotNull('user_id')->pluck('user_id');
            Sync::queue($cids->map(fn ($cid) => (int) $cid)->all(), 'rule');
            DB::table('vatssa_discord_members')->where('in_server', true)->where('is_bot', false)->whereNull('user_id')->update(['needs_sync' => true]);
        } catch (\Throwable $e) {
            report($e); // the rule is saved; the bot's sweep applies it within 15 minutes
        }
    }
}
