@extends('layouts.app')

@section('title', 'Feedback')
@section('content')

<div class="row">
    <div class="col-xl-6 col-lg-12 col-md-12 mb-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    Submit Feedback
                </h6>
            </div>
            <div class="card-body">
                <p>Which area is your feedback about?</p>

                <div class="d-grid gap-2 d-md-flex flex-md-wrap">
                    @foreach($areas as $area)
                        <a href="{{ route('feedback', ['area' => $area->id]) }}" class="btn btn-outline-primary">{{ $area->name }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
