@extends('layouts.app')

@section('title', 'Dashboard')
@section('content')

{{-- Success message fed via JS for TR  --}}
<div class="alert alert-success d-none" id="success-message"></div>

@if($dueInterestRequest)
<div class="alert alert-warning" role="alert">
    <i class="fas fa-exclamation-triangle"></i>&nbsp;&nbsp;Please confirm your continued training interest by <a href="{{ route('training.confirm.interest', ['training' => $dueInterestRequest->training->id, 'key' => $dueInterestRequest->key] ) }}">clicking here</a>, within the deadline at {{ $dueInterestRequest->deadline->toEuropeanDate() }}. Your training will be otherwise be closed.
</div>
@endif

@if($atcInactiveMessage)
<div class="alert alert-warning" role="alert">
    <i class="fas fa-exclamation-triangle"></i>&nbsp;&nbsp;Your ATC rating is marked as inactive in this {{ config('app.mode') }}. <a href="{{ Setting::get('linkContact') }}" target="_blank">Contact {{ Setting::get('atcActivityContact') }}</a> to request a refresh or transfer training to be allowed to control in our airspace.
</div>
@endif

@if($completedTrainingMessage)
<div class="alert alert-success" role="alert">
    <i class="fas fa-star"></i>&nbsp;<b>Congratulations on your completed training!</b>&nbsp;<i class="fas fa-star"></i> You'll receive an email from VATSIM when your rating has been upgraded and ready to be used.
</div>
@endif

@if($workmailRenewal)
<div class="alert alert-warning" role="alert">
    <i class="fas fa-exclamation-triangle"></i>&nbsp;&nbsp;Your registered work e-mail address expires soon. <a href="{{ route('user.settings.extendworkmail') }}">Click here to extend for another 30 days</a>. If not extended, all e-mails will go to your default VATSIM account e-mail upon expire.
</div>
@endif

@if($activeVote)
<div class="alert alert-info" role="alert">
    <i class="fas fa-vote-yea"></i>&nbsp;&nbsp;Vote <i>"{{ $activeVote->question }}"</i> is available. Vote closes {{ \Carbon\Carbon::create($activeVote->end_at)->toEuropeanDateTime() }}. <a href="{{ route('vote.show', $activeVote) }}">Click here to vote</a>.
</div>
@endif

@if($cronJobError)
{{-- VATSSA: says what to check, not just that something is wrong.
     "Are the cron jobs set up according to the manual" is true and useless at
     three in the morning. The scheduler runs from a systemd timer per
     environment; the two commands below are the whole diagnosis. --}}
<div class="alert alert-danger" role="alert">
    <i class="fas fa-exclamation-triangle"></i>&nbsp;&nbsp;<b>The task scheduler is not running.</b>
    Nothing scheduled is happening &mdash; no roster expiry warnings, no mentor
    watch, no member data sync, no endorsement cleanup, no task notifications.
    <br>
    <small class="d-block mt-2">
        On the VPS:
        <code>systemctl list-timers 'control-center-tasks@*'</code>
        then
        <code>journalctl -u control-center-tasks@{{ config('app.env') }}.service --since '1 hour ago'</code>.
        Last successful run:
        {{ \Carbon\Carbon::parse(Setting::get('_lastCronRun', '2000-01-01'))->diffForHumans() }}.
    </small>
</div>
@endif

@if($oudatedVersionWarning)
<div class="alert alert-info" role="alert">
    <i class="fas fa-info-circle"></i>&nbsp;&nbsp;<b>Update Available:</b> Control Center {{ Setting::get('_updateAvailable') }} is available. You are running v{{ config('app.version') }}. <a href="https://github.com/Vatsim-Scandinavia/controlcenter/releases" target="_blank">See details here.</a>
</div>
@endif

