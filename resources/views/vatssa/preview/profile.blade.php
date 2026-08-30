@extends('layouts.vatssa')

@section('title', $user->name)

@section('content')

{{--
    The profile, as it would look migrated.

    The current one is a column of cards, each with a solid primary header, so
    the page reads as a stack of equally important boxes. Here the person is the
    header and everything else is a section under it -- which is what a profile
    is.
--}}
<div class="space-y-8">

    <div class="flex flex-wrap items-center gap-5">
        <span class="grid h-16 w-16 place-items-center rounded-2xl bg-card-header text-lg font-semibold
 text-ink-soft">
            {{ Str::of($user->name)->explode(' ')->take(2)->map(fn ($p) => Str::substr($p, 0, 1))->join('') }}
        </span>

        <div class="min-w-0">
            <h2 class="text-2xl font-semibold tracking-tight">{{ $user->name }}</h2>
            <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm
 text-ink-soft">
                <span class="tabular-nums">{{ $user->id }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ $user->rating?->name ?? 'No rating' }}</span>
                @if($user->created_at)
                    <span aria-hidden="true">·</span>
                    <span>joined {{ $user->created_at->format('M Y') }}</span>
                @endif
            </p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="lg:col-span-2 space-y-3">
            <h3 class="text-sm font-semibold tracking-tight">Trainings</h3>

            @forelse($trainings as $training)
                {{-- Stays inside the mirror. Bouncing to the Bootstrap page
                     halfway through looking at the preview is how you end up
                     judging the wrong thing. --}}
                <a href="{{ route('vatssa.preview.training', $training) }}"
                   class="flex items-center justify-between gap-4 rounded-xl border border-line
 bg-card px-4 py-3.5 transition-colors hover:border-brand">
                    <div class="min-w-0">
                        <p class="text-sm font-medium">
                            {{ $training->ratings->pluck('name')->join(' + ') ?: 'Training' }}
                        </p>
                        <p class="mt-0.5 text-xs text-ink-soft">
                            opened {{ $training->created_at?->format('j M Y') }}
                        </p>
                    </div>
                    <span class="shrink-0 rounded-md bg-card-header px-2 py-1 text-xs font-medium
 text-ink-soft">
                        {{ $training->status->label() }}
                    </span>
                </a>
            @empty
                <p class="rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm
 text-ink-soft">
                    No trainings.
                </p>
            @endforelse
        </section>

        <section class="space-y-3">
            <h3 class="text-sm font-semibold tracking-tight">Endorsements</h3>

            <div class="rounded-xl border border-line bg-card p-4">
                @forelse($user->endorsements as $endorsement)
                    <div class="flex items-center justify-between gap-3 py-1.5 text-sm">
                        <span>{{ Str::of($endorsement->type)->lower()->ucfirst() }}</span>
                        <span class="text-xs text-ink-soft">
                            {{ $endorsement->valid_to?->format('j M Y') ?? 'no expiry' }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-ink-soft">None.</p>
                @endforelse
            </div>
        </section>
    </div>

    @include('vatssa.preview.parts.notice')
</div>

@endsection
