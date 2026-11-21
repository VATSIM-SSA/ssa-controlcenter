<?php

namespace App\Services;

use App\Helpers\VatsimRating;
use App\Http\Controllers\VatssaDiscordSyncController as Sync;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: decides every member's Discord roles. The only evaluator: the bot applies the
 * answer, and the rule page's preview runs this same code.
 *
 * A rule's conditions must all hold (AND). A role is given if any enabled rule gives it, so
 * OR is two rules for one role. An account with no VATSIM link matches only rules made
 * purely of "Not VATSIM Member": a negated condition must never hand it a member role.
 */
class VatssaDiscordRules
{
    public const TYPES = [
        'division' => 'VATSIM division',
        'rating' => 'VATSIM rating',
        'cc_role' => 'CC role',
        'endorsement' => 'Endorsement',
        'roster' => 'On the roster',
        'group' => 'CC group',
        'training' => 'Open training',
        'not_vatsim' => 'Not VATSIM Member',
    ];

    public const ENDORSEMENTS = ['VISITING', 'EXAMINER', 'SOLO', 'FACILITY'];

    /**
     * Every rule with its conditions and roles.
     *
     * @return Collection<int, object>
     */
    public function all(): Collection
    {
        $conditions = DB::table('vatssa_discord_rule_conditions')->orderBy('id')->get()->groupBy('rule_id');
        $roles = DB::table('vatssa_discord_rule_roles')->get()->groupBy('rule_id');

        return DB::table('vatssa_discord_rules')->orderBy('name')->get()->map(function ($rule) use ($conditions, $roles) {
            $rule->conditions = $conditions->get($rule->id, collect())->map(fn ($c) => [
                'type' => $c->type, 'params' => json_decode($c->params, true) ?: [], 'negated' => (bool) $c->negated,
            ])->values()->all();
            $rule->roles = $roles->get($rule->id, collect())->pluck('discord_role_id')->map(fn ($id) => (string) $id)->values()->all();

            return $rule;
        });
    }

