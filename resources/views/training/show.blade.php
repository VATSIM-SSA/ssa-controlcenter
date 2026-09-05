@extends('layouts.app')

@section('title', 'Training')
@section('title-flex')
    <div>
        @can('close', $training)
            <a href="{{ route('training.action.close', $training->id) }}" onclick="return confirm('Are you sure you want to close your training?')" class="btn btn-danger"><i class="fas fa-xmark"></i> Close my training</a>
        @endcan
        {{-- VATSSA: upstream's "mark pre-training as completed" button is gone.

             It is a self-declared tickbox with nothing behind it -- it gates no
             transition, blocks nothing and is not read by any rule. Its only
             effect was a tick next to the status, which alongside a stage now
             called "Theory phase" read as "the theory is done" when it meant
             nothing of the sort. The theory pass comes from Moodle. --}}
    </div>
@endsection
@section('content')

@if($training->status === \App\Helpers\TrainingStatus::CLOSED_BY_SYSTEM || $training->status === \App\Helpers\TrainingStatus::CLOSED_BY_STAFF)
    <div class="alert alert-warning" role="alert">
        <b>Training is closed with reason: </b>
        @if(isset($training->closed_reason))
            {{ $training->closed_reason }}
        @else
            No reason given
        @endif
    </div>
@endif

@if($training->status === \App\Helpers\TrainingStatus::CLOSED_BY_STUDENT)
    <div class="alert alert-warning" role="alert">
        <b>Training closed by student</b>
    </div>
@endif

<div id="otl-alert" class="alert alert-info" style="display: none" role="alert">
    <b id="otl-type"></b><br>
    <i class="fa fa-clock"></i>&nbsp;Valid for 7 days<br>
    <i class="fa fa-link"></i>&nbsp;<a id="otl-link" href=""></a>&nbsp;<button type="button" id="otl-link-copy-btn" class="btn btn-sm"><i class="fas fa-copy"></i></button>
</div>


{{-- The masthead: what this training is, and every action on it. --}}
@include('vatssa.parts.training-identity', ['training' => $training, 'types' => $types])

{{-- The deadlines, above the tabs on purpose.

     Nothing in here is a fact about the training; they are things running out
     -- a roster window closing, a confirmation not yet given. A deadline
     filed behind a tab is a deadline nobody sees until it has passed, which is
     the one outcome the panel exists to prevent. --}}
<div class="row">
    <div class="col-xl-12">
        {{-- The Platforms CARD is gone from this page. Its facts moved up into
             the summary above, where they are read as part of the record
             rather than as a separate errand. Two panels showing the same two
             badges meant one of them was the one people trusted, and it was
             never going to be the one furthest down.

             The roster warning stays, because it is an alert rather than a
             fact: a lapsing roster place is a deadline, and deadlines do not
             belong in a definition list. --}}
        @include('vatssa.parts.roster-warning', ['user' => $training->user])

        @include('vatssa.parts.confirmations', [
            'training' => $training,
            'trainingInterests' => $trainingInterests,
        ])
    </div>
</div>

{{-- ---------------------------------------------------- the training file --}}
{{--
    One box with tabs, the same shape as a member profile.

    Upstream ran this as three columns of cards, so the page had three reading
    orders and the timeline -- the thing a coordinator actually opens a training
    for -- was a third of the width with reports and tasks competing beside it.

    THE TAB LIST IS BUILT ONCE and both the strip and the panes read from it.
    Two hand-kept lists drift into a tab that opens nothing, or a pane with no
    tab, and the second is content rendered for somebody meant never to reach
    it.
--}}
@php
    // Fully qualified, no `use`. Blade compiles a view into a method scope, so
    // a `use` statement inside @php is a fatal error rather than an import.
    $viewer = Auth::user();

    // The timeline first: it is what a coordinator opens a training to read.
    $tabs = ['timeline' => ['label' => 'Timeline', 'icon' => 'fa-stream']];

    // Reports is NOT gated here, deliberately. The card already gates its list
    // on `viewAny` and its buttons on `create`, and those are not the same
    // people -- a mentor who may file a report but not read the history would
    // lose the button along with the tab. Restructuring a layout must not move
    // a permission boundary.
    $tabs['reports'] = ['label' => 'Reports', 'icon' => 'fa-file-lines'];

    $tabs['application'] = ['label' => 'Application', 'icon' => 'fa-file-signature'];
    $tabs['theory'] = ['label' => 'Theory', 'icon' => 'fa-book'];
    $tabs['tasks'] = ['label' => 'Tasks', 'icon' => 'fa-list-check'];
    $tabs['messages'] = ['label' => 'Messages', 'icon' => 'fa-envelope'];

    if ($viewer->can(\App\Models\Vatssa\InternalNote::permissionFor(\App\Models\Vatssa\InternalNote::SCOPE_TRAINING))) {
        $tabs['notes'] = ['label' => 'Internal notes', 'icon' => 'fa-lock'];
    }

    // Last, because it is the only tab that CHANGES the training rather than
    // reporting on it.
    if ($viewer->can('update', $training)) {
        $tabs['manage'] = ['label' => 'Manage', 'icon' => 'fa-sliders'];
    }

    $firstTab = array_key_first($tabs);
