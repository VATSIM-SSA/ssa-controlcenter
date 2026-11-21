{{-- VATSSA: this member's Discord account, last sync and history, plus the sync button. Included from user/show.blade.php. --}}
@can('vatssaDiscordSync', $user)
    @php($vatssaSync = \App\Http\Controllers\VatssaDiscordSyncController::latestFor($user->id))
    @php($vatssaDiscord = \App\Http\Controllers\VatssaDiscordSyncController::presenceFor($user->id))
    @php($vatssaAccount = $vatssaDiscord['account'])
    <dl class="mb-2">
        <dt>Discord</dt>
        <dd>
            @if($vatssaAccount?->in_server)
                On the VATSSA Discord as @<span>{{ $vatssaAccount->name ?? $vatssaAccount->discord_id }}</span>
            @elseif($vatssaAccount)
                Left the VATSSA Discord {{ \Illuminate\Support\Carbon::parse($vatssaAccount->left_at)->diffForHumans() }} (was @<span>{{ $vatssaAccount->name ?? $vatssaAccount->discord_id }}</span>)
            @elseif($vatssaDiscord['at'])
                Not on the VATSSA Discord
            @else
                <span class="text-muted">Unknown: the role sync bot has not reported recently.</span>
            @endif
            @if($vatssaAccount?->last_synced_at)<small class="d-block text-muted">Roles checked {{ \Illuminate\Support\Carbon::parse($vatssaAccount->last_synced_at)->diffForHumans() }}</small>@endif
        </dd>
    </dl>
    <form method="POST" action="{{ route('vatssa.user.discord-sync', $user->id) }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fab fa-discord"></i> Sync Discord roles</button>
    </form>
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
        <details class="small mt-1">
            <summary class="text-muted">Discord history</summary>
            <ul class="list-unstyled mb-0 mt-1">
                @foreach($vatssaDiscord['history'] as $event)
                    <li><span class="text-muted">{{ \Illuminate\Support\Carbon::parse($event->created_at)->format('j M Y H:i') }}</span> {{ \App\Http\Controllers\VatssaDiscordSyncController::EVENTS[$event->event] ?? $event->event }}@if($event->detail): {{ $event->detail }}@endif</li>
                @endforeach
            </ul>
        </details>
    @endif
@endcan
