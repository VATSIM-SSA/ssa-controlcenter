{{--
    VATSSA: Discord and Moodle as two rows inside an existing <dl>.

    ## Why this exists next to `platforms.blade.php`

    The full card answers "can we reach this person" on its own, with the
    check's age and the not-a-VATSIM-member case spelled out. That is the right
    panel when the question is being asked deliberately.

    It is the wrong shape when the question is incidental -- reading a training
    record top to bottom, where "are they on Discord" belongs beside their
    rating and their name, not in a card two screens down. A reader who has to
    go looking treats it as a separate errand and mostly does not.

    So: same facts, two shapes, one source. This renders rows for a <dl> the
    caller already opened; it opens nothing and closes nothing.

    ## The dates

    Nullable, and the line is OMITTED when there is no date rather than printed
    with a "?" after it. Every row that existed before this shipped has no date
    and never will -- the timestamps come off the Discord guild member object
    and the Moodle user record, so they are only knowable from the sweep that
    first asks for them.

    "joined ?" was meant to say "we did not ask", and read as a rendering fault
    on a line that is otherwise two badges. The badge already carries the fact
    that matters -- on Discord or not -- and the "last updated" line below says
    how old that answer is, which is the thing a "?" was reaching for.

    Expects: $user
--}}
@php
    $platforms = \App\Models\Vatssa\UserPlatform::find($user->id);
@endphp

<dt class="pt-2">Platforms</dt>
<dd class="separator pb-3">
    @if($platforms === null)
        <span class="badge bg-secondary">Not checked</span>
        <small class="text-muted d-block">The pipeline writes this on its daily sweep.</small>
    @elseif(! $platforms->vatsim_member)
        <span class="badge bg-secondary">Not a VATSIM member</span>
        <small class="text-muted d-block">Discord account resolves to no CID.</small>
    @else
        <div>
            @if($platforms->on_discord)
                <span class="badge bg-success"><i class="fas fa-check"></i> Discord</span>
            @else
                <span class="badge bg-danger"><i class="fas fa-xmark"></i> Discord</span>
            @endif
            @if($platforms->discord_joined_at)
                <small class="text-muted">joined {{ $platforms->discord_joined_at->toEuropeanDate() }}</small>
            @endif
        </div>

        <div class="mt-1">
            @if($platforms->on_moodle)
                <span class="badge bg-success"><i class="fas fa-check"></i> Moodle</span>
            @else
                <span class="badge bg-danger"><i class="fas fa-xmark"></i> Moodle</span>
            @endif
            @if($platforms->moodle_registered_at)
                <small class="text-muted">registered {{ $platforms->moodle_registered_at->toEuropeanDate() }}</small>
            @endif
        </div>

        {{-- The age of the check, kept.
             The sweep is daily, so a flat "not on Discord" from a stale check
             is worse than no answer at all -- a reader who can see the age can
             judge it, and one who cannot will quote it. --}}
        @if($platforms->checked_at)
            <small class="{{ $platforms->isStale() ? 'text-warning' : 'text-muted' }} d-block mt-1">
                <i class="fas fa-rotate"></i>
                {{-- The @endif goes on its own line. Glued to a word --
                     `recently@endif` -- Blade does not see a directive at all,
                     leaves it as literal text, and the compiled view ends with
                     an unclosed if: "syntax error, unexpected end of file".
                     It took out 41 view tests and never showed up in
                     `view:cache`, which compiled the broken file quite happily. --}}
                Last updated {{ $platforms->checked_at->diffForHumans() }}
                @if($platforms->isStale())
                    &mdash; the daily check has not run recently
                @endif
            </small>
        @endif
    @endif
</dd>
