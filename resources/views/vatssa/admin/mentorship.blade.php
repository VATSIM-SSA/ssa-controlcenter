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
        <p class="mt-2 text-sm text-ink-soft">
            <strong class="font-medium text-ink">Up to</strong> is the ceiling:
            the highest rating this mentor may teach at all.
            <strong class="font-medium text-ink">Total</strong> caps students
            across every rating.
            The per-rating numbers cap each pipeline on its own.
        </p>
        <p class="mt-2 text-sm text-ink-soft">
            Blank is <em>no limit</em>. <strong class="font-medium text-ink">0</strong>
            means they take nobody for that rating. Those are different instructions, and
            the difference is why a blank box is never treated as a zero.
        </p>
    </div>

    <section class="overflow-hidden rounded-xl border border-line bg-card">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left">
                        <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-ink-faint">
                            Mentor
                        </th>
                        <th class="px-3 py-3 text-[11px] font-semibold uppercase tracking-wider text-ink-faint">
                            Up to
                        </th>
                        <th class="px-3 py-3 text-[11px] font-semibold uppercase tracking-wider text-ink-faint">
                            Total
                        </th>
                        {{-- The per-rating block gets its own visual group, so
                             the eye can tell "one number about the mentor" from
                             "one number per pipeline". --}}
                        <th colspan="{{ $ratings->count() }}"
                            class="border-l border-line px-3 py-3 text-center text-[11px]
 font-semibold uppercase tracking-wider text-ink-faint">
                            Per rating
                        </th>
                    </tr>
                    <tr class="border-b border-line-soft text-left">
                        <th colspan="3"></th>
                        @foreach($ratings as $rating)
                            <th class="{{ $loop->first ? 'border-l border-line' : '' }}
 px-3 pb-2 text-center text-xs font-medium text-ink-soft">
                                {{ $rating->name }}
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-line-soft">
                    @forelse($mentors as $mentor)
                        @php
                            $ceiling = $ceilings->get($mentor->id);
                            $load = \App\Models\Vatssa\MentorCapacity::loadFor($mentor);
                            $field = 'w-full rounded-lg border border-line bg-card px-2 py-1.5 '
                                . 'text-sm tabular-nums focus:border-brand '
                                . '';
                        @endphp
                        <tr class="align-middle">
                            <td class="px-5 py-3">
                                <p class="font-medium">{{ $mentor->name }}</p>
                                <p class="mt-0.5 text-xs text-ink-soft">
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
                                <td class="{{ $loop->first ? 'border-l border-line-soft' : '' }} px-3 py-3">
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
                                class="px-5 py-12 text-center text-ink-soft">
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
    <section class="rounded-xl border border-line bg-card">
        <div class="border-b border-line-soft px-6 py-4">
            <h3 class="text-sm font-semibold tracking-tight">Mentor resources</h3>
            <p class="mt-0.5 text-sm text-ink-soft">
                Links shown on every mentor's page. Clear the label to remove one.
            </p>
        </div>

        <div class="space-y-3 p-6">
            @for($i = 0; $i < 6; $i++)
                @php $resource = $resources[$i] ?? null; @endphp
                <div class="grid gap-3 sm:grid-cols-[1fr_2fr_8rem]">
                    <input type="text" name="resources[{{ $i }}][label]"
                           value="{{ $resource?->label }}" placeholder="Label"
                           class="rounded-lg border border-line bg-card px-3 py-2 text-sm
 focus:border-brand">
                    <input type="url" name="resources[{{ $i }}][url]"
                           value="{{ $resource?->url }}" placeholder="https://"
                           class="rounded-lg border border-line bg-card px-3 py-2 text-sm
 focus:border-brand">
                    <input type="text" name="resources[{{ $i }}][icon]"
                           value="{{ $resource?->icon }}" placeholder="fa-link"
                           class="rounded-lg border border-line bg-card px-3 py-2 text-sm
 focus:border-brand">
                </div>
            @endfor
        </div>
    </section>

    <button type="submit"
            class="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-strong">
        Save
    </button>
</form>

@endsection
