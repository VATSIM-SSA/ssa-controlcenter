@extends('layouts.vatssa')

@section('title', 'Automation log')

@section('content')

{{--
    VATSSA: what the automation did, and what it noticed and left alone.

    Converted from Bootstrap. This is one of our own pages, so it costs no
    merge conflict -- see layouts/vatssa.blade.php for why that decides which
    pages get converted and which do not.

    The design carries one idea: the WARNINGS are the page. Everything else is
    reference. The Bootstrap version gave the filter buttons, the card header
    and the table header equal weight, so "nothing needs a look" and "eleven
    things need a look" looked identical at a glance.
--}}
<div class="space-y-6">

    {{-- The headline number, not a filter bar. Somebody opening this page is
         asking one question and it should be answered before they read
         anything else. --}}
    <div class="flex flex-wrap items-end justify-between gap-6">
        <div>
            @if($openWarnings)
                <p class="text-3xl font-semibold tabular-nums tracking-tight text-amber-600 dark:text-amber-400">
                    {{ $openWarnings }}
                </p>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                    {{ Str::plural('thing', $openWarnings) }} the automation noticed and could not fix,
                    in the last 30 days.
                </p>
            @else
                <p class="text-3xl font-semibold tracking-tight text-emerald-600 dark:text-emerald-400">
                    All clear
                </p>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                    Nothing noticed in the last 30 days. That is the state you want this page in.
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @foreach([
                ['warning', 'Needs a look'],
                ['info', 'Actions taken'],
                ['all', 'Everything'],
            ] as [$value, $label])
                <a href="{{ route('vatssa.action-log', ['level' => $value]) }}"
                   class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors
                          {{ $level === $value
                              ? 'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900'
                              : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700' }}">
                    {{ $label }}
                </a>
            @endforeach

            @if($kinds->count())
                <form method="GET" class="ml-1">
                    <input type="hidden" name="level" value="{{ $level }}">
                    <select name="action" onchange="this.form.submit()"
                            class="rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm
                                   dark:border-neutral-700 dark:bg-neutral-900">
                        <option value="">Every kind</option>
                        @foreach($kinds as $kind)
                            <option value="{{ $kind }}" @selected($action === $kind)>{{ $kind }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>
    </div>

    <p class="max-w-3xl text-sm text-neutral-600 dark:text-neutral-400">
        Everything the pipeline does on its own, and everything it noticed and
        deliberately left alone — an empty request desk, a rating with no Moodle
        course, a student waiting on a CPT with no mentor.
        <span class="text-neutral-800 dark:text-neutral-200">The second kind is why this page exists.</span>
        Software that stays quiet about what it cannot handle is worse than
        software that does nothing, because silence reads as fine.
    </p>

    @if($entries->isEmpty())
        <p class="rounded-xl border border-dashed border-neutral-300 px-4 py-16 text-center text-sm
                  text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
            Nothing logged{{ $action ? ' of that kind' : '' }}.
        </p>
    @else
        {{-- A feed, not a table. These entries are prose of different lengths
             about different subjects, and forcing them into four columns of
             equal width makes the short ones look padded and the long ones
             wrap into noise. --}}
        <ol class="overflow-hidden rounded-xl border border-neutral-200 bg-white
                   dark:border-neutral-800 dark:bg-neutral-900">
            @foreach($entries as $entry)
                @php $warned = $entry->level === \App\Models\Vatssa\ActionLog::WARNING; @endphp

                <li class="flex gap-4 border-b border-neutral-100 px-5 py-4 last:border-0
                           dark:border-neutral-800">

                    <span class="mt-0.5 shrink-0 {{ $warned ? 'text-amber-500' : 'text-emerald-500' }}">
                        @include('vatssa.parts.icon', ['name' => $warned ? 'warning' : 'check'])
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm {{ $warned ? 'font-medium' : '' }}">{{ $entry->summary }}</p>

                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs
                                    text-neutral-500 dark:text-neutral-400">
                            <span title="{{ $entry->created_at->toEuropeanDateTime() }}">
                                {{ $entry->created_at->diffForHumans() }}
                            </span>

                            <span aria-hidden="true">·</span>
                            <span>{{ $entry->actorLabel() }}</span>

                            @if($entry->user)
                                <span aria-hidden="true">·</span>
                                <a href="{{ route('user.show', $entry->user) }}"
                                   class="hover:text-neutral-800 dark:hover:text-neutral-200">
                                    {{ $entry->user->name }}
                                </a>
                            @endif

                            @if($entry->training_id)
                                <span aria-hidden="true">·</span>
                                <a href="{{ route('training.show', $entry->training_id) }}"
                                   class="hover:text-neutral-800 dark:hover:text-neutral-200">
                                    training #{{ $entry->training_id }}
                                </a>
                            @endif

                            {{-- The machine-readable key, last and quiet. It is
                                 what you filter by, not what you read. --}}
                            <span aria-hidden="true">·</span>
                            <code class="font-mono text-[11px] text-neutral-400 dark:text-neutral-600">
                                {{ $entry->action }}
                            </code>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>

@endsection
