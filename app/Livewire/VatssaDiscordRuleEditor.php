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

// VATSSA: create or edit one Discord rule, with a live preview of what it changes.
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

    public function save(VatssaDiscordRuleStore $store)
    {
        $data = $store->validate($this->form(), $this->ruleId);
        $store->save($this->ruleId, $data, $this->ruleId ? 'updated' : 'created', $this->dropped === 'remove');
        session()->flash('success', 'Rule saved. Affected members are queued.');

        return $this->redirectRoute('vatssa.discord.rules.index');
    }

    public function delete(VatssaDiscordRuleStore $store)
    {
        abort_unless($this->ruleId && DB::table('vatssa_discord_rules')->where('id', $this->ruleId)->exists(), 404);
        $store->save($this->ruleId, null, 'deleted', $this->dropped === 'remove');
        session()->flash('success', 'Rule deleted.');

        return $this->redirectRoute('vatssa.discord.rules.index');
    }

    private function effect(VatssaDiscordRuleStore $store): array
    {
        if ($this->confirmingDelete) {
            return $store->preview($this->ruleId, null);
        }
        if (trim($this->name) === '' || ! $this->roles) {
            return ['hint' => 'Add a name and pick a role to see the effect.'];
        }
        try {
            return $store->preview($this->ruleId, $store->validate($this->form(), $this->ruleId));
        } catch (ValidationException $e) {
            return ['hint' => collect($e->errors())->flatten()->first()];
        }
    }

    public function render(VatssaDiscordRuleStore $store): View
    {
        $guildRoles = DB::table('vatssa_discord_guild_roles')->where('assignable', true)->orderByDesc('position')->orderBy('name')
            ->get(['discord_role_id', 'name'])->map(fn ($r) => ['id' => (string) $r->discord_role_id, 'name' => $r->name]);
        $known = $guildRoles->pluck('id')->all();
        $missing = collect(array_diff($this->roles, $known))->map(fn ($id) => ['id' => (string) $id, 'name' => $id . ' (not seen on the server)']);
        $term = mb_strtolower(trim($this->roleSearch));

        return view('livewire.vatssa-discord-rule-editor', [
            'effect' => $this->effect($store),
            'roleOptions' => $guildRoles->concat($missing)->filter(fn ($r) => $term === '' || str_contains(mb_strtolower($r['name']), $term))->values(),
            'types' => VatssaDiscordRules::TYPES,
            'endorsements' => VatssaDiscordRules::ENDORSEMENTS,
            'ratings' => collect(VatsimRating::cases())->map(fn ($r) => ['value' => (string) $r->value, 'name' => $r->name])->all(),
            'ratingNames' => DB::table('ratings')->orderBy('name')->pluck('name')->all(),
            'ccRoles' => array_keys(config('roles.roles')),
            'areas' => DB::table('areas')->orderBy('name')->get(['id', 'name']),
            'groups' => DB::table('vatssa_groups')->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
