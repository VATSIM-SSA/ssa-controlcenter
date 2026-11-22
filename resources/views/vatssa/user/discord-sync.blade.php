{{-- VATSSA: this member's Discord account, roles, last sync and history, plus the sync button. Included from user/show.blade.php. --}}
@can('vatssaDiscordSync', $user)
    @php($vatssaSync = \App\Http\Controllers\VatssaDiscordSyncController::latestFor($user->id))
    @php($vatssaDiscord = \App\Http\Controllers\VatssaDiscordSyncController::presenceFor($user->id))
    @php($vatssaAccount = $vatssaDiscord['account'])
    @php($vatssaRoles = \App\Http\Controllers\VatssaDiscordSyncController::rolesFor($vatssaAccount))
    @include('vatssa.discord._style')
    <dl class="mb-2">
        <dt>Discord</dt>
        <dd class="mb-1">
            @if($vatssaAccount?->in_server)
                <span class="vd-dot on"></span>On the VATSSA Discord as @<span>{{ $vatssaAccount->name ?? $vatssaAccount->discord_id }}</span>
            @elseif($vatssaAccount)
                <span class="vd-dot"></span>Left the VATSSA Discord {{ \Illuminate\Support\Carbon::parse($vatssaAccount->left_at)->diffForHumans() }} (was @<span>{{ $vatssaAccount->name ?? $vatssaAccount->discord_id }}</span>)
            @elseif($vatssaDiscord['at'])
                <span class="vd-dot"></span>Not on the VATSSA Discord
            @else
                <span class="text-muted">Unknown: the role sync bot has not reported recently.</span>
            @endif
        </dd>
    </dl>
    @if($vatssaRoles)
        <div class="mb-2">
            @foreach($vatssaRoles['current'] as $role)<span class="vd-chip me-1 mb-1">{{ $role }}</span>@endforeach
            @if($vatssaRoles['add'])<div class="small text-muted">Waiting to add: {{ implode(', ', $vatssaRoles['add']) }}</div>@endif
            @if($vatssaRoles['remove'])<div class="small text-muted">Waiting to remove: {{ implode(', ', $vatssaRoles['remove']) }}</div>@endif
        </div>
    @endif
    <div class="d-flex flex-wrap align-items-center gap-2">
        <form method="POST" action="{{ route('vatssa.user.discord-sync', $user->id) }}">
            @csrf
            <button type="submit" class="btn btn-sm btn-icon btn-light"><i class="fab fa-discord"></i> Sync Discord roles</button>
        </form>
        @if($vatssaDiscord['history']->isNotEmpty())
            <button type="button" class="btn btn-sm btn-icon btn-light" data-bs-toggle="modal" data-bs-target="#vatssaDiscordHistory"><i class="fas fa-clock-rotate-left"></i> Discord history</button>
        @endif
    </div>
    @if($vatssaSync)
        <small class="d-block text-muted mt-1">
            @if($vatssaSync->state !== 'idle' && \Illuminate\Support\Carbon::parse($vatssaSync->queued_at)->lt(now()->subMinutes(5)))
                Not picked up by the bot. The bot's 15-minute sweep updates the roles anyway.
            @elseif($vatssaSync->state !== 'idle' && $vatssaSync->reason === 'button')
                Requested {{ \Illuminate\Support\Carbon::parse($vatssaSync->queued_at)->diffForHumans() }}, waiting for the bot.
            @elseif($vatssaSync->finished_at)
                Last sync: {{ \App\Http\Controllers\VatssaDiscordSyncController::STATUSES[$vatssaSync->result] ?? $vatssaSync->result }},
                {{ \Illuminate\Support\Carbon::parse($vatssaSync->finished_at)->diffForHumans() }}.
                @if($vatssaSync->detail){{ $vatssaSync->detail }}@endif
            @endif
        </small>
    @endif
    @if($vatssaDiscord['history']->isNotEmpty())
        <div class="modal fade" id="vatssaDiscordHistory" tabindex="-1" aria-labelledby="vatssaDiscordHistoryLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title" id="vatssaDiscordHistoryLabel">Discord history</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body">
                        @foreach($vatssaDiscord['history'] as $event)
                            <div class="mb-2">
                                <div class="small text-muted">{{ \Illuminate\Support\Carbon::parse($event->created_at)->format('j M Y H:i') }}</div>
                                <div>{{ \App\Http\Controllers\VatssaDiscordSyncController::EVENTS[$event->event] ?? $event->event }}@if($event->detail): {{ $event->detail }}@endif</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif
@endcan
