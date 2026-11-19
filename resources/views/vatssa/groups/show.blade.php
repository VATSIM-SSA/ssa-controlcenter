@extends('layouts.app')

@section('title', $group->name)

@section('content')
@php($archived = $group->isArchived())
@php($locked = 'Restore the group to change it')
@php($otherStaff = $staffManagers->reject(fn ($staff) => $managers->contains('user_id', $staff['user']->id)))

{{-- Header: the group, and every action as a button --}}
<div class="card shadow mb-3">
    <div class="card-header bg-primary py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="m-0 fw-bold text-white"><i class="fas fa-people-group"></i>&nbsp;{{ $group->name }} @if($archived)<span class="badge bg-light text-dark ms-1">Archived</span>@endif</h6>
        <div class="d-flex flex-wrap gap-2">
            @if($canMembers || ($isStaff && $archived))
                <button type="button" class="btn btn-icon btn-light" data-bs-toggle="modal" data-bs-target="#addMembers" @disabled(! $canMembers) title="{{ $canMembers ? '' : $locked }}"><i class="fas fa-user-plus"></i> Add/remove members</button>
            @endif
            @if($canSettings || ($isStaff && $archived))
                <button type="button" class="btn btn-icon btn-light" data-bs-toggle="modal" data-bs-target="#addManager" @disabled(! $canSettings) title="{{ $canSettings ? '' : $locked }}"><i class="fas fa-user-shield"></i> Add manager</button>
                <button type="button" class="btn btn-icon btn-light" data-bs-toggle="modal" data-bs-target="#settings" @disabled(! $canSettings) title="{{ $canSettings ? '' : $locked }}"><i class="fas fa-gear"></i> Settings</button>
            @endif
            @if($isStaff && ! $archived)
                <form method="post" action="{{ route('vatssa.groups.archive', $group) }}" onsubmit="return confirm('Archive {{ $group->name }}? It stops giving Discord roles and becomes read-only. You can restore it.')">@csrf
                    <button class="btn btn-icon btn-light"><i class="fas fa-box-archive"></i> Archive group</button></form>
            @endif
            @if($isStaff && $archived)
                <form method="post" action="{{ route('vatssa.groups.archive', $group) }}">@csrf<input type="hidden" name="restore" value="1">
                    <button class="btn btn-icon btn-light"><i class="fas fa-rotate-left"></i> Restore group</button></form>
                <button type="button" class="btn btn-icon btn-danger" data-bs-toggle="modal" data-bs-target="#deleteGroup"><i class="fas fa-trash"></i> Delete group…</button>
            @endif
            <a href="{{ route('vatssa.groups.index') }}" class="btn btn-icon btn-light">{{ $isStaff ? 'All groups' : 'My groups' }}</a>
        </div>
    </div>
    <div class="card-body py-2 small text-muted">
        @if($group->description){{ $group->description }} · @endif{{ $members->count() }} {{ Str::plural('member', $members->count()) }} · {{ $managers->count() + $otherStaff->count() }} {{ Str::plural('manager', $managers->count() + $otherStaff->count()) }}
    </div>
    @if($archived)
        <div class="card-footer text-muted small">
            Archived {{ $group->archived_at->toEuropeanDate() }}. Read-only: it gives no Discord roles, its managers cannot see it, and nothing can be changed until it is restored.
        </div>
    @endif
</div>
@foreach(['cids', 'user_id', 'name', 'confirm_name'] as $field)
    @error($field)<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
@endforeach

