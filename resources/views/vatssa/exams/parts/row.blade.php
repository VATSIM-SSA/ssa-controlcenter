{{--
    One exam in a list. Expects: $exam, $tone, $urgent.

    The right-hand side answers the only question the list is for -- whose turn
    it is -- and the stage pill carries the colour. A row that goes amber
    entirely would make every open exam shout equally, which is the same as none
    of them shouting.
--}}
<a href="{{ route('vatssa.exams.show', $exam) }}"
   class="flex flex-wrap items-center justify-between gap-4 rounded-xl border px-4 py-3.5
          transition-colors hover:border-brand
          {{ $urgent ? 'border-warn/40 bg-warn-wash' : 'border-line bg-card' }}">

    <div class="min-w-0">
        <p class="truncate text-sm font-medium">
            {{ $exam->training?->user?->name ?? 'Unknown student' }}
            <span class="ml-1 text-ink-faint">
                {{ $exam->training?->ratings->pluck('name')->join(' + ') }}
            </span>
        </p>

        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-soft">
            @if($exam->scheduled_for)
                <span class="font-medium tabular-nums text-ink">
                    {{ $exam->scheduled_for->format('D j M · H:i') }}z
                </span>
                <span aria-hidden="true">·</span>
            @endif

            @if($exam->examiner)
                <span>{{ $exam->examiner->name }}</span>
                <span aria-hidden="true">·</span>
            @endif

            <span>raised {{ $exam->created_at?->diffForHumans() }}</span>

            {{-- The one thing that has to shout. A confirmed exam whose
                 paperwork is not done, now inside the notice period, is the
                 failure the seven-day rule exists to prevent -- and it is
                 invisible unless something says so. --}}
            @if($exam->noticeBreached())
                <span aria-hidden="true">·</span>
                <span class="font-medium text-bad">
                    inside {{ \App\Models\Vatssa\Exam::NOTICE_DAYS }} days and not published
                </span>
            @endif
        </p>
    </div>

    <div class="shrink-0 text-right">
        <span class="rounded-md px-2 py-1 text-xs font-medium {{ $tone($exam->stage->tone()) }}">
            {{ $exam->stage->label() }}
        </span>

        @if($exam->stage->waitingOn())
            <p class="mt-1 text-xs text-ink-faint">
                waiting on {{ $exam->stage->waitingOn() }}
            </p>
        @endif
    </div>
</a>
