<?php

namespace App\Livewire;

use App\Actions\GrantRole;
use App\Actions\RevokeRole;
use App\Models\Area;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class UserRoles extends Component
{
    use AuthorizesRequests;

    /*
    | VATSSA: #[Locked].
    |
    | mount() authorises `viewAccess` on this user -- once, on the request that
    | built the component. Livewire rehydrates public properties from the
    | payload on every call after that, so swapping the id rendered somebody
    | else's roles to anybody who could legitimately open this for one member.
    |
    | Granting was never exposed: GrantRole re-authorises `updateRole` against
    | whichever user it is handed. The disclosure was the hole, and it is the
    | quieter half of this class of bug -- nothing fails, a page just answers a
    | question it was not asked.
    */
    #[\Livewire\Attributes\Locked]
    public User $user;

    public bool $showAddModal = false;

    public ?string $selectedRole = null;

    /** @var array<int, int> */
    public array $selectedAreaIds = [];

    public bool $selectedGlobal = false;

    /** @var array{role: string, area_id: ?int}|null */
    public ?array $pendingRemoval = null;

    public ?string $status = null;

    public ?string $error = null;

    private ?Collection $areasCache = null;

    public function mount(User $user): void
    {
        $this->authorize('viewAccess', $user);
        $this->user = $user;
    }

    /**
     * All areas, memoized for the duration of the request to avoid N+1 lookups.
     */
    protected function allAreas(): Collection
    {
        return $this->areasCache ??= Area::orderBy('name')->get();
    }

    /**
     * Whether the acting user may grant/revoke $role in $areaId (null = global).
     */
    public function canManage(string $role, ?int $areaId): bool
    {
        $area = $areaId === null ? null : $this->allAreas()->firstWhere('id', $areaId);

        return Gate::inspect('updateRole', [$this->user, $role, $area])->allowed();
    }

    public function openAddModal(): void
    {
        $this->reset(['selectedRole', 'selectedAreaIds', 'selectedGlobal']);
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
    }

    public function updatedSelectedRole(): void
    {
        $this->selectedAreaIds = [];
        $this->selectedGlobal = false;
    }

    /**
     * Roles the acting user may grant to this user in at least one still-available
     * scope/area. Keyed by role => display name. Excludes admin.
     *
     * @return array<string, string>
     */
    public function grantableRoles(): array
    {
        $result = [];

        foreach (config('roles.roles') as $role => $def) {
            // VATSSA: the flag, not a hardcoded name. This used to test for
            // 'admin' by string, so every later ungrantable role would have had
            // to remember to add itself here AND to UserPolicy -- two lists to
            // keep in step, which is how a role ends up offered in a dropdown
            // and refused on submit.
            if (($def['grantable'] ?? true) === false) {
                continue;
            }

            // VATSSA: global only. The area half of this used to decide
            // whether a role appeared at all, so a role that was area-only
            // showed up and then could not be granted -- UserPolicy now
            // refuses every area-scoped grant.
            if ($this->globalOptionFor($role)['enabled']) {
                $result[$role] = $def['name'];
            }
        }

        return $result;
    }

    /**
     * Whether $role may be granted globally to this user, and why not if it can't.
     *
     * @return array{applicable: bool, enabled: bool, reason: ?string}
     */
    public function globalOptionFor(string $role): array
    {
        $scope = config("roles.roles.{$role}.scope");

        if (! in_array($scope, ['global', 'both'], true)) {
            return ['applicable' => false, 'enabled' => false, 'reason' => 'Not available for this role'];
        }

        if ($this->user->roleAssignments->where('role', $role)->whereNull('area_id')->isNotEmpty()) {
            return ['applicable' => true, 'enabled' => false, 'reason' => 'Already assigned'];
        }

        if (! $this->canManage($role, null)) {
            return ['applicable' => true, 'enabled' => false, 'reason' => "You can't grant this globally"];
        }

        return ['applicable' => true, 'enabled' => true, 'reason' => null];
    }

    /**
     * Per-area grant options for $role: one entry per area, each describing
     * whether the actor may grant $role there and why not if it can't.
     *
     * @return array<int, array{area: Area, enabled: bool, reason: ?string}>
     */
    public function areaOptionsFor(string $role): array
    {
        $scope = config("roles.roles.{$role}.scope");

        $held = $this->user->roleAssignments->where('role', $role)
            ->whereNotNull('area_id')->pluck('area_id')->all();

        return $this->allAreas()->map(function (Area $area) use ($role, $scope, $held) {
            if (! in_array($scope, ['area', 'both'], true)) {
                return ['area' => $area, 'enabled' => false, 'reason' => 'Not available for this role'];
            }

            if (in_array($area->id, $held, true)) {
                return ['area' => $area, 'enabled' => false, 'reason' => 'Already assigned'];
            }

            if (! $this->canManage($role, $area->id)) {
                return ['area' => $area, 'enabled' => false, 'reason' => "You can't grant this here"];
            }

            return ['area' => $area, 'enabled' => true, 'reason' => null];
        })->values()->all();
    }

    public function grant(GrantRole $grantRole): void
    {
        if ($this->selectedRole === null) {
            return;
        }

        $role = $this->selectedRole;
        $actor = auth()->user();

        // Build the list of targets from the explicit selections.
        $targets = [];
        if ($this->selectedGlobal) {
            $targets[] = null;
        }
        // whereIn silently drops unknown/stale IDs so a bad selection can never
        // resolve to null and be mistaken for a global grant.
        foreach ($this->allAreas()->whereIn('id', $this->selectedAreaIds) as $area) {
            $targets[] = $area;
        }

        if ($targets === []) {
            $this->error = 'Select at least one option to grant.';
            $this->status = null;

            return;
        }

        if ($role === 'mentor') {
            // Per-area best-effort: each area is an independent external API call, so
            // no cross-area transaction — collect per-target errors and keep the rest.
            $errors = [];
            foreach ($targets as $area) {
                $errors[] = $grantRole($actor, $this->user, $role, $area);
            }
            $errors = array_filter($errors);
        } else {
            // Non-mentor grants have no external dependency → all-or-nothing.
            $errors = [];
            try {
                DB::transaction(function () use ($grantRole, $actor, $role, $targets) {
                    foreach ($targets as $area) {
                        $grantRole($actor, $this->user, $role, $area);
                    }
                });
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $this->error = $errors === [] ? null : implode(' ', $errors);
        $this->status = $errors === [] ? 'Role granted.' : null;
        $this->showAddModal = false;
    }

    public function confirmRemoval(string $role, ?int $areaId): void
    {
        $this->pendingRemoval = ['role' => $role, 'area_id' => $areaId];
    }

    public function cancelRemoval(): void
    {
        $this->pendingRemoval = null;
    }

    /**
     * Number of trainings the user teaches in the pending removal's area
     * (used to spell out the mentor-removal consequence).
     */
    public function removalTrainingCount(): int
    {
        if ($this->pendingRemoval === null || $this->pendingRemoval['area_id'] === null) {
            return 0;
        }

        return $this->user->teaches()->where('area_id', $this->pendingRemoval['area_id'])->count();
    }

    /**
     * Whether the pending removal will actually cause RevokeRole to detach the
     * user's trainings in the area: only true for a mentor area assignment
     * when the user holds no other mentor assignment anywhere.
     */
    public function removalWillDetach(): bool
    {
        if ($this->pendingRemoval === null
            || $this->pendingRemoval['role'] !== 'mentor'
            || $this->pendingRemoval['area_id'] === null) {
            return false;
        }

        return ! $this->user->roleAssignments()
            ->where('role', 'mentor')
            ->where('area_id', '!=', $this->pendingRemoval['area_id'])
            ->exists();
    }

    /**
     * Name of the area for the pending removal, or null for a global removal.
     */
    public function pendingRemovalAreaName(): ?string
    {
        if ($this->pendingRemoval === null || $this->pendingRemoval['area_id'] === null) {
            return null;
        }

        return $this->allAreas()->firstWhere('id', $this->pendingRemoval['area_id'])?->name;
    }

    public function remove(RevokeRole $revokeRole): void
    {
        if ($this->pendingRemoval === null) {
            return;
        }

        $role = $this->pendingRemoval['role'];
        $areaId = $this->pendingRemoval['area_id'];
        $area = $areaId === null ? null : $this->allAreas()->firstWhere('id', $areaId);

        $error = $revokeRole(auth()->user(), $this->user, $role, $area);

        $this->pendingRemoval = null;
        $this->error = $error;
        $this->status = $error === null ? 'Role revoked.' : null;
    }

    public function render(): View
    {
        $this->user->load('roleAssignments.area');

        return view('livewire.user-roles', [
            'roles' => config('roles.roles'),
            'globalAssignments' => $this->user->roleAssignments->whereNull('area_id'),
            'areaGroups' => $this->user->roleAssignments->whereNotNull('area_id')
                ->groupBy(fn ($a) => $a->area->name),
        ]);
    }
}
