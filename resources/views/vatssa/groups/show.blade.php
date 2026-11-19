@extends('layouts.app')

@section('title', $group->name)

@section('content')
@php($archived = $group->isArchived())

{{-- Header: what the group is, and what can be done to it as a whole --}}
<div class="card shadow mb-4">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <h4 class="mb-1">{{ $group->name }} @if($archived)<span class="badge bg-secondary align-middle">Archived</span>@endif</h4>
            @if($group->description)<p class="text-muted mb-1">{{ $group->description }}</p>@endif
            <span class="text-muted small">{{ $members->count() }} {{ Str::plural('member', $members->count()) }} · {{ $managers->count() }} {{ Str::plural('manager', $managers->count()) }}</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('vatssa.groups.index') }}" class="btn btn-outline-secondary btn-sm">{{ $isStaff ? 'All groups' : 'My groups' }}</a>
            @if($isStaff && ! $archived)
                <form method="post" action="{{ route('vatssa.groups.archive', $group) }}" onsubmit="return confirm('Archive {{ $group->name }}? It stops giving Discord roles and becomes read-only. You can restore it.')">@csrf
                    <button class="btn btn-outline-secondary btn-sm"><i class="fas fa-box-archive"></i> Archive group</button></form>
            @endif
            @if($isStaff && $archived)
                <form method="post" action="{{ route('vatssa.groups.archive', $group) }}">@csrf<input type="hidden" name="restore" value="1">
                    <button class="btn btn-success btn-sm"><i class="fas fa-rotate-left"></i> Restore group</button></form>
                <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteGroup"><i class="fas fa-trash"></i> Delete group…</button>
            @endif
        </div>
    </div>
    @if($archived)
        <div class="card-footer bg-light text-muted small">
            Archived {{ $group->archived_at->toEuropeanDate() }}. Read-only: it gives no Discord roles, its managers cannot see it, and nothing can be changed until it is restored.
        </div>
    @endif
</div>
@error('confirm_name')<div class="alert alert-danger">{{ $message }}</div>@enderror

