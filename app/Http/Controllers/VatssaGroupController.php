<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Models\User;
use App\Models\VatssaGroup;
use App\Models\VatssaGroupManager;
use App\Models\VatssaGroupMember;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * VATSSA: groups at /groups. Named lists of people by VATSIM ID (hit squad, events,
 * Discord roles such as Streamer). Ported from the EuroScope plugin package, group
 * part only. Every membership change queues that member for the Discord role sync.
 * Rights: see VatssaGroup.
 */
class VatssaGroupController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless(VatssaGroup::isStaff($user) || VatssaGroup::anyManagedBy($user), 403);

        return view('vatssa.groups.index', [
            'groups' => VatssaGroup::visibleTo($user)->whereNull('archived_at')->withCount('currentMembers')
                ->with('currentManagers.user')->orderBy('name')->get(),
            'archived' => VatssaGroup::isStaff($user)
                ? VatssaGroup::whereNotNull('archived_at')->withCount('currentMembers')->latest('archived_at')->get()
                : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(VatssaGroup::isStaff($request->user()), 403);
        $data = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:255']);

        $group = VatssaGroup::create($data + ['created_by' => $request->user()->id]);
        $group->record('created', null, $group->name, $request->user()->id);
        ActivityLogService::info(LogName::Other, 'Group created: ' . $group->name);

        return redirect()->route('vatssa.groups.show', $group)->with('success', 'Group created.');
    }

    public function show(Request $request, VatssaGroup $group): View
    {
        abort_unless($group->membersEditableBy($request->user()), 403);

        return view('vatssa.groups.show', [
            'group' => $group,
            'members' => $group->members()->with('user')->orderByRaw('removed_at is not null')->latest()->get(),
            'managers' => $group->currentManagers()->with('user')->get(),
            'events' => $group->events()->latest('id')->limit(200)->get(),
            'names' => $this->names($group),
            'canSettings' => $group->settingsEditableBy($request->user()),
            'isStaff' => VatssaGroup::isStaff($request->user()),
        ]);
    }

    public function update(Request $request, VatssaGroup $group): RedirectResponse
    {
        abort_unless($group->settingsEditableBy($request->user()), 403);
        $data = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:255']);

        if ($data['name'] !== $group->name) {
            $group->record('renamed', null, $group->name . ' → ' . $data['name'], $request->user()->id);
        }
        if (($data['description'] ?? null) !== $group->description) {
            $group->record('described', null, $data['description'] ?? null, $request->user()->id);
        }
        $group->update($data);

        return back()->with('success', 'Group saved.');
    }

    /**
     * Add one VATSIM ID or a pasted list, any separators. People who have never signed in
     * to CC can be added; their membership counts the moment they exist.
     */
    public function addMembers(Request $request, VatssaGroup $group): RedirectResponse
    {
        abort_unless($group->membersEditableBy($request->user()), 403);
        $cids = $this->parseCids($request->validate(['cids' => 'required|string|max:20000'])['cids']);
        if ($cids->isEmpty()) {
            return back()->withErrors(['cids' => 'No VATSIM IDs found.']);
        }

        $already = $group->currentMembers()->whereIn('user_id', $cids)->pluck('user_id')->map(fn ($cid) => (int) $cid);
        $new = $cids->diff($already)->values();
        foreach ($new as $cid) {
            VatssaGroupMember::create(['group_id' => $group->id, 'user_id' => $cid, 'added_by' => $request->user()->id]);
            $group->record('member_added', $cid, null, $request->user()->id);
            VatssaDiscordSyncController::queueAuto($cid);
        }
        $noAccount = $new->diff(User::whereIn('id', $new)->pluck('id'));

        return back()->with('success', $new->count() . ' added' . ($already->isNotEmpty() ? ', ' . $already->count() . ' already in the group' : '') . '.'
            . ($noAccount->isNotEmpty() ? ' Not signed in to CC yet: ' . $noAccount->implode(', ') . '. They are in the group already.' : ''));
    }

    /**
     * Remove ticked members (`members[]`) and/or a pasted list of VATSIM IDs (`cids`).
     */
    public function removeMembers(Request $request, VatssaGroup $group): RedirectResponse
    {
        abort_unless($group->membersEditableBy($request->user()), 403);
        $data = $request->validate(['members' => 'sometimes|array', 'members.*' => 'integer', 'cids' => 'nullable|string|max:20000']);

        $cids = collect($data['members'] ?? [])->map(fn ($cid) => (int) $cid)
            ->merge($this->parseCids($data['cids'] ?? ''))->unique()->values();
        $rows = $group->currentMembers()->whereIn('user_id', $cids)->get();
        foreach ($rows as $row) {
            $row->forceFill(['removed_at' => now(), 'removed_by' => $request->user()->id])->save();
            $group->record('member_removed', (int) $row->user_id, null, $request->user()->id);
            VatssaDiscordSyncController::queueAuto((int) $row->user_id);
        }

        return back()->with('success', $rows->count() . ' removed.');
    }

    /**
     * Staff appoint either level; a settings manager appoints member managers only.
     */
    public function addManager(Request $request, VatssaGroup $group): RedirectResponse
    {
        abort_unless($group->settingsEditableBy($request->user()), 403);
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id', 'can_manage_settings' => 'sometimes|boolean']);
        $settings = (bool) ($data['can_manage_settings'] ?? false);
        abort_if($settings && ! VatssaGroup::isStaff($request->user()), 403);

        $current = $group->currentManagers()->where('user_id', $data['user_id'])->first();
        if ($current) {
            $current->update(['can_manage_settings' => $settings]);
        } else {
            VatssaGroupManager::create(['group_id' => $group->id, 'user_id' => $data['user_id'], 'can_manage_settings' => $settings, 'added_by' => $request->user()->id]);
        }
        $group->record('manager_added', (int) $data['user_id'], $settings ? 'members and settings' : 'members', $request->user()->id);

        return back()->with('success', 'Manager saved.');
    }

    public function removeManager(Request $request, VatssaGroup $group, VatssaGroupManager $manager): RedirectResponse
    {
        abort_unless((int) $manager->group_id === (int) $group->id, 404);
        abort_unless($group->settingsEditableBy($request->user()), 403);
        abort_if($manager->can_manage_settings && ! VatssaGroup::isStaff($request->user()), 403);

        if (! $manager->removed_at) {
            $manager->forceFill(['removed_at' => now(), 'removed_by' => $request->user()->id])->save();
            $group->record('manager_removed', (int) $manager->user_id, null, $request->user()->id);
        }

        return back()->with('success', 'Manager removed.');
    }

    /**
     * Archive or restore. An archived group gives no Discord roles and its managers lose
     * access; members and managers are kept, so restoring brings everything back.
     */
    public function archive(Request $request, VatssaGroup $group): RedirectResponse
    {
        abort_unless(VatssaGroup::isStaff($request->user()), 403);
        $restore = $request->boolean('restore');

        if ($restore === $group->isArchived()) {
            $group->forceFill(['archived_at' => $restore ? null : now(), 'archived_by' => $restore ? null : $request->user()->id])->save();
            $group->record($restore ? 'restored' : 'archived', null, null, $request->user()->id);
            $this->queueMembers($group);
        }

        return $restore
            ? redirect()->route('vatssa.groups.show', $group)->with('success', 'Group restored.')
            : redirect()->route('vatssa.groups.index')->with('success', $group->name . ' archived.');
    }

    /**
     * Delete for good: only an archived group, and the name must be typed to confirm.
     */
    public function destroy(Request $request, VatssaGroup $group): RedirectResponse
    {
        abort_unless(VatssaGroup::isStaff($request->user()), 403);
        abort_unless($group->isArchived(), 409, 'Archive the group first.');
        $request->validate(['confirm_name' => 'required|in:' . $group->name], ['confirm_name.in' => 'Type the group name exactly to delete it.']);

        $this->queueMembers($group);
        ActivityLogService::warning(LogName::Other, 'Group deleted: ' . $group->name);
        $group->delete();

        return redirect()->route('vatssa.groups.index')->with('success', 'Group deleted.');
    }

    /**
     * API: active groups and their current members' VATSIM IDs.
     */
    public function apiIndex(): JsonResponse
    {
        $groups = VatssaGroup::whereNull('archived_at')->with('currentMembers:id,group_id,user_id')->orderBy('name')->get()
            ->map(fn (VatssaGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'members' => $group->currentMembers->pluck('user_id')->map(fn ($cid) => (int) $cid)->values(),
            ]);

        return response()->json(['data' => $groups]);
    }

    /**
     * API: one member's active groups. Works for a VATSIM ID with no CC account.
     */
    public function apiMember(int $cid): JsonResponse
    {
        $groups = VatssaGroup::whereNull('archived_at')
            ->whereIn('id', VatssaGroupMember::where('user_id', $cid)->whereNull('removed_at')->select('group_id'))
            ->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => ['cid' => $cid, 'groups' => $groups]]);
    }

    /**
     * @return Collection<int, int>
     */
    private function parseCids(string $text): Collection
    {
        preg_match_all('/\b\d{1,8}\b/', $text, $matches);

        return collect($matches[0])->map(fn ($cid) => (int) $cid)->filter()->unique()->values();
    }

    private function queueMembers(VatssaGroup $group): void
    {
        foreach ($group->currentMembers()->pluck('user_id') as $cid) {
            VatssaDiscordSyncController::queueAuto((int) $cid);
        }
    }

    /**
     * Names for the VATSIM IDs in the history (members, managers and who acted).
     *
     * @return Collection<int, string>
     */
    private function names(VatssaGroup $group): Collection
    {
        $ids = $group->events()->pluck('user_id')->merge($group->events()->pluck('by'))->filter()->unique();

        return User::whereIn('id', $ids)->get()->mapWithKeys(fn (User $user) => [$user->id => $user->name]);
    }
}
