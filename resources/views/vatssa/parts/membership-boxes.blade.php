{{--
    VATSSA: what somebody outside the division is offered instead of training.

    ## Why this replaces the Request Training block rather than sitting beside it

    Training is not available to a non-member, and the block offering it could
    only ever end in "Not eligible". A door that does not open is worse than no
    door: it reads as the application being broken, and it hides the two things
    that ARE available.

    ## And why the training block comes back for a visitor

    A visiting controller can request endorsement training -- FSS and the like.
    So the rule is not "member or not". It is:

        not a member, not visiting  ->  these two boxes
        visiting                    ->  the training block returns, plus this
        member                      ->  the training block, as now

    Expects: $user, $requirements
--}}
@php
    use App\Helpers\Vatssa\MembershipRequestType;
    use App\Models\Vatssa\MembershipRequest;

    // Transfer or visit is a VATSIM policy question, not a preference. Inside
    // the region you move; outside it you visit. TVCP 5.1-5.3.
    $sameRegion = $user->region === config('vatssa.region', 'EMEA');

    $offered = $sameRegion ? MembershipRequestType::TRANSFER : MembershipRequestType::VISITING;
    $openAlready = MembershipRequest::hasOpenFor($user, $offered);
@endphp

<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3">
        <h6 class="m-0 fw-bold text-white">
            <i class="fas {{ $offered->icon() }}"></i>&nbsp;{{ $offered->label() }}
        </h6>
    </div>
    <div class="card-body">
        @if($offered === MembershipRequestType::TRANSFER)
            <p>
                You are inside our region but not in {{ config('app.owner_name_short') }}.
                Ask to transfer here and, once VATSIM has moved you, you can train
                with us like any other member.
            </p>
        @else
            <p>
                You are outside our region, so you would join us as a visiting
                controller: you stay where you are and hold a visiting endorsement
                with us.
            </p>
        @endif

        @if($openAlready)
            <div class="btn btn-success d-block disabled not-allowed" role="button" aria-disabled="true">
                <i class="fas fa-check"></i>&nbsp;Request received
            </div>
            <p class="fs-sm text-muted mt-2 mb-0">
                We will be in touch. You can follow it below.
            </p>
        @else
            <div class="d-grid">
                <a href="{{ route('vatssa.membership.create', ['type' => $offered->value]) }}" class="btn btn-success">
                    {{ $offered->label() }}
                </a>
            </div>
        @endif

        {{-- The requirements, from the same component the training pages use,
             so somebody sees where they stand before they ask rather than
             after they are refused. --}}
        <div class="mt-3">
            @include('vatssa.parts.requirements', [
                'requirements' => $requirements,
                'heading' => 'What you need',
            ])
        </div>
    </div>
</div>
