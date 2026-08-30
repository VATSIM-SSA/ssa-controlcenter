{{--
    One poll, in a list. Expects: $poll.

    The right-hand side answers the only question a list like this needs to:
    is this waiting on me, or on somebody else?
--}}
@php $answered = $poll->responses->contains('user_id', Auth::id()); @endphp

<a href="{{ route('vatssa.availability.show', $poll) }}"
   class="flex items-center justify-between gap-4 rounded-xl border border-line bg-card
 px-4 py-3.5 transition-colors hover:border-brand hover:bg-card-header">

    <div class="min-w-0">
        <p class="truncate text-sm font-medium">{{ $poll->title }}</p>
        <p class="mt-0.5 text-xs text-ink-soft">
            {{ $poll->purposeLabel() }}
            @if($poll->training?->user)
                · {{ $poll->training->user->name }}
            @endif
            · {{ $poll->starts_on->format('j M') }}–{{ $poll->ends_on->format('j M') }}
        </p>
    </div>

    <div class="shrink-0 text-right">
        @if($poll->confirmed_slot)
            <p class="text-sm font-medium tabular-nums text-good">
                {{ $poll->confirmed_slot->format('D j M · H:i') }}z
            </p>
        @elseif($answered)
            <span class="text-xs text-ink-soft">
                You have answered
            </span>
        @else
            <span class="rounded-md bg-warn-wash px-2 py-1 text-xs font-medium text-warn">
                Needs you
            </span>
        @endif
    </div>
</a>
