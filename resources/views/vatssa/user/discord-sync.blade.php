{{-- VATSSA: ask the Discord role sync bot to re-sync this member now, and show how the last request ended. Included from user/show.blade.php. --}}
@can('vatssaDiscordSync')
    @php($vatssaSync = \App\Http\Controllers\VatssaDiscordSyncController::latestFor($user->id))
    <form method="POST" action="{{ route('vatssa.user.discord-sync', $user->id) }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fab fa-discord"></i> Sync Discord roles</button>
    </form>
    @if($vatssaSync)
        <small class="d-block text-muted mt-1">
            @if($vatssaSync->finished_at)
                Last sync: {{ \App\Http\Controllers\VatssaDiscordSyncController::STATUSES[$vatssaSync->status] ?? $vatssaSync->status }},
                {{ \Illuminate\Support\Carbon::parse($vatssaSync->finished_at)->diffForHumans() }}.
                @if($vatssaSync->detail){{ $vatssaSync->detail }}@endif
            @else
                Requested {{ \Illuminate\Support\Carbon::parse($vatssaSync->created_at)->diffForHumans() }}, waiting for the bot.
            @endif
        </small>
    @endif
@endcan
