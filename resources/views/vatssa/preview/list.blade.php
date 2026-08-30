@extends('layouts.vatssa')

@section('title', $heading)

@section('content')

{{--
    VATSSA: every list page in the mirror, from one template.

    Twenty bespoke blades would mean twenty places to fix a spacing bug and a
    preview that fails at the one thing it exists to show -- what a CONSISTENT
    Tailwind Control Center feels like.

    Expects: $heading, $columns, $rows. Optional: $blurb, $empty, $actions.
--}}
<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="max-w-2xl">
            <h2 class="text-xl font-semibold tracking-tight">{{ $heading }}</h2>
            @isset($blurb)
                <p class="mt-1 text-sm text-ink-soft">{{ $blurb }}</p>
            @endisset
        </div>

        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">
                @foreach($actions as $label => $href)
                    <a href="{{ $href }}"
                       class="rounded-lg bg-card-header px-3 py-1.5 text-sm font-medium text-ink
 transition-colors hover:bg-line">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        @endisset
    </div>

    @include('vatssa.preview.parts.table', [
        'columns' => $columns,
        'rows' => $rows,
        'empty' => $empty ?? null,
    ])

    <p class="text-xs text-ink-faint">
        {{ count($rows) }} {{ Str::plural('row', count($rows)) }} shown.
    </p>

    @include('vatssa.preview.parts.notice')
</div>

@endsection
