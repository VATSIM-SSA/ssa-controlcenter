@extends('layouts.app')

@section('title', 'Practical exams')

@section('content')

{{--
    VATSSA: every exam in flight, for everybody.

    One page, not three. The examiner wants the ones they could take, the events
    team wants the ones waiting on them, and a coordinator wants to know why
    their student has not sat yet. Three pages would be three places to keep in
    step; the answer to all three questions is the same list, sorted by whose
    turn it is.

    "Waiting on you" sits above everything and is empty most of the time. The
    reason arranging a CPT used to take a fortnight is not that any step was
    hard -- it is that nobody could tell whose turn it was without reading a
    Discord thread.
--}}

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="alert alert-info" role="alert">
            <i class="fas fa-circle-info"></i>&nbsp;
            Every exam being arranged, and whose turn it is. Everything must be settled
            <strong>{{ \App\Models\Vatssa\Exam::NOTICE_DAYS }} days</strong> before the date
            &mdash; examiner confirmed, events team told, myVATSIM uploaded.
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-hand-point-right"></i>&nbsp;Waiting on you
                </h6>
                <span class="badge bg-light text-dark">{{ $mine->count() }}</span>
            </div>
            <div class="card-body {{ $mine->isEmpty() ? '' : 'p-0' }}">
                @if($mine->isEmpty())
                    <p class="mb-0 text-muted">
                        Nothing needs you. That is the state this page should usually be in.
                    </p>
                @else
                    @include('vatssa.exams.parts.table', ['exams' => $mine])
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-user-graduate"></i>&nbsp;Everything in flight
                </h6>
                <span class="badge bg-light text-dark">{{ $exams->count() }}</span>
            </div>
            <div class="card-body {{ $exams->isEmpty() ? '' : 'p-0' }}">
                @if($exams->isEmpty())
                    <p class="mb-0 text-muted">No exams are being arranged.</p>
                @else
                    @include('vatssa.exams.parts.table', ['exams' => $exams])
                @endif
            </div>
        </div>
    </div>
</div>

@endsection