<div class="row">
    {{-- Members --}}
    <div class="col-xl-8 col-lg-12">
        <div class="card shadow mb-3">
            <div class="card-header bg-primary py-3"><h6 class="m-0 fw-bold text-white"><i class="fas fa-users"></i>&nbsp;Members</h6></div>
            @if($members->isEmpty())
                <div class="card-body"><p class="mb-0 text-muted">No members yet.@if($canMembers) Press <strong>Add/remove members</strong>.@endif</p></div>
            @else
                <form method="post" action="{{ route('vatssa.groups.members.remove', $group) }}">@csrf
                    <table class="table table-sm table-hover table-leftpadded mb-0" width="100%" cellspacing="0">
                        <thead class="table-light"><tr>
                            @if($canMembers)<th style="width:2rem"><input type="checkbox" class="form-check-input" id="selectAll" aria-label="Select all members"></th>@endif
                            <th>Member</th><th>CID</th><th>Added</th>
                            <th class="text-end">
                                @if($canMembers)
                                    <button class="btn btn-danger btn-sm py-0 d-none" id="removeSelectedButton" onclick="return confirm('Remove the selected members from {{ $group->name }}?')">Remove selected</button>
                                @endif
                            </th>
                        </tr></thead>
                        <tbody>
                        @foreach($members as $member)
                            <tr>
                                @if($canMembers)<td><input type="checkbox" name="members[]" value="{{ $member->user_id }}" class="form-check-input member-check" aria-label="Select {{ $member->user_id }}"></td>@endif
                                <td>
                                    @if($member->user)
                                        @can('view', $member->user)<a href="{{ route('user.show', $member->user_id) }}">{{ $member->user->name }}</a>@else{{ $member->user->name }}@endcan
                                    @else
                                        <span class="text-muted">No CC account yet</span>
                                    @endif
                                </td>
                                <td>{{ $member->user_id }}</td>
                                <td class="text-muted" colspan="2">{{ $member->created_at->toEuropeanDate() }}@if($member->added_by), {{ $names[$member->added_by] ?? $member->added_by }}@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </form>
            @endif
        </div>

        <details class="card shadow mb-3">
            <summary class="card-header py-2 small fw-bold">History</summary>
            <ul class="list-group list-group-flush">
                @forelse($events as $event)
                    <li class="list-group-item small d-flex justify-content-between gap-3 py-1">
                        <span>
                            {{ ucfirst(str_replace('_', ' ', $event->event)) }}@if($event->user_id): {{ $names[$event->user_id] ?? $event->user_id }}@endif
                            @if($event->detail)<span class="text-muted">({{ $event->detail }})</span>@endif
                        </span>
                        <span class="text-muted text-nowrap">{{ $event->by ? ($names[$event->by] ?? $event->by) . ', ' : '' }}{{ $event->created_at->toEuropeanDateTime() }}</span>
                    </li>
                @empty
                    <li class="list-group-item text-muted small">Nothing yet</li>
                @endforelse
            </ul>
        </details>
    </div>

    {{-- Managers: a short list --}}
    <div class="col-xl-4 col-lg-12">
        <div class="card shadow mb-3">
            <div class="card-header bg-primary py-3"><h6 class="m-0 fw-bold text-white"><i class="fas fa-user-shield"></i>&nbsp;Managers</h6></div>
            <ul class="list-group list-group-flush">
                @foreach($managers as $manager)
                    <li class="list-group-item py-2 d-flex justify-content-between align-items-center gap-2">
                        <span class="small">{{ $manager->user?->name }} <span class="text-muted">{{ $manager->user_id }}</span></span>
                        <span class="d-flex align-items-center gap-1">
                            @if($isStaff && ! $archived)
                                <form method="post" action="{{ route('vatssa.groups.managers.add', $group) }}">@csrf
                                    <input type="hidden" name="user_id" value="{{ $manager->user_id }}">
                                    <select name="level" class="form-select form-select-sm py-0" onchange="this.form.submit()" aria-label="What {{ $manager->user?->name }} can manage">
                                        <option value="members" @selected(! $manager->can_manage_settings)>Members only</option>
                                        <option value="settings" @selected($manager->can_manage_settings)>Members and settings</option>
                                    </select>
                                </form>
                            @else
                                <span class="badge bg-light text-dark border">{{ $manager->can_manage_settings ? 'Members and settings' : 'Members only' }}</span>
                            @endif
                            @if($canSettings && ($isStaff || ! $manager->can_manage_settings))
                                <form method="post" action="{{ route('vatssa.groups.managers.remove', [$group, $manager]) }}" onsubmit="return confirm('Remove {{ $manager->user?->name }} as a manager?')">@csrf
                                    <button class="btn btn-link btn-sm text-muted hover-red p-0" title="Remove manager" aria-label="Remove manager"><i class="fas fa-xmark"></i></button></form>
                            @endif
                        </span>
                    </li>
                @endforeach
                @foreach($otherStaff as $staff)
                    <li class="list-group-item py-2 d-flex justify-content-between align-items-center gap-2">
                        <span class="small">{{ $staff['user']->name }} <span class="text-muted">{{ $staff['user']->id }}</span></span>
                        <span class="badge bg-light text-dark border" title="Manages every group through the Manage groups permission">Manage groups permission ({{ $staff['roles'] }})</span>
                    </li>
                @endforeach
                @if($managers->isEmpty())
                    <li class="list-group-item py-2 small text-muted">No group managers yet.</li>
                @endif
            </ul>
            <div class="card-footer small text-muted">
                <strong>Members only</strong> can add and remove members, nothing else. <strong>Members and settings</strong> can also rename the group, change its description, and add or remove Members only managers. Only staff can archive or delete a group.
            </div>
        </div>
    </div>
</div>

