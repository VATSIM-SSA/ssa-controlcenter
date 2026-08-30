@extends('layouts.vatssa')

@section('title', $poll->title)

@section('content')

<div class="space-y-8">

    {{-- What this is, and what to do. A grid with no sentence above it gets
         filled in wrong by half the people who see it. --}}
    <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <span class="rounded-md bg-brand-50 px-2 py-0.5 text-[11px] font-semibold uppercase
                                 tracking-wide text-brand-700 dark:bg-brand-950/60 dark:text-brand-300">
                        {{ $poll->purposeLabel() }}
                    </span>
                    @unless($poll->isOpen())
                        <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold uppercase
                                     tracking-wide text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                            Confirmed
                        </span>
                    @endunless
                </div>

                <h2 class="mt-2 text-xl font-semibold tracking-tight">{{ $poll->title }}</h2>

                @if($poll->description)
                    <p class="mt-1 max-w-2xl text-sm text-neutral-600 dark:text-neutral-400">
                        {{ $poll->description }}
                    </p>
                @endif

                <p class="mt-3 text-sm text-neutral-500 dark:text-neutral-400">
                    Mark <strong class="text-neutral-700 dark:text-neutral-200">every</strong> time you could
                    make, not just your preferred one. Drag to paint, drag again to erase.
                    More options means a shorter wait.
                </p>
            </div>

            @if($poll->confirmed_slot)
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm
                            dark:border-emerald-900/50 dark:bg-emerald-950/40">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">
                        Confirmed for
                    </p>
                    <p class="mt-0.5 font-semibold tabular-nums text-emerald-900 dark:text-emerald-200">
                        {{ $poll->confirmed_slot->format('D j M') }} ·
                        {{ $poll->confirmed_slot->format('H:i') }}z
                    </p>
                </div>
            @endif
        </div>
    </div>

    @livewire('vatssa.availability-grid', ['poll' => $poll])

    {{-- Who has answered. The thing everybody actually wants to know while
         waiting is "is it me we are waiting for", and a list answers that
         faster than any status badge. --}}
    <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
        <h3 class="text-sm font-semibold tracking-tight">Who has answered</h3>

        @if($poll->responses->isEmpty())
            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">Nobody yet.</p>
        @else
            <ul class="mt-4 space-y-2.5">
                @foreach($poll->responses as $response)
                    <li class="flex items-center justify-between gap-4 text-sm">
                        <span class="flex items-center gap-2.5">
                            <span class="grid h-7 w-7 place-items-center rounded-full bg-neutral-100
                                         text-[11px] font-semibold text-neutral-600
                                         dark:bg-neutral-800 dark:text-neutral-300">
                                {{ Str::of($response->user?->name ?? '?')->explode(' ')->take(2)
                                    ->map(fn ($p) => Str::substr($p, 0, 1))->join('') }}
                            </span>
                            <span>{{ $response->user?->name ?? 'Unknown' }}</span>
                            @if($response->role !== 'participant')
                                <span class="rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium
                                             uppercase tracking-wide text-neutral-500
                                             dark:bg-neutral-800 dark:text-neutral-400">
                                    {{ $response->role }}
                                </span>
                            @endif
                        </span>
                        <span class="tabular-nums text-neutral-500 dark:text-neutral-400">
                            {{ count($response->slots ?? []) }} slots
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- The link. Everything else in the workflow emails this, but somebody
         will always want to paste it into Discord themselves. --}}
    <div class="rounded-xl border border-dashed border-neutral-300 p-4 dark:border-neutral-700">
        <p class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Share this page</p>
        <p class="mt-1 break-all font-mono text-xs text-neutral-700 dark:text-neutral-300">
            {{ route('vatssa.availability.show', $poll) }}
        </p>
    </div>
</div>

@endsection
