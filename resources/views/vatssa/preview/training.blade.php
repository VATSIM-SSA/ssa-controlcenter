@extends('layouts.vatssa')

@section('title', $training->user?->name ?? 'Training')

@section('content')

{{--
    The training page, mirrored.

    This is the page a coordinator spends their day on, the densest in the
    application, and the one the timeline lives on. If a migration would not
    improve this page it is not worth doing, so it is the one to judge.

    Three things changed, and none of them is a colour:

    THE STAGE IS A RAIL, not a word in a box. "Awaiting mentor" means nothing
    to somebody on their first training; a position on a line does, and it also
    shows what comes next without anybody having to know the sequence.

    THE TIMELINE IS THE PAGE. On the real one it is the last card, below the
    panels, so the thing you came to read is the thing you scroll past
    everything else to reach.

    PANELS ARE SECTIONS, not cards with solid headers. Nine cards each shouting
    in the primary colour is the same as none of them shouting.

    Read only. Every action links back to the real page -- see PreviewController.
--}}
<div class="space-y-8">

    {{-- Who, and where they are. --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-2xl font-semibold tracking-tight">
                {{ $training->user?->name ?? 'Unknown' }}
            </h2>
            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm
 text-ink-soft">
                <span class="tabular-nums">{{ $training->user_id }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ $training->ratings->pluck('name')->join(' + ') ?: 'no rating' }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ $training->area?->name ?? 'no area' }}</span>
                <span aria-hidden="true">·</span>
                <span>opened {{ $training->created_at?->format('j M Y') }}</span>
            </p>
        </div>

        <a href="{{ route('training.show', $training) }}"
           class="rounded-lg bg-card-header px-3 py-1.5 text-sm font-medium text-ink
 transition-colors hover:bg-line">
            Open the real page
        </a>
    </div>

    {{-- The rail. --}}
    <div class="rounded-xl border border-line bg-card p-6">
        @if($training->status->isClosed())
            <p class="text-sm">
                <span class="rounded-md bg-card-header px-2 py-1 text-xs font-medium
 text-ink-soft">
                    {{ $training->status->label() }}
                </span>
                @if($training->closed_reason)
                    <span class="ml-2 text-ink-soft">
                        {{ $training->closed_reason }}
                    </span>
                @endif
            </p>
        @else
            <ol class="flex items-start gap-1.5">
                @foreach(\App\Helpers\TrainingStatus::inLifecycleOrder() as $stage)
                    @continue($stage->isClosed())
                    @php
                        $order = $training->status->lifecycleOrder();
                        $done = $order >= $stage->lifecycleOrder();
                        $here = $training->status === $stage;
                    @endphp
                    <li class="flex-1">
                        <div class="h-1.5 rounded-full
 {{ $here ? 'bg-brand' : ($done ? 'bg-brand-wash' : 'bg-line') }}"></div>
                        <p class="mt-2 text-[11px] leading-tight
 {{ $here ? 'font-semibold text-ink'
                                           : ($done ? 'text-ink-soft'
                                                    : 'text-ink-faint') }}">
                            {{ $stage->label() }}
                        </p>
                    </li>
                @endforeach
            </ol>

            @if($training->paused_at)
                <p class="mt-4 rounded-lg bg-warn-wash px-3 py-2 text-sm text-warn">
                    Paused since {{ $training->paused_at->format('j M Y') }} — the 90-day clock is frozen.
                </p>
            @endif
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- The timeline, first and widest. It is what people come for. --}}
        <section class="lg:col-span-2">
            <h3 class="text-sm font-semibold tracking-tight">Timeline</h3>

            @if($activities->isEmpty())
                <p class="mt-3 rounded-xl border border-dashed border-line px-4 py-12
 text-center text-sm text-ink-soft">
                    Nothing recorded yet.
                </p>
            @else
                <ol class="mt-3 space-y-0">
                    @foreach($activities as $activity)
                        <li class="relative flex gap-4 pb-6 last:pb-0">
                            {{-- The rail behind the dots, stopped before the
                                 last one so the line does not dangle. --}}
                            @unless($loop->last)
                                <span class="absolute left-[11px] top-6 h-full w-px
 bg-line"></span>
                            @endunless

                            <span class="relative z-10 mt-0.5 grid h-6 w-6 shrink-0 place-items-center
 rounded-full border border-line bg-card">
                                @include('vatssa.parts.icon', [
                                    'name' => match ($activity->type) {
                                        'STATUS', 'TYPE' => 'clock',
                                        'MENTOR' => 'users',
                                        'PAUSE' => 'clock',
                                        'COMMENT' => 'check',
                                        default => 'check',
                                    },
                                ])
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm">
                                    @switch($activity->type)
                                        @case('STATUS')
                                            Moved to
                                            <span class="font-medium">
                                                {{ \App\Helpers\TrainingStatus::tryFrom((int) $activity->new_data)?->label() ?? '?' }}
                                            </span>
                                            @if($activity->comment)
                                                — {{ $activity->comment }}
                                            @endif
                                            @break

                                        @case('MENTOR')
                                            @if($activity->new_data)
                                                <span class="font-medium">
                                                    {{ \App\Models\User::find($activity->new_data)?->name ?? 'Somebody' }}
                                                </span> assigned as mentor
                                            @else
                                                {{-- ->name on a find() that returns null is a
                                                     500 on the real page. A deleted mentor
                                                     should not take the timeline down. --}}
                                                <span class="font-medium">
                                                    {{ \App\Models\User::find($activity->old_data)?->name ?? 'A mentor' }}
                                                </span> removed as mentor
                                            @endif
                                            @break

                                        @case('PAUSE')
                                            {{ $activity->new_data ? 'Paused' : 'Resumed' }}
                                            @break

                                        @default
                                            {{ $activity->comment ?? $activity->type }}
                                    @endswitch
                                </p>

                                <p class="mt-0.5 text-xs text-ink-soft">
                                    {{ $activity->created_at?->diffForHumans() }}
                                    @if($activity->triggered_by_id)
                                        · {{ \App\Models\User::find($activity->triggered_by_id)?->name ?? 'Somebody' }}
                                    @else
                                        {{-- A null actor is how you tell the pipeline
                                             did this rather than a person. --}}
                                        · <span class="text-ink-faint">system</span>
                                    @endif
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <div class="space-y-6">

            <section>
                <h3 class="text-sm font-semibold tracking-tight">Mentors</h3>
                <div class="mt-3 rounded-xl border border-line bg-card p-4">
                    @forelse($training->mentors as $mentor)
                        <a href="{{ route('vatssa.preview.profile', $mentor) }}"
                           class="flex items-center gap-2.5 py-1.5 text-sm hover:text-brand-strong">
                            <span class="grid h-7 w-7 place-items-center rounded-full bg-card-header
 text-[11px] font-semibold text-ink-soft">
                                {{ Str::of($mentor->name)->explode(' ')->take(2)
                                    ->map(fn ($p) => Str::substr($p, 0, 1))->join('') }}
                            </span>
                            {{ $mentor->name }}
                        </a>
                    @empty
                        <p class="text-sm text-warn">
                            Nobody is mentoring this student.
                        </p>
                    @endforelse
                </div>
            </section>

            <section>
                <h3 class="text-sm font-semibold tracking-tight">Requests</h3>
                <div class="mt-3 space-y-2">
                    @forelse($tasks as $task)
                        <div class="rounded-xl border border-line bg-card px-4 py-3">
                            <p class="text-sm font-medium">{{ $task->type()->getName() }}</p>
                            @if($task->message)
                                <p class="mt-0.5 text-xs text-ink-soft">
                                    {{ $task->message }}
                                </p>
                            @endif
                            <p class="mt-1.5 text-xs text-ink-soft">
                                {{ $task->creator?->name ?? 'System' }}
                                · {{ $task->created_at?->diffForHumans() }}
                            </p>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-line px-4 py-6
 text-center text-sm text-ink-soft">
                            None.
                        </p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>


    {{-- ---------------------------------------------------------------
         Everything else the real page carries.

         The mirror was missing most of it: the application, reports and
         examinations, interest confirmations, the theory results, the platform
         ticks and the message log. A preview of the training page that leaves
         out two thirds of the training page cannot answer whether a migration
         would be an improvement -- the whole question is whether it still works
         when it is dense.

         The VATSSA panels are re-rendered here rather than @included from
         resources/views/vatssa/parts/. Those partials render inside a Bootstrap
         page that loads app.scss, and this page deliberately does not.
         --------------------------------------------------------------- --}}

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="lg:col-span-2 space-y-6">

            {{-- Reports and examinations, one list. They are one story about a
                 student, and two lists means reading it in two passes. --}}
            <div>
                <h3 class="text-sm font-semibold tracking-tight">Reports and examinations</h3>

                @forelse($reportsAndExams as $item)
                    @php $isExam = $item instanceof \App\Models\TrainingExamination; @endphp
                    <div class="mt-3 rounded-xl border border-line bg-card p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <p class="text-sm font-medium">
                                {{ $isExam ? 'Examination' : 'Training report' }}
                                @if($isExam && $item->position)
                                    <span class="ml-1 font-mono text-xs text-ink-faint">
                                        {{ $item->position->callsign }}
                                    </span>
                                @endif
                            </p>
                            <p class="text-xs text-ink-soft">
                                {{ optional($isExam ? $item->examination_date : $item->report_date)
                                    ? \Carbon\Carbon::parse($isExam ? $item->examination_date : $item->report_date)->format('j M Y')
                                    : '—' }}
                                · {{ $isExam ? ($item->examiner?->name ?? 'examiner unknown')
                                             : ($item->author?->name ?? 'author unknown') }}
                            </p>
                        </div>

                        @if($isExam && $item->result)
                            <p class="mt-2">
                                <span class="rounded-md px-2 py-1 text-xs font-medium
                                    {{ \Illuminate\Support\Str::contains(strtolower($item->result), 'pass')
                                        ? 'bg-good-wash text-good' : 'bg-warn-wash text-warn' }}">
                                    {{ $item->result }}
                                </span>
                            </p>
                        @endif

                        @if(! $isExam && $item->contentimprove)
                            <p class="mt-2 whitespace-pre-line text-sm text-ink-soft">{{ $item->contentimprove }}</p>
                        @endif

                        @if($item->draft ?? false)
                            <p class="mt-2 text-xs text-warn">Draft — not yet filed.</p>
                        @endif
                    </div>
                @empty
                    <p class="mt-3 rounded-xl border border-dashed border-line px-4 py-8
                              text-center text-sm text-ink-soft">
                        No reports or examinations yet.
                    </p>
                @endforelse
            </div>

            {{-- The application. Written once, at the start, and read whenever
                 somebody asks why this person is training. --}}
            <div>
                <h3 class="text-sm font-semibold tracking-tight">Application</h3>
                <div class="mt-3 space-y-4 rounded-xl border border-line bg-card p-5">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-ink-faint">Type</p>
                        <p class="mt-1 text-sm">
                            {{ $types[$training->type]['text'] ?? 'Unknown' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-ink-faint">Motivation</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-ink-soft">
                            {{ $training->motivation ?: 'Nothing written.' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-ink-faint">
                            English only
                        </p>
                        <p class="mt-1 text-sm text-ink-soft">
                            {{ $training->english_only_training ? 'Yes' : 'No' }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- The email log. Control Center cannot see what its own mailer
                 sent; this is the only place the question "what has this
                 student actually been told" has an answer. --}}
            <div>
                <h3 class="text-sm font-semibold tracking-tight">Emails sent</h3>

                @if($messages->isEmpty())
                    <p class="mt-3 rounded-xl border border-dashed border-line px-4 py-8
                              text-center text-sm text-ink-soft">
                        Nothing logged.
                    </p>
                @else
                    <ul class="mt-3 divide-y divide-line-soft overflow-hidden rounded-xl
                               border border-line bg-card">
                        @foreach($messages as $message)
                            <li class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3">
                                <span class="min-w-0 text-sm">{{ $message->subject }}</span>
                                <span class="text-xs text-ink-soft">
                                    {{ $message->kind }}
                                    · {{ $message->source }}
                                    · {{ \Carbon\Carbon::parse($message->sent_at)->format('j M Y') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        <div class="space-y-6">

            {{-- Theory. Keyed to person plus rating, never to a training -- a
                 result owned by a training dies with it, and the person still
                 knows the material. --}}
            <section>
                <h3 class="text-sm font-semibold tracking-tight">Theory</h3>
                <div class="mt-3 rounded-xl border border-line bg-card p-4">
                    @forelse($attempts as $attempt)
                        <div class="flex items-center justify-between gap-3 py-1.5 text-sm">
                            <span>
                                {{ $attempt->rating }}
                                <span class="ml-1 text-xs text-ink-faint">
                                    {{ $attempt->taken_at?->format('j M Y') }}
                                </span>
                            </span>
                            <span class="rounded-md px-2 py-0.5 text-xs font-medium
                                {{ $attempt->passed ? 'bg-good-wash text-good' : 'bg-warn-wash text-warn' }}">
                                {{ $attempt->passed ? 'Pass' : 'Fail' }}
                                @can('training.results.grades')
                                    · {{ $attempt->grade }}%
                                @endcan
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-ink-soft">No attempts recorded.</p>
                    @endforelse

                    @if($attempts->isNotEmpty())
                        <p class="mt-3 border-t border-line-soft pt-3 text-xs text-ink-soft">
                            The <strong>latest</strong> attempt decides. A pass followed by a
                            failed retake is not currently a pass.
                        </p>
                    @endif
                </div>
            </section>

            {{-- Platforms. Control Center has no concept of Discord and cannot
                 see Moodle; without this the commonest stall in the pipeline is
                 invisible. --}}
            <section>
                <h3 class="text-sm font-semibold tracking-tight">Platforms</h3>
                <div class="mt-3 space-y-2 rounded-xl border border-line bg-card p-4 text-sm">
                    @php
                        $tick = fn ($on) => $on
                            ? '<span class="text-good">yes</span>'
                            : '<span class="text-warn">no</span>';
                    @endphp

                    <div class="flex justify-between gap-4">
                        <span class="text-ink-soft">On Discord</span>
                        <span>{!! $tick($platforms?->on_discord) !!}</span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-ink-soft">On Moodle</span>
                        <span>{!! $tick($platforms?->on_moodle) !!}</span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-ink-soft">Enrolment</span>
                        <span class="{{ $platforms?->isEnrolled() ? '' : 'text-warn' }}">
                            {{ $platforms?->enrolmentLabel() ?? 'never checked' }}
                        </span>
                    </div>

                    @unless($platforms?->checked_at)
                        <p class="border-t border-line-soft pt-2 text-xs text-ink-soft">
                            The bot has never looked at this member. That is different from
                            "they are not there".
                        </p>
                    @endunless
                </div>
            </section>

            {{-- Interest confirmations. The 90-day nudge that closes a training
                 nobody answered. --}}
            <section>
                <h3 class="text-sm font-semibold tracking-tight">Interest confirmations</h3>
                <div class="mt-3 rounded-xl border border-line bg-card p-4">
                    @forelse($interests as $interest)
                        @php
                            // `expired` is the InterestStatus enum, not a
                            // boolean -- the column is named for what it was
                            // before it became a three-state.
                            $answered = $interest->confirmed_at !== null;
                            $overdue = ! $answered && $interest->deadline?->isPast();
                        @endphp
                        <div class="flex items-center justify-between gap-3 py-1.5 text-sm">
                            <span class="text-ink-soft">
                                asked {{ $interest->created_at?->format('j M Y') }}
                            </span>
                            <span class="rounded-md px-2 py-0.5 text-xs font-medium
                                {{ $answered ? 'bg-good-wash text-good'
                                             : ($overdue ? 'bg-warn-wash text-warn' : 'bg-card-header text-ink-soft') }}">
                                {{ $answered ? 'Confirmed' : ($overdue ? 'No answer' : 'Waiting') }}
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-ink-soft">None requested.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    @include('vatssa.preview.parts.notice')
</div>

@endsection
