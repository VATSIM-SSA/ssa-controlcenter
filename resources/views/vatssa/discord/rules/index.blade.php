@extends('layouts.app')

@section('title', 'Discord rules')

@section('title-flex')
    <div class="d-flex gap-2 align-items-center">
        @if($audit->isNotEmpty())
            <button type="button" class="btn btn-icon btn-light d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#ruleHistory"><i class="fas fa-clock-rotate-left"></i> History</button>
        @endif
        @if($canManage)
            <a href="{{ route('vatssa.discord.rules.create') }}" class="btn btn-icon btn-light d-inline-flex align-items-center gap-1"><i class="fas fa-plus"></i> New rule</a>
        @endif
    </div>
@endsection

@section('content')
@include('vatssa.discord._style')

@php($paused = $bot && $bot['online'] && ($bot['paused_removals'] || $bot['paused_adds']))
<div class="card shadow mb-3">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
        @if(! $bot || ! $bot['online'])
            <span><span class="vd-dot"></span>Bot offline{{ $bot ? ', last seen ' . \Illuminate\Support\Carbon::parse($bot['seen_at'])->diffForHumans() : '' }}</span>
        @elseif($paused)
            <span><span class="vd-dot warn"></span><strong>Paused by a safety limit:</strong>
                {{ collect(['role removals' => $bot['paused_removals'], 'role additions' => $bot['paused_adds']])->filter()->keys()->implode(' and ') }}</span>
        @else
            <span><span class="vd-dot on"></span>Bot online</span>
        @endif
        @if($bot)
            <span class="text-muted">{{ ['report' => 'Report mode: nothing is applied', 'adds' => 'Adds only', 'live' => 'Live'][$bot['mode']] ?? $bot['mode'] }}</span>
            @if($bot['frozen'])<span class="text-muted">{{ $bot['frozen'] }} frozen {{ Str::plural('member', $bot['frozen']) }}</span>@endif
        @endif
        <span class="text-muted">{{ $rules->where('enabled', true)->count() }} of {{ $rules->count() }} rules on</span>
        @if($paused && $canManage)
            <form method="post" action="{{ route('vatssa.discord.rules.approve') }}" class="ms-auto" onsubmit="return confirm('Apply the held changes now? The next sweep ignores the hourly limits once.')">@csrf
                <button class="btn btn-sm btn-success">Approve and resume</button></form>
        @endif
    </div>
</div>

<div class="card shadow mb-4">
    @if($byRole->isEmpty())
        <div class="card-body">
            <p class="mb-0">No rules yet. The bot changes nobody's roles until there is one.</p>
        </div>
    @else
        <div class="card-body border-bottom py-2">
            <input type="search" id="ruleSearch" class="form-control form-control-sm" style="max-width: 18rem" placeholder="Search roles and rules" aria-label="Search roles and rules">
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-leftpadded vd-table mb-0" width="100%" cellspacing="0">
                <thead class="table-light"><tr><th>Discord role</th><th>Given when</th><th>Rule</th>@if($canManage)<th class="text-end">On</th><th></th>@endif</tr></thead>
                @foreach($byRole as $role)
                    <tbody class="vd-group">
                        @foreach($role['rules'] as $rule)
                            <tr class="{{ $rule->enabled ? '' : 'vd-off' }}">
                                @if($loop->first)
                                    <td class="vd-role" rowspan="{{ count($role['rules']) }}">{{ $role['name'] }}</td>
                                @endif
                                <td>
                                    @unless($loop->first)<span class="vd-join">or</span>@endunless
                                    @foreach($rule->words as $word)
                                        <span class="vd-chip">{{ $word }}</span>@unless($loop->last)<span class="vd-join">and</span>@endunless
                                    @endforeach
                                </td>
                                <td class="vd-rule-name">{{ $rule->name }}</td>
                                @if($canManage)
                                    <td class="text-end">
                                        <form method="post" action="{{ route('vatssa.discord.rules.toggle', $rule->id) }}" class="d-inline-block">@csrf
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch" @checked($rule->enabled) onchange="this.form.submit()" aria-label="{{ $rule->enabled ? 'Switch off' : 'Switch on' }} {{ $rule->name }}">
                                            </div>
                                        </form>
                                    </td>
                                    <td class="text-end"><a href="{{ route('vatssa.discord.rules.edit', $rule->id) }}" class="btn btn-sm btn-icon btn-light d-inline-flex align-items-center" aria-label="Edit {{ $rule->name }}"><i class="fas fa-pen"></i></a></td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    @endif
</div>

@if($audit->isNotEmpty())
    <div class="modal fade" id="ruleHistory" tabindex="-1" aria-labelledby="ruleHistoryLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="ruleHistoryLabel">Rule history</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body p-0">
                    <table class="table table-sm table-leftpadded vd-table mb-0" width="100%" cellspacing="0">
                        <thead class="table-light"><tr><th>When</th><th>Rule</th><th>Change</th><th>By</th>@if($canManage)<th></th>@endif</tr></thead>
                        <tbody>
                        @foreach($audit as $entry)
                            <tr>
                                <td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('j M Y H:i') }}</td>
                                <td>{{ $entry->rule_name }}</td>
                                <td>{{ ucfirst($entry->action) }}</td>
                                <td>{{ $entry->by ?? 'System' }}</td>
                                @if($canManage)
                                    <td class="text-end">@if($entry->before)
                                        <form method="post" action="{{ route('vatssa.discord.rules.restore', $entry->id) }}" onsubmit="return confirm('Put this rule back as it was before this change?')">@csrf
                                            <button class="btn btn-sm btn-icon btn-light">Restore</button></form>
                                    @endif</td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endif
@endsection

@section('js')
<script>
(function () {
    const search = document.getElementById('ruleSearch');
    if (!search) return;
    search.addEventListener('input', () => {
        const term = search.value.trim().toLowerCase();
        document.querySelectorAll('.vd-group').forEach(group => {
            group.hidden = term !== '' && !group.textContent.toLowerCase().includes(term);
        });
    });
})();
</script>
@endsection
