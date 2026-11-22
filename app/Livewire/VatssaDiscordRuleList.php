<?php

namespace App\Livewire;

use App\Helpers\LogName;
use App\Http\Controllers\VatssaDiscordSyncController as Sync;
use App\Services\ActivityLogService;
use App\Services\VatssaDiscordRules;
use App\Services\VatssaDiscordRuleStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

// VATSSA: the Discord rule list. One block per role, because a role is given if any rule holds.
class VatssaDiscordRuleList extends Component
{
    #[Url(except: '')]
    public string $search = '';

    public bool $showHistory = false;

    public ?string $status = null;

    public ?string $error = null;

    // Runs on every Livewire request, where the page controller's check does not.
    public function boot(): void
    {
        abort_unless(Auth::user()?->hasPermission('vatssa.discord.rules.view'), 403);
    }

    private function needManage(): void
    {
        abort_unless(Auth::user()->hasPermission('vatssa.discord.rules.manage'), 403);
    }

    public function toggle(int $id, VatssaDiscordRules $engine, VatssaDiscordRuleStore $store): void
    {
        $this->needManage();
        $rule = $engine->all()->firstWhere('id', $id) ?? abort(404);
        $store->save($id, ['name' => $rule->name, 'description' => $rule->description, 'enabled' => ! $rule->enabled,
            'conditions' => $rule->conditions, 'roles' => $rule->roles], $rule->enabled ? 'disabled' : 'enabled');
        [$this->status, $this->error] = [$rule->name . ($rule->enabled ? ' switched off.' : ' switched on.'), null];
    }

    public function restore(int $audit, VatssaDiscordRuleStore $store): void
    {
        $this->needManage();
        $why = $store->restore($audit);
        [$this->status, $this->error] = $why ? [null, $why] : ['Rule restored. Affected members are queued.', null];
    }

    /**
     * Release a batch the hourly limits held back. The bot's next poll clears the pause and
     * runs one sweep without the limits.
     */
    public function approve(): void
    {
        $this->needManage();
        Cache::put(Sync::BOT_APPROVE, Auth::id(), now()->addHour());
        ActivityLogService::warning(LogName::Role, 'Discord role sync: held changes approved');
        [$this->status, $this->error] = ['Approved. The bot applies the held changes within a few seconds.', null];
    }

    public function render(VatssaDiscordRules $engine): View
    {
        $rules = $engine->all();
        $groups = DB::table('vatssa_groups')->pluck('name', 'id');
        $areas = DB::table('areas')->pluck('name', 'id');
        foreach ($rules as $rule) {
            $rule->words = collect($rule->conditions)->map(fn ($c) => $engine->describe($c, $groups, $areas))->all();
        }

        $known = DB::table('vatssa_discord_guild_roles')->get()->mapWithKeys(fn ($r) => ['r' . $r->discord_role_id => $r]);
        $term = mb_strtolower(trim($this->search));
        $byRole = $rules->flatMap(fn ($rule) => array_map(fn ($role) => ['role' => (string) $role, 'rule' => $rule], $rule->roles))
            ->groupBy(fn ($row) => 'r' . $row['role'])
            ->map(fn ($rows, $key) => [
                'key' => $key,
                'name' => $known->get($key)?->name ?? $rows[0]['role'],
                'position' => (int) ($known->get($key)?->position ?? 0),
                'rules' => $rows->pluck('rule')->sortByDesc('enabled')->values(),
            ])
            ->filter(fn ($role) => $term === '' || str_contains(mb_strtolower($role['name']), $term)
                || $role['rules']->contains(fn ($r) => str_contains(mb_strtolower($r->name . ' ' . implode(' ', $r->words)), $term)))
            ->sortBy([['position', 'desc'], ['name', 'asc']])->values();

        return view('livewire.vatssa-discord-rule-list', [
            'rules' => $rules,
            'byRole' => $byRole,
            'bot' => Sync::botStatus(),
            'audit' => $this->showHistory ? DB::table('vatssa_discord_rule_audit')->orderByDesc('id')->limit(30)->get() : collect(),
            'canManage' => Auth::user()->hasPermission('vatssa.discord.rules.manage'),
        ]);
    }
}
