{{--
    VATSSA: the seven-day roster warning, if one has gone out.

    Split out of `platforms.blade.php` when that card's facts moved into the
    page summary. It did not go with them, and the reason is the distinction
    worth keeping: everything else on that card was a FACT about somebody --
    on Discord, on Moodle, checked at. This is a DEADLINE, and a deadline that
    renders as a row in a definition list stops being one.

    Upstream warns about inactivity roughly four months before a roster place
    lapses, and repeats monthly, which nobody acts on. This is the seven-day
    one. Showing it is what lets staff answer "nobody told me" with a date
    rather than a recollection.

    Expects: $user
--}}
@php $rosterWarning = \App\Models\Vatssa\RosterWarning::find($user->id); @endphp

@if($rosterWarning && $rosterWarning->expires_on->isFuture())
    <div class="alert alert-danger" role="alert">
        <strong><i class="fas fa-hourglass-half"></i>&nbsp;Roster place lapses
            {{ $rosterWarning->expires_on->toEuropeanDate() }}</strong>
        <small class="d-block">
            Warning emailed {{ $rosterWarning->warned_at->diffForHumans() }}.
            Controlling before that date keeps it.
        </small>
    </div>
@endif
