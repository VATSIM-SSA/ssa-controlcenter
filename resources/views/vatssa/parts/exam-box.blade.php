{{--
    VATSSA: the upcoming exam, on the training page.

    ## Why this is on the training page at all

    The exam workflow has its own pages, and it should -- five parties need a
    shared list. But a coordinator opening a student's training asks "where is
    this person up to", and an exam being arranged is most of that answer.
    Sending them to another page to find out is how the two views drift apart in
    somebody's head.

    ## It is a summary and a link, not a second set of controls

    Every action lives on the exam page. Duplicating the buttons here would mean
    two places to keep in step, and the one that is wrong is the one somebody
    uses.

    Bootstrap markup: this renders inside `training/show.blade.php`, which loads
    app.scss and not the Tailwind entry point. See layouts/vatssa.blade.php.

    Expects: $training. Optional: $exam (resolved here when not passed).
--}}
@php
    $exam = $exam ?? \App\Models\Vatssa\Exam::where('training_id', $training->id)
        ->open()->latest()->first();
@endphp

@if($exam)
    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 fw-bold text-white">
                <i class="fas fa-user-graduate"></i>&nbsp;Practical exam
            </h6>
            <span class="badge bg-light text-dark">{{ $exam->stage->label() }}</span>
        </div>

        <div class="card-body">
            @if($exam->scheduled_for)
                <dl class="mb-3">
                    <dt>When</dt>
                    <dd>
                        {{ $exam->scheduled_for->format('l j F Y') }}
                        at {{ $exam->scheduled_for->format('H:i') }}z
                    </dd>

                    @if($exam->examiner)
                        <dt>Examiner</dt>
                        <dd>{{ $exam->examiner->name }}</dd>
                    @endif

                    @if($exam->position)
                        <dt>Position</dt>
                        <dd>{{ $exam->position->callsign }}</dd>
                    @endif
                </dl>
            @endif

            @if($exam->stage->waitingOn())
                <p class="text-muted mb-3">
                    Waiting on <strong>{{ $exam->stage->waitingOn() }}</strong>.
                </p>
            @endif

            {{-- The one thing that has to shout on this page. A confirmed exam
                 whose paperwork is unfinished, now inside the notice period,
                 is exactly what the seven-day rule exists to catch -- and it is
                 invisible unless something says so where people look. --}}
            @if($exam->noticeBreached())
                <div class="alert alert-danger mb-3" role="alert">
                    <i class="fas fa-triangle-exclamation"></i>&nbsp;
                    Inside the {{ \App\Models\Vatssa\Exam::NOTICE_DAYS }}-day notice period and not
                    published: {{ implode(', ', $exam->checklistOutstanding()) }} still outstanding.
                </div>
            @endif

            <a href="{{ route('vatssa.exams.show', $exam) }}" class="btn btn-outline-primary btn-icon">
                <i class="fas fa-arrow-right"></i>&nbsp;Open the exam
            </a>
        </div>
    </div>
@else
    @can('create', [\App\Models\Vatssa\Exam::class, $training])
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-user-graduate"></i>&nbsp;Practical exam
                </h6>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    Nothing arranged. Requesting one asks the ATC training manager to authorise
                    it; the student is only asked for availability once they have.
                </p>

                <form method="POST" action="{{ route('vatssa.exams.store', $training) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-icon">
                        <i class="fas fa-plus"></i>&nbsp;Request a practical exam
                    </button>
                </form>
            </div>
        </div>
    @endcan
@endif
