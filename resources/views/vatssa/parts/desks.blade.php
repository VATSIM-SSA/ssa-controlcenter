{{--
    VATSSA: which request desks this person sits at, and where they are set.

    Distinct from their ROLES, and the difference is the point. A role grants
    permissions; a desk decides who receives work. An ATC training manager holds
    every coordinator permission and is nobody's default coordinator.

    ## This is where the Request routing page went

    That page was a grid of every desk against every candidate, and it grew with
    every rating times every desk. Worse, it REBUILT EVERY DESK IN THE DIVISION
    on every save, which is why it needed a guard against a browser omitting an
    empty multi-select and silently emptying all of them at once.

    Assignment is a fact about a person, so it belongs on that person. This box
    touches one member's rows and nothing else, so that whole class of accident
    stops existing, and "what access does this person have" has one page rather
    than two. The division-wide read is the access report, which now carries a
    Desks column beside Roles.

    Admin only, as it was: who receives which requests is a leadership
    arrangement, and putting it in front of the ATC training manager invites
    quiet reassignment.

    Expects: $user
--}}
@can('system.settings.manage')
    @php
        $rows = \App\Models\Vatssa\RequestTarget::with('rating')
            ->where('user_id', $user->id)->get();

        // What they sit at now, as the same "tier:rating" keys the form posts.
        $held = $rows->map(fn ($row) => \App\Models\Vatssa\RequestTarget::isPerRating($row->tier)
            ? $row->tier . ':' . $row->rating_id
            : $row->tier)->all();

        $tiers = \App\Models\Vatssa\RequestTarget::tiers(true);
        $ratings = \App\Models\Rating::whereNotNull('vatsim_rating')
            ->orderBy('vatsim_rating')->get();
    @endphp

    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 fw-bold text-white">
                <i class="fas fa-inbox"></i>&nbsp;Request desks
            </h6>
        </div>
        <div class="card-body">
            @if($rows->isEmpty())
                <p class="text-muted">
                    On no request desk. They receive nothing automatically.
                </p>
            @endif

            <form method="POST" action="{{ route('vatssa.admin.desks.update', $user) }}">
                @csrf

                @foreach($tiers as $tier => $desk)
                    @if($desk['per_rating'])
                        <div class="mb-2">
                            <div class="fw-bold fs-sm">{{ $desk['label'] }}</div>
                            @if($desk['hint'])
                                <div class="text-muted fs-sm mb-1">{{ $desk['hint'] }}</div>
                            @endif

                            {{-- A coordinator row with NO rating is the catch-all,
                                 and it is what makes a one-coordinator division
                                 work without four identical rows. It is offered
                                 first, and named, rather than left to be
                                 discovered by leaving a field blank. --}}
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="desks[]"
                                       value="{{ $tier }}:" id="desk-{{ $tier }}-all"
                                       @checked(in_array($tier . ':', $held, true))>
                                <label class="form-check-label" for="desk-{{ $tier }}-all">All ratings</label>
                            </div>

                            @foreach($ratings as $rating)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="desks[]"
                                           value="{{ $tier }}:{{ $rating->id }}" id="desk-{{ $tier }}-{{ $rating->id }}"
                                           @checked(in_array($tier . ':' . $rating->id, $held, true))>
                                    <label class="form-check-label" for="desk-{{ $tier }}-{{ $rating->id }}">{{ $rating->name }}</label>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="desks[]"
                                   value="{{ $tier }}" id="desk-{{ $tier }}"
                                   @checked(in_array($tier, $held, true))>
                            <label class="form-check-label" for="desk-{{ $tier }}">
                                <span class="fw-bold fs-sm">{{ $desk['label'] }}</span>
                                @if($desk['hint'])
                                    <span class="d-block text-muted fs-sm">{{ $desk['hint'] }}</span>
                                @endif
                            </label>
                        </div>
                    @endif
                @endforeach

                <button type="submit" class="btn btn-sm btn-outline-secondary mt-2">Save desks</button>
            </form>
        </div>
    </div>
@endcan
