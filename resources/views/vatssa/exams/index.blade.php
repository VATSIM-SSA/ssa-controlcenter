@extends('layouts.vatssa')

@section('title', 'Practical exams')

@section('content')

{{--
    VATSSA: every exam in flight, for everybody.

    ## One page, not three

    The examiner wants the ones they could take, the events team wants the ones
    waiting on them, and a coordinator wants to know why their student has not
    sat yet. Three pages would be three places to keep in step and three chances
    for one of them to disagree. The answer to all three questions is the same
    list, sorted by whose turn it is.

    ## "Needs you" is the whole design

    Above the fold, before anything else, and empty most of the time. The reason
    arranging a CPT used to take a fortnight is not that any step was hard -- it
    is that nobody could tell whose turn it was without reading a Discord
    thread.
--}}
@php
    $tone = fn (string $t) => match ($t) {
        'brand' => 'bg-brand-wash text-brand-strong',
        'good' => 'bg-good-wash text-good',
        'warn' => 'bg-warn-wash text-warn',
        default => 'bg-card-header text-ink-soft',
    };
@endphp

<div class="space-y-8">

    <div class="max-w-2xl">
        <h2 class="text-xl font-semibold tracking-tight">Practical exams</h2>
        <p class="mt-1 text-sm text-ink-soft">
            Every exam being arranged, and whose turn it is. Everything must be
            settled <strong class="font-medium text-ink">{{ \App\Models\Vatssa\Exam::NOTICE_DAYS }} days</strong>
            before the date — examiner confirmed, events team told, myVATSIM uploaded.
        </p>
    </div>

    {{-- Waiting on you. --}}
    <section>
        <h3 class="text-sm font-semibold tracking-tight">Waiting on you</h3>

        @if($mine->isEmpty())
            <p class="mt-3 rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm
                      text-ink-soft">
                Nothing needs you. That is the state this page should usually be in.
            </p>
        @else
            <div class="mt-3 space-y-2">
                @foreach($mine as $exam)
                    @include('vatssa.exams.parts.row', ['exam' => $exam, 'tone' => $tone, 'urgent' => true])
                @endforeach
            </div>
        @endif
    </section>

    <section>
        <h3 class="text-sm font-semibold tracking-tight">Everything in flight</h3>

        @if($exams->isEmpty())
            <p class="mt-3 rounded-xl border border-dashed border-line px-4 py-12 text-center text-sm
                      text-ink-soft">
                No exams are being arranged.
            </p>
        @else
            <div class="mt-3 space-y-2">
                @foreach($exams as $exam)
                    @include('vatssa.exams.parts.row', ['exam' => $exam, 'tone' => $tone, 'urgent' => false])
                @endforeach
            </div>
        @endif
    </section>
</div>

@endsection
