@extends('layouts.app')

@section('title', 'Feedback')
@section('content')

<div class="row">
    <div class="col-xxl-8 col-xl-10 col-lg-12 mb-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">Submit Feedback</h6>
                @if($area)
                    <span class="text-white small">
                        {{ $area->name }}
                        @if($areas->count() > 1)
                            &middot; <a href="{{ route('feedback') }}" class="text-white">Change</a>
                        @endif
                    </span>
                @endif
            </div>
            <div class="card-body">
                <p class="text-muted">Tell us about a controller who gave you a service{{ $area ? ' in ' . $area->name : '' }}. Your feedback helps us improve.</p>
                @error('area')
                    <div class="alert alert-danger py-2">{{ $message }}</div>
                @enderror

                <livewire:feedback-form :area-id="$area?->id" :flights="$flights" />
            </div>
        </div>
    </div>
</div>

@endsection