    /**
     * Roles the bot may touch: in an enabled rule, or retired by staff.
     *
     * @return array<int, string>
     */
    public function managed(?Collection $rules = null): array
    {
        $given = ($rules ?? $this->all())->filter(fn ($r) => $r->enabled)->flatMap(fn ($r) => $r->roles);
        $retired = DB::table('vatssa_discord_guild_roles')->where('retired', true)->pluck('discord_role_id');

        return $given->merge($retired)->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    /**
     * The roles each account should have.
     *
     * @param  array<int, array{discord_id: string, cid: int}>  $accounts  cid 0 = no VATSIM link
     * @return array{roles: array<string, array<int, string>>, unknown: array<int, string>, facts: array<string, mixed>}
     */
    public function evaluate(array $accounts, ?Collection $rules = null): array
    {
        $rules = ($rules ?? $this->all())->filter(fn ($r) => $r->enabled)->values();
        $cids = collect($accounts)->pluck('cid')->filter()->map(fn ($cid) => (int) $cid)->unique()->values();

        $users = collect();
        foreach ($cids->chunk(500) as $chunk) {
            $users = $users->merge(User::with(['endorsements.ratings', 'roleAssignments', 'trainings', 'atcActivity'])
                ->whereIn('id', $chunk->values())->where('email', '!=', Sync::PSEUDONYMISED_EMAIL)->get());
        }
        $users = $users->keyBy('id');

        // No CC account: VATSIM's answer from our own table, or in line for a check.
        $facts = app(VatssaVatsimFacts::class)->forBot($cids->reject(fn ($cid) => $users->has($cid))->values()->all());

        $groups = collect();
        foreach ($cids->chunk(500) as $chunk) {
            $groups = $groups->merge(DB::table('vatssa_group_members as m')->join('vatssa_groups as g', 'g.id', '=', 'm.group_id')
                ->whereNull('m.removed_at')->whereNull('g.archived_at')->whereIn('m.user_id', $chunk->values())->get(['m.user_id', 'm.group_id']));
        }
        $groups = $groups->groupBy('user_id')->map(fn ($rows) => $rows->pluck('group_id')->map(fn ($id) => (int) $id)->all());

        $owner = config('app.owner_code');
        $out = ['roles' => [], 'unknown' => [], 'facts' => ['pulled_at' => $facts['pulled_at'], 'leaves_waiting' => $facts['leaves_waiting']]];
        foreach ($accounts as $account) {
            $id = (string) $account['discord_id'];
            $cid = (int) $account['cid'];
            if ($cid === 0) {
                $subject = ['linked' => false];
            } else {
                $user = $users->get($cid);
                $rating = $user ? $user->getRawOriginal('rating') : ($facts['data'][$cid]['rating'] ?? null);
                $division = $user ? $user->division : ($facts['data'][$cid]['division'] ?? null);
                if ($rating === null || ! $division) {
                    $out['unknown'][] = $id; // no answer means no change

                    continue;
                }
                $subject = ['linked' => true, 'rating' => (int) $rating, 'home' => $division === $owner, 'user' => $user, 'groups' => $groups->get($cid, [])];
            }

            $want = [];
            foreach ($rules as $rule) {
                if ($this->ruleMatches($rule, $subject)) {
                    array_push($want, ...$rule->roles);
                }
            }
            $out['roles'][$id] = array_values(array_unique($want));
        }

        return $out;
    }

    private function ruleMatches(object $rule, array $subject): bool
    {
        if (! $rule->conditions || ! $rule->roles) {
            return false; // a rule with no conditions must never mean "everyone"
        }
        foreach ($rule->conditions as $condition) {
            if (! $subject['linked'] && ($condition['type'] !== 'not_vatsim' || $condition['negated'])) {
                return false;
            }
            if ($this->holds($condition, $subject) === $condition['negated']) {
                return false;
            }
        }

        return true;
    }

    private function holds(array $condition, array $subject): bool
    {
        $p = $condition['params'];
        if ($condition['type'] === 'not_vatsim') {
            return ! $subject['linked'];
        }
        /** @var User|null $user */
        $user = $subject['user'];

        return match ($condition['type']) {
            'division' => ($p['value'] ?? '') === ($subject['home'] ? 'home' : 'visitor'),
            'rating' => match ($p['mode'] ?? 'in') {
                'min' => $subject['rating'] >= (int) ($p['value'] ?? PHP_INT_MAX),
                'max' => $subject['rating'] <= (int) ($p['value'] ?? PHP_INT_MIN),
                default => in_array($subject['rating'], array_map('intval', $p['values'] ?? []), true),
            },
            'group' => in_array((int) ($p['group'] ?? 0), $subject['groups'], true),
            'cc_role' => $user !== null && $user->roleAssignments->contains(fn ($a) => $a->role === ($p['role'] ?? null) && match ($p['area'] ?? 'any') {
                'any' => true,
                'global' => $a->area_id === null,
                default => (int) $a->area_id === (int) $p['area'],
            }),
            'roster' => $user !== null && (($p['area'] ?? 'any') === 'any'
                ? (bool) $user->isAtcActive()
                : $user->atcActivity->contains(fn ($a) => (int) $a->area_id === (int) $p['area'] && $a->atc_active)),
            'training' => $user !== null && $user->trainings->contains(fn ($t) => $t->status->isOpen()
                && (($p['area'] ?? 'any') === 'any' || (int) $t->area_id === (int) $p['area'])),
            'endorsement' => $user !== null && $this->endorsed($user, $p),
            default => false, // an unknown type never gives a role
        };
    }

    /**
     * compare: `any` (holds one, of this rating if given), `highest` (their highest of this
     * type is exactly this rating) or `at_least` (their highest is this rating or above).
     */
    private function endorsed(User $user, array $p): bool
    {
        $held = $user->endorsements->filter(fn ($e) => $e->type === ($p['type'] ?? null) && ! $e->revoked && ! $e->expired
            && (! $e->valid_to || Carbon::parse($e->valid_to)->isFuture()));
        $rating = $p['rating'] ?? null;
        if (! $rating) {
            return $held->isNotEmpty();
        }
        $names = $held->flatMap(fn ($e) => $e->ratings->pluck('name'));
        $compare = $p['compare'] ?? 'any';
        if ($compare === 'any') {
            return $names->contains($rating);
        }

        $order = array_column(VatsimRating::cases(), 'name');
        $highest = $names->map(fn ($name) => array_search($name, $order, true))->filter(fn ($i) => $i !== false)->max();
        $target = array_search($rating, $order, true);
        if ($highest === null || $target === false) {
            return false;
        }

        return $compare === 'highest' ? $highest === $target : $highest >= $target;
    }

    /**
     * A condition in words, for the rule page.
     */
    public function describe(array $condition, Collection $groups, Collection $areas): string
    {
        $p = $condition['params'];
        $area = fn ($value) => match ($value ?? 'any') {
            'any' => 'any area',
            'global' => 'global',
            default => $areas->get((int) $value, 'area ' . $value),
        };
        $name = fn ($value) => VatsimRating::tryFrom((int) $value)?->name ?? (string) $value;

        $text = match ($condition['type']) {
            'division' => ($p['value'] ?? '') === 'home' ? 'Home member' : 'Visitor',
            'rating' => match ($p['mode'] ?? 'in') {
                'min' => 'Rating ' . $name($p['value'] ?? '') . ' or above',
                'max' => 'Rating ' . $name($p['value'] ?? '') . ' or below',
                default => 'Rating ' . collect($p['values'] ?? [])->map($name)->implode(' or '),
            },
            'cc_role' => 'CC role ' . ($p['role'] ?? '') . ' (' . $area($p['area'] ?? 'any') . ')',
            'endorsement' => ucfirst(strtolower($p['type'] ?? '')) . ' endorsement' . (empty($p['rating']) ? '' : match ($p['compare'] ?? 'any') {
                'highest' => ', highest is ' . $p['rating'],
                'at_least' => ', highest is ' . $p['rating'] . ' or above',
                default => ' ' . $p['rating'],
            }),
            'roster' => 'On the roster (' . $area($p['area'] ?? 'any') . ')',
            'group' => 'In group ' . $groups->get((int) ($p['group'] ?? 0), '(deleted)'),
            'training' => 'Open training (' . $area($p['area'] ?? 'any') . ')',
            'not_vatsim' => 'Not VATSIM Member',
            default => $condition['type'],
        };

        return ($condition['negated'] ? 'NOT ' : '') . $text;
    }
}