<div class="row">
    <!-- Current rating card  -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-primary shadow h-100 py-2">
            <div class="card-body">
                <div class="row g-0 align-items-center">
                    <div class="col me-2">
                        <div class="fs-sm fw-bold text-uppercase text-gray-600 mb-1">Current Rating</div>
                        <div class="h5 mb-0 fw-bold text-gray-800">{{ $data['rating'] }} ({{ $data['rating_short'] }})</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-id-badge fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Division card -->
    <div class="col-xl-3 col-md-6 mb-4 d-none d-xl-block d-lg-block d-md-block">
        <div class="card border-left-primary shadow h-100 py-2">
            <div class="card-body">
                <div class="row g-0 align-items-center">
                    <div class="col me-2">
                        <div class="fs-sm fw-bold text-uppercase text-gray-600 mb-1">Your associated division</div>
                        <div class="h5 mb-0 fw-bold text-gray-800">
                            @if(config('app.mode') == 'subdivision')
                                {{ $data['division'] }}/{{ $data['subdivision'] }}
                            @else
                                {{ $data['division'] }}
                            @endif
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-earth-europe fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ATC Hours card -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card {{ ($atcHours < Setting::get('atcActivityRequirement', 10)) ? 'border-left-danger' : 'border-left-success' }} shadow h-100 py-2">
            <div class="card-body">
                <div class="row g-0 align-items-center">
                    <div class="col me-2">
                        <div class="fs-sm fw-bold text-success text-uppercase mb-1">ATC Hours (Last {{ Setting::get("atcActivityQualificationPeriod") }} months)</div>
                        <div class="h5 mb-0 fw-bold text-gray-800">{{ $atcHours ? round($atcHours).' hours of '.Setting::get("atcActivityRequirement").' required' : 'N/A' }}</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-clock fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    
    
    <!-- Last training card -->
    <div class="col-xl-3 col-md-6 mb-4 d-none d-xl-block d-lg-block d-md-block">
        <div class="card border-left-info shadow h-100 py-2">
            <div class="card-body">
                <div class="row g-0 align-items-center">
                    <div class="col me-2">
                        <div class="fs-sm fw-bold text-info text-uppercase mb-1">My last training</div>
                        <div class="row g-0 align-items-center">
                            <div class="col-auto">
                                <div class="h5 mb-0 me-3 fw-bold text-gray-800">{{ $data['report'] != null ? $data['report']->report_date->toEuropeanDate() : "-" }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
</div>

<div class="row">
    <!-- Area Chart -->
    <div class="col-xl-8 col-lg-7 ">
        
        @if(\Auth::user()->hasRole('mentor'))
        <div class="card shadow mb-4 d-none d-xl-block d-lg-block d-md-block">
            <!-- Card Header - Dropdown -->
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">My Students</h6>
            </div>
            <!-- Card Body -->
            <div class="card-body {{ sizeof($studentTrainings) == 0 ? '' : 'p-0' }}">
                
                @if (sizeof($studentTrainings) == 0)
                <p class="mb-0">You have no students.</p>
                @else
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-leftpadded mb-0" width="100%" cellspacing="0">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Level</th>
                                <th>Area</th>
                                <th>State</th>
                                <th>Last Training</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($studentTrainings as $training)
                            <tr>
                                <td><a href="{{ $training->path() }}">{{ $training->user->name }}</a></td>
                                <td>
                                    <i class="{{ $types[$training->type]["icon"] }} text-primary"></i>
                                    @foreach($training->ratings as $rating)
                                    @if ($loop->last)
                                    {{ $rating->name }}
                                    @else
                                    {{ $rating->name . " + " }}
                                    @endif
                                    @endforeach
                                </td>
                                <td>{{ $training->area->name }}</td>
                                <td>
                                    <i class="{{ $training->status->icon() }} text-{{ $training->status->color() }}"></i>&ensp;{{ $training->status->label() }}{{ isset($training->paused_at) ? ' (PAUSED)' : '' }}
                                </td>
                                <td>
                                    @if($training->reports->count() > 0)
                                        @php
                                            $reportDate = Carbon\Carbon::make($training->reports->sortBy('report_date')->last()->report_date);
                                            $trainingIntervalExceeded = $reportDate->diffInDays() >= Setting::get('trainingInterval');
                                        @endphp
                                        <span title="{{ $reportDate->toEuropeanDate() }}">
                                            @if($reportDate->isToday())
                                            <span class="{{ ($trainingIntervalExceeded && $training->status !== \App\Helpers\TrainingStatus::AWAITING_EXAM && !$training->paused_at) ? 'text-danger' : '' }}">Today</span>
                                            @elseif($reportDate->isYesterday())
                                            <span class="{{ ($trainingIntervalExceeded && $training->status !== \App\Helpers\TrainingStatus::AWAITING_EXAM && !$training->paused_at) ? 'text-danger' : '' }}">Yesterday</span>
                                            @elseif($reportDate->diffInDays() <= 7)
                                            <span class="{{ ($trainingIntervalExceeded && $training->status !== \App\Helpers\TrainingStatus::AWAITING_EXAM && !$training->paused_at) ? 'text-danger' : '' }}">{{ $reportDate->diffForHumans(['parts' => 1]) }}</span>
                                            @else
                                            <span class="{{ ($trainingIntervalExceeded && $training->status !== \App\Helpers\TrainingStatus::AWAITING_EXAM && !$training->paused_at) ? 'text-danger' : '' }}">{{ $reportDate->diffForHumans(['parts' => 2]) }}</span>
                                            @endif
                                            
                                        </span>
                                    @else
                                        No registered training yet
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
        @endif
        
        <div class="card shadow mb-4">
            <!-- Card Header - Dropdown -->
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">My Trainings</h6>
            </div>
            <!-- Card Body -->
            <div class="card-body {{ $trainings->count() == 0 ? '' : 'p-0' }}">
                
                @if ($trainings->count() == 0)
                <p>You have no registered trainings.</p>
                @else
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-leftpadded mb-0" width="100%" cellspacing="0">
                        <thead class="table-light">
                            <tr>
                                <th>Level</th>
                                <th>Area</th>
                                <th>Period</th>
                                <th>State</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($trainings as $training)
                            <tr>
                                <td>
                                    <a href="{{ $training->path() }}">
                                        @foreach($training->ratings as $rating)
                                        @if ($loop->last)
                                        {{ $rating->name }}
                                        @else
                                        {{ $rating->name . " + " }}
                                        @endif
                                        @endforeach
                                    </a>
                                </td>
                                <td>{{ $training->area->name }}</td>
                                <td>
                                    @if ($training->started_at == null && $training->closed_at == null)
                                    Training not started
                                    @elseif ($training->closed_at == null)
                                    {{ $training->started_at->toEuropeanDate() }} -
                                    @elseif ($training->started_at != null)
                                    {{ $training->started_at->toEuropeanDate() }} - {{ $training->closed_at->toEuropeanDate() }}
                                    @else
                                    N/A
                                    @endif
                                </td>
                                <td>
                                    <i class="{{ $training->status->icon() }} text-{{ $training->status->color() }}"></i>&ensp;{{ $training->status->label() }}{{ isset($training->paused_at) ? ' (PAUSED)' : '' }}
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
    
    <div class="col-xl-4 col-lg-5">
        <div class="card shadow mb-4">
            <!-- Card Header - Dropdown -->
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">Request Training</h6>
            </div>
            <!-- Card Body -->
            <div class="card-body">
                <div class="text-center">
                    <img class="img-fluid px-3 px-sm-4 mb-4" style="width: 25rem;" src="images/undraw_speech_to_text_vatsim.svg" alt="">
                </div>
                <p>Are you interested in becoming an Air Traffic Controller? Wish to receive training for a higher rating? Request training below and you will be notified when a space is available.</p>
                
                @can('apply', \App\Models\Training::class)
                <div class="d-grid">
                    <a href="{{ route('training.apply') }}" class="btn btn-success">
                        Request training
                    </a>
                </div>
                @else
                
                {{-- VATSSA: two states, and only one of them is a refusal.

                     Somebody who already has a training open is not being told
                     no -- they are being told they are in the queue, which is
                     the answer they came for. That keeps upstream's sentence.

                     Everything else says "Not eligible" and nothing more. The
                     pill used to carry the policy's FIRST denial, so a member
                     read "You must join the SSA division to apply" as though it
                     were the whole story, when it was one of several rules in
                     whatever order the policy happened to check them. The
                     reasons live in the list below, where all of them fit. --}}
                @php($hasOpenTraining = \Auth::user()->hasActiveTrainings(true) && Setting::get('trainingEnabled'))

                <div class="btn btn-{{ $hasOpenTraining ? 'success' : 'secondary' }} d-block disabled not-allowed" role="button" aria-disabled="true">
                    @if($hasOpenTraining)
                        <i class="fas fa-check"></i>
                        {{ Gate::inspect('apply', \App\Models\Training::class)->message() }}
                    @else
                        <i class="fas fa-circle-info"></i>
                        Not eligible
                    @endif
                </div>

                {{-- VATSSA: WHAT the requirements are, not only which one
                     refused you first.

                     The pill above is the policy's first denial, one sentence,
                     in whatever order the policy happens to check. Somebody
                     reading it learned one reason at a time and had no way to
                     see the rest. Same rules, listed. --}}
                <div class="mt-3">
                    @include('vatssa.parts.requirements', [
                        'requirements' => \App\Services\Vatssa\MembershipCheck::for(\Auth::user()),
                        'heading' => 'What you need',
                    ])
                </div>
                
                {{-- VATSSA: the four questions, rebuilt.

                     ## What was wrong with it

                     A blue `alert-primary` holding four bold questions and four
                     answers, separated by `<br>`, all inside one `<p>`. Four
                     problems, and they compound:

                     1. An ALERT is the page telling you something has gone
                        wrong. Nothing has; this is help text. The blue slab
                        pulled the eye to the least urgent thing in the card,
                        directly under a list of requirements that actually
                        needed reading.
                     2. `<br>` between items is not separation. Question and
                        answer ran together, and the four pairs ran together with
                        each other, so it read as one blue paragraph.
                     3. The ANSWERS were the links. A whole sentence underlined
                        in link blue is harder to read than the same sentence in
                        plain text, and it gives no clue which words are the
                        action.
                     4. Four chevrons, one per line, decorating nothing.

                     ## What it is now

                     No tint. Hierarchy instead of colour: one quiet section
                     label, then question and answer as two lines, with a
                     hairline between pairs. The link sits on the ACTION --
                     "Read about joining" -- not on the explanation around it.

                     Collapsed by default. Most people opening this card want the
                     requirement list above it; these four are for the person
                     whose answer is not in that list, and that is exactly when
                     the rare case should not crowd out the common one. --}}
                @if(Setting::get('trainingEnabled'))
                    @php($faqId = 'training-faq-' . \Auth::id())

                    <div class="mt-3 pt-3 border-top">
                        <button class="btn btn-link btn-sm p-0 text-decoration-none fw-bold fs-sm text-uppercase"
                                type="button" data-bs-toggle="collapse" data-bs-target="#{{ $faqId }}"
                                aria-expanded="false" aria-controls="{{ $faqId }}">
                            <i class="fas fa-chevron-down"></i>&nbsp;Common questions
                        </button>

                        <div class="collapse mt-2" id="{{ $faqId }}">
                            <dl class="mb-0 fs-sm">
                                <dt>How do I join the division?</dt>
                                <dd class="text-muted border-bottom pb-2 mb-2">
                                    <a href="{{ Setting::get('linkJoin') }}" target="_blank">Read about joining</a>.
                                    You can apply here within 24 hours of the transfer.
                                </dd>

                                <dt>How do I apply as a visiting controller?</dt>
                                <dd class="text-muted border-bottom pb-2 mb-2">
                                    <a href="{{ Setting::get('linkVisiting') }}" target="_blank">Check the visiting page</a>
                                    for what is required.
                                </dd>

                                <dt>My rating is inactive.</dt>
                                <dd class="text-muted border-bottom pb-2 mb-2">
                                    <a href="{{ Setting::get('linkContact') }}" target="_blank">Contact training staff</a>
                                    about refresh or transfer training.
                                </dd>

                                <dt>How long is the queue?</dt>
                                <dd class="text-muted mb-0">
                                    {{ \Auth::user()->getActiveTraining()?->area?->waiting_time
                                        ?? 'Shown on the application page, and in your confirmation email.' }}
                                </dd>
                            </dl>
                        </div>
                    </div>
                @endif
                
                @endcan
            </div>
        </div>
    </div>
    
</div>
@endsection
