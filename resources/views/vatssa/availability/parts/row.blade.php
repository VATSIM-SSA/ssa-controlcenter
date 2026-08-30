{{--
    One poll, in a list. Expects: $poll.

    The right-hand side answers the only question a list like this needs to:
    is this waiting on me, or on somebody else?
--}}
@php $answered = $poll->responses->contains('user_id', Auth::id()); @endphp

<a href="{{ route('vatssa.availability.show', $poll) }}"
   class="flex items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-white
          px-4 py-3.5 transition-colors hover:border-neutral-300 hover:bg-neutral-50
          dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-neutral-700 dark:hover:bg-neutral-800/50">

    <div class="min-w-0">
        <p class="truncate text-sm font-medium">{{ $poll->title }}</p>
        <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
            {{ $poll->purposeLabel() }}
            @if($poll->training?->user)
                · {{ $poll->training->user->name }}
            @endif
            · {{ $poll->starts_on->format('j M') }}–{{ $poll->ends_on->format('j M') }}
        </p>
    </div>

    <div class="shrink-0 text-right">
        @if($poll->confirmed_slot)
            <p class="text-sm font-medium tabular-nums text-emerald-700 dark:text-emerald-400">
                {{ $poll->confirmed_slot->format('D j M · H:i') }}z
            </p>
        @elseif($answered)
            <span class="text-xs text-neutral-500 dark:text-neutral-400">
                You have answered
            </span>
        @else
            <span class="rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800
                         dark:bg-amber-950/50 dark:text-amber-300">
                Needs you
            </span>
        @endif
    </div>
</a>
