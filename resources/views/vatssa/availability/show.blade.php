@extends('layouts.app')

@section('title', $poll->title)

@section('content')

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        {{-- What this is, and what to do. A grid with no sentence above it gets
             filled in wrong by half the people who see it. --}}
        <div class="alert alert-info" role="alert">
            <i class="fas fa-circle-info"></i>&nbsp;
            Mark <strong>every</strong> time you could make, not just your preferred one.
            Drag to paint, drag again to erase. More options means a shorter wait.
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-calendar-check"></i>&nbsp;{{ $poll->title }}
                </h6>
                <span>
                    <span class="badge bg-light text-dark">{{ $poll->purposeLabel() }}</span>
                    @unless($poll->isOpen())
                        <span class="badge bg-success">Confirmed</span>
                    @endunless
                </span>
            </div>
            <div class="card-body">
                @if($poll->description)
                    <p>{{ $poll->description }}</p>
                @endif

                @if($poll->confirmed_slot)
                    <div class="alert alert-success mb-0" role="alert">
                        <i class="fas fa-circle-check"></i>&nbsp;
                        Confirmed for
                        <strong>{{ $poll->confirmed_slot->format('D j M') }} &middot;
                            {{ $poll->confirmed_slot->format('H:i') }}z</strong>
                    </div>
                @else
                    <p class="mb-0 text-muted">
                        {{ $poll->starts_on->format('j M Y') }}
                        &ndash; {{ $poll->ends_on->format('j M Y') }},
                        in {{ $poll->slot_minutes }}-minute slots.
                        All times {{ \App\Models\Vatssa\AvailabilityPoll::timezoneLabel() }}.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xl-8 col-md-12 mb-12">
        @livewire('vatssa.availability-grid', ['poll' => $poll])
    </div>

    <div class="col-xl-4 col-md-12 mb-12">
        {{-- Who has answered. The thing everybody actually wants to know while
             waiting is "is it me we are waiting for", and a list answers that
             faster than any status badge. --}}
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-users"></i>&nbsp;Who has answered
                </h6>
                <span class="badge bg-light text-dark">{{ $poll->responses->count() }}</span>
            </div>
            <div class="card-body {{ $poll->responses->isEmpty() ? '' : 'p-0' }}">
                @if($poll->responses->isEmpty())
                    <p class="mb-0 text-muted">Nobody yet.</p>
                @else
                    <div class="list-group list-group-flush">
                        @foreach($poll->responses as $response)
                            <div class="list-group-item d-flex align-items-center justify-content-between gap-3">
                                <span class="text-truncate">
                                    {{ $response->user?->name ?? 'Unknown' }}
                                    @if($response->role !== 'participant')
                                        <span class="badge bg-secondary">{{ $response->role }}</span>
                                    @endif
                                </span>
                                <small class="text-muted flex-shrink-0">
                                    {{ count($response->slots ?? []) }} slots
                                </small>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- The link. Everything else in the workflow emails this, but somebody
             will always want to paste it into Discord themselves. --}}
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-link"></i>&nbsp;Share this page
                </h6>
            </div>
            <div class="card-body">
                <input type="text" class="form-control form-control-sm font-monospace"
                       value="{{ route('vatssa.availability.show', $poll) }}"
                       readonly onclick="this.select()">
            </div>
        </div>
    </div>
</div>

@endsection
