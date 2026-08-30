@extends('layouts.vatssa')

@section('title', $poll->title)

@section('content')

<div class="space-y-8">

    {{-- What this is, and what to do. A grid with no sentence above it gets
         filled in wrong by half the people who see it. --}}
    <div class="rounded-xl border border-line bg-card p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <span class="rounded-md bg-brand-wash px-2 py-0.5 text-[11px] font-semibold uppercase
 tracking-wide text-brand-strong">
                        {{ $poll->purposeLabel() }}
                    </span>
                    @unless($poll->isOpen())
                        <span class="rounded-md bg-good-wash px-2 py-0.5 text-[11px] font-semibold uppercase
 tracking-wide text-good">
                            Confirmed
                        </span>
                    @endunless
                </div>

                <h2 class="mt-2 text-xl font-semibold tracking-tight">{{ $poll->title }}</h2>

                @if($poll->description)
                    <p class="mt-1 max-w-2xl text-sm text-ink-soft">
                        {{ $poll->description }}
                    </p>
                @endif

                <p class="mt-3 text-sm text-ink-soft">
                    Mark <strong class="text-ink">every</strong> time you could
                    make, not just your preferred one. Drag to paint, drag again to erase.
                    More options means a shorter wait.
                </p>
            </div>

            @if($poll->confirmed_slot)
                <div class="rounded-lg border border-good/40 bg-good-wash px-4 py-3 text-sm">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-good">
                        Confirmed for
                    </p>
                    <p class="mt-0.5 font-semibold tabular-nums text-good">
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
    <div class="rounded-xl border border-line bg-card p-6">
        <h3 class="text-sm font-semibold tracking-tight">Who has answered</h3>

        @if($poll->responses->isEmpty())
            <p class="mt-2 text-sm text-ink-soft">Nobody yet.</p>
        @else
            <ul class="mt-4 space-y-2.5">
                @foreach($poll->responses as $response)
                    <li class="flex items-center justify-between gap-4 text-sm">
                        <span class="flex items-center gap-2.5">
                            <span class="grid h-7 w-7 place-items-center rounded-full bg-card-header
 text-[11px] font-semibold text-ink-soft">
                                {{ Str::of($response->user?->name ?? '?')->explode(' ')->take(2)
                                    ->map(fn ($p) => Str::substr($p, 0, 1))->join('') }}
                            </span>
                            <span>{{ $response->user?->name ?? 'Unknown' }}</span>
                            @if($response->role !== 'participant')
                                <span class="rounded bg-card-header px-1.5 py-0.5 text-[10px] font-medium
 uppercase tracking-wide text-ink-soft">
                                    {{ $response->role }}
                                </span>
                            @endif
                        </span>
                        <span class="tabular-nums text-ink-soft">
                            {{ count($response->slots ?? []) }} slots
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- The link. Everything else in the workflow emails this, but somebody
         will always want to paste it into Discord themselves. --}}
    <div class="rounded-xl border border-dashed border-line p-4">
        <p class="text-xs font-medium text-ink-soft">Share this page</p>
        <p class="mt-1 break-all font-mono text-xs text-ink">
            {{ route('vatssa.availability.show', $poll) }}
        </p>
    </div>
</div>

@endsection
