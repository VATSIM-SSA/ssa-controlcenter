@extends('layouts.app')

@section('title', $group->name)

@section('content')
@if($group->isArchived())
    <div class="alert alert-secondary d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-box-archive"></i> Archived {{ $group->archived_at->toEuropeanDate() }}. It gives no Discord roles, and its managers cannot see it.</span>
        @if($isStaff)
            <div class="d-flex gap-2">
                <form method="post" action="{{ route('vatssa.groups.archive', $group) }}">@csrf<input type="hidden" name="restore" value="1">
                    <button class="btn btn-sm btn-success">Restore group</button></form>
                <form method="post" action="{{ route('vatssa.groups.destroy', $group) }}" class="d-flex gap-2">@csrf @method('DELETE')
                    <input name="confirm_name" class="form-control form-control-sm" placeholder="Type {{ $group->name }} to delete" aria-label="Type the group name to delete it" required>
                    <button class="btn btn-sm btn-danger text-nowrap">Delete for good</button></form>
            </div>
        @endif
    </div>
    @error('confirm_name')<div class="alert alert-danger">{{ $message }}</div>@enderror
@endif
<div class="row">
    <div class="col-xl-7 col-lg-12 col-md-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white"><i class="fas fa-users"></i>&nbsp;{{ $group->name }}: members</h6>
                <div class="d-flex gap-2">
                    @if($isStaff && ! $group->isArchived())
                        <form method="post" action="{{ route('vatssa.groups.archive', $group) }}" onsubmit="return confirm('Archive this group? It stops giving Discord roles. Members are kept, and you can restore it.')">@csrf
                            <button class="btn btn-icon btn-light"><i class="fas fa-box-archive"></i> Archive</button></form>
                    @endif
                    <a href="{{ route('vatssa.groups.index') }}" class="btn btn-icon btn-light">{{ $isStaff ? 'All groups' : 'My groups' }}</a>
                </div>
            </div>
            <div class="card-body {{ $members->isEmpty() ? '' : 'p-0' }}">
                @if($members->isEmpty())
                    <p class="mb-0">No members yet</p>
                @else
                    <form method="post" action="{{ route('vatssa.groups.members.remove', $group) }}" onsubmit="return confirm('Remove the ticked members from this group?')">@csrf
                        <table class="table table-sm table-hover table-leftpadded mb-0" width="100%" cellspacing="0">
                            <thead class="table-light"><tr><th></th><th>Member</th><th>Added</th></tr></thead>
                            <tbody>
                            @foreach($members as $member)
                                <tr class="{{ $member->removed_at ? 'text-muted' : '' }}">
                                    <td>@unless($member->removed_at)<input type="checkbox" name="members[]" value="{{ $member->user_id }}" class="form-check-input" aria-label="Select {{ $member->user_id }}">@endunless</td>
                                    <td>@if($member->user)
                                            @can('view', $member->user)<a href="{{ route('user.show', $member->user_id) }}">{{ $member->user->name }} ({{ $member->user_id }})</a>@else{{ $member->user->name }} ({{ $member->user_id }})@endcan
                                        @else{{ $member->user_id }} <span class="badge bg-secondary">Not signed in to CC yet</span>@endif</td>
                                    <td>{{ $member->created_at->toEuropeanDate() }}@if($member->removed_at), removed {{ $member->removed_at->toEuropeanDate() }}@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        <div class="p-2"><button class="btn btn-sm btn-outline-danger">Remove ticked</button></div>
                    </form>
                @endif
            </div>
        </div>

        <details class="card shadow mb-4">
            <summary class="card-header py-3 fw-bold">History</summary>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    @forelse($events as $event)
                        <tr>
                            <td class="text-nowrap">{{ $event->created_at->toEuropeanDateTime() }}</td>
                            <td>{{ str_replace('_', ' ', $event->event) }}@if($event->user_id): {{ $names[$event->user_id] ?? $event->user_id }}@endif @if($event->detail)({{ $event->detail }})@endif</td>
                            <td class="text-muted">{{ $event->by ? ($names[$event->by] ?? $event->by) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td>Nothing yet</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </details>
    </div>

    <div class="col-xl-5 col-lg-12 col-md-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3"><h6 class="m-0 fw-bold text-white">Add or remove members</h6></div>
            <div class="card-body">
                <form method="post" action="{{ route('vatssa.groups.members.add', $group) }}">@csrf
                    <label for="cids" class="form-label fw-bold">VATSIM IDs</label>
                    <textarea id="cids" name="cids" class="form-control mb-2" rows="4" placeholder="One ID, or paste a list"></textarea>
                    <div class="form-text mb-2">Anyone with a VATSIM ID, also people who have never signed in to CC.</div>
                    @error('cids')<div class="text-danger mb-2">{{ $message }}</div>@enderror
                    <button class="btn btn-success">Add</button>
                    <button class="btn btn-outline-danger" formaction="{{ route('vatssa.groups.members.remove', $group) }}" onclick="return confirm('Remove these VATSIM IDs from the group?')">Remove these</button>
                </form>
            </div>
        </div>

        @if($canSettings)
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3"><h6 class="m-0 fw-bold text-white">Settings</h6></div>
            <div class="card-body">
                <form method="post" action="{{ route('vatssa.groups.update', $group) }}" id="groupSettings">@csrf @method('PATCH')
                    <div class="mb-2"><label for="name" class="form-label fw-bold">Name</label>
                        <input id="name" name="name" class="form-control" value="{{ $group->name }}" required maxlength="80"></div>
                    <div class="mb-2"><label for="description" class="form-label fw-bold">Description</label>
                        <input id="description" name="description" class="form-control" value="{{ $group->description }}" maxlength="255"></div>
                    <button class="btn btn-success">Save</button>
                </form>
            </div>
        </div>
        @endif

        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3"><h6 class="m-0 fw-bold text-white">Managers</h6></div>
            <div class="card-body">
                <p>Member managers add and remove members. Settings managers can also rename the group and appoint member managers.</p>
                @forelse($managers as $manager)
                    <div class="d-flex justify-content-between align-items-center border-bottom py-1">
                        <span>{{ $manager->user?->name }} ({{ $manager->user_id }}) <span class="badge bg-light text-dark">{{ $manager->can_manage_settings ? 'members and settings' : 'members' }}</span></span>
                        @if($canSettings && ($isStaff || ! $manager->can_manage_settings))
                            <form method="post" action="{{ route('vatssa.groups.managers.remove', [$group, $manager]) }}" onsubmit="return confirm('Remove this manager?')">@csrf
                                <button class="btn btn-link btn-sm text-muted hover-red p-0" title="Remove manager"><i class="fas fa-trash"></i></button></form>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">No managers. Only staff can change this group.</p>
                @endforelse
                @if($canSettings)
                    <form method="post" action="{{ route('vatssa.groups.managers.add', $group) }}" class="mt-3">@csrf
                        <div class="d-flex gap-2">
                            <input name="user_id" class="form-control" inputmode="numeric" placeholder="VATSIM ID" required aria-label="VATSIM ID of the new manager">
                            <button class="btn btn-success text-nowrap">Add manager</button>
                        </div>
                        @if($isStaff)
                            <div class="form-check mt-2"><input type="hidden" name="can_manage_settings" value="0">
                                <input class="form-check-input" type="checkbox" name="can_manage_settings" value="1" id="settingsLevel">
                                <label class="form-check-label" for="settingsLevel">Also manages settings</label></div>
                        @endif
                    </form>
                    @error('user_id')<div class="text-danger mt-1">{{ $message }}</div>@enderror
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