{{-- Pop-ups --}}
@if($canMembers)
<div class="modal fade" id="addMembers" tabindex="-1" aria-labelledby="addMembersLabel" aria-hidden="true" data-open="{{ $errors->has('cids') ? '1' : '' }}">
    <div class="modal-dialog">
        <form method="post" action="{{ route('vatssa.groups.members.add', $group) }}" class="modal-content">@csrf
            <div class="modal-header"><h5 class="modal-title" id="addMembersLabel">Add or remove members of {{ $group->name }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label for="cids" class="form-label fw-bold">VATSIM CIDs</label>
                <textarea id="cids" name="cids" class="form-control mb-1" rows="4" placeholder="1234567, 7654321"></textarea>
                <div class="form-text">Separate CIDs with commas. A CID is a number from 800000 to 3000000. People who have never signed in to CC can be added too.</div>
            </div>
            <div class="modal-footer justify-content-between">
                <button class="btn btn-outline-danger" formaction="{{ route('vatssa.groups.members.remove', $group) }}" onclick="return confirm('Remove these CIDs from {{ $group->name }}?')">Remove these CIDs instead</button>
                <button class="btn btn-success">Add to group</button>
            </div>
        </form>
    </div>
</div>
@endif

@if($canSettings)
<div class="modal fade" id="addManager" tabindex="-1" aria-labelledby="addManagerLabel" aria-hidden="true" data-open="{{ $errors->has('user_id') ? '1' : '' }}">
    <div class="modal-dialog">
        <form method="post" action="{{ route('vatssa.groups.managers.add', $group) }}" class="modal-content">@csrf
            <div class="modal-header"><h5 class="modal-title" id="addManagerLabel">Add a manager</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label for="managerCid" class="form-label fw-bold">VATSIM CID</label>
                <input id="managerCid" name="user_id" class="form-control mb-3" inputmode="numeric" placeholder="1234567" required>
                @if($isStaff)
                    <div class="form-label fw-bold">They can manage</div>
                    <div class="form-check mb-1"><input class="form-check-input" type="radio" name="level" value="members" id="levelMembers" checked>
                        <label class="form-check-label" for="levelMembers">Members only <span class="text-muted d-block small">Can add and remove members. Nothing else.</span></label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="level" value="settings" id="levelSettings">
                        <label class="form-check-label" for="levelSettings">Members and settings <span class="text-muted d-block small">Can add and remove members, rename the group, change its description, and add or remove Members only managers. Cannot archive or delete the group.</span></label></div>
                @else
                    <input type="hidden" name="level" value="members">
                    <p class="text-muted small mb-0">They will be a Members only manager: they can add and remove members, nothing else.</p>
                @endif
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success">Add manager</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="settings" tabindex="-1" aria-labelledby="settingsLabel" aria-hidden="true" data-open="{{ $errors->has('name') ? '1' : '' }}">
    <div class="modal-dialog">
        <form method="post" action="{{ route('vatssa.groups.update', $group) }}" class="modal-content" id="groupSettings">@csrf @method('PATCH')
            <div class="modal-header"><h5 class="modal-title" id="settingsLabel">Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label for="name" class="form-label fw-bold">Name</label>
                <input id="name" name="name" class="form-control mb-3" value="{{ $group->name }}" required maxlength="80">
                <label for="description" class="form-label fw-bold">Description</label>
                <input id="description" name="description" class="form-control" value="{{ $group->description }}" maxlength="255">
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success">Save</button></div>
        </form>
    </div>
</div>
@endif

@if($isStaff && $archived)
<div class="modal fade" id="deleteGroup" tabindex="-1" aria-labelledby="deleteGroupLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="{{ route('vatssa.groups.destroy', $group) }}" class="modal-content">@csrf @method('DELETE')
            <div class="modal-header"><h5 class="modal-title" id="deleteGroupLabel">Delete {{ $group->name }}?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p>This deletes the group, its members, managers and history for good. It cannot be undone.</p>
                <label for="confirmName" class="form-label">Type <strong>{{ $group->name }}</strong> to confirm</label>
                <input id="confirmName" name="confirm_name" class="form-control" autocomplete="off" data-expected="{{ $group->name }}" required>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger" id="confirmDelete" disabled>Delete for good</button></div>
        </form>
    </div>
</div>
@endif
@endsection

@section('js')
<script>
(function () {
    const checks = document.querySelectorAll('.member-check');
    const button = document.getElementById('removeSelectedButton');
    const all = document.getElementById('selectAll');
    const update = () => {
        const n = [...checks].filter(c => c.checked).length;
        if (button) {
            button.classList.toggle('d-none', n === 0);
            button.textContent = 'Remove ' + n + ' selected';
        }
        if (all) { all.checked = n > 0 && n === checks.length; all.indeterminate = n > 0 && n < checks.length; }
    };
    checks.forEach(c => c.addEventListener('change', update));
    if (all) { all.addEventListener('change', () => { checks.forEach(c => { c.checked = all.checked; }); update(); }); }

    const confirmName = document.getElementById('confirmName');
    if (confirmName) {
        confirmName.addEventListener('input', () => {
            document.getElementById('confirmDelete').disabled = confirmName.value !== confirmName.dataset.expected;
        });
    }

    // Reopen the pop-up whose form came back with an error.
    document.querySelectorAll('.modal[data-open="1"]').forEach(m => bootstrap.Modal.getOrCreateInstance(m).show());
})();
</script>
@endsection
