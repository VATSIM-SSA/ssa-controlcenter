@extends('layouts.app')

@section('title', 'Feedback #' . $feedback->id)
@section('content')

@php($controllerName = $feedback->referenceUser?->name ?? 'the controller')

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a href="{{ route('reports.feedback') }}" class="btn btn-link px-0 me-2"><i class="fas fa-arrow-left"></i> All feedback</a>
    <span class="badge bg-{{ $feedback->status->color() }}">{{ $feedback->status->label() }}</span>
    @if($feedback->sentiment)
        <span class="badge bg-{{ $feedback->sentiment->color() }}">{{ $feedback->sentiment->label() }}</span>
    @endif
    @if($feedback->wasSent())
        <span class="badge bg-info text-dark">Sent to controller</span>
    @endif
</div>

<div class="row">
    {{-- Left: the feedback, and what the controller has --}}
    <div class="col-xl-8 col-lg-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">Feedback #{{ $feedback->id }}</h6>
                @if($canEdit)
                    <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#feedback-edit-modal">
                        <i class="fas fa-pen"></i> Edit details
                    </button>
                @endif
            </div>
            <div class="card-body">
                <div class="row">
                    <dl class="col-md-6 row mb-0">
                        <dt class="col-5">Controller</dt>
                        <dd class="col-7">
                            @isset($feedback->referenceUser)
                                <a href="{{ route('user.show', $feedback->referenceUser) }}">{{ $feedback->referenceUser->name }}</a>
                                <small class="text-muted">{{ $feedback->referenceUser->id }}</small>
                            @else
                                <span class="text-muted">None</span>
                            @endisset
                        </dd>

                        <dt class="col-5">Position</dt>
                        <dd class="col-7">{{ $feedback->referencePosition?->callsign ?? '—' }}</dd>

                        <dt class="col-5">Session</dt>
                        <dd class="col-7">{{ $feedback->sessionLabel() ?? '—' }}</dd>

                        <dt class="col-5">Area</dt>
                        <dd class="col-7">{{ $feedback->area?->name ?? '—' }}</dd>
                    </dl>
                    <dl class="col-md-6 row mb-0">
                        <dt class="col-5">Submitter</dt>
                        <dd class="col-7">
                            <a href="{{ route('user.show', $feedback->submitter) }}">{{ $feedback->submitter->name }}</a>
                            <small class="text-muted">{{ $feedback->submitter_user_id }}</small>
                        </dd>

                        <dt class="col-5">Flight</dt>
                        <dd class="col-7">{{ $feedback->flightLabel() ?? '—' }}</dd>

                        <dt class="col-5">Received</dt>
                        <dd class="col-7">{{ $feedback->created_at->toEuropeanDateTime() }}</dd>
                    </dl>
                </div>

                <hr>

                <h6 class="fw-bold small text-uppercase text-muted">What the submitter wrote</h6>
                <div class="border rounded p-3 bg-body-tertiary">{!! nl2br(e($feedback->feedback)) !!}</div>
            </div>
        </div>

        <div class="card shadow mb-4" id="feedback-delivery">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold">What {{ $controllerName }} sees</h6>
                @if($feedback->wasSent())
                    <span class="badge bg-info text-dark">{{ $feedback->sent_version->label() }}</span>
                @else
                    <span class="badge bg-light text-dark">Not sent</span>
                @endif
            </div>
            <div class="card-body">
                @if($feedback->wasSent())
                    @if($feedback->sent_version === \App\Helpers\FeedbackDelivery::REWRITTEN)
                        <div class="border rounded p-3 mb-3">{!! nl2br(e($feedback->sent_message)) !!}</div>
                    @else
                        <p class="text-muted">The submitter's words above, without the submitter's name.</p>
                    @endif

                    <dl class="row mb-3">
                        <dt class="col-sm-4">Sent</dt>
                        <dd class="col-sm-8">{{ $feedback->sent_at->toEuropeanDateTime() }} by {{ $feedback->sentBy?->name ?? 'unknown' }}</dd>

                        <dt class="col-sm-4">Acknowledged</dt>
                        <dd class="col-sm-8">
                            @if($feedback->latestAcknowledgement?->acknowledged_at)
                                <i class="fas fa-check text-success"></i> {{ $feedback->latestAcknowledgement->acknowledged_at->toEuropeanDateTime() }}
                            @else
                                <i class="fas fa-hourglass-half text-warning"></i> Not yet
                            @endif
                        </dd>

                        <dt class="col-sm-4">Discord</dt>
                        <dd class="col-sm-8">{{ $feedback->discord_posted_at ? 'Posted ' . $feedback->discord_posted_at->toEuropeanDateTime() : 'Not posted' }}</dd>
                    </dl>

                    @if($canReview)
                        <form action="{{ route('feedback.withdraw', $feedback) }}" method="POST"
                            onsubmit="return confirm({{ \Illuminate\Support\Js::from('Withdraw this feedback from ' . $controllerName . '? It leaves their profile and dashboard' . ($feedback->discord_posted_at ? ' and the Discord post is deleted' : '') . '. The e-mail they already got cannot be recalled.') }})">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-reply"></i> Withdraw from {{ $controllerName }}</button>
                        </form>
                        <small class="form-text text-muted d-block mt-2">To send a different version, withdraw this one first.</small>
                    @endif
                @else
                    @if($feedback->discord_posted_at)
                        <div class="alert alert-warning py-2 small">An earlier Discord post could not be deleted. Remove it in Discord by hand.</div>
                    @endif

                    <p class="mb-3">Nothing has been sent to {{ $controllerName }}. The feedback stays with staff unless you send it.</p>

                    @if($canReview)
                        @if($feedback->referenceUser === null)
                            <small class="text-muted">Set a controller with <b>Edit details</b> to send it.</small>
                        @else
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#feedback-send-modal">
                                <i class="fas fa-paper-plane"></i> Send to {{ $controllerName }}
                            </button>
                        @endif
                    @endif
                @endif
            </div>
        </div>
    </div>

    {{-- Right: review and timeline --}}
    <div class="col-xl-4 col-lg-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">Review</h6>
            </div>
            <div class="card-body">
                <p class="mb-3">
                    @if($feedback->isOpen())
                        <span class="badge bg-warning text-dark">Open</span> Waiting for review.
                    @else
                        <span class="badge bg-secondary">Closed</span>
                        by {{ $feedback->closedBy?->name ?? 'unknown' }}, {{ $feedback->closed_at?->toEuropeanDateTime() }}
                    @endif
                </p>

                <div class="mb-3">
                    <span class="form-label d-block small text-uppercase text-muted fw-bold">Tone</span>
                    @if($canReview)
                        <form action="{{ route('feedback.sentiment', $feedback) }}" method="POST" class="btn-group" role="group" aria-label="Tone">
                            @csrf
                            @foreach(\App\Helpers\FeedbackSentiment::cases() as $sentiment)
                                @php($active = $feedback->sentiment === $sentiment)
                                <button type="submit" name="sentiment" value="{{ $active ? '' : $sentiment->value }}"
                                    class="btn btn-sm {{ $active ? 'btn-' . $sentiment->color() : 'btn-outline-' . $sentiment->color() }}"
                                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                                    title="{{ $active ? 'Clear the tag' : 'Mark as ' . strtolower($sentiment->label()) }}">
                                    <i class="fas {{ $sentiment === \App\Helpers\FeedbackSentiment::POSITIVE ? 'fa-thumbs-up' : 'fa-thumbs-down' }}"></i>
                                    {{ $sentiment->label() }}
                                </button>
                            @endforeach
                        </form>
                    @else
                        {{ $feedback->sentiment?->label() ?? 'Not tagged' }}
                    @endif
                </div>

                @if($canReview)
                    @if($feedback->isOpen())
                        <button type="button" class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#feedback-close-modal">
                            <i class="fas fa-check-double"></i> Close review
                        </button>
                        <small class="form-text text-muted d-block mt-2">Closing does not send anything to {{ $controllerName }}.</small>
                    @else
                        <form action="{{ route('feedback.reopen', $feedback) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary w-100"><i class="fas fa-rotate-left"></i> Reopen review</button>
                        </form>
                        <small class="form-text text-muted d-block mt-2">Reopening is for discussing it again. It does not change what {{ $controllerName }} has.</small>
                    @endif
                @endif
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">Timeline</h6>
            </div>
            @if($canReview)
                <form action="{{ route('feedback.comment', $feedback) }}" method="POST" class="p-2 border-bottom">
                    @csrf
                    <div class="input-group">
                        <input type="text" name="comment" class="form-control @error('comment') is-invalid @enderror" placeholder="Internal comment, staff only ..." maxlength="4000" aria-label="Internal comment" required>
                        <button class="btn btn-outline-primary" type="submit">Comment</button>
                    </div>
                </form>
            @endif
            <div class="timeline">
                <ul class="sessions">
                    @foreach($activities as $activity)
                        <li data-id="{{ $activity->id }}">
                            <div class="time">
                                <i class="fas {{ $activity->type->icon() }}"></i>
                                {{ $activity->triggeredBy?->name ?? 'System' }} — {{ $activity->created_at->toEuropeanDateTime() }}
                            </div>
                            <p>
                                @switch($activity->type)
                                    @case(\App\Helpers\FeedbackActivityType::COMMENT)
                                        {!! nl2br(e($activity->comment)) !!}
                                        @break
                                    @case(\App\Helpers\FeedbackActivityType::SENTIMENT)
                                        @if($activity->sentimentTo())
                                            Tagged <span class="badge text-bg-light">{{ $activity->sentimentTo()->label() }}</span>
                                        @else
                                            Removed the <span class="badge text-bg-light">{{ $activity->sentimentFrom()?->label() }}</span> tag
                                        @endif
                                        @break
                                    @case(\App\Helpers\FeedbackActivityType::EDITED)
                                        @foreach($activity->changes() as $change)
                                            {{ $change['label'] }} changed from <span class="badge text-bg-light">{{ $change['from'] }}</span>
                                            to <span class="badge text-bg-light">{{ $change['to'] }}</span>@if(! $loop->last)<br>@endif
                                        @endforeach
                                        @break
                                    @case(\App\Helpers\FeedbackActivityType::CLOSED)
                                        Closed the review
                                        @break
                                    @case(\App\Helpers\FeedbackActivityType::REOPENED)
                                        Reopened the review
                                        @break
                                    @case(\App\Helpers\FeedbackActivityType::SENT)
                                        Sent to the controller: <span class="badge text-bg-light">{{ $activity->delivery()?->label() }}</span>
                                        @if($activity->postedToDiscord())
                                            and posted to Discord
                                        @endif
                                        @if($activity->comment)
                                            <span class="d-block small text-muted mt-1">"{{ \Illuminate\Support\Str::limit($activity->comment, 200) }}"</span>
                                        @endif
                                        @break
                                    @case(\App\Helpers\FeedbackActivityType::WITHDRAWN)
                                        Withdrew it from the controller
                                        @if($activity->removedFromDiscord())
                                            and deleted the Discord post
                                        @endif
                                        @break
                                    @case(\App\Helpers\FeedbackActivityType::ACKNOWLEDGED)
                                        Acknowledged the feedback sent to them
                                        @break
                                @endswitch
                            </p>
                        </li>
                    @endforeach
                    <li>
                        <div class="time">
                            <i class="fas fa-inbox"></i>
                            {{ $feedback->submitter->name }} — {{ $feedback->created_at->toEuropeanDateTime() }}
                        </div>
                        <p>Submitted the feedback</p>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

