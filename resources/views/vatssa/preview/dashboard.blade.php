@extends('layouts.vatssa')

@section('title', 'Dashboard')

@section('content')

{{--
    The dashboard, as it would look migrated.

    Two deliberate departures from the current one:

    A dashboard should answer "what needs me" before it says who you are. The
    real one leads with a welcome card and a rating badge -- facts you already
    know about yourself -- and puts the queue three clicks away.

    Numbers get their own row and nothing else competes with them. The current
    page renders every panel as a card with a solid primary header, so the
    urgent and the decorative shout equally loudly, which is the same as
    nothing shouting.
--}}
<div class="space-y-8">

    <div>
        <p class="text-sm text-ink-soft">
            {{ now()->format('l j F') }}
        </p>
        <h2 class="mt-1 text-2xl font-semibold tracking-tight">
            {{ Str::of($user->name)->explode(' ')->first() }}
        </h2>
    </div>

    {{-- The numbers. Tabular figures so they line up, which is a small thing
         that separates a dashboard from a web page.

         Your own open tasks come FIRST and are the only one that goes amber,
         because it is the only number on this page you can personally do
         something about today. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach([
            ['On your desk', $myTasks, 'Open requests assigned to you', true],
            ['In the queue', $queueDepth, 'Waiting for theory to start', false],
            ['Awaiting a mentor', $awaitingMentor, 'Passed theory, nobody assigned', false],
            ['In training', $inTraining, 'Currently being mentored', false],
        ] as [$label, $value, $hint, $mine])
            <div class="rounded-xl border p-5
 {{ $mine && $value
                            ? 'border-warn/40 bg-warn-wash'
                            : 'border-line bg-card' }}">
                <p class="text-sm font-medium text-ink-soft">{{ $label }}</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums tracking-tight
 {{ $mine && $value ? 'text-warn' : '' }}">
                    {{ $value }}
                </p>
                <p class="mt-1 text-xs text-ink-faint">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Your own training, if you have one. --}}
        <div class="lg:col-span-2">
            <div class="rounded-xl border border-line bg-card p-6">
                <h3 class="text-sm font-semibold tracking-tight">Your training</h3>

                @if($training)
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <span class="rounded-md bg-brand-wash px-2.5 py-1 text-sm font-medium text-brand-strong">
                            {{ $training->status->label() }}
                        </span>
                        <span class="text-sm text-ink-soft">
                            opened {{ $training->created_at?->diffForHumans() }}
                        </span>
                    </div>

                    {{-- A progress rail instead of a status word on its own.
                         "Awaiting mentor" means nothing to somebody on their
                         first training; a position on a line does. --}}
                    <ol class="mt-6 flex items-center gap-1.5">
                        @foreach(\App\Helpers\TrainingStatus::inLifecycleOrder() as $stage)
                            @continue($stage->isClosed())
                            @php $done = $training->status->lifecycleOrder() >= $stage->lifecycleOrder(); @endphp
                            <li class="flex-1">
                                <div class="h-1.5 rounded-full {{ $done ? 'bg-brand' : 'bg-line' }}"></div>
                                <p class="mt-2 text-[11px] {{ $done ? 'text-ink' : 'text-ink-faint' }}">
                                    {{ $stage->label() }}
                                </p>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="mt-3 text-sm text-ink-soft">
                        You have no open training.
                    </p>
                @endif
            </div>
        </div>

        {{-- Identity, demoted. Still here, no longer the headline. --}}
        <div class="rounded-xl border border-line bg-card p-6">
            <h3 class="text-sm font-semibold tracking-tight">You</h3>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-soft">CID</dt>
                    <dd class="tabular-nums">{{ $user->id }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-soft">Rating</dt>
                    <dd>{{ $user->rating?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-soft">Joined</dt>
                    <dd>{{ $user->created_at?->format('M Y') }}</dd>
                </div>
            </dl>
        </div>
    </div>

    @include('vatssa.preview.parts.notice')
</div>

@endsection
