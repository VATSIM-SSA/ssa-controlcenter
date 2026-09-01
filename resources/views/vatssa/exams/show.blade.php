@extends('layouts.app')

@section('title', ($exam->training?->user?->name ?? 'Exam') . ' — practical exam')

@section('content')

{{--
    VATSSA: one exam, and the one thing you can do about it.

    Only your step is on the page -- not five panels with four of them disabled.
    A page showing every step greyed out teaches people to scan past the whole
    thing looking for the live one, and makes a five-party workflow feel like a
    form with permissions.

    Every action posts to ExamController, which moves the stage exactly one step
    and writes to the training's timeline. See ExamPolicy for why each step is a
    different permission.
--}}
@php
    $variant = fn (string $tone) => match ($tone) {
        'brand' => 'bg-primary',
        'good' => 'bg-success',
        'warn' => 'bg-warning text-dark',
        default => 'bg-secondary',
    };
@endphp

<div class="row">
    <div class="col-xl-8 col-lg-12 col-md-12">

        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-user-graduate"></i>&nbsp;
                    {{ $exam->training?->user?->name ?? 'Unknown student' }}
                </h6>
                <span class="badge {{ $variant($exam->stage->tone()) }}">
                    {{ $exam->stage->label() }}
                </span>
            </div>

            <div class="card-body">
                <dl class="copyable">
                    <dt>Rating</dt>
                    <dd>{{ $exam->training?->ratings->pluck('name')->join(' + ') ?: '—' }}</dd>

                    <dt>Training</dt>
                    <dd><a href="{{ route('training.show', $exam->training_id) }}">open the training</a></dd>

                    @if($exam->requester)
                        <dt>Requested by</dt>
                        <dd>{{ $exam->requester->name }}</dd>
                    @endif

                    @if($exam->scheduled_for)
                        <dt>Scheduled</dt>
                        <dd>
                            {{ $exam->scheduled_for->format('l j F Y') }}
                            at {{ $exam->scheduled_for->format('H:i') }}z
                            @if($exam->examiner) &mdash; {{ $exam->examiner->name }} @endif
                            @if($exam->position) ({{ $exam->position->callsign }}) @endif
                        </dd>
                    @endif

                    @if($exam->stage->waitingOn())
                        <dt>Waiting on</dt>
                        <dd>{{ $exam->stage->waitingOn() }}</dd>
                    @endif
                </dl>

                @if($exam->noticeBreached())
                    <div class="alert alert-danger mb-0" role="alert">
                        <i class="fas fa-triangle-exclamation"></i>&nbsp;
                        Inside the {{ \App\Models\Vatssa\Exam::NOTICE_DAYS }}-day notice period and
                        not published: {{ implode(', ', $exam->checklistOutstanding()) }} still
                        outstanding. This postpones unless it is finished.
                    </div>
                @endif

                @if($exam->outcome_note)
                    <p class="text-muted mb-0">{{ $exam->outcome_note }}</p>
                @endif
            </div>
        </div>

        {{-- ------------------------------------------------------------
             The one live step.
             ------------------------------------------------------------ --}}

        @can('authorise', $exam)
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3">
                    <h6 class="m-0 fw-bold text-white">Authorise this exam</h6>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        The student is asked for their availability as soon as you do. Nothing is
                        asked of them before that, so an exam nobody authorised never costs them a
                        month of marking a grid.
                    </p>
                    <form method="POST" action="{{ route('vatssa.exams.authorise', $exam) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-icon">
                            <i class="fas fa-check"></i>&nbsp;Authorise
                        </button>
                    </form>
                </div>
            </div>
        @endcan

        @if($exam->poll)
        @can('submitAvailability', $exam)
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3">
                    <h6 class="m-0 fw-bold text-white">When could you sit it?</h6>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Mark <strong>every</strong> time you could make, not just your preferred
                        one. An examiner has to match one and so does the division calendar, so
                        more options is a shorter wait. Nothing sooner than
                        {{ \App\Models\Vatssa\Exam::NOTICE_DAYS }} days away can be used, which is
                        why the grid starts when it does.
                    </p>

                    @livewire('vatssa.availability-grid', ['poll' => $exam->poll,
                        'role' => \App\Models\Vatssa\AvailabilityPoll::ROLE_STUDENT])

                    <form method="POST" action="{{ route('vatssa.exams.submit', $exam) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-icon">
                            <i class="fas fa-paper-plane"></i>&nbsp;I am done &mdash; send to the events team
                        </button>
                    </form>
                </div>
            </div>
        @endcan
        @endif

        @can('clear', $exam)
            @if($exam->stage === \App\Helpers\ExamStage::AWAITING_EVENTS && $exam->poll)
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary py-3">
                        <h6 class="m-0 fw-bold text-white">Which of these times are clear?</h6>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            The student's times are shaded underneath. Mark the ones that do not
                            clash with a division plan &mdash;
                            <strong>including the ones not published yet</strong>, which is the
                            whole reason this is a person and not a calendar query.
                        </p>
                        <p class="text-muted">
                            If none of them work, cancel the exam and say why. Sending an empty
                            list on leaves an examiner staring at nothing.
                        </p>

                        @livewire('vatssa.availability-grid', ['poll' => $exam->poll,
                            'role' => \App\Models\Vatssa\AvailabilityPoll::ROLE_EVENTS])

                        <form method="POST" action="{{ route('vatssa.exams.clear', $exam) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-icon">
                                <i class="fas fa-calendar-check"></i>&nbsp;These are clear
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary py-3">
                        <h6 class="m-0 fw-bold text-white">Publishing</h6>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            All of it has to be done
                            {{ \App\Models\Vatssa\Exam::NOTICE_DAYS }} days before the exam.
                        </p>
                        <form method="POST" action="{{ route('vatssa.exams.publish', $exam) }}">
                            @csrf
                            @foreach([
                                'banner_made' => 'Banner made',
                                'on_discord' => 'On the Discord calendar',
                                'on_myvatsim' => 'Uploaded to myVATSIM',
                                'on_social' => 'Posted to social media',
                                'vatsim_approved' => 'Approved by VATSIM',
                            ] as $name => $label)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           id="chk-{{ $name }}" name="{{ $name }}" value="1"
                                           @checked($exam->$name)>
                                    <label class="form-check-label" for="chk-{{ $name }}">{{ $label }}</label>
                                </div>
                            @endforeach

                            <button type="submit" class="btn btn-primary btn-icon mt-3">
                                <i class="fas fa-save"></i>&nbsp;Save
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        @endcan

        @can('confirm', $exam)
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3">
                    <h6 class="m-0 fw-bold text-white">Take this exam</h6>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        These are the times the student can make and the events team have cleared.
                        Confirming takes the exam and books the slot in one step &mdash; there is
                        no claiming it and picking a date later.
                    </p>

                    @if($slots === [])
                        <div class="alert alert-warning mb-0" role="alert">
                            Nothing here works any more. Every cleared time is now inside the
                            {{ \App\Models\Vatssa\Exam::NOTICE_DAYS }}-day notice period, so the
                            student needs to give fresh availability.
                        </div>
                    @else
                        <form method="POST" action="{{ route('vatssa.exams.confirm', $exam) }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-7 mb-3">
                                    <label class="form-label" for="slot">Slot</label>
                                    <select class="form-select" name="slot" id="slot" required>
                                        @foreach($slots as $slot)
                                            <option value="{{ $slot }}">
                                                {{ \Carbon\Carbon::parse($slot)->format('D j M Y · H:i') }}z
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-5 mb-3">
                                    <label class="form-label" for="position_id">Position (optional)</label>
                                    <select class="form-select" name="position_id" id="position_id">
                                        <option value="">Decide later</option>
                                        @foreach($positions as $position)
                                            <option value="{{ $position->id }}">{{ $position->callsign }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-icon">
                                <i class="fas fa-check"></i>&nbsp;Confirm &mdash; I will examine this
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endcan

        @if($exam->stage->waitingOn()
            && ! Auth::user()->can('authorise', $exam)
            && ! Auth::user()->can('submitAvailability', $exam)
            && ! Auth::user()->can('clear', $exam)
            && ! Auth::user()->can('confirm', $exam))
            {{-- Said plainly rather than shown as a row of disabled buttons,
                 which is a page telling you to try clicking things. --}}
            <div class="alert alert-secondary" role="alert">
                Waiting on {{ $exam->stage->waitingOn() }}. Nothing for you to do here yet.
            </div>
        @endif
    </div>

    <div class="col-xl-4 col-lg-12 col-md-12">

        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-list-check"></i>&nbsp;Progress
                </h6>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    @foreach(\App\Helpers\ExamStage::open() as $stage)
                        @php
                            $done = $exam->stage->value >= $stage->value;
                            $here = $exam->stage === $stage;
                        @endphp
                        <li class="list-group-item d-flex align-items-center gap-2">
                            <i class="fas {{ $here ? 'fa-circle-dot text-primary' : ($done ? 'fa-circle-check text-success' : 'fa-circle text-muted') }}"></i>
                            <span class="{{ $here ? 'fw-bold' : ($done ? '' : 'text-muted') }}">
                                {{ $stage->label() }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        @if($exam->poll)
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3">
                    <h6 class="m-0 fw-bold text-white">
                        <i class="fas fa-calendar"></i>&nbsp;Availability
                    </h6>
                </div>
                <div class="card-body">
                    @forelse($exam->poll->responses as $response)
                        <div class="d-flex justify-content-between align-items-center py-1">
                            <span>
                                {{ $response->user?->name ?? 'Unknown' }}
                                <span class="badge bg-secondary">{{ $response->role }}</span>
                            </span>
                            <span class="text-muted">{{ count($response->slots ?? []) }} times</span>
                        </div>
                    @empty
                        <p class="text-muted mb-0">Nobody has answered yet.</p>
                    @endforelse

                    <p class="text-muted mb-0 mt-3">
                        <strong>{{ count($exam->offerableSlots()) }}</strong> of those work for the
                        student, are clear of division plans, and are far enough out to be legal.
                    </p>
                </div>
            </div>
        @endif

        @can('cancel', $exam)
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3">
                    <h6 class="m-0 fw-bold text-white">
                        <i class="fas fa-xmark"></i>&nbsp;Call it off
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('vatssa.exams.cancel', $exam) }}"
                          onsubmit="return confirm('Cancel this exam? Everybody involved will see the reason.')">
                        @csrf
                        <div class="mb-3">
                            <input type="text" class="form-control" name="reason" required maxlength="255"
                                   placeholder="Why — everybody involved will see this">
                        </div>
                        <button type="submit" class="btn btn-danger btn-icon">
                            <i class="fas fa-xmark"></i>&nbsp;Cancel exam
                        </button>
                    </form>
                </div>
            </div>
        @endcan
    </div>
</div>

@endsection
