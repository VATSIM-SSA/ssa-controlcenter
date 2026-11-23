<?php

namespace App\Services;

use App\Helpers\VatsimRating;
use App\Http\Controllers\VatssaDiscordSyncController as Sync;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * VATSSA: checks, saves and restores Discord rules for the rule pages.
 */
class VatssaDiscordRuleStore
{
    private const RULES = 'vatssa_discord_rules';

    private const AUDIT = 'vatssa_discord_rule_audit';

    public function __construct(private VatssaDiscordRules $engine) {}

    /**
     * @return array{name: string, description: ?string, enabled: bool, conditions: array, roles: array<int, string>}
     */
    public function validate(array $input, ?int $id = null): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:80', Rule::unique(self::RULES, 'name')->ignore($id)],
            'description' => 'nullable|string|max:255',
            'enabled' => 'sometimes|boolean',
            'conditions' => 'required|array|min:1|max:10',
            'conditions.*.type' => ['required', Rule::in(array_keys(VatssaDiscordRules::TYPES))],
            'roles' => 'required|array|min:1|max:20',
            'roles.*' => ['required', 'string', 'regex:/^\d{15,21}$/'],
        ], ['conditions.required' => 'Add at least one condition.', 'roles.required' => 'Pick at least one Discord role.'])->validate();

        $roles = array_values(array_unique(array_map('strval', $data['roles'])));
        $assignable = DB::table('vatssa_discord_guild_roles')->where('assignable', true)->whereIn('discord_role_id', $roles)
            ->pluck('discord_role_id')->map(fn ($r) => (string) $r)->all();
        // A role already on this rule may stay even if the bot has not listed it yet.
        $kept = $id ? DB::table('vatssa_discord_rule_roles')->where('rule_id', $id)->pluck('discord_role_id')->map(fn ($r) => (string) $r)->all() : [];
        if (array_diff($roles, $assignable, $kept)) {
            throw ValidationException::withMessages(['roles' => 'Pick roles the bot can assign.']);
        }

        return [
            'name' => $data['name'],
            'description' => ($data['description'] ?? '') === '' ? null : $data['description'],
            'enabled' => (bool) ($data['enabled'] ?? true),
            'conditions' => array_values(array_map(fn ($row) => $this->condition($row), $input['conditions'])),
            'roles' => $roles,
        ];
    }

    /**
     * A stored condition as the flat row the editor binds to.
     */
    public function toRow(array $condition): array
    {
        $p = $condition['params'];

        return [
            'key' => uniqid('c', true), 'type' => $condition['type'], 'negated' => $condition['negated'] ? '1' : '0',
            'division' => $condition['type'] === 'division' ? $p['value'] : 'home',
            'rating_mode' => $p['mode'] ?? 'in',
            'ratings' => array_map('strval', $p['values'] ?? []),
            'rating_value' => (string) ($condition['type'] === 'rating' && ($p['mode'] ?? 'in') !== 'in' ? $p['value'] : VatsimRating::S1->value),
            'role' => $p['role'] ?? array_key_first(config('roles.roles')),
            'endorsement' => $condition['type'] === 'endorsement' ? $p['type'] : 'VISITING',
            'compare' => $p['compare'] ?? 'any',
            'endorsement_rating' => $condition['type'] === 'endorsement' ? ($p['rating'] ?? '') : '',
            'group' => (string) ($p['group'] ?? DB::table('vatssa_groups')->whereNull('archived_at')->orderBy('name')->value('id') ?? ''),
            'area' => (string) ($p['area'] ?? 'any'),
        ];
    }

    public function blankRow(): array
    {
        return $this->toRow(['type' => 'division', 'params' => ['value' => 'home'], 'negated' => false]);
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

        $params = match ($row['type']) {
            'division' => in_array($row['division'] ?? '', ['home', 'visitor'], true) ? ['value' => $row['division']] : $fail('Pick home or visitor.'),
            'rating' => $this->ratingParams($row, array_column(VatsimRating::cases(), 'value'), $fail),
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
     * Roles this rule gives today that nothing would give after this edit, by name.
     *
     * @param  array<int, string>  $roles  what the rule would give; empty for a delete
     * @return array<int, string>
     */
    public function droppedRoles(?int $id, array $roles): array
    {
        $all = $this->engine->all();
        $old = $all->firstWhere('id', $id);
        if (! $old || ! $old->enabled) {
            return [];
        }
        $still = $all->reject(fn ($r) => $r->id === $id)->filter(fn ($r) => $r->enabled)->flatMap(fn ($r) => $r->roles)
            ->merge($roles)->map(fn ($r) => (string) $r)->all();
        $gone = array_values(array_diff(array_map('strval', $old->roles), $still));
        if (! $gone) {
            return [];
        }
        $names = DB::table('vatssa_discord_guild_roles')->whereIn('discord_role_id', $gone)->get(['discord_role_id', 'name'])
            ->mapWithKeys(fn ($r) => ['r' . $r->discord_role_id => $r->name]);

        return array_map(fn ($role) => $names->get('r' . $role, $role), $gone);
    }

    /**
     * Save, audit, settle which roles stay managed, and queue everyone. `$data` null deletes.
     */
    public function save(?int $id, ?array $data, string $action, bool $removeDropped = false, ?int $keepId = null): void
    {
        DB::transaction(function () use (&$id, $data, $action, $removeDropped, $keepId) {
            $all = $this->engine->all();
            $managedBefore = $this->engine->managed($all);
            $old = $id ? $all->firstWhere('id', $id) : null;
            abort_if($id && ! $old, 404, 'That rule no longer exists.');
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

    /**
     * Put a rule back as it was before an audit entry. Returns why not, or null.
     */
    public function restore(int $audit): ?string
    {
        $entry = DB::table(self::AUDIT)->where('id', $audit)->first();
        if (! $entry) {
            return 'That change no longer exists.';
        }
        $before = $entry->before ? json_decode($entry->before, true) : null;
        $exists = DB::table(self::RULES)->where('id', $entry->rule_id)->exists();
        if ($before && DB::table(self::RULES)->where('name', $before['name'])->where('id', '!=', $entry->rule_id)->exists()) {
            return 'Another rule now has the name ' . $before['name'] . '.';
        }
        if (! $before && ! $exists) {
            return 'That rule no longer exists.';
        }
        if ($before) {
            try {
                $before = $this->validate([...$before, 'conditions' => array_map(fn ($c) => $this->toRow($c), $before['conditions'])], $exists ? (int) $entry->rule_id : null);
            } catch (ValidationException $e) {
                return 'That version cannot come back: ' . collect($e->errors())->flatten()->first();
            }
        }
        $this->save($exists ? (int) $entry->rule_id : null, $before, 'restored', false, (int) $entry->rule_id);

        return null;
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
