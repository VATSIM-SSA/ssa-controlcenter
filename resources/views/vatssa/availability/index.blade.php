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
                                {{-- From the model, so adding a purpose is one
                                     line there and appears here on its own. --}}
                                <select class="form-select" id="purpose" name="purpose" required>
                                    @foreach(\App\Models\Vatssa\AvailabilityPoll::PURPOSES as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="weeks">How far ahead</label>
                                <select class="form-select" id="weeks" name="weeks" required>
                                    @foreach([1, 2, 4, 6, 8] as $w)
                                        @continue($w > config('vatssa.availability.max_weeks', 8))
                                        <option value="{{ $w }}" @selected($w === 4)>
                                            {{ $w }} {{ \Illuminate\Support\Str::plural('week', $w) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="visibility">Who can open it</label>
                            <select class="form-select" id="visibility" name="visibility" required>
                                @foreach(\App\Models\Vatssa\AvailabilityPoll::VISIBILITIES as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                A poll is a list of when named people are free. "Only the people I
                                invite" is the default for that reason &mdash; you can add more at
                                any time from the poll's own page.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="participants">
                                Who to ask <span class="text-muted">(optional &mdash; add more later)</span>
                            </label>
                            {{-- A plain multi-select over members. Not a
                                 combobox: this list is a few hundred names at
                                 most, and a picker that needs JavaScript to
                                 work is a picker that fails silently when the
                                 JavaScript does. --}}
                            <select class="form-select" id="participants" name="participants[]"
                                    multiple size="6">
                                @foreach($members as $member)
                                    <option value="{{ $member->id }}">
                                        {{ $member->name }} ({{ $member->id }})
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                Hold Ctrl (or Cmd) to pick several. Everybody picked can open the
                                poll straight away, whichever visibility you chose.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="title">Title</label>
                            <input type="text" class="form-control" id="title" name="title"
                                   required maxlength="120"
                                   placeholder="S2 mentoring &mdash; Web One">
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