<div class="row">
    {{-- Members --}}
    <div class="col-xl-8 col-lg-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3"><h6 class="m-0 fw-bold text-white"><i class="fas fa-users"></i>&nbsp;Members</h6></div>
            @if($members->isEmpty())
                <div class="card-body"><p class="mb-0 text-muted">No members yet.@if($canMembers) Add VATSIM CIDs on the right.@endif</p></div>
            @else
                <form method="post" action="{{ route('vatssa.groups.members.remove', $group) }}" id="removeSelected">@csrf
                    <table class="table table-sm table-hover table-leftpadded mb-0" width="100%" cellspacing="0">
                        <thead class="table-light"><tr>
                            @if($canMembers)<th style="width:2rem"><input type="checkbox" class="form-check-input" id="selectAll" aria-label="Select all members"></th>@endif
                            <th>Member</th><th>Added</th><th>Added by</th>
                        </tr></thead>
                        <tbody>
                        @foreach($members as $member)
                            <tr>
                                @if($canMembers)<td><input type="checkbox" name="members[]" value="{{ $member->user_id }}" class="form-check-input member-check" aria-label="Select {{ $member->user_id }}"></td>@endif
                                <td>
                                    @if($member->user)
                                        @can('view', $member->user)<a href="{{ route('user.show', $member->user_id) }}">{{ $member->user->name }}</a>@else{{ $member->user->name }}@endcan
                                        <span class="text-muted">{{ $member->user_id }}</span>
                                    @else
                                        {{ $member->user_id }} <span class="badge bg-light text-dark border" title="They are in the group already; it applies once they sign in">No CC account yet</span>
                                    @endif
                                </td>
                                <td>{{ $member->created_at->toEuropeanDate() }}</td>
                                <td class="text-muted">{{ $member->added_by ? ($names[$member->added_by] ?? $member->added_by) : '' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @if($canMembers)
                        <div class="card-footer bg-white">
                            <button class="btn btn-sm btn-danger d-none" id="removeSelectedButton" onclick="return confirm('Remove the selected members from {{ $group->name }}?')">Remove selected</button>
                            <span class="text-muted small" id="removeSelectedHint">Tick members to remove them.</span>
                        </div>
                    @endif
                </form>
            @endif
        </div>

        <details class="card shadow mb-4">
            <summary class="card-header py-3 fw-bold">History</summary>
            <ul class="list-group list-group-flush">
                @forelse($events as $event)
                    <li class="list-group-item small d-flex justify-content-between gap-3">
                        <span>
                            {{ ucfirst(str_replace('_', ' ', $event->event)) }}@if($event->user_id): {{ $names[$event->user_id] ?? $event->user_id }}@endif
                            @if($event->detail)<span class="text-muted">({{ $event->detail }})</span>@endif
                        </span>
                        <span class="text-muted text-nowrap">{{ $event->by ? ($names[$event->by] ?? $event->by) . ', ' : '' }}{{ $event->created_at->toEuropeanDateTime() }}</span>
                    </li>
                @empty
                    <li class="list-group-item text-muted">Nothing yet</li>
                @endforelse
            </ul>
        </details>
    </div>

    <div class="col-xl-4 col-lg-12">
        {{-- Add or remove by list --}}
        @if($canMembers || ($isStaff && $archived))
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3"><h6 class="m-0 fw-bold text-white">Add members</h6></div>
            <div class="card-body">
                <form method="post" action="{{ route('vatssa.groups.members.add', $group) }}">@csrf
                    <fieldset @disabled(! $canMembers)>
                        <label for="cids" class="form-label fw-bold">VATSIM CIDs</label>
                        <textarea id="cids" name="cids" class="form-control mb-1" rows="3" placeholder="1234567, 7654321"></textarea>
                        <div class="form-text mb-3">Separate CIDs with commas. People who have never signed in to CC can be added too.</div>
                        @error('cids')<div class="text-danger mb-2">{{ $message }}</div>@enderror
                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-success">Add to group</button>
                            <button class="btn btn-outline-danger" formaction="{{ route('vatssa.groups.members.remove', $group) }}" onclick="return confirm('Remove these CIDs from {{ $group->name }}?')">Remove these CIDs</button>
                        </div>
                    </fieldset>
                </form>
            </div>
        </div>
        @endif

        {{-- Managers --}}
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3"><h6 class="m-0 fw-bold text-white">Managers</h6></div>
            <div class="card-body">
                <dl class="small mb-3">
                    <dt>Members only</dt><dd class="mb-1 text-muted">Add and remove members.</dd>
                    <dt>Members and settings</dt><dd class="mb-0 text-muted">Also rename the group and appoint "members only" managers.</dd>
                </dl>

                @foreach($staffManagers->reject(fn ($staff) => $managers->contains('user_id', $staff['user']->id)) as $staff)
                    <div class="d-flex justify-content-between align-items-center border-top py-2 gap-2">
                        <span>{{ $staff['user']->name }} <span class="text-muted">{{ $staff['user']->id }}</span></span>
                        <span class="badge bg-light text-dark border" title="Manages every group through the Manage groups permission">Manage groups permission ({{ $staff['roles'] }})</span>
                    </div>
                @endforeach
                @forelse($managers as $manager)
                    <div class="d-flex justify-content-between align-items-center border-top py-2 gap-2">
                        <span>{{ $manager->user?->name }} <span class="text-muted">{{ $manager->user_id }}</span></span>
                        <span class="d-flex align-items-center gap-2">
                            @if($isStaff && ! $archived)
                                <form method="post" action="{{ route('vatssa.groups.managers.add', $group) }}">@csrf
                                    <input type="hidden" name="user_id" value="{{ $manager->user_id }}">
                                    <select name="level" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="What {{ $manager->user?->name }} can manage">
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
                    </div>
                @empty
                    <p class="border-top pt-2 mb-0 text-muted small">No group managers yet. The people above manage every group through their role.</p>
                @endforelse

                @if($canSettings || ($isStaff && $archived))
                    <form method="post" action="{{ route('vatssa.groups.managers.add', $group) }}" class="border-top pt-3 mt-3">@csrf
                        <fieldset @disabled(! $canSettings)>
                            <label for="managerCid" class="form-label fw-bold">Add a manager</label>
                            <input id="managerCid" name="user_id" class="form-control mb-2" inputmode="numeric" placeholder="VATSIM CID" required>
                            @if($isStaff)
                                <div class="mb-2">
                                    <div class="form-check"><input class="form-check-input" type="radio" name="level" value="members" id="levelMembers" checked>
                                        <label class="form-check-label" for="levelMembers">Members only</label></div>
                                    <div class="form-check"><input class="form-check-input" type="radio" name="level" value="settings" id="levelSettings">
                                        <label class="form-check-label" for="levelSettings">Members and settings</label></div>
                                </div>
                            @else
                                <input type="hidden" name="level" value="members">
                                <div class="form-text mb-2">They can add and remove members.</div>
                            @endif
                            @error('user_id')<div class="text-danger mb-2">{{ $message }}</div>@enderror
                            <button class="btn btn-success">Add manager</button>
                        </fieldset>
                    </form>
                @endif
            </div>
        </div>

        {{-- Settings --}}
        @if($canSettings || ($isStaff && $archived))
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3"><h6 class="m-0 fw-bold text-white">Settings</h6></div>
            <div class="card-body">
                <form method="post" action="{{ route('vatssa.groups.update', $group) }}" id="groupSettings">@csrf @method('PATCH')
                    <fieldset @disabled(! $canSettings)>
                        <div class="mb-2"><label for="name" class="form-label fw-bold">Name</label>
                            <input id="name" name="name" class="form-control" value="{{ $group->name }}" required maxlength="80"></div>
                        <div class="mb-3"><label for="description" class="form-label fw-bold">Description</label>
                            <input id="description" name="description" class="form-control" value="{{ $group->description }}" maxlength="255"></div>
                        <button class="btn btn-success">Save</button>
                    </fieldset>
                </form>
            </div>
        </div>
        @endif
    </div>
</div>

@if($isStaff && $archived)
<div class="modal fade" id="deleteGroup" tabindex="-1" aria-labelledby="deleteGroupLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="{{ route('vatssa.groups.destroy', $group) }}" class="modal-content">@csrf @method('DELETE')
            <div class="modal-header">
                <h5 class="modal-title" id="deleteGroupLabel">Delete {{ $group->name }}?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>This deletes the group, its members, managers and history for good. It cannot be undone.</p>
                <label for="confirmName" class="form-label">Type <strong>{{ $group->name }}</strong> to confirm</label>
                <input id="confirmName" name="confirm_name" class="form-control" autocomplete="off" data-expected="{{ $group->name }}" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger" id="confirmDelete" disabled>Delete for good</button>
            </div>
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
    const hint = document.getElementById('removeSelectedHint');
    const all = document.getElementById('selectAll');
    const update = () => {
        const n = [...checks].filter(c => c.checked).length;
        if (button) {
            button.classList.toggle('d-none', n === 0);
            button.textContent = 'Remove ' + n + ' selected';
            hint.classList.toggle('d-none', n !== 0);
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
})();
</script>
@endsection