{{-- Close dialog --}}
@if($canReview && $feedback->isOpen())
    <div class="modal fade" id="feedback-close-modal" tabindex="-1" aria-labelledby="feedback-close-title" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form class="modal-content" action="{{ route('feedback.close', $feedback) }}" method="POST">
                @csrf
                <input type="hidden" name="_dialog" value="close">
                <div class="modal-header">
                    <h5 class="modal-title" id="feedback-close-title">Close review of feedback #{{ $feedback->id }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    {{-- Closing never sends anything; say plainly what the controller has. --}}
                    @if($feedback->wasSent())
                        <div class="alert alert-info py-2">
                            <i class="fas fa-paper-plane"></i> {{ ucfirst($controllerName) }} has this feedback ({{ strtolower($feedback->sent_version->label()) }}). Closing does not change that.
                        </div>
                    @else
                        <div class="alert alert-warning py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span><i class="fas fa-lock"></i> Nothing has been sent to {{ $controllerName }}. Closing keeps it with staff; you can still send it later.</span>
                            @if($feedback->referenceUser)
                                <button type="button" class="btn btn-sm btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#feedback-send-modal">
                                    Send to {{ $controllerName }} first
                                </button>
                            @endif
                        </div>
                    @endif

                    <label class="form-label fw-bold" for="close-comment">Internal comment <small class="fw-normal text-muted">(optional, staff only)</small></label>
                    <textarea class="form-control" id="close-comment" name="comment" rows="2" maxlength="4000" placeholder="What was done, or why">{{ old('comment') }}</textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Close review</button>
                </div>
            </form>
        </div>
    </div>
