@extends('layouts.app')

@section('title', 'Groups')

@section('content')
<div class="row">
    <div class="col-xl-8 col-lg-12 col-md-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">Groups</h6>
            </div>
            <div class="card-body {{ $groups->isEmpty() ? '' : 'p-0' }}">
                @if($groups->isEmpty())
                    <p class="mb-0">No groups yet</p>
                @else
                    <table class="table table-sm table-hover table-leftpadded mb-0" width="100%" cellspacing="0">
                        <thead class="table-light"><tr><th>Group</th><th>Members</th><th>Managers</th></tr></thead>
                        <tbody>
                        @foreach($groups as $group)
                            <tr>
                                <td><a href="{{ route('vatssa.groups.show', $group) }}">{{ $group->name }}</a>@if($group->description)<br><span class="text-muted">{{ $group->description }}</span>@endif</td>
                                <td>{{ $group->current_members_count }}</td>
                                <td>{{ $group->currentManagers->map(fn ($manager) => $manager->user?->name)->filter()->implode(', ') ?: 'Staff only' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        @if($archived->isNotEmpty())
            <details class="card shadow mb-4">
                <summary class="card-header py-3 fw-bold">Archived groups ({{ $archived->count() }})</summary>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover table-leftpadded mb-0" width="100%" cellspacing="0">
                        <thead class="table-light"><tr><th>Group</th><th>Members</th><th>Archived</th></tr></thead>
                        <tbody>
                        @foreach($archived as $group)
                            <tr class="text-muted"><td><a href="{{ route('vatssa.groups.show', $group) }}">{{ $group->name }}</a></td>
                                <td>{{ $group->current_members_count }}</td><td>{{ $group->archived_at->toEuropeanDate() }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    </div>

    @can('vatssa.groups.manage')
    <div class="col-xl-4 col-lg-12 col-md-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">New group</h6>
            </div>
            <div class="card-body">
                <p>A group is a name and a list of people, such as the hit squad or an event team. Discord roles can be linked to a group on the Discord rules page.</p>
                <form method="post" action="{{ route('vatssa.groups.store') }}">@csrf
                    <div class="mb-3">
                        <label for="groupName" class="form-label fw-bold">Name</label>
                        <input id="groupName" name="name" class="form-control" placeholder="SSA HitSquad" required maxlength="80">
                    </div>
                    <div class="mb-3">
                        <label for="groupDescription" class="form-label fw-bold">Description</label>
                        <input id="groupDescription" name="description" class="form-control" maxlength="255">
                    </div>
                    <button class="btn btn-success">Create group</button>
                </form>
            </div>
        </div>
    </div>
    @endcan
</div>
@endsection
