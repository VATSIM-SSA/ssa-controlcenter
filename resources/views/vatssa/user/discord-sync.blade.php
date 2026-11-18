{{-- VATSSA: ask the Discord role sync bot to re-sync this member now, and show how the last request ended. Included from user/show.blade.php. --}}
@can('vatssaDiscordSync')
    @php($vatssaSync = \App\Http\Controllers\VatssaDiscordSyncController::latestFor($user->id))
    @php($vatssaDiscord = \App\Http\Controllers\VatssaDiscordSyncController::presenceFor($user->id))
    <dl class="mb-2">
        <dt>Discord</dt>
        <dd>
            @if(! $vatssaDiscord['at'])
                <span class="text-muted">Unknown: the role sync bot has not reported recently.</span>
            @elseif($vatssaDiscord['accounts']->isEmpty())
                Not on the VATSSA Discord
            @else
                On the VATSSA Discord as
                {{ $vatssaDiscord['accounts']->map(fn ($a) => '@' . ($a->name ?? $a->discord_id))->implode(', ') }}
                @if($vatssaDiscord['accounts']->count() > 1)<span class="text-danger">(several accounts)</span>@endif
            @endif
            @if($vatssaDiscord['at'])<small class="d-block text-muted">Checked {{ $vatssaDiscord['at']->diffForHumans() }}</small>@endif
        </dd>
    </dl>
    <form method="POST" action="{{ route('vatssa.user.discord-sync', $user->id) }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fab fa-discord"></i> Sync Discord roles</button>
    </form>
    @if($vatssaSync)
        <small class="d-block text-muted mt-1">
            @if($vatssaSync->finished_at)
                Last sync{{ ($vatssaSync->source ?? 'button') === 'auto' ? ' (automatic)' : '' }}: {{ \App\Http\Controllers\VatssaDiscordSyncController::STATUSES[$vatssaSync->status] ?? $vatssaSync->status }},
                {{ \Illuminate\Support\Carbon::parse($vatssaSync->finished_at)->diffForHumans() }}.
                @if($vatssaSync->detail){{ $vatssaSync->detail }}@endif
            @elseif(\Illuminate\Support\Carbon::parse($vatssaSync->created_at)->lt(now()->subMinutes(5)))
                Not picked up by the bot. The bot's 15-minute sweep updates the roles anyway.
            @else
                {{ ($vatssaSync->source ?? 'button') === 'auto' ? 'Change detected' : 'Requested' }} {{ \Illuminate\Support\Carbon::parse($vatssaSync->created_at)->diffForHumans() }}, waiting for the bot.
            @endif
        </small>
    @endif
@endcan
