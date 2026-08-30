@extends('layouts.vatssa')

@section('title', 'Trainings')

@section('content')

{{--
    The table page, which decides how the whole application feels.

    Control Center is mostly tables, and bootstrap-table renders them as a grey
    grid with a border on every cell and a header bar in the primary colour.
    That one component is doing more to make the app look dated than the palette
    is -- so this is the page worth looking at hardest.

    Three changes, and none of them is a colour:

    No vertical rules, and horizontal ones only between rows. Boxing every cell
    makes a table look like a spreadsheet, and nobody has ever wanted their
    admin tool to look like a spreadsheet.

    The status is a quiet pill, not a coloured row. Colouring the row makes
    every row shout; colouring one small thing inside it lets the eye scan.

    Names are the anchor and everything else is secondary text. The current
    table gives the CID, the name, the rating and the date the same weight, so
    there is nothing to scan by.
--}}
<div class="space-y-6">

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white
                dark:border-neutral-800 dark:bg-neutral-900">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-neutral-200 text-left dark:border-neutral-800">
                        <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wider
                                   text-neutral-400">Student</th>
                        <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wider
                                   text-neutral-400">Rating</th>
                        <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wider
                                   text-neutral-400">Stage</th>
                        <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wider
                                   text-neutral-400">Mentor</th>
                        <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider
                                   text-neutral-400">Waiting</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse($trainings as $training)
                        <tr class="transition-colors hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="px-5 py-3">
                                <a href="{{ route('training.show', $training) }}"
                                   class="font-medium hover:text-brand-600 dark:hover:text-brand-400">
                                    {{ $training->user?->name ?? 'Unknown' }}
                                </a>
                                <div class="text-xs tabular-nums text-neutral-400">{{ $training->user_id }}</div>
                            </td>

                            <td class="px-5 py-3 text-neutral-600 dark:text-neutral-400">
                                {{ $training->ratings->pluck('name')->join(' + ') ?: '—' }}
                            </td>

                            <td class="px-5 py-3">
                                @php
                                    // Colour carries meaning here and nowhere else on the
                                    // row: amber is waiting on us, brand is under way,
                                    // neutral is waiting on somebody outside the pipeline.
                                    $tone = match ($training->status) {
                                        \App\Helpers\TrainingStatus::AWAITING_MENTOR
                                            => 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300',
                                        \App\Helpers\TrainingStatus::ACTIVE_TRAINING
                                            => 'bg-brand-50 text-brand-700 dark:bg-brand-950/60 dark:text-brand-300',
                                        default
                                            => 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300',
                                    };
                                @endphp
                                <span class="rounded-md px-2 py-1 text-xs font-medium {{ $tone }}">
                                    {{ $training->status->label() }}
                                </span>
                            </td>

                            <td class="px-5 py-3 text-neutral-600 dark:text-neutral-400">
                                @if($training->mentors->isNotEmpty())
                                    {{ $training->mentors->pluck('name')->join(', ') }}
                                @else
                                    <span class="text-neutral-400 dark:text-neutral-600">nobody</span>
                                @endif
                            </td>

                            <td class="px-5 py-3 text-right tabular-nums text-neutral-500 dark:text-neutral-400">
                                {{ $training->created_at?->diffForHumans(null, true) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                No open trainings.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('vatssa.preview.parts.notice')
</div>

@endsection
