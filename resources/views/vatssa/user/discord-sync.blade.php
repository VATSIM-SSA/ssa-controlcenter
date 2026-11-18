{{-- VATSSA: ask the Discord role sync bot to re-sync this member now. Included from user/show.blade.php. --}}
@can('vatssaDiscordSync')
    <form method="POST" action="{{ route('vatssa.user.discord-sync', $user->id) }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fab fa-discord"></i> Sync Discord roles</button>
    </form>
@endcan
