@extends('layouts.vatssa')

@section('title', $heading)

@section('content')

{{--
    VATSSA: every list page in the mirror, from one template.

    Twenty bespoke blades would mean twenty places to fix a spacing bug and a
    preview that fails at the one thing it exists to show -- what a CONSISTENT
    Tailwind Control Center feels like.

    Expects: $heading, $columns, $rows.
    Optional: $blurb, $empty, $filters, $actions.
--}}
<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="max-w-2xl">
            <h2 class="text-xl font-semibold tracking-tight">{{ $heading }}</h2>
            @if($blurb ?? null)
                <p class="mt-1 text-sm text-ink-soft">{{ $blurb }}</p>
            @endif
        </div>

        @if($actions ?? [])
            <div class="flex flex-wrap items-center gap-2">
                @foreach($actions as $label => $href)
                    <a href="{{ $href }}"
                       class="rounded-lg bg-brand px-3 py-2 text-sm font-medium text-white
                              transition-colors hover:bg-brand-strong">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    {{-- The row count lives in the table now, beside the filters, because it
         changes as you filter and a total sitting somewhere else would go
         stale the moment anybody typed. --}}
    @include('vatssa.preview.parts.table', [
        'columns' => $columns,
        'rows' => $rows,
        'empty' => $empty ?? null,
        'filters' => $filters ?? [],
    ])

    @include('vatssa.preview.parts.notice')
</div>

@endsection
