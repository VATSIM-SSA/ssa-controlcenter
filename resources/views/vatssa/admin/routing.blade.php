@extends('layouts.app')

@section('title', 'Request routing')

@section('content')

{{--
    VATSSA: who sits at each request desk.

    Upstream asks the requester to name a person and offers a datalist of
    everyone holding any role. This is what replaces that: the requester picks a
    desk, and this page says who that desk currently is.
--}}

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="alert alert-info" role="alert">
            <i class="fas fa-circle-info"></i>&nbsp;
            A request goes to the desk, not to a name. Several people per desk is
            fine — it lands on whichever of them has the fewest open requests,
            and <strong>everyone at that desk sees it</strong> on the Tasks page.
            Who may READ a desk is a ladder: leadership sees every desk, the ATC
            training manager sees theirs and every pipeline, a coordinator sees
            only their own rating.
        </div>
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-triangle-exclamation"></i>&nbsp;
            <strong>An empty desk is not a silent failure, but it is a
            failure.</strong> A request sent to a desk with nobody on it stays
            with whoever raised it, and a warning goes to the log. Fill in at
            least the coordinator row for every rating you train.
        </div>
    </div>
</div>

<form method="POST" action="{{ route('vatssa.admin.routing.update') }}">
    @csrf

    @foreach($tiers as $tierKey => $tier)
        <div class="row">
            <div class="col-xl-12 col-md-12 mb-12">
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary py-3">
                        <h6 class="m-0 fw-bold text-white">{{ $tier['label'] }}</h6>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">{{ $tier['hint'] }}</p>

                        @if($tier['per_rating'])
                            {{-- One row per rating: VATSSA's pipelines are per
                                 rating, so "the S2 coordinator" is a different
                                 person from "the C1 coordinator". A rating left
                                 empty has NO desk, and requests for it stay with
                                 whoever raised them. --}}
                            @foreach($ratings as $rating)
                                @include('vatssa.admin.parts.routing-row', [
                                    'key' => $tierKey . ':' . $rating->id,
                                    'label' => $rating->name,
                                    'selected' => $targets->where('tier', $tierKey)
                                        ->where('rating_id', $rating->id)->pluck('user_id')->all(),
                                    'candidates' => $candidates,
                                ])
                            @endforeach

                            {{-- No catch-all row. A pipeline desk is always one
                                 rating's desk -- "the pipeline coordinator" is not
                                 a thing anybody can be, and a catch-all would put
                                 somebody on every pipeline queue by accident. --}}
                        @else
                            @include('vatssa.admin.parts.routing-row', [
                                'key' => $tierKey,
                                'label' => 'Assigned to',
                                'selected' => $targets->where('tier', $tierKey)->pluck('user_id')->all(),
                                'candidates' => $candidates,
                            ])
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="row">
        <div class="col-xl-12 mb-4">
            <button type="submit" class="btn btn-primary">Save routing</button>
        </div>
    </div>
</form>

@endsection
