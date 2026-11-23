<?php

namespace App\Livewire;

use App\Helpers\VatsimRating;
use App\Services\VatssaDiscordRules;
use App\Services\VatssaDiscordRuleStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

// VATSSA: create or edit one Discord rule.
class VatssaDiscordRuleEditor extends Component
{
    #[Locked]
    public ?int $ruleId = null;

    #[Locked]
    public bool $enabled = true;

    public string $name = '';

    public string $description = '';

    /** @var array<int, array<string, mixed>> */
    public array $conditions = [];

    /** @var array<int, string> */
    public array $roles = [];

    public string $roleSearch = '';

    public string $dropped = 'keep';

    /** When this rule last changed, so a save cannot overwrite someone else's edit. */
    #[Locked]
    public ?string $version = null;

    public bool $confirmingDelete = false;

    // Runs on every Livewire request, where the page controller's check does not.
    public function boot(): void
    {
        abort_unless(Auth::user()?->hasPermission('vatssa.discord.rules.manage'), 403);
    }

    public function mount(?int $ruleId, VatssaDiscordRules $engine, VatssaDiscordRuleStore $store): void
    {
        $this->ruleId = $ruleId;
        if ($ruleId === null) {
            $this->conditions = [$store->blankRow()];

            return;
        }
        $rule = $engine->all()->firstWhere('id', $ruleId) ?? abort(404);
        $this->name = $rule->name;
        $this->description = (string) $rule->description;
        $this->enabled = (bool) $rule->enabled;
        $this->roles = $rule->roles;
        $this->conditions = array_map(fn ($c) => $store->toRow($c), $rule->conditions);
        $this->version = (string) DB::table('vatssa_discord_rules')->where('id', $ruleId)->value('updated_at');
    }

    /**
     * `global` exists only for CC roles. Left behind, it would show as "any area" and fail on save.
     */
    public function updated(string $property, mixed $value): void
    {
        if (preg_match('/^conditions\.(\d+)\.type$/', $property, $match) && $value !== 'cc_role'
            && ($this->conditions[(int) $match[1]]['area'] ?? null) === 'global') {
            $this->conditions[(int) $match[1]]['area'] = 'any';
        }
    }

    public function addCondition(VatssaDiscordRuleStore $store): void
    {
        $this->conditions[] = $store->blankRow();
    }

    public function removeCondition(int $index): void
    {
        unset($this->conditions[$index]);
        $this->conditions = array_values($this->conditions);
    }

    private function form(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'enabled' => $this->enabled,
            'conditions' => $this->conditions, 'roles' => $this->roles];
    }

    public function save(VatssaDiscordRuleStore $store): void
    {
        $stored = $this->ruleId ? DB::table('vatssa_discord_rules')->where('id', $this->ruleId)->value('updated_at') : null;
        if ($stored !== null && (string) $stored !== $this->version) {
            throw ValidationException::withMessages(['name' => 'Someone else changed this rule. Reload the page before saving.']);
        }
        $data = $store->validate($this->form(), $this->ruleId);
        $store->save($this->ruleId, $data, $this->ruleId ? 'updated' : 'created', $this->dropped === 'remove');
        session()->flash('success', 'Rule saved. Affected members are queued.');

        $this->redirectRoute('vatssa.discord.rules.index');
    }

    public function delete(VatssaDiscordRuleStore $store): void
    {
        abort_unless($this->ruleId && DB::table('vatssa_discord_rules')->where('id', $this->ruleId)->exists(), 404);
        $store->save($this->ruleId, null, 'deleted', $this->dropped === 'remove');
        session()->flash('success', 'Rule deleted.');
        $this->redirectRoute('vatssa.discord.rules.index');
    }

    public function render(): View
    {
        $guildRoles = DB::table('vatssa_discord_guild_roles')->where('assignable', true)->orderByDesc('position')->orderBy('name')
            ->get(['discord_role_id', 'name'])->map(fn ($r) => ['id' => (string) $r->discord_role_id, 'name' => $r->name]);
        $known = $guildRoles->pluck('id')->all();
        $missing = collect(array_diff($this->roles, $known))->map(fn ($id) => ['id' => (string) $id, 'name' => $id . ' (not seen on the server)']);
        $term = mb_strtolower(trim($this->roleSearch));

        // A rule on an archived or deleted group must show that group, never the first in the list.
        $selected = collect($this->conditions)->where('type', 'group')->pluck('group')->filter()->map(fn ($id) => (int) $id)->unique();
        $groupRows = DB::table('vatssa_groups')->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $selected))->orderBy('name')->get(['id', 'name', 'archived_at']);
        $groups = $groupRows->map(fn ($g) => ['id' => (string) $g->id, 'label' => $g->name . ($g->archived_at ? ' (archived)' : '')])
            ->concat($selected->diff($groupRows->pluck('id')->map(fn ($id) => (int) $id))->map(fn ($id) => ['id' => (string) $id, 'label' => '(deleted group)']))->values();

        return view('livewire.vatssa-discord-rule-editor', [
            'droppedRoles' => app(VatssaDiscordRuleStore::class)->droppedRoles($this->ruleId, $this->confirmingDelete || ! $this->enabled ? [] : $this->roles),
            'roleOptions' => $guildRoles->concat($missing)->filter(fn ($r) => $term === '' || str_contains(mb_strtolower($r['name']), $term))->values(),
            'types' => VatssaDiscordRules::TYPES,
            'endorsements' => VatssaDiscordRules::ENDORSEMENTS,
            'ratings' => collect(VatsimRating::cases())->map(fn ($r) => ['value' => (string) $r->value, 'name' => $r->name])->all(),
            'ratingNames' => DB::table('ratings')->orderBy('name')->pluck('name')->all(),
            'ccRoles' => array_keys(config('roles.roles')),
            'areas' => DB::table('areas')->orderBy('name')->get(['id', 'name']),
            'groups' => $groups,
        ]);
    }
}
