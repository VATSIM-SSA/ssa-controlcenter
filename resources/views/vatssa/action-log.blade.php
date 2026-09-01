@extends('layouts.app')

@section('title', 'Automation log')

@section('title-flex')
    <div>
        @foreach([
            ['warning', 'Needs a look'],
            ['info', 'Actions taken'],
            ['all', 'Everything'],
        ] as [$value, $label])
            <a class="btn btn-sm {{ $level === $value ? 'btn-primary' : 'btn-outline-primary' }}"
               href="{{ route('vatssa.action-log', ['level' => $value]) }}">{{ $label }}</a>
        @endforeach
    </div>
@endsection

@section('content')

{{--
    VATSSA: what the automation did, and what it noticed and could not fix.

    ## Why this is Bootstrap again

    It was Tailwind, on its own layout, with its own sidebar. That made it the
    one page in the application that looked like a different application --
    which was fine while the Tailwind layout was the destination, and stopped
    being fine the moment the restyle became the destination instead.

    The restyle handles every page at once by redefining what Bootstrap's own
    class names look like. A page written in those class names inherits it for
    free and stays inherited when upstream changes. A page written in Tailwind
    on a parallel layout is a second thing to keep in step, for ever, for no
    benefit -- see resources/sass/_migration.scss.
--}}

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="alert {{ $openWarnings ? 'alert-warning' : 'alert-success' }}" role="alert">
            @if($openWarnings)
                <i class="fas fa-triangle-exclamation"></i>&nbsp;
                <strong>{{ $openWarnings }}</strong>
                {{ Str::plural('thing', $openWarnings) }} the automation noticed and could not
                fix, in the last 30 days.
            @else
                <i class="fas fa-check"></i>&nbsp;
                Nothing noticed in the last 30 days. That is the state this page should be in.
            @endif
        </div>

        <p class="text-muted">
            Everything the pipeline does on its own, and everything it noticed and deliberately
            left alone &mdash; an empty request desk, a rating with no Moodle course, a student
            waiting on an exam with no mentor.
            <strong>The second kind is why this page exists.</strong>
            Software that stays quiet about what it cannot handle is worse than software that
            does nothing, because silence reads as fine.
        </p>
    </div>
</div>

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-robot"></i>&nbsp;Automation log
                </h6>

                @if($kinds->count())
                    <form method="GET">
                        <input type="hidden" name="level" value="{{ $level }}">
                        <select name="action" class="form-select form-select-sm"
                                onchange="this.form.submit()">
                            <option value="">Every kind</option>
                            @foreach($kinds as $kind)
                                <option value="{{ $kind }}" @selected($action === $kind)>{{ $kind }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
            </div>

            <div class="card-body {{ $entries->isEmpty() ? '' : 'p-0' }}">
                @if($entries->isEmpty())
                    <p class="mb-0 text-muted">
                        Nothing logged{{ $action ? ' of that kind' : '' }}.
                    </p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover table-leftpadded mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 2rem"></th>
                                    <th>What happened</th>
                                    <th>About</th>
                                    <th>By</th>
                                    <th>When</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($entries as $entry)
                                    @php $warned = $entry->level === \App\Models\Vatssa\ActionLog::WARNING; @endphp
                                    <tr>
                                        <td>
                                            <i class="fas {{ $warned ? 'fa-triangle-exclamation text-warning' : 'fa-check text-success' }}"></i>
                                        </td>
                                        <td>
                                            {{ $entry->summary }}
                                            <small class="text-muted d-block">{{ $entry->action }}</small>
                                        </td>
                                        <td>
                                            @if($entry->user)
                                                <a href="{{ route('user.show', $entry->user) }}">{{ $entry->user->name }}</a>
                                            @endif
                                            @if($entry->training_id)
                                                <a class="d-block small" href="{{ route('training.show', $entry->training_id) }}">
                                                    training #{{ $entry->training_id }}
                                                </a>
                                            @endif
                                            @if(! $entry->user && ! $entry->training_id)
                                                <span class="text-muted">&mdash;</span>
                                            @endif
                                        </td>
                                        <td><span class="badge bg-secondary">{{ $entry->actorLabel() }}</span></td>
                                        <td style="white-space: nowrap">
                                            <span data-bs-toggle="tooltip"
                                                  title="{{ $entry->created_at->toEuropeanDateTime() }}">
                                                {{ $entry->created_at->diffForHumans() }}
                                            </span>
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
</div>

@endsection
