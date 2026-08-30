@extends('layouts.vatssa')

@section('title', 'Mentorship')

@section('content')

{{--
    VATSSA: mentor capacity and ceilings.

    Converted to Tailwind. Our own page, so no merge conflict -- see
    layouts/vatssa.blade.php for why that decides what gets converted.

    Every form field name is unchanged: max_rating[id], total[id],
    capacity[id][ratingId], resources[i][label|url|icon]. A restyle that quietly
    renames a field is a restyle that breaks a controller.

    The one design idea: THREE NUMBERS, and they mean different things.
    Bootstrap rendered them as three identical little boxes in a row, so nobody
    could tell the ceiling from the total from the per-rating limit without
    reading the header. They are grouped and labelled here instead.
--}}
<form method="POST" action="{{ route('vatssa.admin.mentorship.update') }}" class="space-y-8">
    @csrf

    <div class="max-w-3xl">
        <h2 class="text-xl font-semibold tracking-tight">Mentorship</h2>
        <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
            <strong class="font-medium text-neutral-800 dark:text-neutral-200">Up to</strong> is the ceiling:
            the highest rating this mentor may teach at all.
            <strong class="font-medium text-neutral-800 dark:text-neutral-200">Total</strong> caps students
            across every rating.
            The per-rating numbers cap each pipeline on its own.
        </p>
        <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
            Blank is <em>no limit</em>. <strong class="font-medium text-neutral-800 dark:text-neutral-200">0</strong>
            means they take nobody for that rating. Those are different instructions, and
            the difference is why a blank box is never treated as a zero.
        </p>
    </div>

    <section class="overflow-hidden rounded-xl border border-neutral-200 bg-white
                    dark:border-neutral-800 dark:bg-neutral-900">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-neutral-200 text-left dark:border-neutral-800">
                        <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-neutral-400">
                            Mentor
                        </th>
                        <th class="px-3 py-3 text-[11px] font-semibold uppercase tracking-wider text-neutral-400">
                            Up to
                        </th>
                        <th class="px-3 py-3 text-[11px] font-semibold uppercase tracking-wider text-neutral-400">
                            Total
                        </th>
                        {{-- The per-rating block gets its own visual group, so
                             the eye can tell "one number about the mentor" from
                             "one number per pipeline". --}}
                        <th colspan="{{ $ratings->count() }}"
                            class="border-l border-neutral-200 px-3 py-3 text-center text-[11px]
                                   font-semibold uppercase tracking-wider text-neutral-400
                                   dark:border-neutral-800">
                            Per rating
                        </th>
                    </tr>
                    <tr class="border-b border-neutral-100 text-left dark:border-neutral-800">
                        <th colspan="3"></th>
                        @foreach($ratings as $rating)
                            <th class="{{ $loop->first ? 'border-l border-neutral-200 dark:border-neutral-800' : '' }}
                                       px-3 pb-2 text-center text-xs font-medium text-neutral-500
                                       dark:text-neutral-400">
                                {{ $rating->name }}
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse($mentors as $mentor)
                        @php
                            $ceiling = $ceilings->get($mentor->id);
                            $load = \App\Models\Vatssa\MentorCapacity::loadFor($mentor);
                            $field = 'w-full rounded-lg border border-neutral-300 bg-white px-2 py-1.5 '
                                . 'text-sm tabular-nums focus:border-brand-500 '
                                . 'dark:border-neutral-700 dark:bg-neutral-950';
                        @endphp
                        <tr class="align-middle">
                            <td class="px-5 py-3">
                                <p class="font-medium">{{ $mentor->name }}</p>
                                <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                    <span class="tabular-nums">{{ $mentor->id }}</span>
                                    · running {{ $load }}
                                </p>
                            </td>

                            <td class="px-3 py-3">
                                <select name="max_rating[{{ $mentor->id }}]" class="{{ $field }}">
                                    <option value="">No ceiling</option>
                                    @foreach($ratings as $rating)
                                        <option value="{{ $rating->id }}"
                                            @selected($ceiling?->max_rating_id === $rating->id)>
                                            {{ $rating->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>

                            <td class="px-3 py-3">
                                <input type="number" min="0" max="99" placeholder="—"
                                       name="total[{{ $mentor->id }}]"
                                       value="{{ $ceiling?->total_limit }}"
                                       class="{{ $field }} w-20">
                            </td>

                            @foreach($ratings as $rating)
                                @php
                                    $row = $capacity->where('user_id', $mentor->id)
                                        ->where('rating_id', $rating->id)->first();
                                @endphp
                                <td class="{{ $loop->first ? 'border-l border-neutral-100 dark:border-neutral-800' : '' }} px-3 py-3">
                                    <input type="number" min="0" max="99" placeholder="—"
                                           name="capacity[{{ $mentor->id }}][{{ $rating->id }}]"
                                           value="{{ $row?->student_limit }}"
                                           class="{{ $field }} w-16 text-center">
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 3 + $ratings->count() }}"
                                class="px-5 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                Nobody holds the mentor role.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Mentor resources: the links that appear on every mentor's own page.
         Six rows is the realistic ceiling, and a blank row is how you add one --
         an "add row" button for something used twice a year is a control to
         maintain rather than a feature. --}}
    <section class="rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
        <div class="border-b border-neutral-100 px-6 py-4 dark:border-neutral-800">
            <h3 class="text-sm font-semibold tracking-tight">Mentor resources</h3>
            <p class="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                Links shown on every mentor's page. Clear the label to remove one.
            </p>
        </div>

        <div class="space-y-3 p-6">
            @for($i = 0; $i < 6; $i++)
                @php $resource = $resources[$i] ?? null; @endphp
                <div class="grid gap-3 sm:grid-cols-[1fr_2fr_8rem]">
                    <input type="text" name="resources[{{ $i }}][label]"
                           value="{{ $resource?->label }}" placeholder="Label"
                           class="rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm
                                  focus:border-brand-500 dark:border-neutral-700 dark:bg-neutral-950">
                    <input type="url" name="resources[{{ $i }}][url]"
                           value="{{ $resource?->url }}" placeholder="https://"
                           class="rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm
                                  focus:border-brand-500 dark:border-neutral-700 dark:bg-neutral-950">
                    <input type="text" name="resources[{{ $i }}][icon]"
                           value="{{ $resource?->icon }}" placeholder="fa-link"
                           class="rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm
                                  focus:border-brand-500 dark:border-neutral-700 dark:bg-neutral-950">
                </div>
            @endfor
        </div>
    </section>

    <button type="submit"
            class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
        Save
    </button>
</form>

@endsection
