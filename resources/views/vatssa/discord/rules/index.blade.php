@extends('layouts.app')

@section('title', 'Discord rules')

@section('content')
<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold text-white">Discord rules</h6>
        @if($canManage)<a href="{{ route('vatssa.discord.rules.create') }}" class="btn btn-icon btn-light"><i class="fas fa-plus"></i> New rule</a>@endif
    </div>
    <div class="card-body {{ $rules->isEmpty() ? '' : 'p-0' }}">
        @if($rules->isEmpty())
            <p class="mb-0">No rules yet. The bot changes nobody's roles until there is one.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover table-leftpadded mb-0" width="100%" cellspacing="0">
                    <thead class="table-light"><tr><th>Rule</th><th>When all of these hold</th><th>Gives</th>@if($canManage)<th></th>@endif</tr></thead>
                    <tbody>
                    @foreach($rules as $rule)
                        <tr class="{{ $rule->enabled ? '' : 'text-muted' }}">
                            <td>{{ $rule->name }}@unless($rule->enabled) <span class="badge bg-light text-dark border">Off</span>@endunless</td>
                            <td>{{ implode(' and ', $rule->words) }}</td>
                            <td>{{ collect($rule->roles)->map(fn ($id) => $roleNames->get($id) ?? $id)->implode(', ') }}</td>
                            @if($canManage)
                                <td class="text-nowrap">
                                    <a href="{{ route('vatssa.discord.rules.edit', $rule->id) }}" class="btn btn-sm btn-icon btn-light">Edit</a>
                                    <form method="post" action="{{ route('vatssa.discord.rules.toggle', $rule->id) }}" class="d-inline">@csrf
                                        <button class="btn btn-sm btn-icon btn-light">{{ $rule->enabled ? 'Switch off' : 'Switch on' }}</button></form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if($audit->isNotEmpty())
    <details class="card shadow mb-4">
        <summary class="card-header py-3 fw-bold">Rule history</summary>
        <div class="card-body p-0">
            <table class="table table-sm table-leftpadded mb-0" width="100%" cellspacing="0">
                <thead class="table-light"><tr><th>When</th><th>Rule</th><th>Change</th><th>By</th>@if($canManage)<th></th>@endif</tr></thead>
                <tbody>
                @foreach($audit as $entry)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('j M Y H:i') }}</td>
                        <td>{{ $entry->rule_name }}</td>
                        <td>{{ ucfirst($entry->action) }}</td>
                        <td>{{ $entry->by ?? 'System' }}</td>
                        @if($canManage)
                            <td>@if($entry->before)
                                <form method="post" action="{{ route('vatssa.discord.rules.restore', $entry->id) }}" onsubmit="return confirm('Put this rule back as it was before this change?')">@csrf
                                    <button class="btn btn-sm btn-icon btn-light">Restore</button></form>
                            @endif</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif
@endsection
