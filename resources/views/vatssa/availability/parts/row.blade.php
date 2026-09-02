{{--
    VATSSA: one poll, in a list. Expects: $poll.

    The right-hand side answers the only question a list like this needs to:
    is this waiting on me, or on somebody else?
--}}
@php $answered = $poll->responses->contains('user_id', Auth::id()); @endphp

<a href="{{ route('vatssa.availability.show', $poll) }}"
   class="list-group-item list-group-item-action d-flex flex-wrap align-items-center justify-content-between gap-3">

    <div class="text-truncate">
        <div class="fw-medium text-truncate">{{ $poll->title }}</div>
        <small class="text-muted">
            {{ $poll->purposeLabel() }}
            @if($poll->training?->user)
                &middot; {{ $poll->training->user->name }}
            @endif
            &middot; {{ $poll->starts_on->format('j M') }}&ndash;{{ $poll->ends_on->format('j M') }}
            &middot; {{ $poll->weekCount() }} {{ \Illuminate\Support\Str::plural('week', $poll->weekCount()) }}
        </small>
    </div>

    <div class="flex-shrink-0 text-end">
        @if($poll->confirmed_slot)
            <span class="badge bg-success">
                {{ $poll->confirmed_slot->format('D j M · H:i') }}z
            </span>
        @elseif($answered)
            <small class="text-muted">You have answered</small>
        @else
            <span class="badge bg-warning text-dark">Needs you</span>
        @endif
    </div>
</a>
