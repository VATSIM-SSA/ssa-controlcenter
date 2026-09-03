@extends('layouts.app')

@section('title', $type->label())

@section('content')

{{--
    VATSSA: the form somebody outside the division fills in.

    Short on purpose. Everything the division needs about them -- CID, name,
    rating, region, division -- came from VATSIM at login and is already on
    their user row; asking them to type it again would be asking them to
    confirm what we already know and giving them a chance to get it wrong.

    So the only field is the one we genuinely do not have: why.
--}}

<div class="row">
    <div class="col-xl-7 col-lg-9 col-md-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas {{ $type->icon() }}"></i>&nbsp;{{ $type->label() }}
                </h6>
            </div>
            <div class="card-body">
                @if($type === \App\Helpers\Vatssa\MembershipRequestType::TRANSFER)
                    <p>
                        You are asking to move to {{ config('app.owner_name') }}. If we
                        approve it, the transfer happens on VATSIM and you will then
                        complete a short familiarisation with us before you control.
                    </p>
                @else
                    <p>
                        You are asking to control with us as a visiting controller. You
                        stay where you are; if we approve it, you complete a short
                        familiarisation and we issue a visiting endorsement.
                    </p>
                @endif

                {{-- Approve by default, and said out loud.

                     TVCP 5.4 allows exactly three grounds for refusing, and
                     somebody filling this in has no way of knowing that. Saying
                     it removes the main reason people do not ask. --}}
                <div class="alert alert-light border">
                    <i class="fas fa-circle-info"></i>&nbsp;
                    We approve these unless you do not meet the requirements, there is a
                    disciplinary finding in the last twelve months, or you have not met a
                    currency requirement. If we refuse, we tell you which of those it was.
                </div>

                <form method="POST" action="{{ route('vatssa.membership.store') }}">
                    @csrf
                    <input type="hidden" name="type" value="{{ $type->value }}">

                    <div class="mb-3">
                        <label class="form-label" for="motivation">
                            Why would you like to {{ $type === \App\Helpers\Vatssa\MembershipRequestType::TRANSFER ? 'transfer to us' : 'visit us' }}?
                        </label>
                        <textarea class="form-control" id="motivation" name="motivation"
                                  rows="5" maxlength="2000" required>{{ old('motivation') }}</textarea>
                        @error('motivation')
                            <span class="text-danger">{{ $errors->first('motivation') }}</span>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-success">Send request</button>
                    <a href="{{ route('dashboard') }}" class="btn btn-link">Cancel</a>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-5 col-lg-3 col-md-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">Where you stand</h6>
            </div>
            <div class="card-body">
                {{-- The same component the dashboard and the application page
                     use. A cross here does not stop you asking: most of these
                     are shown to staff and decided by a person. --}}
                @include('vatssa.parts.requirements', ['requirements' => $requirements])

                <p class="fs-sm text-muted mt-3 mb-0">
                    A cross does not stop you asking. Staff see this list with your
                    request and decide.
                </p>
            </div>
        </div>
    </div>
</div>

@endsection
