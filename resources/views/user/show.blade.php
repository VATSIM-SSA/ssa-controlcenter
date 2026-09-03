@extends('layouts.app')

@section('title', 'User Details')

@section('header')
    @vite(['resources/sass/bootstrap-table.scss', 'resources/js/bootstrap-table.js'])
@endsection

@section('content')

<div class="row">
    <div class="col-xl-3 col-md-4 col-sm-12 mb-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-user"></i>&nbsp;{{ $user->first_name.' '.$user->last_name }}
                </h6>
            </div>
            <div class="card-body">

                <dl class="copyable">
                    {{-- VATSSA: platforms live in this list, not in a card of
                         their own further down. "Are they on Discord" is part
                         of who somebody is here, and a reader going down a
                         profile should not have to know to go looking. --}}
                    @include('vatssa.parts.platform-lines', ['user' => $user])

                    <dt>VATSIM ID</dt>
                    <dd>
                        {{ $user->id }}
                        <button type="button" onclick="navigator.clipboard.writeText('{{ $user->id }}')"><i class="fas fa-copy"></i></button>
                        <a href="https://stats.vatsim.net/stats/{{ $user->id }}" target="_blank" title="VATSIM Stats" class="link-btn me-1"><i class="fas fa-chart-simple"></i></button></a>
                        @if($user->division == 'EUD' && Auth::user()->can('users.manage'))
                            <a href="https://core.vateud.net/manage/controller/{{ $user->id }}/view" target="_blank" title="VATEUD Core Profile" class="link-btn"><i class="fa-solid fa-earth-europe"></i></button></a>
                        @endif
                    </dd>

                    <dt>Name</dt>
                    <dd>{{ $user->first_name.' '.$user->last_name }}<button type="button" onclick="navigator.clipboard.writeText('{{ $user->first_name.' '.$user->last_name }}')"><i class="fas fa-copy"></i></button></dd>

                    <dt>Email</dt>
                    <dd class="separator pb-3">{{ $user->email }}<button type="button" onclick="navigator.clipboard.writeText('{{ $user->email }}')"><i class="fas fa-copy"></i></button></dd>

                    <dt class="pt-2">ATC Rating</dt>
                    <dd>{{ $user->rating_short }}</dd>


                    @if(config('app.mode') == 'subdivision')
                        <dt>Sub/Division</dt>
                        <dd class="separator pb-3">{{ $user->division }} / {{ $user->subdivision }}</dd>
                    @else
                        <dt>Division</dt>
                        <dd class="separator pb-3">{{ $user->division }}</dd>
                    @endif

                    <dt class="pt-2">ATC Active</dt>
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

                    <div id="vatsim-data">
                        <dt class="pt-2">VATSIM Stats&nbsp;<a href="https://stats.vatsim.net/stats/{{ $user->id }}" target="_blank"><i class="fas fa-link"></i></a></dt>
                    </div>

                    <dd class="separator pb-3"></dd>

                    <dt class="pt-2">Last login</dt>
                    <dd>{{ $user->last_login->toEuropeanDateTime() }}</dd>

                    @can('users.manage')
                        <dt class="pt-2">Last activity</dt>
                        <dd>{{ isset($user->last_activity) ? $user->last_activity->toEuropeanDateTime() : 'N/A' }}</dd>
                    @endcan

                </dl>
            </div>
        </div>

        @can('viewAccess', $user)
            @livewire('user-roles', ['user' => $user])
        @endcan

        {{-- VATSSA: which desks they receive requests on. Admin only -- a role
             grants permissions, a desk decides who gets the work, and the two
             are set in different places on purpose. --}}
        @include('vatssa.parts.desks', ['user' => $user])

        {{-- VATSSA: upstream's Mentoring card is GONE, folded into
             vatssa.parts.mentoring-summary further down.

             Two cards with the same heading on one page, both about the same
             mentor -- one listing their students, the other their ceiling, load
             and teachable ratings. Neither answered a question on its own. The
             students table and its "See reports" button moved across verbatim;
             nothing was lost. --}}
    </div>

    <div class="col-xl-9 col-md-8 col-sm-12 mb-12">
        <div class="row">
            <div class="col-xl-8 col-lg-12 col-md-12">
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 fw-bold text-white">
                            Trainings
                        </h6>
                        @can('create', \App\Models\Training::class)
                            <a href="{{ route('training.create.id', $user->id) }}" class="btn btn-icon btn-light"><i class="fas fa-plus"></i> Add new training</a>
                        @endcan
                    </div>
                    <div class="card-body {{ $trainings->count() == 0 ? '' : 'p-0' }}">

                        @if($trainings->count() == 0)
                            <p class="mb-0">No registered trainings</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm table-leftpadded mb-0" width="100%" cellspacing="0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>State</th>
                                            <th>Level</th>
                                            <th>Area</th>
                                            <th>Type</th>
                                            <th>Applied</th>
                                            <th>Ended</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($trainings as $training)
                                        <tr>
                                            <td>
                                                <i class="{{ $training->status->icon() }} text-{{ $training->status->color() }}"></i>&ensp;<a href="/training/{{ $training->id }}">{{ $training->status->label() }}</a>{{ isset($training->paused_at) ? ' (PAUSED)' : '' }}
                                            </td>
                                            <td>
                                                @if ( is_iterable($ratings = $training->ratings->toArray()) )
                                                    @for( $i = 0; $i < sizeof($ratings); $i++ )
                                                        @if ( $i == (sizeof($ratings) - 1) )
                                                            {{ $ratings[$i]["name"] }}
                                                        @else
                                                            {{ $ratings[$i]["name"] . " + " }}
                                                        @endif
                                                    @endfor
                                                @else
                                                    {{ $ratings["name"] }}
                                                @endif
                                            </td>
                                            <td>
                                                {{ $training->area->name }}
                                            </td>
                                            <td>
                                                <i class="{{ $types[$training->type]["icon"] }}"></i>&ensp;{{ $types[$training->type]["text"] }}
                                            </td>
                                            <td>
                                                {{ $training->created_at->toEuropeanDate() }}
                                            </td>
                                            <td>
                                                @if ($training->closed_at != null)
                                                    {{ $training->closed_at->toEuropeanDate() }}
                                                @else
                                                    N/A
                                                @endif
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                    </div>
                </div>
            </div>

            {{-- Mentoring, where Division Exams used to be.

                 That card fetched VATEUD's theory record over HTTP on every
                 profile view, answered the same question as the Moodle theory
                 panel two rows down, and never showed a VATSSA CPT at all --
                 our practicals never reach VATEUD. See the partial. --}}
            <div class="col-xl-4 col-lg-12 col-md-12">
                @include('vatssa.parts.mentoring-summary', ['user' => $user])
            </div>
        </div>

        {{-- VATSSA: the two things Control Center could not answer about a
             member -- can we reach them, and what have they passed.

             A row of their own rather than stacked under Division Exams: that
             made the right-hand column taller than Trainings and left a hole
             beside it. Platforms narrow, theory wide, matching the training
             page so the same two panels sit the same way in both places. --}}
        {{-- EIGHT AND FOUR, like every other row on this page.

             Upstream splits this column 8/4 twice -- Trainings beside Division
             Exams, Activity beside Recent Connections. These rows were 4/8 and
             7/5, so every gutter landed somewhere different and the right edge
             came out ragged. Matching the rhythm is most of what "tidy" means
             on a page like this.

             Theory takes the wide half because it is a table; platforms is two
             badges and sits in the narrow one, directly under Division Exams
             which is also short. --}}
        {{-- VATSSA: the theory panel on a PROFILE is unfiltered -- every rating
             this person has ever sat. Your own is always yours; somebody
             else's is `training.results.history.view`, which is the ATC
             training manager and admin.

             A pipeline coordinator still sees theory where it answers the
             question they have open: on the training itself, filtered to that
             training's rating. What they no longer get is a person's whole
             examination history from the member directory. --}}
        @php
            $showTheoryHistory = Auth::user()->is($user)
                || Auth::user()->can('training.results.history.view');
        @endphp

        <div class="row">
            @if($showTheoryHistory)
                <div class="col-xl-8 col-lg-12 col-md-12">
                    @include('vatssa.parts.theory', ['user' => $user])
                </div>
            @endif
            {{-- Full width when theory is not shown, or the warning sits in a
                 third of a row with two thirds of nothing beside it. --}}
            <div class="{{ $showTheoryHistory ? 'col-xl-4' : 'col-xl-12' }} col-lg-12 col-md-12">
                {{-- The card is gone; its facts are in the summary at the top.
                     The roster warning is not a fact, it is a deadline, so it
                     stays as an alert. --}}
                @include('vatssa.parts.roster-warning', ['user' => $user])
            </div>
        </div>

        {{-- Full width, like Endorsements below it. Admin-only notes about the
             person, which outlive every training -- closing a training must not
             erase the reason it was closed. --}}
        <div class="row">
            <div class="col-xl-12 col-lg-12 col-md-12">
                @include('vatssa.parts.internal-notes', [
                    'scope' => \App\Models\Vatssa\InternalNote::SCOPE_USER,
                    'notes' => \App\Models\Vatssa\InternalNote::where('user_id', $user->id)
                        ->where('scope', \App\Models\Vatssa\InternalNote::SCOPE_USER)
                        ->with('author')->latest()->get(),
                    'action' => route('vatssa.notes.user', $user),
                ])
            </div>
        </div>

        <div class="col-xl-12 col-lg-12 col-md-12 p-0">
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 fw-bold text-white">
                        Endorsements
                    </h6>
                    @can('create', \App\Models\Endorsement::class)
                        <a href="{{ route('endorsements.create.id', $user->id) }}" class="btn btn-icon btn-light"><i class="fas fa-plus"></i> Add new endorsement</a>
                    @endcan
                </div>
                <div class="card-body d-flex flex-wrap gap-3">

                    @if($endorsements->count() == 0)
                        <p class="mb-0">No registered endorsements</p>
                    @endif

                    @foreach($endorsements as $endorsement)
                        <div class="card bg-light mb-3 endorsement-card" data-endorsement-id="{{ $endorsement['id'] }}">
                            <div class="card-header fw-bold">

                                @if($endorsement->revoked)
                                    <i class="fas fa-circle-xmark text-danger" data-bs-toggle="tooltip" data-bs-placement="top" title="Revoked"></i>
                                @elseif($endorsement->expired)
                                    <i class="fas fa-circle-minus text-danger" data-bs-toggle="tooltip" data-bs-placement="top" title="Expired"></i>
                                @else
                                    <i class="fas fa-circle-check text-success" data-bs-toggle="tooltip" data-bs-placement="top" title="Active"></i>
                                @endif

                                {{ ucfirst(strtolower($endorsement->type)) }} Endorsement

                                @can('delete', [\App\Models\Endorsement::class, $endorsement])
                                    <a href="{{ route('endorsements.delete', $endorsement->id) }}" class="text-muted float-end hover-red" data-bs-toggle="tooltip" data-bs-placement="top" title="Revoke" onclick="return confirm('Are you sure you want to revoke this endorsement?')"><i class="fas fa-trash"></i></a>
                                @endcan

                                @if($endorsement->type == 'SOLO' && isset($endorsement->valid_to))
                                    @can('shorten', [\App\Models\Endorsement::class, $endorsement])
                                        <span class="flatpickr">
                                            <input type="text" style="width: 1px; height: 1px; visibility: hidden;" data-endorsement-id="{{ $endorsement['id'] }}" data-date="{{ $endorsement->valid_to->format('Y-m-d') }}" data-input>
                                            <a role="button" class="input-button text-muted float-end hover-red text-decoration-none" data-bs-toggle="tooltip" data-bs-placement="top" title="Shorten expire date" data-toggle>
                                                <i class="fas fa-calendar-minus"></i>&nbsp;
                                            </a>
                                        </span>
                                    @endcan
                                @endif
                            </div>
                            <div class="card-body">
                                <table class="table-card">
                                    @if($endorsement->type == "FACILITY")
                                        <tr class="spacing">
                                            <th>Position</th>
                                            <td>{{ $endorsement->ratings->first()->endorsement_type }} {{ $endorsement->ratings->first()->name }}</td>
                                        </tr>
                                        <tr>
                                            <th>Issued</th>
                                            <td>{{ $endorsement->valid_from->toEuropeanDate() }}</td>
                                        </tr>
                                        <tr class="spacing">
                                            <th>Expire</th>
                                            <td>{{ isset($endorsement->valid_to) ? $endorsement->valid_to->toEuropeanDateTime() : 'Never' }}</td>
                                        </tr>
                                        <tr>
                                            <th>Issued by</th>
                                            <td>{{ $endorsement->issuedBy?->name ?? 'System' }}</td>
                                        </tr>
                                        @if($endorsement->revoked)
                                            <tr>
                                                <th>Revoked by</th>
                                                <td>{{ $endorsement->revokedBy?->name ?? 'System' }}</td>
                                            </tr>
                                        @endif
                                    @elseif($endorsement->type == 'SOLO')
                                        <tr class="spacing">
                                            <th>Rating</th>
                                            <td>{{ implode(', ', $endorsement->positions->pluck('callsign')->toArray()) }}</td>
                                        </tr>
                                        <tr>
                                            <th>Issued</th>
                                            <td>{{ $endorsement->valid_from->toEuropeanDate() }}</td>
                                        </tr>
                                        <tr class="spacing">
                                            <th>Expire</th>
                                            <td>{{ isset($endorsement->valid_to) ? $endorsement->valid_to->toEuropeanDateTime() : 'Never' }}</td>
                                        </tr>
                                        <tr>
                                            <th>Issued by</th>
                                            <td>{{ $endorsement->issuedBy?->name ?? 'System' }}</td>
                                        </tr>
                                        @if($endorsement->revoked)
                                            <tr>
                                                <th>Revoked by</th>
                                                <td>{{ $endorsement->revokedBy?->name ?? 'System' }}</td>
                                            </tr>
                                        @endif
                                    @elseif($endorsement->type == "VISITING")
                                        <tr>
                                            <th>Rating</th>
                                            <td>{{ $endorsement->ratings->first()->name }}</td>
                                        </tr>
                                        <tr>
                                            <th>Areas</th>
                                            <td>{{ implode(', ', $endorsement->areas->pluck('name')->toArray()) }}</td>
                                        </tr>
                                        <tr>
                                            <th>Issued</th>
                                            <td>{{ $endorsement->valid_from->toEuropeanDate() }}</td>
                                        </tr>
                                        <tr class="spacing">
                                            <th>Expire</th>
                                            <td>{{ isset($endorsement->valid_to) ? $endorsement->valid_to->toEuropeanDateTime() : 'Never' }}</td>
                                        </tr>
                                        <tr>
                                            <th>Issued by</th>
                                            <td>{{ $endorsement->issuedBy?->name ?? 'System' }}</td>
                                        </tr>
                                        @if($endorsement->revoked)
                                            <tr>
                                                <th>Revoked by</th>
                                                <td>{{ $endorsement->revokedBy?->name ?? 'System' }}</td>
                                            </tr>
                                        @endif
                                    @elseif($endorsement->type == "EXAMINER")
                                        <tr>
                                            <th>Examining</th>
                                            <td>{{ $endorsement->ratings->first()->name }}</td>
                                        </tr>
                                        <tr class="spacing">
                                            <th>Areas</th>
                                            <td>{{ implode(', ', $endorsement->areas->pluck('name')->toArray()) }}</td>
                                        </tr>
                                        <tr>
                                            <th>Issued</th>
                                            <td>{{ $endorsement->valid_from->toEuropeanDate() }}</td>
                                        </tr>
                                        <tr class="spacing">
                                            <th>Expire</th>
                                            <td>{{ isset($endorsement->valid_to) ? $endorsement->valid_to->toEuropeanDateTime() : 'Never' }}</td>
                                        </tr>
                                        <tr>
                                            <th>Issued by</th>
                                            <td>{{ $endorsement->issuedBy?->name ?? 'System' }}</td>
                                        </tr>
                                        @if($endorsement->revoked)
                                            <tr>
                                                <th>Revoked by</th>
                                                <td>{{ $endorsement->revokedBy?->name ?? 'System' }}</td>
                                            </tr>
                                        @endif
                                    @endif
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-8 col-lg-12 col-md-12">
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 fw-bold text-white">
                            Activity
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="ratio ratio-21x9">
                            <canvas id="activityChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-lg-12 col-md-12">
                {{-- Feedback about this controller.

                     #1467 asks for it here so staff can see how much feedback
                     somebody has received without going to the report and
                     filtering. Renders nothing for anybody who cannot read the
                     feedback report, and the rows come through the same
                     `visibleTo()` scope that report uses -- so this can never
                     be a way around the area scope.

                     The submitter is named, unlike the controller-facing page:
                     this is the staff view, and knowing who said what is half
                     of deciding what to do about it. --}}
                @if($feedbackReceived->isNotEmpty())
                    <div class="card shadow mb-4">
                        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 fw-bold text-white">Feedback received</h6>
                            <a href="{{ route('reports.feedback', ['controller' => $user->id, 'status' => '']) }}"
                               class="btn btn-icon btn-light btn-sm">
                                <i class="fas fa-list"></i> All
                            </a>
                        </div>
                        <div class="card-body">
                            @foreach($feedbackReceived as $item)
                                <div class="mb-3 pb-3 {{ ! $loop->last ? 'border-bottom' : '' }}">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <span class="small text-muted">
                                            {{ $item->referencePosition?->callsign ?? 'No position' }}
                                            &middot; {{ $item->created_at->toEuropeanDate() }}
                                        </span>
                                        <span class="text-nowrap">
                                            @if($item->sentiment)
                                                <span class="badge text-bg-{{ $item->sentiment->color() }}">{{ $item->sentiment->label() }}</span>
                                            @endif
                                            <span class="badge text-bg-{{ $item->status->color() }}">{{ $item->status->label() }}</span>
                                        </span>
                                    </div>
                                    <div class="mt-1" style="white-space: pre-wrap;">{{ Str::limit($item->feedback, 240) }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="card shadow mb-4">
                    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 fw-bold text-white">
                            Recent Connections
                        </h6>
                    </div>
                    <div class="card-body {{ $recentAtcSessions->count() == 0 ? '' : 'p-0' }}">

                        @if($recentAtcSessions->count() == 0)
                            <p class="mb-0">No recent ATC sessions</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm table-leftpadded mb-0" width="100%" cellspacing="0">
                                    <thead class="table-light">
                                        <tr>
                                            <th data-sortable="true">Callsign</th>
                                            <th data-sortable="true">Date</th>
                                            <th data-sortable="true">Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($recentAtcSessions as $session)
                                        <tr>
                                            <td>{{ $session['callsign'] }}</td>
                                            <td>{{ isset($session['start']) ? Carbon\Carbon::parse($session['start'])->toEuropeanDateTime() : '—' }}</td>
                                            <td>{{ $session['duration'] ?? '—' }}</td>
                                        </tr>
                                        @endforeach
                                        <tr>
                                            <td colspan="3" class="text-center">
                                                <a href="https://stats.vatsim.net/stats/{{ $user->id }}" target="_blank" rel="noopener noreferrer">View additional sessions</a>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        @endif

                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@endsection

@section('js')

    <!-- Flatpickr -->
    @include('scripts.tooltips')
    @vite(['resources/js/flatpickr.js', 'resources/sass/flatpickr.scss', 'resources/js/chart.js'])
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            document.querySelectorAll('.flatpickr').flatpickr({ disableMobile: true, minDate: "{!! date('Y-m-d') !!}", dateFormat: "Y-m-d", locale: {firstDayOfWeek: 1 }, wrap: true, altInputClass: "hide",
                onChange: function(selectedDates, dateStr, instance) {
                    if(confirm('Are you sure you want to shorten this endorsement expire date to '+dateStr+'? Student will be notified by e-mail.')){
                        window.location.replace("/endorsements/shorten/"+instance.input.dataset.endorsementId+"/"+dateStr);
                    }
                },
                onReady: function(dateObj, dateStr, instance){ instance.config.maxDate = instance.input.dataset.date }
            });
        });
    </script>

    <!-- VATSIM Data Fetch -->
    <script>
        fetch("{{ route('user.vatsimhours') }}?cid={{ $user->id }}")
            .then(response => response.json())
            .then(data => {
                var vatsimHours = document.getElementById("vatsim-data");

                if (data.data) {
                    for (let key in data.data) {
                        if (key === "pilot") {
                            vatsimHours.innerHTML += "<dd class='mb-0'>Pilot: " + Math.round(data.data[key]) + "h</dd>"
                        } else if (key !== "id" && key !== "pilot" && key !== "atc" && data.data[key] > 0) {
                            vatsimHours.innerHTML += "<dd class='mb-0'>" + key.toUpperCase() + ": " + Math.round(data.data[key]) + "h</dd>"
                        }
                    }
                } else {
                    vatsimHours.innerHTML = vatsimHours.innerHTML + "<dd>No Data</dd>"
                }
            })
            .catch(error => {
                console.error(error);
                alert('An error occurred while fetching VATSIM hours data.');
            });
    </script>

    <!-- Activity chart -->
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const chartElement = document.getElementById('activityChart');
            if (!chartElement) return;

            // Calculate date range (11 months ago to now)
            const fromDate = new Date();
            fromDate.setMonth(fromDate.getMonth() - 11);
            fromDate.setHours(0, 0, 0, 0);

            const toDate = new Date();
            toDate.setHours(23, 59, 59, 999);

            const apiUrl = "{{ route('user.statistics.sessions', $user) }}?from="
                + encodeURIComponent(fromDate.toISOString())
                + "&to="
                + encodeURIComponent(toDate.toISOString());

            fetch(apiUrl)
                .then(response => {
                    if (!response.ok) {
                        return response.json()
                            .then(data => {
                                // API returned an error (e.g., StatisticsApiException)
                                throw new Error(data.error || `HTTP ${response.status}`);
                            })
                            .catch(() => Promise.reject(new Error(`HTTP ${response.status}`)));
                    }
                    return response.json();
                })
                .then(data => {
                    // Check if response contains an error (from StatisticsApiException)
                    if (data.error) {
                        throw new Error(data.error);
                    }

                    // Handle empty response - user has no ATC sessions
                    if (!Array.isArray(data) || data.length === 0) {
                        chartElement.closest('.card-body').innerHTML = '<p class="mb-0">No ATC activity data available</p>';
                        return;
                    }

                    // Process sessions and calculate hours
                    const sessions = data.map(session => {
                        const logonTime = new Date(session.logontime * 1000);
                        const logoffTime = new Date(session.logofftime * 1000);
                        // Calculate hours: difference is in milliseconds, convert to hours
                        const hours = Number(((logoffTime - logonTime) / 3_600_000).toFixed(1));

                        return {
                            ...session,
                            logontime: logonTime,
                            logofftime: logoffTime,
                            hours: hours,
                        };
                    });

                    // Initialize activity object with last 12 months (include year to avoid cross-year collisions)
                    const activity = {};
                    const now = new Date();
                    for (let i = 11; i >= 0; i--) {
                        const monthDate = new Date(now.getFullYear(), now.getMonth() - i, 1);
                        const monthKey = monthDate.toLocaleString('default', { month: 'short', year: 'numeric' });
                        activity[monthKey] = 0;
                    }

                    // Aggregate hours by month
                    sessions.forEach(session => {
                        const monthKey = session.logontime.toLocaleString('default', { month: 'short', year: 'numeric' });
                        if (activity[monthKey] !== undefined) {
                            activity[monthKey] += session.hours;
                        }
                    });

                    // Create chart
                    new Chart(chartElement, {
                        type: 'bar',
                        data: {
                            labels: Object.keys(activity),
                            datasets: [{
                                label: 'Hours online',
                                data: Object.values(activity),
                                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                                borderColor: 'rgb(54, 162, 235)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                        },
                    });
                })
                .catch(error => {
                    console.error('Statistics API error:', error);
                    chartElement.closest('.card-body').innerHTML = '<p class="mb-0 text-danger">Failed to load activity data</p>';
                });
        });
    </script>

@endsection