@endphp

<div class="card shadow mb-4">
    <div class="card-body">
        {{-- The strip is in the BODY, not a card-header: this fork restyles
             .nav-tabs as flat underlines, so there is no notch to cut into a
             header, and its own underline is the separator. --}}
        <ul class="nav nav-tabs mb-3" role="tablist">
            @foreach($tabs as $key => $tab)
                <li class="nav-item" role="presentation">
                    <button class="nav-link @if($key === $firstTab) active @endif"
                            id="tab-{{ $key }}"
                            data-bs-toggle="tab"
                            data-bs-target="#pane-{{ $key }}"
                            type="button"
                            role="tab"
                            aria-controls="pane-{{ $key }}"
                            aria-selected="{{ $key === $firstTab ? 'true' : 'false' }}">
                        <i class="fas {{ $tab['icon'] }}"></i>&nbsp;{{ $tab['label'] }}
                    </button>
                </li>
            @endforeach
        </ul>

        <div class="tab-content">
            {{-- Timeline --}}
            @isset($tabs['timeline'])
                <div class="tab-pane fade @if($firstTab === 'timeline') show active @endif"
                     id="pane-timeline" role="tabpanel" aria-labelledby="tab-timeline" tabindex="0">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 fw-bold text-white">
                                Timeline
                            </h6>
                        </div>
                        @can('comment', [\App\Models\TrainingActivity::class, \App\Models\Training::find($training->id)])
                            <form action="{{ route('training.activity.comment') }}" method="POST">
                                @csrf
                                <div class="input-group">
                                    <input type="hidden" name="training_id" value="{{ $training->id }}">
                                    <input type="hidden" name="update_id" id="activity_update_id" value="">
                                    <input type="text" name="comment" id="activity_comment" class="form-control border" placeholder="Your internal comment ..." maxlength="512">
                                    <button class="btn btn-outline-primary" id="activity_button" type="submit">Comment</button>
                                </div>
                            </form>
                        @endcan
                        <div class="timeline">
                            <ul class="sessions">
                                @foreach($activities as $activity)
                                    @can('view', [\App\Models\TrainingActivity::class, \App\Models\Training::find($training->id), $activity->type])
                                        <li data-id="{{ $activity->id }}">
                                            <div class="time">
                                                @if($activity->type == "STATUS" || $activity->type == "TYPE")
                                                    <i class="fas fa-right-left"></i>
                                                @elseif($activity->type == "MENTOR")
                                                    @if($activity->new_data)
                                                        <i class="fas fa-user-plus"></i>
                                                    @elseif($activity->old_data)
                                                        <i class="fas fa-user-minus"></i>
                                                    @endif
                                                @elseif($activity->type == "PAUSE")
                                                    <i class="fas fa-circle-pause"></i>
                                                @elseif($activity->type == "ENDORSEMENT")
                                                    <i class="fas fa-check-square"></i>
                                                @elseif($activity->type == "RATING")
                                                    <i class="fas fa-list-check"></i>
                                                @elseif($activity->type == "COMMENT")
                                                    <i class="fas fa-comment"></i>
                                                @elseif($activity->type == 'PRETRAINING')
                                                    <i class="fas fa-graduation-cap"></i>
                                                @endif

                                                {{-- VATSSA: say SYSTEM out loud.

                                                     A null actor is how the pipeline signs its work --
                                                     BridgeController::comment() and close() both pass null
                                                     deliberately, so a reader in a year can tell the bot moved
                                                     somebody rather than a person.

                                                     But this printed a name only when there WAS one, so those
                                                     rows rendered with no author at all: just a date. The
                                                     distinction the null carries never reached the page. --}}
                                                @isset($activity->triggered_by_id)
                                                    {{ \App\Models\User::find($activity->triggered_by_id)?->name ?? "Deleted account" }} —
                                                @else
                                                    <span class="badge bg-secondary">System</span> —
                                                @endisset

                                                {{ $activity->created_at->toEuropeanDateTime() }}
                                                @can('comment', [\App\Models\TrainingActivity::class, \App\Models\Training::find($training->id)])
                                                    @if($activity->type == "COMMENT" && now() <= $activity->created_at->addDays(1) && $activity->triggered_by_id == \Auth::user()->id)
                                                        <button class="btn btn-sm float-end" onclick="updateComment({{ $activity->id }}, '{{ $activity->comment }}')"><i class="fas fa-pencil"></i></button>
                                                    @endif
                                                @endcan
                                            </div>
                                            <p>

                                                @if($activity->type == "STATUS")
                                                    @if(($activity->new_data == -2 || $activity->new_data == -4) && isset($activity->comment))
                                                        Status changed from <span class="badge text-bg-light">{{ \App\Helpers\TrainingStatus::from((int) $activity->old_data)->label() }}</span>
                                                    to <span class="badge text-bg-light">{{ \App\Helpers\TrainingStatus::from((int) $activity->new_data)->label() }}</span>
                                                    with reason <span class="badge text-bg-light">{{ $activity->comment }}</span>
                                                    @else
                                                        Status changed from <span class="badge text-bg-light">{{ \App\Helpers\TrainingStatus::from((int) $activity->old_data)->label() }}</span>
                                                    to <span class="badge text-bg-light">{{ \App\Helpers\TrainingStatus::from((int) $activity->new_data)->label() }}</span>
                                                    @endif
                                                @elseif($activity->type == "TYPE")
                                                    Training type changed from <span class="badge text-bg-light">{{ \App\Http\Controllers\TrainingController::$types[$activity->old_data]["text"] }}</span>
                                                    to <span class="badge text-bg-light">{{ \App\Http\Controllers\TrainingController::$types[$activity->new_data]["text"] }}</span>
                                                @elseif($activity->type == "MENTOR")
                                                    @if($activity->new_data)
                                                        <span class="badge text-bg-light">{{ \App\Models\User::find($activity->new_data)->name }}</span> assigned as mentor
                                                    @elseif($activity->old_data)
                                                    <span class="badge text-bg-light">{{ \App\Models\User::find($activity->old_data)->name }}</span> removed as mentor
                                                    @endif
                                                @elseif($activity->type == "PAUSE")
                                                    @if($activity->new_data)
                                                        Training paused
                                                    @else
                                                        Training unpaused
                                                    @endif
                                                @elseif($activity->type == "ENDORSEMENT")
                                                    @if(\App\Models\Endorsement::find($activity->new_data) !== null)
                                                        @empty($activity->comment)
                                                            <span class="badge text-bg-light">
                                                                {{ str(\App\Models\Endorsement::find($activity->new_data)->type)->lower()->ucfirst() }} endorsement
                                                            </span> granted, valid to
                                                            <span class="badge text-bg-light">
                                                                @isset(\App\Models\Endorsement::find($activity->new_data)->valid_to)
                                                                    {{ \App\Models\Endorsement::find($activity->new_data)->valid_to->toEuropeanDateTime() }}
                                                                @else
                                                                    Forever
                                                                @endisset
                                                            </span>
                                                        @else
                                                            <span class="badge text-bg-light">
                                                                {{ str(\App\Models\Endorsement::find($activity->new_data)->type)->lower()->ucfirst() }} endorsement
                                                            </span> granted, valid to
                                                            <span class="badge text-bg-light">
                                                                @isset(\App\Models\Endorsement::find($activity->new_data)->valid_to)
                                                                    {{ \App\Models\Endorsement::find($activity->new_data)->valid_to->toEuropeanDateTime() }}
                                                                @else
                                                                    Forever
                                                                @endisset
                                                            </span>
                                                            for positions:
                                                            @foreach(explode(',', $activity->comment) as $p)
                                                                <span class="badge text-bg-light">{{ $p }}</span>
                                                            @endforeach
                                                        @endempty
                                                    @endif
                                                @elseif($activity->type == "RATING")
                                                    @isset($activity->rating)
                                                        <span class="badge text-bg-light">{{ $activity->rating->name }}</span> part completed
                                                    @endisset
                                                @elseif($activity->type == "COMMENT")
                                                    {!! nl2br(e($activity->comment)) !!}

                                                    @if($activity->created_at != $activity->updated_at)
                                                        <span class="text-muted">(edited)</span>
                                                    @endif
                                                @elseif($activity->type == "PRETRAINING")
                                                    Pre-training marked as
                                                    <span class="badge text-bg-light">
                                                        @if($activity->new_data)
                                                            <i class="fas fa-check"></i>
                                                            Completed
                                                        @else
                                                            <i class="fas fa-xmark"></i>
                                                            Not completed
                                                        @endif
                                                    </span>
                                                @endif

                                            </p>
                                        </li>
                                    @endcan
                                @endforeach
                                <li>
                                    <div class="time">
                                        <i class="fas fa-flag"></i>
                                        @isset($training->created_by)
                                            {{ \App\Models\User::find($training->created_by)->name }} —
                                        @endisset
                                        {{ $training->created_at->toEuropeanDateTime() }}
                                    </div>
                                    <p>
                                        Training created
                                    </p>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            @endisset

            {{-- Reports and examinations --}}
            @isset($tabs['reports'])
                <div class="tab-pane fade @if($firstTab === 'reports') show active @endif"
                     id="pane-reports" role="tabpanel" aria-labelledby="tab-reports" tabindex="0">
                    <div class="card shadow mb-4 ">
                        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">

                            @if($training->status->isInProgress())
                                <h6 class="m-0 fw-bold text-white">
                            @else
                                <h6 class="m-0 mt-1 mb-2 fw-bold text-white">
                            @endif
                                Training Reports
                            </h6>

                            @if(
                                \Auth::user()->can('create', [\App\Models\OneTimeLink::class, $training, \App\Models\OneTimeLink::TRAINING_REPORT_TYPE]) ||
                                \Auth::user()->can('create', [\App\Models\OneTimeLink::class, $training, \App\Models\OneTimeLink::TRAINING_EXAMINATION_TYPE]) ||
                                $training->status->isInProgress()
                            )
                                <div class="dropdown" style="display: inline;">
                                    <button class="btn btn-light btn-icon dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="fas fa-plus"></i> Create
                                    </button>

                                    <div class="dropdown-menu" aria-labelledby="dropdownMenuButton">
                                        @can('create', [\App\Models\TrainingReport::class, $training])
                                            @if($training->status->isInProgress())
                                                <a class="dropdown-item" href="{{ route('training.report.create', ['training' => $training->id]) }}"><i class="fas fa-file"></i> Training Report</a>
                                            @endif
                                        @else
                                            <a class="dropdown-item disabled" href="#"><i class="fas fa-lock"></i>&nbsp;Training Report</a>
                                        @endcan

                                        @can('create', [\App\Models\TrainingExamination::class, $training])
                                            @if($training->status === \App\Helpers\TrainingStatus::AWAITING_EXAM)
                                                <a class="dropdown-item" href="{{ route('training.examination.create', ['training' => $training->id]) }}"><i class="fas fa-file"></i> Exam Report</a>
                                            @endif
                                        @else
                                            <a class="dropdown-item disabled" href="#"><i class="fas fa-lock"></i>&nbsp;Exam Report</a>
                                        @endcan

                                        @can('create', [\App\Models\OneTimeLink::class, $training, \App\Models\OneTimeLink::TRAINING_REPORT_TYPE])
                                            <button class="dropdown-item" id="getOneTimeLinkReport"><i class="fas fa-link"></i> Report one-time link</button>
                                        @endif
                                        @can('create', [\App\Models\OneTimeLink::class, $training, \App\Models\OneTimeLink::TRAINING_EXAMINATION_TYPE])
                                            <button class="dropdown-item" id="getOneTimeLinkExam"><i class="fas fa-link"></i> Examination one-time link</button>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                        <div class="card-body p-0">

                            @can('viewAny', [\App\Models\TrainingReport::class, $training])
                                <div class="accordion" id="reportAccordion">
                                    @if ($reportsAndExams->count() == 0)
                                        <div class="card-text text-primary p-3">
                                            No training reports yet.
                                        </div>
                                    @else

                                        @foreach($reportsAndExams as $reportModel)
                                            @if(is_a($reportModel, '\App\Models\TrainingReport'))
                                                <x-training.training-report :report="$reportModel" />
                                            @else
                                                <x-training.exam-report :report="$reportModel" />
                                            @endif
                                        @endforeach
                                    @endif
                                </div>
                            @else
                                <div class="card-text text-primary p-3">
                                    You don't have access to see the training reports.
                                </div>
                            @endcan

                        </div>
                    </div>
                </div>
            @endisset

            {{-- Application --}}
            @isset($tabs['application'])
                <div class="tab-pane fade @if($firstTab === 'application') show active @endif"
                     id="pane-application" role="tabpanel" aria-labelledby="tab-application" tabindex="0">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 fw-bold text-white">
                                Application
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="card bg-light mb-3">
                                <div class="card-body">

                                    @if($training->english_only_training)
                                        <i class="fas fa-flag-usa"></i>&nbsp;&nbsp;Requesting training in English only<br>
                                    @else
                                        <i class="fas fa-flag"></i>&nbsp;&nbsp;Requesting training in local language or English<br>
                                    @endif

                                    @isset($training->experience)
                                        <i class="fas fa-book"></i>&nbsp;&nbsp;{{ $experiences[$training->experience]["text"] }}
                                    @endisset

                                </div>
                            </div>
                        </div>

                        <div class="p-4">
                            <p class="fw-bold text-primary-emphasis">
                                <i class="fas fa-envelope-open-text"></i>&nbsp;Letter of motivation
                            </p>

                            @if(empty($training->motivation))
                                <p><i>Not provided / relevant</i></p>
                            @else
                                <p>{{ $training->motivation }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endisset

            {{-- Theory --}}
            @isset($tabs['theory'])
                <div class="tab-pane fade @if($firstTab === 'theory') show active @endif"
                     id="pane-theory" role="tabpanel" aria-labelledby="tab-theory" tabindex="0">
                    {{-- VATSSA: the practical exam being arranged, if there is one.
                         A coordinator opening a training asks "where is this person up
                         to", and an exam in flight is most of that answer. Summary and a
                         link only -- every action lives on the exam page, because two
                         places to do the same thing is one place that goes stale. --}}


                    @include('vatssa.parts.theory', [
                        'user' => $training->user,
                        'onlyRatings' => $training->ratings->pluck('name')->all(),
                        'panelTitle' => 'Theory for this rating',
                        // Only the Standard track sits theory. Refresh, transfer,
                        // fast-track and familiarisation students already hold the rating.
                        'needsNoTheory' => $training->type != 1,
                    ])
                </div>
            @endisset

            {{-- Related tasks --}}
            @isset($tabs['tasks'])
                <div class="tab-pane fade @if($firstTab === 'tasks') show active @endif"
                     id="pane-tasks" role="tabpanel" aria-labelledby="tab-tasks" tabindex="0">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 fw-bold text-white">
                                Related Tasks
                            </h6>
                        </div>
                        <div class="card-body {{ $relatedTasks->count() == 0 ? '' : 'p-0' }}">

                            @if($relatedTasks->count() == 0)
                                <p class="mb-0">No related task history</p>
                            @else
                                <div class="table-responsive">
                                    <table class="table table-sm table-leftpadded mb-0" width="100%" cellspacing="0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Task</th>
                                                <th>Creator</th>
                                                <th>Assignee</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($relatedTasks as $task)
                                            <tr>
                                                <td>
                                                    <i class="fas {{ $task->type()->getIcon() }}" data-bs-toggle="tooltip" data-bs-placement="top"></i>
                                                    {{ $task->type()->getText($task) }}
                                                </td>
                                                <td>
                                                    {{-- VATSSA: nullable, and now actually
                                                         null -- the automation raises
                                                         requests with no creator. --}}
                                                    {{ $task->creator?->name ?? 'System' }}
                                                </td>
                                                <td>
                                                    {{ $task->assignee->name }}
                                                </td>
                                                <td>
                                                    @if($task->status == \App\Helpers\TaskStatus::COMPLETED)
                                                        <i class="fas fa-check text-success"></i>
                                                    @elseif($task->status == \App\Helpers\TaskStatus::DECLINED)
                                                        <i class="fas fa-times text-danger"></i>
                                                    @elseif($task->status == \App\Helpers\TaskStatus::PENDING)
                                                        <i class="fas fa-hourglass text-warning"></i>
                                                    @endif

                                                    @if($task->status == \App\Helpers\TaskStatus::COMPLETED || $task->status == \App\Helpers\TaskStatus::DECLINED)
                                                        <span class="text-muted" title="{{ $task->closed_at->toEuropeanDateTime() }}">{{ $task->closed_at->diffForHumans() }}</span>
                                                    @else
                                                        <span class="text-muted" title="{{ $task->created_at->toEuropeanDateTime() }}">{{ $task->created_at->diffForHumans() }}</span>
                                                    @endif

                                                </td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                        </div>
                    </div>
                </div>
            @endisset

            {{-- Messages sent --}}
            @isset($tabs['messages'])
                <div class="tab-pane fade @if($firstTab === 'messages') show active @endif"
                     id="pane-messages" role="tabpanel" aria-labelledby="tab-messages" tabindex="0">
                    @include('vatssa.parts.message-log', ['training' => $training])
                </div>
            @endisset

            {{-- Internal notes --}}
            @isset($tabs['notes'])
                <div class="tab-pane fade @if($firstTab === 'notes') show active @endif"
                     id="pane-notes" role="tabpanel" aria-labelledby="tab-notes" tabindex="0">
                    @include('vatssa.parts.internal-notes', [
                        'scope' => \App\Models\Vatssa\InternalNote::SCOPE_TRAINING,
                        'notes' => \App\Models\Vatssa\InternalNote::where('training_id', $training->id)
                            ->where('scope', \App\Models\Vatssa\InternalNote::SCOPE_TRAINING)
                            ->with('author')->latest()->get(),
                        'action' => route('vatssa.notes.training', $training),
                    ])
                </div>
            @endisset

            {{-- Manage --}}
            @isset($tabs['manage'])
                <div class="tab-pane fade @if($firstTab === 'manage') show active @endif"
                     id="pane-manage" role="tabpanel" aria-labelledby="tab-manage" tabindex="0">
                    @can('update', $training)
                        <div class="card shadow mb-4">

                            <div class="card-body">
                                <form action="{{ route('training.update.details', ['training' => $training->id]) }}" method="POST">
                                    @method('PATCH')
                                    @csrf

                                    <div class="mb-3">

                                        @if($activeTrainingInterest)
                                            <div class="alert alert-warning" role="alert">
                                                <i class="fas fa-exclamation-triangle"></i>&nbsp;This training has an active interest request pending.
                                            </div>
                                        @endif

                                        @php
                                            $pipelineOwned = in_array($training->status, [
                                                \App\Helpers\TrainingStatus::IN_QUEUE,
                                                \App\Helpers\TrainingStatus::PRE_TRAINING,
                                                \App\Helpers\TrainingStatus::AWAITING_MENTOR,
                                            ], true);
                                        @endphp

                                        {{-- VATSSA: say WHY the list is short, rather than
                                             leaving somebody to wonder where the options
                                             went. A control that silently offers less than
                                             it did yesterday reads as broken. --}}
                                        @if($pipelineOwned)
                                            <div class="alert alert-secondary py-2 mb-2">
                                                <small>
                                                    <i class="fas fa-robot"></i>&nbsp;
                                                    <strong>{{ $training->status->label() }}</strong> is set by the
                                                    training pipeline, from the student\'s Moodle enrolment, their
                                                    theory result and whether a mentor is assigned. It cannot be
                                                    changed by hand — the next cycle would move them straight back.
                                                    <span class="d-block mt-1">
                                                        Pausing still works, and closing is still available if the
                                                        student has dropped out.
                                                    </span>
                                                </small>
                                            </div>
                                        @endif

                                        <label class="form-label" for="trainingStateSelect">Select training state</label>
                                        <select class="form-select" name="status" id="trainingStateSelect" @if(Auth::user()->cannot('update', $training)) disabled @endif>
                                            {{-- Lifecycle order, not declaration order:
                                                 AWAITING_MENTOR is stored as 4 so nothing
                                                 had to be renumbered, and cases() would
                                                 put it last in the list. --}}
                                            @foreach(\App\Helpers\TrainingStatus::inLifecycleOrder() as $status)
                                                {{-- VATSSA: context-aware. The pipeline owns in-queue,
                                                     pre-training and awaiting-mentor; the one manual move
                                                     is active training back to awaiting a mentor. --}}
                                                @if($status->isAssignableFrom($training->status))
                                                    <option value="{{ $status->value }}" @selected($training->status === $status)>{{ $status->label() }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>

                                    <div class="mb-3" id="closedReasonInput" style="display: none">
                                        <label class="form-label" for="trainingCloseReason">Closed reason</label>
                                        <input type="text" id="trainingCloseReason" class="form-control" name="closed_reason" placeholder="{{ $training->closed_reason }}" maxlength="65">
                                    </div>

                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="check1" name="paused_at" {{ $training->paused_at ? "checked" : "" }} @if(Auth::user()->cannot('update', $training)) disabled @endif>
                                        <label class="form-check-label" for="check1">
                                            Paused
                                            @if(isset($training->paused_at))
                                                <span class='badge bg-danger'>{{ \Carbon\Carbon::create($training->paused_at)->diffForHumans(['parts' => 2]) }}</span>
                                            @endif
                                        </label>
                                    </div>

                                    <hr>

                                    @can('update', $training)
                                    <div class="mb-3">
                                        <label class="form-label" for="assignMentors">Assigned mentors: <span class="badge bg-secondary">Ctrl/Cmd+Click</span> to select multiple</label>
                                        <select multiple class="form-select" name="mentors[]" id="assignMentors">
                                            @foreach($trainingMentors as $mentor)
                                                <option value="{{ $mentor->id }}" {{ ($training->mentors->contains($mentor->id)) ? "selected" : "" }}>{{ $mentor->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @endcan

                                    <button type="submit" id="training-submit-btn" class="btn btn-primary" onclick="handleSubmit(event)">Save
                                        <div class="submit-spinner spinner-border spinner-border-sm" role="status" style="display: none;">&nbsp;</div>
                                    </button>

                                </form>
                            </div>
                        </div>
                    @endcan
                </div>
            @endisset
        </div>
    </div>
</div>

@foreach($requestTypes as $requestType)
    @if($requestType->allowNonVatsimRatings() == true || ($requestType->allowNonVatsimRatings() == false && $training->hasVatsimRatings() == true))
        @include('training.parts.taskmodal', ['requestType' => $requestType, 'training' => $training])
    @endif
@endforeach

@if($showCompletionControl)
    @can('update', $training)
        @if($canCompletePartially)
            @include('training.parts.completepartmodal', ['training' => $training, 'completablePart' => $completablePart, 'otherOutstandingRatings' => $otherOutstandingRatings, 'upgradeRequestedForPart' => $upgradeRequestedForPart])
        @endif
        @include('training.parts.completetrainingmodal', ['training' => $training, 'outstandingRatings' => $outstandingRatings, 'outstandingEndorsementRatings' => $outstandingEndorsementRatings])
    @endcan
@endif

@endsection

@section('js')

    {{-- Remember which tab was open, exactly as the member profile does.

         A training is a page people reload constantly -- after filing a report,
         after a status change -- and landing back on the Timeline every time
         makes the tabs feel like they lost your place. replaceState rather than
         assigning location.hash, which would scroll the page off the masthead. --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var strip = document.querySelector('[role="tablist"]');
            if (!strip || !window.bootstrap) return;

            var wanted = window.location.hash.replace('#tab-', '');
            if (wanted) {
                var trigger = document.getElementById('tab-' + wanted);
                // Only a tab that exists for THIS reader.
                if (trigger) {
                    bootstrap.Tab.getOrCreateInstance(trigger).show();
                }
            }

            strip.addEventListener('shown.bs.tab', function (event) {
                history.replaceState(null, '', '#' + event.target.id);
            });
        });
    </script>


    <!-- One Time Links -->
    <script>

        // Generate a one time report link
        var getOneTimeLinkReport = document.getElementById('getOneTimeLinkReport')
        if(getOneTimeLinkReport){
            getOneTimeLinkReport.addEventListener('click', async function (event) {
                event.preventDefault();
                event.target.disabled = true
                let route = await getOneTimeLink('{!! \App\Models\OneTimeLink::TRAINING_REPORT_TYPE !!}');
                event.target.disabled = false

                document.getElementById('otl-alert').style.display = "block";
                document.getElementById('otl-type').innerHTML = "Training Report one-time link";
                document.getElementById('otl-link').href = route
                document.getElementById('otl-link').innerHTML = route
                document.getElementById('otl-link-copy-btn').onclick = function(){navigator.clipboard.writeText(route)}
            });
        }


        // Generate a one time exam report link
        var getOneTimeLinkExam = document.getElementById('getOneTimeLinkExam')
        if(getOneTimeLinkExam){
            getOneTimeLinkExam.addEventListener('click', async function (event) {
                event.preventDefault();
                event.target.disabled = true
                let route = await getOneTimeLink('{!! \App\Models\OneTimeLink::TRAINING_EXAMINATION_TYPE !!}');
                event.target.disabled = false

                document.getElementById('otl-alert').style.display = "block";
                document.getElementById('otl-type').innerHTML = "Examination Report";
                document.getElementById('otl-link').href = route
                document.getElementById('otl-link').innerHTML = route
                document.getElementById('otl-link-copy-btn').onclick = function(){navigator.clipboard.writeText(route)}
            });
        }

        async function getOneTimeLink(type) {
            return '{!! env('APP_URL') !!}' + '/training/onetime/' + await getOneTimeLinkKey(type);
        }

        async function getOneTimeLinkKey(type) {
            let key;

            const response = await fetch('{{ route('training.onetimelink.store', ['training' => $training]) }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{!! csrf_token() !!}',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ type }),
            })
            .then(response => {
                if (response.ok) {
                    return response.json();
                } else {
                    console.error(response);
                    alert('An error occurred while trying to generate the one-time link.');
                }
            })
            .catch(error => {
                console.error(error);
                alert('An error occurred while trying to generate the one-time link.');
            })

            return response.key;
        }

        function updateComment(id, oldText){
            const commentInput = document.getElementById('activity_comment')

            document.getElementById('activity_update_id').value = id
            commentInput.value = oldText
            document.getElementById('activity_button').innerHTML = 'Update'

            // Flash the comment field to show it's now holding the comment
            // being edited. The colour lives in .flash-highlight so it follows
            // the active theme; setting it inline here would stick around and
            // override the themed background.
            commentInput.classList.remove('flash-highlight')
            void commentInput.offsetWidth // reflow, so a repeat edit restarts the animation
            commentInput.classList.add('flash-highlight')
            commentInput.addEventListener('animationend', function(){
                commentInput.classList.remove('flash-highlight')
            }, { once: true })
        }

    </script>

    <!-- Training report accordian -->
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            // Add minus icon for collapse element which is open by default
            var showCollapses = document.querySelectorAll(".collapse.show");
            showCollapses.forEach(function(collapse) {
                var cardHeader = collapse.previousElementSibling;
                var icon = cardHeader.querySelector(".fas");
                if (icon) {
                    icon.classList.add("fa-chevron-down");
                    icon.classList.remove("fa-chevron-right");
                }
            });

            // Toggle plus minus icon on show hide of collapse element
            var collapses = document.querySelectorAll(".collapse");
            collapses.forEach(function(collapse) {
                collapse.addEventListener('show.bs.collapse', function() {
                    var cardHeader = collapse.previousElementSibling;
                    var icon = cardHeader.querySelector(".fas");
                    if (icon) {
                        icon.classList.remove("fa-chevron-right");
                        icon.classList.add("fa-chevron-down");
                    }
                });

                collapse.addEventListener('hide.bs.collapse', function() {
                    var cardHeader = collapse.previousElementSibling;
                    var icon = cardHeader.querySelector(".fas");
                    if (icon) {
                        icon.classList.remove("fa-chevron-down");
                        icon.classList.add("fa-chevron-right");
                    }
                });
            });

            // Closure reason input
            var trainingStateSelect = document.querySelector('#trainingStateSelect');
            if(trainingStateSelect){
                toggleClosureReasonField(document.querySelector('#trainingStateSelect').value);

                var trainingStateSelect = document.querySelector('#trainingStateSelect');
                if (trainingStateSelect) {
                    trainingStateSelect.addEventListener('change', function () {
                        toggleClosureReasonField(trainingStateSelect.value);
                    });
                }

                function toggleClosureReasonField(val) {
                    var closedReasonInput = document.querySelector('#closedReasonInput');
                    if (closedReasonInput) {
                        if (val == -2) {
                            closedReasonInput.style.display = 'block';
                        } else {
                            closedReasonInput.style.display = 'none';
                        }
                    }
                }
            }

            var markdownContentLinks = document.querySelectorAll("#markdown-content p a, #markdown-improve p a");
            markdownContentLinks.forEach(function(link) {
                link.setAttribute('target', '_blank');
            });
        });
    </script>

    <!-- Spinner on submission -->
    <script>
        function handleSubmit(event) {
            event.preventDefault();
            document.querySelector('.submit-spinner').style.display = 'inherit';
            event.target.disabled = true;
            event.target.closest('form').submit();
        }
    </script>
@endsection