@endif

{{-- Send dialog, whenever the controller has nothing yet, open or closed --}}
@if($canReview && ! $feedback->wasSent() && $feedback->referenceUser)
    <div class="modal fade" id="feedback-send-modal" tabindex="-1" aria-labelledby="feedback-send-title" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form class="modal-content" action="{{ route('feedback.send', $feedback) }}" method="POST"
                x-data="{ delivery: @js(old('delivery', 'original')), discord: {{ old('discord') ? 'true' : 'false' }}, sending: false }"
                x-on:submit="sending = true">
                @csrf
                <input type="hidden" name="_dialog" value="send">
                <div class="modal-header">
                    <h5 class="modal-title" id="feedback-send-title">Send feedback #{{ $feedback->id }} to {{ $controllerName }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('feedback.partials.delivery-options', ['prefix' => 'send'])

                    <p class="small text-muted mb-3">Sending does not close the review; that stays a separate step.</p>

                    <label class="form-label fw-bold" for="send-comment">Internal comment <small class="fw-normal text-muted">(optional, staff only)</small></label>
                    <textarea class="form-control" id="send-comment" name="comment" rows="2" maxlength="4000" placeholder="Why it is being sent now">{{ old('comment') }}</textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" x-bind:disabled="sending">
                        <span x-text="discord ? 'Send and post' : 'Send'">Send</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif

