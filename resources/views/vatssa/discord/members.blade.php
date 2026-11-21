@extends('layouts.app')

@section('title', 'Discord members')

@section('content')
<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3">
        <h6 class="m-0 fw-bold text-white">Discord members</h6>
    </div>
    <div class="card-body border-bottom py-2">
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
            <select name="show" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Show">
                @foreach(['server' => 'In the server', 'not_vatsim' => 'Not VATSIM', 'bots' => 'Bots', 'left' => 'Left'] as $value => $label)
                    <option value="{{ $value }}" @selected($show === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm w-auto" placeholder="Name, CID or Discord ID" aria-label="Search">
            <button class="btn btn-icon btn-light btn-sm">Search</button>
        </form>
    </div>
    <div class="card-body p-0">
        @if($members->isEmpty())
            <p class="m-3">Nobody here</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover table-leftpadded mb-0" width="100%" cellspacing="0">
                    <thead class="table-light"><tr><th>Discord</th><th>CID</th><th>Status</th><th>Roles checked</th><th>Seen</th></tr></thead>
                    <tbody>
                    @foreach($members as $member)
                        <tr>
                            <td>
                                <details>
                                    <summary>{{ $member->name ?? $member->discord_id }}</summary>
                                    <div class="small text-muted">{{ $member->discord_id }}</div>
                                    <ul class="list-unstyled small mb-1">
                                        @foreach($history->get($member->discord_id, collect()) as $event)
                                            <li><span class="text-muted">{{ \Illuminate\Support\Carbon::parse($event->created_at)->format('j M Y H:i') }}</span> {{ \App\Http\Controllers\VatssaDiscordSyncController::EVENTS[$event->event] ?? $event->event }}@if($event->detail): {{ $event->detail }}@endif</li>
                                        @endforeach
                                    </ul>
                                </details>
                            </td>
                            <td>@if($member->user_id)<a href="{{ route('user.show', $member->user_id) }}">{{ $member->user_id }}</a>@else<span class="text-muted">None</span>@endif</td>
                            <td>
                                @if($member->is_bot) Bot
                                @elseif($member->user_id) VATSIM
                                @elseif($member->vatsim_linked) VATSIM, no CC account
                                @elseif($member->vatsim_linked === null || $member->vatsim_linked === '') <span class="text-muted">Unknown</span>
                                @else Not VATSIM
                                @endif
                                @unless($member->in_server)<span class="text-muted">(left {{ \Illuminate\Support\Carbon::parse($member->left_at)->diffForHumans() }})</span>@endunless
                            </td>
                            <td>{{ $member->last_synced_at ? \Illuminate\Support\Carbon::parse($member->last_synced_at)->diffForHumans() : 'Never' }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($member->last_seen_at)->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    @if($members->hasPages())<div class="card-footer">{{ $members->links() }}</div>@endif
</div>
@endsection
