@extends('layouts.app')

@section('title', 'My availability')

@section('content')

{{--
    VATSSA: one availability grid for practical exams, mentoring sessions and
    meetings.

    Built in rather than deployed beside. Rallly and Crab.fit both do this well
    and neither has an API, so a poll answered there could never sync back to
    the training page it belongs to -- which is the whole point of asking.
--}}

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="alert alert-info" role="alert">
            <i class="fas fa-circle-info"></i>&nbsp;
            Mark when you could be there and everybody sees the overlap straight away,
            instead of a chain of messages working it out.
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-hourglass-half"></i>&nbsp;Waiting on an answer
                </h6>
                <span class="badge bg-light text-dark">{{ $open->count() }}</span>
            </div>
            <div class="card-body {{ $open->isEmpty() ? '' : 'p-0' }}">
                @if($open->isEmpty())
                    <p class="mb-0 text-muted">Nothing open. Nobody is waiting on you.</p>
                @else
                    <div class="list-group list-group-flush">
                        @foreach($open as $poll)
                            @include('vatssa.availability.parts.row', ['poll' => $poll])
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@if($settled->isNotEmpty())
    <div class="row">
        <div class="col-xl-12 col-md-12 mb-12">
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 fw-bold text-white">
                        <i class="fas fa-circle-check"></i>&nbsp;Settled
                    </h6>
                    <span class="badge bg-light text-dark">{{ $settled->count() }}</span>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @foreach($settled as $poll)
                            @include('vatssa.availability.parts.row', ['poll' => $poll])
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="card shadow mb-4">
            {{-- Folded away, because most visits here are to answer somebody
                 else's question rather than to ask one. Bootstrap's own
                 collapse rather than Alpine: this page has no other reason to
                 pull Alpine in, and a dependency carried for one toggle is a
                 dependency somebody removes without noticing the toggle. --}}
            <div class="card-header bg-primary py-3">
                <button class="btn btn-link p-0 m-0 fw-bold text-white text-decoration-none"
                        type="button" data-bs-toggle="collapse" data-bs-target="#askGroup"
                        aria-expanded="false" aria-controls="askGroup">
                    <i class="fas fa-plus"></i>&nbsp;Ask a group when they are free
                </button>
            </div>
            <div class="collapse" id="askGroup">
                <div class="card-body">
                    <form method="POST" action="{{ route('vatssa.availability.store') }}">
                        @csrf

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="purpose">What is it for</label>
                                <select class="form-select" id="purpose" name="purpose" required>
                                    <option value="mentoring">Mentoring session</option>
                                    <option value="cpt">Practical exam</option>
                                    <option value="meeting">Meeting</option>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="weeks">How far ahead</label>
                                <select class="form-select" id="weeks" name="weeks" required>
                                    <option value="2">2 weeks</option>
                                    <option value="4" selected>4 weeks</option>
                                    <option value="8">8 weeks</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="title">Title</label>
                            <input type="text" class="form-control" id="title" name="title"
                                   required maxlength="120"
                                   placeholder="S2 practical exam &mdash; Web One">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="description">
                                Anything people should know <span class="text-muted">(optional)</span>
                            </label>
                            <textarea class="form-control" id="description" name="description"
                                      rows="2" maxlength="1000"></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">Create</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
