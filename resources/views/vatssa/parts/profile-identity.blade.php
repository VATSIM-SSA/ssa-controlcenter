{{--
    VATSSA: who this person is, across the top of the page.

    Upstream ran this as a tall card down the left, which made it a COLUMN
    competing with the content beside it -- the page had two reading orders and
    the eye had to pick one. Across the top it is a masthead instead: read once,
    then everything below is about the person it named.

    The facts are grouped by the question they answer, not by the order the
    columns happened to be in: who they are, where they stand, what they fly,
    when we last saw them. Standing is first among those because it is the one
    that changes the meaning of every section below it.

    Expects: $user, $areas, $atcActivityHours, $totalHours, $relationship,
             $approvedController, $relationshipSince, $rosterSince
--}}
<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-white">
            <i class="fas fa-user"></i>&nbsp;{{ $user->first_name.' '.$user->last_name }}
        </h6>

        {{-- The standing, in the header rather than the body.

             It is the one fact on this page that changes how everything under
             it should be read -- an endorsement list means something different
             for a visitor than for a home member -- so it sits where the name
             is rather than waiting in a list. --}}
        <span class="d-flex align-items-center gap-2">
            <span class="badge text-bg-{{ $relationship->color() }}"
                  data-bs-toggle="tooltip" data-bs-placement="bottom"
                  title="{{ $relationship->description() }}">
                <i class="fas {{ $relationship->icon() }}"></i>&nbsp;{{ $relationship->label() }}
            </span>

            {{-- The second axis, always shown, including when the answer is no.

                 Absence would read as "we have not checked", and the whole
                 point of the roster line is that somebody can tell at a glance
                 whether this person may control. --}}
            <span class="badge text-bg-{{ $approvedController ? 'success' : 'secondary' }}">
                <i class="fas fa-{{ $approvedController ? 'headset' : 'circle-minus' }}"></i>&nbsp;{{ $approvedController ? 'Approved controller' : 'Not on the roster' }}
            </span>
        </span>
    </div>

    <div class="card-body">
        {{-- `copyable` goes on each dl, NOT on this row.

             _global.scss scopes the rule as `dl.copyable`, and everything it
             does is to the buttons inside: hide them until the line is hovered,
             strip the border and background, and turn them green on click. On
             the wrapper it matched nothing, so every copy button rendered as a
             raw browser button -- a bordered grey box sitting permanently
             beside the CID, the name and the email. --}}
        <div class="row g-4">

            {{-- Identity. --}}
            <div class="col-xl-3 col-md-6">
                <dl class="mb-0 copyable">
                    <dt>VATSIM ID</dt>
                    <dd>
                        {{ $user->id }}
                        <button type="button" onclick="navigator.clipboard.writeText('{{ $user->id }}')"><i class="fas fa-copy"></i></button>
                        <a href="https://stats.vatsim.net/stats/{{ $user->id }}" target="_blank" title="VATSIM Stats" class="link-btn me-1"><i class="fas fa-chart-simple"></i></a>
                        @if($user->division == 'EUD' && Auth::user()->can('users.manage'))
                            <a href="https://core.vateud.net/manage/controller/{{ $user->id }}/view" target="_blank" title="VATEUD Core Profile" class="link-btn"><i class="fa-solid fa-earth-europe"></i></a>
                        @endif
                    </dd>

                    <dt>Name</dt>
                    <dd>{{ $user->first_name.' '.$user->last_name }}<button type="button" onclick="navigator.clipboard.writeText('{{ $user->first_name.' '.$user->last_name }}')"><i class="fas fa-copy"></i></button></dd>

                    <dt>Email</dt>
                    <dd class="mb-0">{{ $user->email }}<button type="button" onclick="navigator.clipboard.writeText('{{ $user->email }}')"><i class="fas fa-copy"></i></button></dd>
                </dl>
            </div>

            {{-- Standing. The two axes again, with their dates this time.

                 The badges above answer "what are they"; this answers "since
                 when", which is the question the header has no room for. --}}
            <div class="col-xl-3 col-md-6">
                <dl class="mb-0 copyable">
                    @if(config('app.mode') == 'subdivision')
                        <dt>Sub/Division</dt>
                        <dd>{{ $user->division }} / {{ $user->subdivision }}</dd>
                    @else
                        <dt>Division</dt>
                        <dd>{{ $user->division }}</dd>
                    @endif

                    <dt>Divisional standing</dt>
                    <dd>
                        {{ $relationship->label() }}
                        @if($relationshipSince && $relationshipSince->note !== 'First recorded')
                            <span class="text-muted fs-sm d-block">since {{ $relationshipSince->effective_from->toEuropeanDate() }}</span>
                        @endif
                    </dd>

                    <dt>Roster</dt>
                    <dd class="mb-0">
                        {{ $approvedController ? 'Approved controller' : 'Not on the roster' }}
                        @if($rosterSince && $rosterSince->note !== 'First recorded')
                            <span class="text-muted fs-sm d-block">since {{ $rosterSince->effective_from->toEuropeanDate() }}</span>
                        @endif
                    </dd>
                </dl>
            </div>

            {{-- Controlling. --}}
            <div class="col-xl-3 col-md-6">
                <dl class="mb-0 copyable">
                    <dt>ATC Rating</dt>
                    <dd>{{ $user->rating_short }}</dd>

                    <dt>ATC Active</dt>
                    <dd>
                        @if($user->isVisiting())
                            <i class="far fa-circle-check text-success"></i>
                            Visiting
                        @else
                            <i class="fas fa-circle-{{ $user->isAtcActive() ? 'check' : 'xmark' }} text-{{ $user->isAtcActive() ? 'success' : 'danger' }}"></i> {{ ($totalHours >= 10) ? round($totalHours) : round($totalHours, 1) }} hours
                        @endif
                    </dd>

                    <dt>ATC Hours</dt>
                    @foreach($areas as $area)
                        <dd class="mb-0">
                            @if(!Setting::get('atcActivityBasedOnTotalHours'))
                                @if($atcActivityHours[$area->id]["active"])
                                    <i class="far fa-circle-check text-success"></i>
                                @else
                                    <i class="far fa-circle-xmark text-danger"></i>
                                @endif
                            @endif

                            {{ $area->name }}: {{ ($atcActivityHours[$area->id]["hours"] >= 10) ? round($atcActivityHours[$area->id]["hours"]) : round($atcActivityHours[$area->id]["hours"], 1) }}h
                            {!! ($atcActivityHours[$area->id]["graced"]) ? '<i class="fas fa-person-praying" data-bs-toggle="tooltip" data-bs-placement="right" title="This controller is in grace period for '.Setting::get('atcActivityGracePeriod', 12).' months after completing their training"></i>' : '' !!}
                        </dd>
                    @endforeach
                </dl>
            </div>

            {{-- Presence: platforms, VATSIM totals, and when we last saw them. --}}
            <div class="col-xl-3 col-md-6">
                <dl class="mb-0 copyable">
                    {{-- VATSSA: platforms live in this list, not in a card of
                         their own further down. "Are they on Discord" is part
                         of who somebody is here, and a reader going down a
                         profile should not have to know to go looking. --}}
                    @include('vatssa.parts.platform-lines', ['user' => $user])

                    <div id="vatsim-data">
                        <dt>VATSIM Stats&nbsp;<a href="https://stats.vatsim.net/stats/{{ $user->id }}" target="_blank"><i class="fas fa-link"></i></a></dt>
                    </div>

                    <dt class="pt-2">Last login</dt>
                    <dd class="mb-0">{{ $user->last_login->toEuropeanDateTime() }}</dd>

                    @can('users.manage')
                        <dt class="pt-2">Last activity</dt>
                        <dd class="mb-0">{{ isset($user->last_activity) ? $user->last_activity->toEuropeanDateTime() : 'N/A' }}</dd>
                    @endcan
                </dl>
            </div>

        </div>
    </div>
</div>
