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
                        &ndash; {{ $poll->ends_on->format('j M Y') }}
                        &mdash; <strong>{{ $poll->weekCount() }} {{ \Illuminate\Support\Str::plural('week', $poll->weekCount()) }}</strong>,
                        in {{ $poll->slot_minutes }}-minute slots.
                        All times {{ \App\Models\Vatssa\AvailabilityPoll::timezoneLabel() }}.
                    </p>
                @endif

                {{-- Who can open it, on the page rather than only in the form
                     that created it. Somebody pasting the link needs to know
                     whether it will work for the person they are pasting it
                     to, and that is not a question they should have to guess
                     the answer to. --}}
                <p class="mb-0 mt-2 small text-muted">
                    <i class="fas fa-user-lock"></i>
                    {{ $poll->visibilityLabel() }}.
                    @if($poll->visibility === \App\Models\Vatssa\AvailabilityPoll::VISIBILITY_INVITED)
                        Anybody else opening the link is refused.
                    @endif
                </p>
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

                @if($poll->visibility === \App\Models\Vatssa\AvailabilityPoll::VISIBILITY_INVITED)
                    <p class="form-text mb-0">
                        Only invited people can open this. Add them below, or the link
                        will not work for them.
                    </p>
                @endif
            </div>
        </div>

        {{-- Adding people afterwards.

             The usual way a poll goes wrong is somebody being left off it, and
             having to delete and recreate to fix that is exactly why people go
             back to asking in the group chat instead. --}}
        @if($poll->isManageableBy(auth()->user()) && $poll->isOpen())
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3">
                    <h6 class="m-0 fw-bold text-white">
                        <i class="fas fa-user-plus"></i>&nbsp;Ask somebody else
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('vatssa.availability.participants', $poll) }}">
                        @csrf
                        <select class="form-select form-select-sm mb-2" name="participants[]"
                                multiple size="6" required>
                            @foreach($members as $member)
                                @continue($poll->responses->contains('user_id', $member->id))
                                <option value="{{ $member->id }}">
                                    {{ $member->name }} ({{ $member->id }})
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary">Add them</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>

@endsection
