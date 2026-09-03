@extends('layouts.app')

@section('title', 'Feedback about you')

@section('content')

{{--
    The feedback a controller has been shown about themselves.

    ANONYMOUS, and that is the feature rather than an omission. Feedback is
    given on the understanding that it goes to staff; a controller who learns
    exactly who complained about them has been told something the submitter
    never agreed to tell them. Staff keep the whole record.

    Only forwarded feedback reaches this page. Open feedback has not been read
    yet, and closed feedback was read and deliberately not passed on -- showing
    either here would make the two staff outcomes the same outcome.
--}}

<div class="row">
    <div class="col-xl-8 col-lg-10 col-md-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">Feedback about you</h6>
            </div>
            <div class="card-body">
                @forelse($feedback as $item)
                    <div class="border-start border-3 border-primary ps-3 mb-4">
                        <div class="small text-muted mb-1">
                            {{ $item->referencePosition?->callsign ?? 'Position not recorded' }}
                            &middot;
                            {{ $item->created_at->toEuropeanDate() }}
                        </div>
                        <div style="white-space: pre-wrap;">{{ $item->feedback }}</div>
                    </div>
                @empty
                    <p class="mb-0 text-muted">
                        Nothing yet. Feedback appears here once staff have reviewed it and
                        passed it on.
                    </p>
                @endforelse

                @if($feedback->hasPages())
                    {{ $feedback->links() }}
                @endif
            </div>
        </div>
    </div>
</div>

@endsection
