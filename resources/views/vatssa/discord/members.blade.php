@extends('layouts.app')

@section('title', 'Discord members')

@section('content')
@include('vatssa.discord._style')

<div class="card shadow mb-4">
    <div class="card-body border-bottom py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <ul class="nav nav-pills">
            @foreach(['server' => 'In the server', 'not_vatsim' => 'Not VATSIM', 'bots' => 'Bots', 'left' => 'Left'] as $value => $label)
                <li class="nav-item">
                    <a class="nav-link py-1 px-3 {{ $show === $value ? 'active' : '' }}" @if($show === $value) aria-current="page" @endif href="{{ route('vatssa.discord.members', array_filter(['show' => $value, 'q' => $search])) }}">{{ $label }} <span class="opacity-75">{{ number_format($counts[$value]) }}</span></a>
                </li>
            @endforeach
        </ul>
        <form method="GET" class="d-flex gap-2">
            <input type="hidden" name="show" value="{{ $show }}">
            <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" style="width: 15rem" placeholder="Name, CID or Discord ID" aria-label="Search">
            <button class="btn btn-sm btn-icon btn-light" aria-label="Search"><i class="fas fa-magnifying-glass"></i></button>
        </form>
    </div>

    @if($members->isEmpty())
        <div class="card-body"><p class="mb-0">{{ $search !== '' ? 'Nobody matches that search.' : 'Nobody here yet.' }}</p></div>
    @else
        <div class="table-responsive">
            <table class="table table-hover table-leftpadded vd-table mb-0" width="100%" cellspacing="0">
                <thead class="table-light"><tr><th>Discord</th><th>CID</th><th>Status</th><th>Managed roles</th><th>Roles checked</th><th></th></tr></thead>
                <tbody>
                @foreach($members as $member)
                    @php($roles = collect(json_decode($member->role_ids ?? '[]', true) ?: [])->filter(fn ($id) => in_array((string) $id, $managed, true))->map(fn ($id) => $roleNames->get('r' . $id) ?? $id)->values())
                    <tr>
                        <td><div class="fw-bold">{{ $member->name ?? 'Unknown name' }}</div><div class="small text-muted">{{ $member->discord_id }}</div></td>
                        <td>@if($member->user_id)<a href="{{ route('user.show', $member->user_id) }}">{{ $member->user_id }}</a>@else<span class="text-muted">None</span>@endif</td>
                        <td class="text-nowrap">
                            @if($member->is_bot) Bot
                            @elseif($member->user_id) VATSIM
                            @elseif($member->vatsim_linked === null) <span class="text-muted">Not checked</span>
                            @elseif($member->vatsim_linked) VATSIM, no CC account
                            @else Not VATSIM
                            @endif
                            @unless($member->in_server)<div class="small text-muted">Left {{ \Illuminate\Support\Carbon::parse($member->left_at)->diffForHumans() }}</div>@endunless
                        </td>
                        <td>
                            @foreach($roles->take(3) as $role)<span class="vd-chip me-1">{{ $role }}</span>@endforeach
                            @if($roles->count() > 3)<span class="small text-muted" title="{{ $roles->slice(3)->implode(', ') }}">+{{ $roles->count() - 3 }}</span>@endif
                        </td>
                        <td class="text-nowrap small">{{ $member->last_synced_at ? \Illuminate\Support\Carbon::parse($member->last_synced_at)->diffForHumans() : 'Not yet' }}</td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-icon btn-light" data-history="{{ $member->discord_id }}" data-name="{{ $member->name ?? $member->discord_id }}" aria-label="History of {{ $member->name ?? $member->discord_id }}"><i class="fas fa-clock-rotate-left"></i></button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if($members->hasPages())<div class="card-footer">{{ $members->links() }}</div>@endif
</div>

<div class="modal fade" id="memberHistory" tabindex="-1" aria-labelledby="memberHistoryLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="memberHistoryLabel">History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body" id="memberHistoryBody"></div>
        </div>
    </div>
</div>
@endsection

@section('js')
<script>
(function () {
    const history = @js($historyText);
    const modal = document.getElementById('memberHistory');
    const body = document.getElementById('memberHistoryBody');
    document.querySelectorAll('[data-history]').forEach(button => {
        button.addEventListener('click', () => {
            document.getElementById('memberHistoryLabel').textContent = button.dataset.name;
            body.replaceChildren();
            const rows = history['d' + button.dataset.history] || [];
            if (!rows.length) body.textContent = 'No history yet.';
            rows.forEach(row => {
                const line = document.createElement('div');
                line.className = 'mb-2';
                const when = document.createElement('div');
                when.className = 'small text-muted';
                when.textContent = row.when;
                const what = document.createElement('div');
                what.textContent = row.what;
                line.append(when, what);
                body.appendChild(line);
            });
            bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    });
})();
</script>
@endsection
