@extends('layouts.app')

@section('title', 'Automation log')

@section('title-flex')
    <div class="d-flex flex-wrap gap-3 align-items-center">
        <div>
            <i class="fas fa-filter"></i>&nbsp;
            {{-- Warnings first, and by default. Things the system noticed and
                 did NOT act on are the rows that need a person; the successful
                 actions are there for when you go looking. --}}
            <a class="btn btn-sm {{ $level === 'warning' ? 'btn-primary' : 'btn-outline-primary' }}"
               href="{{ route('vatssa.action-log', ['level' => 'warning']) }}">Needs a look</a>
            <a class="btn btn-sm {{ $level === 'info' ? 'btn-primary' : 'btn-outline-primary' }}"
               href="{{ route('vatssa.action-log', ['level' => 'info']) }}">Actions taken</a>
            <a class="btn btn-sm {{ $level === 'all' ? 'btn-primary' : 'btn-outline-primary' }}"
               href="{{ route('vatssa.action-log', ['level' => 'all']) }}">Everything</a>
        </div>

        @if($kinds->count())
            <form method="GET" class="d-flex gap-1">
                <input type="hidden" name="level" value="{{ $level }}">
                <select name="action" class="form-select form-select-sm" style="max-width: 16rem"
                        onchange="this.form.submit()">
                    <option value="">Every kind</option>
                    @foreach($kinds as $kind)
                        <option value="{{ $kind }}" @selected($action === $kind)>{{ $kind }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>
@endsection

@section('content')

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="alert alert-info" role="alert">
            <i class="fas fa-circle-info"></i>&nbsp;
            Everything the pipeline does automatically, and everything it noticed
            and deliberately left alone. <strong>The second kind is why this page
            exists</strong> — an empty request desk, a rating with no Moodle
            course, a student waiting on a CPT with no mentor. Software that stays
            quiet about what it cannot handle is worse than software that does
            nothing, because silence reads as fine.
            @if($openWarnings)
                <span class="d-block mt-1">
                    <strong>{{ $openWarnings }}</strong>
                    {{ Str::plural('thing', $openWarnings) }} noticed in the last 30 days.
                </span>
            @endif
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-robot"></i>&nbsp;Automation log
                </h6>
                <span class="badge bg-light text-dark">{{ $entries->count() }}</span>
            </div>
            <div class="card-body {{ $entries->isEmpty() ? '' : 'p-0' }}">
                @if($entries->isEmpty())
                    <p class="mb-0 text-muted">
                        @if($level === 'warning')
                            Nothing needs a look. That is the state you want this
                            page to be in.
                        @else
                            Nothing logged yet.
                        @endif
                    </p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover table-leftpadded mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>When</th>
                                    <th>What happened</th>
                                    <th>About</th>
                                    <th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($entries as $entry)
                                    <tr>
                                        <td style="white-space: nowrap">
                                            <span data-bs-toggle="tooltip"
                                                  title="{{ $entry->created_at->toEuropeanDateTime() }}">
                                                {{ $entry->created_at->diffForHumans() }}
                                            </span>
                                        </td>
                                        <td>
                                            @if($entry->level === \App\Models\Vatssa\ActionLog::WARNING)
                                                <i class="fas fa-triangle-exclamation text-warning"></i>
                                            @else
                                                <i class="fas fa-check text-success"></i>
                                            @endif
                                            {{ $entry->summary }}
                                            <small class="text-muted d-block">{{ $entry->action }}</small>
                                        </td>
                                        <td>
                                            @if($entry->user)
                                                <a href="{{ route('user.show', $entry->user) }}">{{ $entry->user->name }}</a>
                                            @endif
                                            @if($entry->training_id)
                                                <a class="d-block small"
                                                   href="{{ route('training.show', $entry->training_id) }}">
                                                    training #{{ $entry->training_id }}
                                                </a>
                                            @endif
                                            @if(! $entry->user && ! $entry->training_id)
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td><span class="badge bg-secondary">{{ $entry->actorLabel() }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@endsection