{{-- Edit dialog --}}
@if($canEdit)
    @php($utc = fn ($date) => $date?->copy()->utc()->format('Y-m-d\TH:i'))
    <div class="modal fade" id="feedback-edit-modal" tabindex="-1" aria-labelledby="feedback-edit-title" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form class="modal-content" action="{{ route('feedback.update', $feedback) }}" method="POST"
                x-data="{ area: @js((string) old('area', $feedback->explicitArea?->id ?? '')), position: @js(old('position', $feedback->referencePosition?->callsign ?? '')) }">
                @method('PATCH')
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="feedback-edit-title">Edit feedback #{{ $feedback->id }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6 class="fw-bold small text-uppercase text-muted mb-2">The controller</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" for="feedback-edit-controller">Controller (CID)</label>
                            <input id="feedback-edit-controller" class="form-control @error('controller') is-invalid @enderror" type="text" name="controller" list="feedback-controllers-list"
                                value="{{ old('controller', $feedback->referenceUser?->id) }}" placeholder="CID">
                            @error('controller')
                                <span class="text-danger small">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="feedback-edit-position">Position</label>
                            <input id="feedback-edit-position" class="form-control @error('position') is-invalid @enderror" type="text" name="position" list="feedback-positions-list"
                                x-model="position" x-on:input="area = ''" x-bind:disabled="area !== ''">
                            @error('position')
                                <span class="text-danger small">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="feedback-edit-area">Area</label>
                            <select id="feedback-edit-area" class="form-select @error('area') is-invalid @enderror" name="area"
                                x-model="area" x-on:change="position = ''" x-bind:disabled="position !== ''">
                                <option value="">None</option>
                                @foreach($editAreas as $editArea)
                                    <option value="{{ $editArea->id }}">{{ $editArea->name }}</option>
                                @endforeach
                            </select>
                            @error('area')
                                <span class="text-danger small">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="feedback-edit-session-callsign">Session callsign</label>
                            <input id="feedback-edit-session-callsign" class="form-control @error('session_callsign') is-invalid @enderror" type="text" name="session_callsign" maxlength="20"
                                value="{{ old('session_callsign', $feedback->session_callsign) }}">
                            @error('session_callsign')
                                <span class="text-danger small">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="feedback-edit-session-start">Session start, UTC</label>
                            <input id="feedback-edit-session-start" class="form-control @error('session_started_at') is-invalid @enderror" type="datetime-local" name="session_started_at"
                                value="{{ old('session_started_at', $utc($feedback->session_started_at)) }}">
                            @error('session_started_at')
                                <span class="text-danger small">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="feedback-edit-session-end">Session end, UTC</label>
                            <input id="feedback-edit-session-end" class="form-control @error('session_ended_at') is-invalid @enderror" type="datetime-local" name="session_ended_at"
                                value="{{ old('session_ended_at', $utc($feedback->session_ended_at)) }}">
                            @error('session_ended_at')
                                <span class="text-danger small">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>

                    <h6 class="fw-bold small text-uppercase text-muted mb-2">The pilot's flight</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label" for="feedback-edit-pilot-callsign">Pilot callsign</label>
                            <input id="feedback-edit-pilot-callsign" class="form-control @error('pilot_callsign') is-invalid @enderror" type="text" name="pilot_callsign" maxlength="20"
                                value="{{ old('pilot_callsign', $feedback->pilot_callsign) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="feedback-edit-event-date">Date of event, UTC</label>
                            <input id="feedback-edit-event-date" class="form-control @error('event_date') is-invalid @enderror" type="date" name="event_date" max="{{ now()->toDateString() }}"
                                value="{{ old('event_date', $feedback->event_date?->toDateString()) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="feedback-edit-departure">Departure, ICAO</label>
                            <input id="feedback-edit-departure" class="form-control @error('departure_airport') is-invalid @enderror" type="text" name="departure_airport" maxlength="4"
                                value="{{ old('departure_airport', $feedback->departure_airport) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="feedback-edit-arrival">Arrival, ICAO</label>
                            <input id="feedback-edit-arrival" class="form-control @error('arrival_airport') is-invalid @enderror" type="text" name="arrival_airport" maxlength="4"
                                value="{{ old('arrival_airport', $feedback->arrival_airport) }}">
                        </div>
                    </div>
                    @foreach(['pilot_callsign', 'event_date', 'departure_airport', 'arrival_airport'] as $flightField)
                        @error($flightField)
                            <span class="text-danger small d-block">{{ $message }}</span>
                        @enderror
                    @endforeach

                    <small class="form-text text-muted d-block">
                        A position carries its own area, so set one or the other. Moving the feedback to another area hands it to that area's staff.
                        The submitter and what they wrote cannot be edited. Every change is recorded on the timeline.
                    </small>

                    <datalist id="feedback-controllers-list">
                        @foreach($editControllers as $editController)
                            <option value="{{ $editController->id }}">{{ $editController->name }}</option>
                        @endforeach
                    </datalist>
                    <datalist id="feedback-positions-list">
                        @foreach($editPositions as $editPosition)
                            <option value="{{ $editPosition->callsign }}">{{ $editPosition->name }}</option>
                        @endforeach
                    </datalist>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save changes</button>
                </div>
            </form>
        </div>
    </div>
@endif

@endsection

@section('js')
<script>
    // Reopen the dialog a failed submit came from, so the errors are in view.
    document.addEventListener('DOMContentLoaded', function () {
        const dialogs = {
            close: 'feedback-close-modal',
            send: 'feedback-send-modal',
            edit: 'feedback-edit-modal',
        };
        @php($failedDialog = $errors->any() ? (old('_dialog') ?? ($errors->hasAny(['controller', 'position', 'area', 'session_callsign', 'session_started_at', 'session_ended_at', 'pilot_callsign', 'event_date', 'departure_airport', 'arrival_airport']) ? 'edit' : null)) : null)
        const failed = document.getElementById(dialogs[@js($failedDialog)] ?? '');
        if (failed) {
            bootstrap.Modal.getOrCreateInstance(failed).show();
        }
    });
</script>
@endsection
