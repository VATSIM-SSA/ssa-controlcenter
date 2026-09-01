@extends('layouts.app')

@section('title', 'Moodle course map')

@section('content')

{{--
    VATSSA: which rating needs which Moodle course, and what counts as a pass.

    A table rather than a file in the bot's container, because these change with
    course revisions rather than with code, and a new course should not be a
    rebuild and a restart.
--}}

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="alert alert-info" role="alert">
            <i class="fas fa-circle-info"></i>&nbsp;
            <strong>One quiz per course counts</strong> — the course's final
            quiz, which is that rating's theory exam. Earlier quizzes in a
            course are practice and are not tracked at all. Every attempt at the
            exam quiz is kept, and <strong>the latest one decides</strong>: a
            pass followed by a failed retake is not currently a pass.
        </div>
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-triangle-exclamation"></i>&nbsp;
            A rating with an id of <code>0</code> is treated as
            <strong>not configured</strong> and is left out of the map, so that
            rating needs no theory at all. That is deliberate: it is visible and
            gets fixed, where sending real ids for a course that does not exist
            would give every student no attempts — indistinguishable from a room
            full of failures.
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">Theory courses</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('vatssa.admin.courses.update') }}">
                    @csrf

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Rating</th>
                                    <th>Moodle course id</th>
                                    <th>Exam quiz id</th>
                                    <th>Pass mark</th>
                                    <th>Active</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($courses as $index => $course)
                                    <tr>
                                        <td>
                                            <input type="text" class="form-control form-control-sm" style="max-width: 8rem"
                                                   name="courses[{{ $index }}][rating]" value="{{ $course->rating }}" readonly>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control form-control-sm" min="0"
                                                   name="courses[{{ $index }}][course_id]" value="{{ $course->course_id }}">
                                        </td>
                                        <td>
                                            <input type="number" class="form-control form-control-sm" min="0"
                                                   name="courses[{{ $index }}][exam_quiz_id]" value="{{ $course->exam_quiz_id }}">
                                        </td>
                                        <td>
                                            <div class="input-group input-group-sm" style="max-width: 8rem">
                                                <input type="number" class="form-control" min="0" max="100" step="0.5"
                                                       name="courses[{{ $index }}][pass_mark]" value="{{ $course->pass_mark }}">
                                                <span class="input-group-text">%</span>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="hidden" name="courses[{{ $index }}][active]" value="0">
                                            <input type="checkbox" class="form-check-input"
                                                   name="courses[{{ $index }}][active]" value="1" @checked($course->active)>
                                        </td>
                                    </tr>
                                @endforeach

                                @if($courses->isEmpty())
                                    <tr>
                                        <td colspan="5" class="text-muted">
                                            No ratings configured. The pipeline seeds these on its
                                            first run against the bridge.
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>

                    @if($courses->isNotEmpty())
                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    @endif
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
