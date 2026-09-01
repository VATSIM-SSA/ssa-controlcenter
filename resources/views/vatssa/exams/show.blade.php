@extends('layouts.vatssa')

@section('title', ($exam->training?->user?->name ?? 'Exam') . ' — practical exam')

@section('content')

{{--
    VATSSA: one exam, and the one thing you can do about it.

    ## Only your step is on the page

    Not five panels with four of them disabled. A page that shows every step
    greyed out teaches people to scan past the whole thing looking for the live
    one, and it makes a five-party workflow feel like a form with permissions.
    The rail says where it is; below it there is exactly one action, and it is
    yours or it is nobody's.

    Every action posts to ExamController, which moves the stage one step and
    writes to the training's timeline. See ExamPolicy for why each step is a
    different permission.
--}}
@php
    $tone = fn (string $t) => match ($t) {
        'brand' => 'bg-brand-wash text-brand-strong',
        'good' => 'bg-good-wash text-good',
        'warn' => 'bg-warn-wash text-warn',
        default => 'bg-card-header text-ink-soft',
    };
    $field = 'w-full rounded-lg border border-line bg-card px-3 py-2 text-sm focus:border-brand';
@endphp

<div class="space-y-8">

    {{-- Who and what. --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-2xl font-semibold tracking-tight">
                {{ $exam->training?->user?->name ?? 'Unknown student' }}
            </h2>
            <p class="mt-1 flex flex-wrap items-center gap-x-3 text-sm text-ink-soft">
                <span>{{ $exam->training?->ratings->pluck('name')->join(' + ') ?: 'no rating' }}</span>
                <span aria-hidden="true">·</span>
                <a href="{{ route('training.show', $exam->training_id) }}" class="hover:text-ink">
                    open the training
                </a>
                @if($exam->requester)
                    <span aria-hidden="true">·</span>
                    <span>requested by {{ $exam->requester->name }}</span>
                @endif
            </p>
        </div>

        <span class="rounded-md px-2.5 py-1 text-sm font-medium {{ $tone($exam->stage->tone()) }}">
            {{ $exam->stage->label() }}
        </span>
    </div>

    {{-- The rail. Six stages, and the one it is on. --}}
    <div class="rounded-xl border border-line bg-card p-6">
        <ol class="flex items-start gap-1.5">
            @foreach(\App\Helpers\ExamStage::open() as $stage)
                @php
                    $done = $exam->stage->value >= $stage->value;
                    $here = $exam->stage === $stage;
                @endphp
                <li class="flex-1">
                    <div class="h-1.5 rounded-full
                                {{ $here ? 'bg-brand' : ($done ? 'bg-brand-wash' : 'bg-line') }}"></div>
                    <p class="mt-2 text-[11px] leading-tight
                              {{ $here ? 'font-semibold text-ink' : ($done ? 'text-ink-soft' : 'text-ink-faint') }}">
                        {{ $stage->label() }}
                    </p>
                </li>
            @endforeach
        </ol>

        @if($exam->scheduled_for)
            <p class="mt-5 border-t border-line-soft pt-4 text-sm">
                <span class="font-medium tabular-nums">
                    {{ $exam->scheduled_for->format('l j F Y') }} at
                    {{ $exam->scheduled_for->format('H:i') }}z
                </span>
                @if($exam->examiner)
                    <span class="text-ink-soft">· examined by {{ $exam->examiner->name }}</span>
                @endif
                @if($exam->position)
                    <span class="text-ink-soft">· {{ $exam->position->callsign }}</span>
                @endif
            </p>
        @endif

        @if($exam->noticeBreached())
            <p class="mt-4 rounded-lg bg-bad-wash px-3 py-2 text-sm text-bad">
                This is inside the {{ \App\Models\Vatssa\Exam::NOTICE_DAYS }}-day notice period and
                is not published yet. Everything outstanding has to be finished or the exam is
                postponed.
            </p>
        @endif

        @if($exam->outcome_note)
            <p class="mt-4 text-sm text-ink-soft">{{ $exam->outcome_note }}</p>
        @endif
    </div>

    {{-- ----------------------------------------------------------------
         The one live step.
         ---------------------------------------------------------------- --}}

    @can('authorise', $exam)
        <form method="POST" action="{{ route('vatssa.exams.authorise', $exam) }}"
              class="rounded-xl border border-line bg-card p-6">
            @csrf
            <h3 class="text-sm font-semibold tracking-tight">Authorise this exam</h3>
            <p class="mt-1 max-w-2xl text-sm text-ink-soft">
                The student is asked for their availability as soon as you do. Nothing is
                asked of them before that, so an exam nobody authorised never costs them a
                month of marking a grid.
            </p>
            <button type="submit"
                    class="mt-4 rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white
                           hover:bg-brand-strong">
                Authorise
            </button>
        </form>
    @endcan

    @can('submitAvailability', $exam)
        <div class="space-y-4">
            <div class="rounded-xl border border-line bg-card p-6">
                <h3 class="text-sm font-semibold tracking-tight">When could you sit it?</h3>
                <p class="mt-1 max-w-2xl text-sm text-ink-soft">
                    Mark <strong class="font-medium text-ink">every</strong> time you could make,
                    not just your preferred one. An examiner has to match one of them and so does
                    the division calendar, so more options is a shorter wait.
                    Nothing sooner than {{ \App\Models\Vatssa\Exam::NOTICE_DAYS }} days away can be
                    used, which is why the grid starts when it does.
                </p>
            </div>

            @livewire('vatssa.availability-grid', ['poll' => $exam->poll,
                'role' => \App\Models\Vatssa\AvailabilityPoll::ROLE_STUDENT])

            <form method="POST" action="{{ route('vatssa.exams.submit', $exam) }}">
                @csrf
                <button type="submit"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white
                               hover:bg-brand-strong">
                    I am done — send to the events team
                </button>
            </form>
        </div>
    @endcan

    @can('clear', $exam)
        @if($exam->stage === \App\Helpers\ExamStage::AWAITING_EVENTS)
            <div class="space-y-4">
                <div class="rounded-xl border border-line bg-card p-6">
                    <h3 class="text-sm font-semibold tracking-tight">Which of these times are clear?</h3>
                    <p class="mt-1 max-w-2xl text-sm text-ink-soft">
                        The student's times are shaded underneath. Mark the ones that do not clash
                        with a division plan — <strong class="font-medium text-ink">including the
                        ones not published yet</strong>, which is the whole reason this is a person
                        and not a calendar query.
                    </p>
                    <p class="mt-2 max-w-2xl text-sm text-ink-soft">
                        If none of them work, cancel the exam and say why. Sending an empty list on
                        would leave an examiner staring at nothing with no idea whose turn it is.
                    </p>
                </div>

                @livewire('vatssa.availability-grid', ['poll' => $exam->poll,
                    'role' => \App\Models\Vatssa\AvailabilityPoll::ROLE_EVENTS])

                <form method="POST" action="{{ route('vatssa.exams.clear', $exam) }}">
                    @csrf
                    <button type="submit"
                            class="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white
                                   hover:bg-brand-strong">
                        These are clear — offer them to examiners
                    </button>
                </form>
            </div>
        @else
            {{-- Confirmed: the publishing checklist. Booleans rather than a
                 stage each, because they happen in parallel and any one of them
                 can be the last one outstanding. --}}
            <form method="POST" action="{{ route('vatssa.exams.publish', $exam) }}"
                  class="rounded-xl border border-line bg-card p-6">
                @csrf
                <h3 class="text-sm font-semibold tracking-tight">Publishing</h3>
                <p class="mt-1 text-sm text-ink-soft">
                    All of it has to be done {{ \App\Models\Vatssa\Exam::NOTICE_DAYS }} days before
                    the exam.
                </p>

                <div class="mt-4 space-y-2">
                    @foreach([
                        'banner_made' => 'Banner made',
                        'on_discord' => 'On the Discord calendar',
                        'on_myvatsim' => 'Uploaded to myVATSIM',
                        'on_social' => 'Posted to social media',
                        'vatsim_approved' => 'Approved by VATSIM',
                    ] as $name => $label)
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5
                                      text-sm hover:bg-card-header">
                            <input type="checkbox" name="{{ $name }}" value="1"
                                   @checked($exam->$name)
                                   class="h-4 w-4 rounded border-line text-brand focus:ring-brand">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <button type="submit"
                        class="mt-4 rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white
                               hover:bg-brand-strong">
                    Save
                </button>
            </form>
        @endif
    @endcan

    @can('confirm', $exam)
        <form method="POST" action="{{ route('vatssa.exams.confirm', $exam) }}"
              class="rounded-xl border border-line bg-card p-6">
            @csrf
            <h3 class="text-sm font-semibold tracking-tight">Take this exam</h3>
            <p class="mt-1 max-w-2xl text-sm text-ink-soft">
                These are the times the student can make and the events team have cleared.
                Confirming takes the exam and books the slot in one step — there is no
                claiming it and picking a date later.
            </p>

            @if($slots === [])
                <p class="mt-4 rounded-lg bg-warn-wash px-3 py-2 text-sm text-warn">
                    Nothing here works any more. Every cleared time is now inside the
                    {{ \App\Models\Vatssa\Exam::NOTICE_DAYS }}-day notice period, so the student
                    needs to give fresh availability.
                </p>
            @else
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium">Slot</span>
                        <select name="slot" required class="mt-1.5 {{ $field }}">
                            @foreach($slots as $slot)
                                <option value="{{ $slot }}">
                                    {{ \Carbon\Carbon::parse($slot)->format('D j M Y · H:i') }}z
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium">
                            Position <span class="font-normal text-ink-faint">(optional)</span>
                        </span>
                        <select name="position_id" class="mt-1.5 {{ $field }}">
                            <option value="">Decide later</option>
                            @foreach($positions as $position)
                                <option value="{{ $position->id }}">{{ $position->callsign }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <button type="submit"
                        class="mt-4 rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white
                               hover:bg-brand-strong">
                    Confirm — I will examine this
                </button>
            @endif
        </form>
    @endcan

    {{-- Waiting on somebody else. Said plainly rather than shown as a row of
         disabled buttons, which is a page telling you to try clicking things. --}}
    @if($exam->stage->waitingOn()
        && ! Auth::user()->can('authorise', $exam)
        && ! Auth::user()->can('submitAvailability', $exam)
        && ! Auth::user()->can('clear', $exam)
        && ! Auth::user()->can('confirm', $exam))
        <p class="rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm
                  text-ink-soft">
            Waiting on {{ $exam->stage->waitingOn() }}. Nothing for you to do here yet.
        </p>
    @endif

    {{-- Who has said what. The availability itself, once it exists, so anybody
         can see whether the hold-up is a missing answer or a genuine clash. --}}
    @if($exam->poll)
        <section>
            <h3 class="text-sm font-semibold tracking-tight">Availability</h3>
            <div class="mt-3 rounded-xl border border-line bg-card p-4">
                @forelse($exam->poll->responses as $response)
                    <div class="flex items-center justify-between gap-3 py-1.5 text-sm">
                        <span>
                            {{ $response->user?->name ?? 'Unknown' }}
                            <span class="ml-1 rounded bg-card-header px-1.5 py-0.5 text-[10px]
                                         font-medium uppercase tracking-wide text-ink-soft">
                                {{ $response->role }}
                            </span>
                        </span>
                        <span class="tabular-nums text-ink-soft">
                            {{ count($response->slots ?? []) }} times
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-ink-soft">Nobody has answered yet.</p>
                @endforelse

                <p class="mt-3 border-t border-line-soft pt-3 text-xs text-ink-soft">
                    <strong class="font-medium text-ink">{{ count($exam->offerableSlots()) }}</strong>
                    of those work for the student, are clear of division plans, and are far
                    enough out to be legal.
                </p>
            </div>
        </section>
    @endif

    @can('cancel', $exam)
        <form method="POST" action="{{ route('vatssa.exams.cancel', $exam) }}"
              onsubmit="return confirm('Cancel this exam? Everybody involved will see the reason.')"
              class="rounded-xl border border-bad/40 p-4">
            @csrf
            <p class="text-sm font-medium">Call it off</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <input type="text" name="reason" required maxlength="255"
                       placeholder="Why — everybody involved will see this"
                       class="min-w-0 flex-1 rounded-lg border border-line bg-card px-3 py-2 text-sm
                              focus:border-brand">
                <button type="submit"
                        class="rounded-lg border border-bad/40 px-3 py-2 text-sm font-medium text-bad
                               hover:bg-bad-wash">
                    Cancel exam
                </button>
            </div>
        </form>
    @endcan
</div>

@endsection
