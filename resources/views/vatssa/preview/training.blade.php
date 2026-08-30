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
                      text-neutral-500 dark:text-neutral-400">
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
           class="rounded-lg bg-neutral-100 px-3 py-1.5 text-sm font-medium text-neutral-700
                  transition-colors hover:bg-neutral-200
                  dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700">
            Open the real page
        </a>
    </div>

    {{-- The rail. --}}
    <div class="rounded-xl border border-neutral-200 bg-white p-6
                dark:border-neutral-800 dark:bg-neutral-900">
        @if($training->status->isClosed())
            <p class="text-sm">
                <span class="rounded-md bg-neutral-100 px-2 py-1 text-xs font-medium
                             text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                    {{ $training->status->label() }}
                </span>
                @if($training->closed_reason)
                    <span class="ml-2 text-neutral-600 dark:text-neutral-400">
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
                                    {{ $here ? 'bg-brand-500' : ($done ? 'bg-brand-200 dark:bg-brand-900' : 'bg-neutral-200 dark:bg-neutral-800') }}"></div>
                        <p class="mt-2 text-[11px] leading-tight
                                  {{ $here ? 'font-semibold text-neutral-900 dark:text-neutral-100'
                                           : ($done ? 'text-neutral-600 dark:text-neutral-400'
                                                    : 'text-neutral-400 dark:text-neutral-600') }}">
                            {{ $stage->label() }}
                        </p>
                    </li>
                @endforeach
            </ol>

            @if($training->paused_at)
                <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900
                          dark:bg-amber-950/40 dark:text-amber-200">
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
                <p class="mt-3 rounded-xl border border-dashed border-neutral-300 px-4 py-12
                          text-center text-sm text-neutral-500
                          dark:border-neutral-700 dark:text-neutral-400">
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
                                             bg-neutral-200 dark:bg-neutral-800"></span>
                            @endunless

                            <span class="relative z-10 mt-0.5 grid h-6 w-6 shrink-0 place-items-center
                                         rounded-full border border-neutral-200 bg-white
                                         dark:border-neutral-700 dark:bg-neutral-900">
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

                                <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ $activity->created_at?->diffForHumans() }}
                                    @if($activity->triggered_by_id)
                                        · {{ \App\Models\User::find($activity->triggered_by_id)?->name ?? 'Somebody' }}
                                    @else
                                        {{-- A null actor is how you tell the pipeline
                                             did this rather than a person. --}}
                                        · <span class="text-neutral-400 dark:text-neutral-600">system</span>
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
                <div class="mt-3 rounded-xl border border-neutral-200 bg-white p-4
                            dark:border-neutral-800 dark:bg-neutral-900">
                    @forelse($training->mentors as $mentor)
                        <a href="{{ route('vatssa.preview.profile', $mentor) }}"
                           class="flex items-center gap-2.5 py-1.5 text-sm hover:text-brand-600
                                  dark:hover:text-brand-400">
                            <span class="grid h-7 w-7 place-items-center rounded-full bg-neutral-100
                                         text-[11px] font-semibold text-neutral-600
                                         dark:bg-neutral-800 dark:text-neutral-300">
                                {{ Str::of($mentor->name)->explode(' ')->take(2)
                                    ->map(fn ($p) => Str::substr($p, 0, 1))->join('') }}
                            </span>
                            {{ $mentor->name }}
                        </a>
                    @empty
                        <p class="text-sm text-amber-700 dark:text-amber-400">
                            Nobody is mentoring this student.
                        </p>
                    @endforelse
                </div>
            </section>

            <section>
                <h3 class="text-sm font-semibold tracking-tight">Requests</h3>
                <div class="mt-3 space-y-2">
                    @forelse($tasks as $task)
                        <div class="rounded-xl border border-neutral-200 bg-white px-4 py-3
                                    dark:border-neutral-800 dark:bg-neutral-900">
                            <p class="text-sm font-medium">{{ $task->type()->getName() }}</p>
                            @if($task->message)
                                <p class="mt-0.5 text-xs text-neutral-600 dark:text-neutral-400">
                                    {{ $task->message }}
                                </p>
                            @endif
                            <p class="mt-1.5 text-xs text-neutral-500 dark:text-neutral-400">
                                {{ $task->creator?->name ?? 'System' }}
                                · {{ $task->created_at?->diffForHumans() }}
                            </p>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-neutral-300 px-4 py-6
                                  text-center text-sm text-neutral-500
                                  dark:border-neutral-700 dark:text-neutral-400">
                            None.
                        </p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    @include('vatssa.preview.parts.notice')
</div>

@endsection
