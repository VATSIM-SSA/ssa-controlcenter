@extends('layouts.vatssa')

@section('title', 'Theory courses')

@section('content')

{{--
    VATSSA: which rating needs which Moodle course, and what counts as a pass.

    A table rather than a file in the bot's container, because these change with
    course revisions rather than with code, and a new course should not be a
    rebuild and a restart.

    Converted to Tailwind. Field names unchanged:
    courses[i][rating|course_id|exam_quiz_id|pass_mark|active].

    The design idea: an UNCONFIGURED rating is the state that matters, and in
    Bootstrap it was a row that looked exactly like every other row. It now
    marks itself.
--}}
<form method="POST" action="{{ route('vatssa.admin.courses.update') }}" class="space-y-8">
    @csrf

    <div class="max-w-3xl space-y-3">
        <h2 class="text-xl font-semibold tracking-tight">Theory courses</h2>

        <p class="text-sm text-neutral-600 dark:text-neutral-400">
            <span class="text-neutral-800 dark:text-neutral-200">One quiz per course counts</span> —
            the course's final quiz, which is that rating's theory exam. Earlier quizzes are
            practice and are not tracked at all. Every attempt at the exam quiz is kept, and
            <span class="text-neutral-800 dark:text-neutral-200">the latest one decides</span>:
            a pass followed by a failed retake is not currently a pass.
        </p>

        <p class="text-sm text-neutral-600 dark:text-neutral-400">
            A rating with an id of <code class="font-mono text-xs">0</code> is treated as
            <span class="text-neutral-800 dark:text-neutral-200">not configured</span> and left
            out of the map, so that rating needs no theory at all. Deliberate: it is visible and
            gets fixed, where sending real ids for a course that does not exist would give every
            student no attempts — indistinguishable from a room full of failures.
        </p>
    </div>

    <section class="overflow-hidden rounded-xl border border-neutral-200 bg-white
                    dark:border-neutral-800 dark:bg-neutral-900">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-neutral-200 text-left dark:border-neutral-800">
                        @foreach(['Rating', 'Course', 'Exam quiz', 'Pass mark', 'Active'] as $heading)
                            <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-neutral-400">
                                {{ $heading }}
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @foreach($courses as $index => $course)
                        @php
                            $unset = ! $course->course_id || ! $course->exam_quiz_id;
                            $field = 'w-24 rounded-lg border border-neutral-300 bg-white px-2 py-1.5 '
                                . 'text-sm tabular-nums focus:border-brand-500 '
                                . 'dark:border-neutral-700 dark:bg-neutral-950';
                        @endphp
                        <tr class="{{ $unset ? 'bg-amber-50/60 dark:bg-amber-950/20' : '' }}">
                            <td class="px-5 py-3">
                                <span class="font-medium">{{ $course->rating }}</span>
                                <input type="hidden" name="courses[{{ $index }}][rating]"
                                       value="{{ $course->rating }}">
                                @if($unset)
                                    <p class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">
                                        Not configured — students skip theory
                                    </p>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <input type="number" min="0" name="courses[{{ $index }}][course_id]"
                                       value="{{ $course->course_id }}" class="{{ $field }}">
                            </td>
                            <td class="px-5 py-3">
                                <input type="number" min="0" name="courses[{{ $index }}][exam_quiz_id]"
                                       value="{{ $course->exam_quiz_id }}" class="{{ $field }}">
                            </td>
                            <td class="px-5 py-3">
                                <input type="number" min="0" max="100" step="0.1"
                                       name="courses[{{ $index }}][pass_mark]"
                                       value="{{ $course->pass_mark }}" class="{{ $field }}">
                            </td>
                            <td class="px-5 py-3">
                                {{-- The hidden 0 goes first. An unchecked box is
                                     omitted entirely by the browser, so without it
                                     every save would leave every course active. --}}
                                <input type="hidden" name="courses[{{ $index }}][active]" value="0">
                                <input type="checkbox" name="courses[{{ $index }}][active]" value="1"
                                       @checked($course->active)
                                       class="h-4 w-4 rounded border-neutral-300 text-brand-500
                                              focus:ring-brand-500 dark:border-neutral-600 dark:bg-neutral-800">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <button type="submit"
            class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
        Save courses
    </button>
</form>

@endsection
