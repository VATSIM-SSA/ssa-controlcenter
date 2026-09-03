<?php

namespace App\Livewire;

use App\Actions\GrantRole;
use App\Actions\RevokeRole;
use App\Models\Area;
use App\Models\Rating;
use App\Models\User;
use App\Models\Vatssa\RequestTarget;
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

    /*
    | VATSSA: request desks live in this component too.
    |
    | They were a separate "Request desks" card, read-only, with an Edit button
    | that sent you to a division-wide grid on another page. Two cards answering
    | one question -- what access does this person have -- and the half you
    | could act on was somewhere else.
    |
    | A role grants permissions; a desk decides who receives the work. They are
    | genuinely different things, which is why they stay two lists inside one
    | card rather than becoming one list.
    |
    | Gated on `system.settings.manage`, as the old card was: who receives which
    | requests is a leadership arrangement, and putting it in front of every
    | training manager invites quiet reassignment.
    */
    public bool $showDeskModal = false;

    /** "tier" or "tier:ratingId" -- the same key shape the desk tables use. */
    public ?string $selectedDesk = null;

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

        // VATSSA: global is the only target, so it is the state rather than a
        // choice. The picker no longer asks; if this were left false the Grant
        // button would build an empty target list and do nothing at all.
        $this->selectedGlobal = true;
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
    }

    public function updatedSelectedRole(): void
    {
        $this->selectedAreaIds = [];

        // Stays true. See openAddModal().
        $this->selectedGlobal = true;
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

    // ---------------------------------------------------------------- desks

    /** Whether the acting user may change this member's desks at all. */
    public function canManageDesks(): bool
    {
        return Gate::allows('system.settings.manage');
    }

    /**
     * The desks this member sits at, newest first, as rows to render.
     *
     * Reads the table directly rather than `RequestTarget::desksFor()`: that
     * helper adds the leadership desk for anybody holding
     * `system.settings.manage`, which is right when deciding who SEES a queue
     * and wrong here -- this list is what somebody was actually given, and a
     * row you cannot remove because it was never stored is a confusing thing to
     * show beside rows you can.
     *
     * @return Collection<int, RequestTarget>
     */
    public function heldDesks(): Collection
    {
        return RequestTarget::with('rating')
            ->where('user_id', $this->user->id)
            ->get();
    }

    /**
     * Every desk that could be added, minus the ones already held.
     *
     * Per-rating desks expand into one option per rating plus a catch-all,
     * because "the pipeline coordinator" is not a thing anybody can be -- the
     * S2 and C1 coordinators are different people. The catch-all is offered
     * FIRST and named, rather than left to be discovered by leaving a field
     * blank.
     *
     * @return array<string, string> key => label
     */
    public function deskOptions(): array
    {
        $held = $this->heldDesks()
            ->map(fn (RequestTarget $row) => RequestTarget::isPerRating($row->tier)
                ? $row->tier . ':' . $row->rating_id
                : $row->tier)
            ->all();

        $ratings = Rating::whereNotNull('vatsim_rating')->orderBy('vatsim_rating')->get();
        $options = [];

        foreach (RequestTarget::tiers(true) as $tier => $desk) {
            if (! $desk['per_rating']) {
                $options[$tier] = $desk['label'];

                continue;
            }

            $options[$tier . ':'] = $desk['label'] . ' — all ratings';

            foreach ($ratings as $rating) {
                $options[$tier . ':' . $rating->id] = $desk['label'] . ' — ' . $rating->name;
            }
        }

        return array_diff_key($options, array_flip($held));
    }

    public function openDeskModal(): void
    {
        abort_unless($this->canManageDesks(), 403);

        $this->selectedDesk = null;
        $this->showDeskModal = true;
    }

    public function closeDeskModal(): void
    {
        $this->showDeskModal = false;
        $this->selectedDesk = null;
    }

    public function addDesk(): void
    {
        // Re-checked here, not only in openDeskModal(). Livewire calls arrive
        // as their own requests, so a gate on the thing that opened a dialog is
        // not a gate on the thing the dialog does.
        abort_unless($this->canManageDesks(), 403);

        [$tier, $ratingId] = array_pad(explode(':', (string) $this->selectedDesk, 2), 2, null);

        if (! RequestTarget::isTier($tier)) {
            $this->error = 'That is not a desk.';

            return;
        }

        // A blank rating on a per-rating desk is the CATCH-ALL, not "no desk".
        $resolvedRating = RequestTarget::isPerRating($tier) && $ratingId !== null && $ratingId !== ''
            ? (int) $ratingId
            : null;

        // firstOrCreate, so a double submit is not an error and does not
        // duplicate a row the unique index would refuse anyway.
        RequestTarget::firstOrCreate([
            'tier' => $tier,
            'rating_id' => $resolvedRating,
            'user_id' => $this->user->id,
        ]);

        $this->closeDeskModal();
        $this->error = null;
        $this->status = 'Desk added.';
    }

    public function removeDesk(int $id): void
    {
        abort_unless($this->canManageDesks(), 403);

        // Scoped to THIS user's rows. The id comes from the payload like every
        // other Livewire argument, and without the scope it would remove any
        // desk assignment in the division.
        RequestTarget::where('user_id', $this->user->id)->whereKey($id)->delete();

        $this->error = null;
        $this->status = 'Desk removed.';
    }

    public function render(): View
    {
        $this->user->load('roleAssignments.area');

        return view('livewire.user-roles', [
            'roles' => config('roles.roles'),
            'globalAssignments' => $this->user->roleAssignments->whereNull('area_id'),
            'areaGroups' => $this->user->roleAssignments->whereNotNull('area_id')
                ->groupBy(fn ($a) => $a->area->name),
            'desks' => $this->canManageDesks() ? $this->heldDesks() : collect(),
        ]);
    }
}
