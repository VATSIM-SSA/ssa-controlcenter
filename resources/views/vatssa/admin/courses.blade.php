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

        <p class="text-sm text-ink-soft">
            <span class="text-ink">One quiz per course counts</span> —
            the course's final quiz, which is that rating's theory exam. Earlier quizzes are
            practice and are not tracked at all. Every attempt at the exam quiz is kept, and
            <span class="text-ink">the latest one decides</span>:
            a pass followed by a failed retake is not currently a pass.
        </p>

        <p class="text-sm text-ink-soft">
            A rating with an id of <code class="font-mono text-xs">0</code> is treated as
            <span class="text-ink">not configured</span> and left
            out of the map, so that rating needs no theory at all. Deliberate: it is visible and
            gets fixed, where sending real ids for a course that does not exist would give every
            student no attempts — indistinguishable from a room full of failures.
        </p>
    </div>

    <section class="overflow-hidden rounded-xl border border-line bg-card">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left">
                        @foreach(['Rating', 'Course', 'Exam quiz', 'Pass mark', 'Active'] as $heading)
                            <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ $heading }}
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-line-soft">
                    @foreach($courses as $index => $course)
                        @php
                            $unset = ! $course->course_id || ! $course->exam_quiz_id;
                            $field = 'w-24 rounded-lg border border-line bg-card px-2 py-1.5 '
                                . 'text-sm tabular-nums focus:border-brand '
                                . '';
                        @endphp
                        <tr class="{{ $unset ? 'bg-warn-wash' : '' }}">
                            <td class="px-5 py-3">
                                <span class="font-medium">{{ $course->rating }}</span>
                                <input type="hidden" name="courses[{{ $index }}][rating]"
                                       value="{{ $course->rating }}">
                                @if($unset)
                                    <p class="mt-0.5 text-xs text-warn">
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
                                       class="h-4 w-4 rounded border-line text-brand
 focus:ring-brand">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <button type="submit"
            class="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-strong">
        Save courses
    </button>
</form>

@endsection
