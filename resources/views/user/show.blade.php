@extends('layouts.app')

@section('title', 'User Details')

@section('header')
    @vite(['resources/sass/bootstrap-table.scss', 'resources/js/bootstrap-table.js'])
@endsection

@section('content')

{{--
    VATSSA: a member's file, read top to bottom.

    ## Why this is not a grid of cards any more

    Upstream laid the profile out as two columns: a tall details card down the
    left and everything else beside it. That gave the page two reading orders
    competing for the same eye, and no way to find one card except by reading
    all twenty. Nothing was grouped, so Endorsements sat between Internal notes
    and Activity for no reason other than the order somebody added them.

    It is now a masthead, a summary strip, and eight named sections. The
    masthead runs across the top because identity is read once and then
    everything below is about the person it named. The sections are in the order
    somebody actually asks about a member:

      1. Divisional history  -- what they are to us, and since when
      2. Training            -- what they are learning
      3. Roster              -- whether they may control, and on what
      4. Feedback            -- what people have said about them controlling
      5. Internal notes      -- what we have recorded, not visible to them
      6. Visiting & transferring -- the requests that changed their standing
      7. Terminal log        -- what was done about them on VATSIM Terminal
      8. Access              -- what they can do in here

    Standing first, because it changes how every section under it should be
    read: an endorsement list means something different for a visitor than for
    a home member.
--}}

{{-- The masthead. Identity, standing, controlling, presence. --}}
@include('vatssa.parts.profile-identity', [
    'user' => $user,
    'areas' => $areas,
    'atcActivityHours' => $atcActivityHours,
    'totalHours' => $totalHours,
    'relationship' => $relationship,
    'approvedController' => $approvedController,
    'relationshipSince' => $relationshipSince,
    'rosterSince' => $rosterSince,
])

{{-- The summary strip: are they flying, and when were they last on.

     Directly under the masthead and above the sections, because these two
     answer "is this person active right now", which is the question somebody
     opening a profile most often has before any of the detail below. --}}
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

{{-- ------------------------------------------------------- 2. Training --}}
{{-- VATSSA: the theory panel on a PROFILE is unfiltered -- every rating this
     person has ever sat. Your own is always yours; somebody else's is
     `training.results.history.view`, which is the ATC training manager and
     admin.

     A pipeline coordinator still sees theory where it answers the question they
     have open: on the training itself, filtered to that training's rating. What
     they no longer get is a person's whole examination history from the member
     directory. --}}
@php
    $showTheoryHistory = Auth::user()->is($user)
        || Auth::user()->can('training.results.history.view');
@endphp


{{-- ------------------------------------------------------ the member file --}}
{{--
    One box with tabs, rather than eight stacked sections.

    Stacked, the page was four screens deep and every visit meant scrolling
    past seven things to reach the eighth. The sections were already named and
    ordered; making them tabs keeps that and costs one click, which is cheaper
    than a scroll you have to aim.

    THE TAB LIST IS BUILT ONCE, above, and both the strip and the panes read
    from it. Two hand-kept lists is how a page ends up with a tab that opens
    nothing, or a pane nobody can reach -- and the second is a silent
    permissions leak waiting to happen.

    Gating is per tab. A reader who may not see the Terminal log has no
    Terminal tab at all, rather than a tab that opens an empty box.
--}}
@php
    // Fully qualified, no `use`. Blade compiles a view into a method scope, so
    // a `use` statement inside @php is a fatal error rather than an import --
    // which is why every other blade in this fork names classes in full.
    $viewer = Auth::user();

    $tabs = [
        'standing' => ['label' => 'Divisional history', 'icon' => 'fa-id-card'],
        'training' => ['label' => 'Training', 'icon' => 'fa-graduation-cap'],
        'roster' => ['label' => 'Roster', 'icon' => 'fa-headset'],
    ];

    if ($viewer->can('viewFeedback', \App\Models\ManagementReport::class)) {
        $tabs['feedback'] = ['label' => 'Feedback', 'icon' => 'fa-comment-dots'];
    }

    // EITHER note permission earns the tab, because the two partials inside it
    // have different audiences and each renders nothing without its own. A
    // reader holding one of the two still has something to open.
    if ($viewer->can(\App\Models\Vatssa\InternalNote::permissionFor(\App\Models\Vatssa\InternalNote::SCOPE_USER))
        || $viewer->can(\App\Models\Vatssa\InternalNote::permissionFor(\App\Models\Vatssa\InternalNote::SCOPE_TRAINING))) {
        $tabs['notes'] = ['label' => 'Internal notes', 'icon' => 'fa-lock'];
    }

    $tabs['membership'] = ['label' => 'Visiting & transferring', 'icon' => 'fa-right-left'];

    if ($viewer->can('membership.terminal.view')) {
        $tabs['terminal'] = ['label' => 'Terminal log', 'icon' => 'fa-terminal'];
    }

    if ($viewer->can('viewAccess', $user)) {
        $tabs['access'] = ['label' => 'Access', 'icon' => 'fa-key'];
    }

    // Whichever tab survived the gating first, so the page always opens on
    // something rather than on a blank body.
    $firstTab = array_key_first($tabs);
@endphp

<div class="card shadow mb-4">
    <div class="card-body">
        {{-- The strip is in the BODY, not in a card-header.

             This fork already restyles .nav-tabs as flat underlines rather than
             Bootstrap's bordered notch, so there is no notch that needs to be
             cut into the card header for the active tab to read as open. Put in
             a header, the strip would have drawn its own rule two pixels above
             the header's, and fought the header's !important padding for the
             room to sit in. Its own underline is the separator. --}}
        <ul class="nav nav-tabs mb-3" role="tablist">
            @foreach($tabs as $key => $tab)
                <li class="nav-item" role="presentation">
                    <button class="nav-link @if($key === $firstTab) active @endif"
                            id="tab-{{ $key }}"
                            data-bs-toggle="tab"
                            data-bs-target="#pane-{{ $key }}"
                            type="button"
                            role="tab"
                            aria-controls="pane-{{ $key }}"
                            aria-selected="{{ $key === $firstTab ? 'true' : 'false' }}">
                        <i class="fas {{ $tab['icon'] }}"></i>&nbsp;{{ $tab['label'] }}
                    </button>
                </li>
            @endforeach
        </ul>

        <div class="tab-content">
            {{-- Divisional history --}}
            @isset($tabs['standing'])
                <div class="tab-pane fade @if($firstTab === 'standing') show active @endif"
                     id="pane-standing" role="tabpanel" aria-labelledby="tab-standing" tabindex="0">
            @include('vatssa.parts.divisional-history', [
                'user' => $user,
                'relationship' => $relationship,
                'approvedController' => $approvedController,
                'statusHistory' => $statusHistory,
            ])
                </div>
            @endisset

            {{-- Training --}}
            @isset($tabs['training'])
                <div class="tab-pane fade @if($firstTab === 'training') show active @endif"
                     id="pane-training" role="tabpanel" aria-labelledby="tab-training" tabindex="0">
            <div class="row">
                <div class="col-xl-12">
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
            </div>

            <div class="row">
                @if($showTheoryHistory)
                    <div class="col-xl-8 col-lg-12 col-md-12">
                        @include('vatssa.parts.theory', ['user' => $user])
                    </div>
                @endif

                {{-- Mentoring, where Division Exams used to be.

                     That card fetched VATEUD's theory record over HTTP on every profile
                     view, answered the same question as the Moodle theory panel beside
                     it, and never showed a VATSSA CPT at all -- our practicals never
                     reach VATEUD. See the partial.

                     Full width when theory is not shown, or it sits in a third of a row
                     with two thirds of nothing beside it. --}}
                <div class="{{ $showTheoryHistory ? 'col-xl-4' : 'col-xl-12' }} col-lg-12 col-md-12">
                    @include('vatssa.parts.mentoring-summary', ['user' => $user])
                </div>
            </div>
                </div>
            @endisset

            {{-- Roster --}}
            @isset($tabs['roster'])
                <div class="tab-pane fade @if($firstTab === 'roster') show active @endif"
                     id="pane-roster" role="tabpanel" aria-labelledby="tab-roster" tabindex="0">
            @include('vatssa.parts.roster-status', [
                'user' => $user,
                'approvedController' => $approvedController,
                'rosterHistory' => $rosterHistory,
            ])

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
            @endisset

            {{-- Feedback --}}
            @isset($tabs['feedback'])
                <div class="tab-pane fade @if($firstTab === 'feedback') show active @endif"
                     id="pane-feedback" role="tabpanel" aria-labelledby="tab-feedback" tabindex="0">
            @if($feedbackReceived->isEmpty())
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary py-3">
                        <h6 class="m-0 fw-bold text-white">Feedback received</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-0 text-muted">No feedback received.</p>
                    </div>
                </div>
            @else
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
                </div>
            @endisset

            {{-- Internal notes --}}
            @isset($tabs['notes'])
                <div class="tab-pane fade @if($firstTab === 'notes') show active @endif"
                     id="pane-notes" role="tabpanel" aria-labelledby="tab-notes" tabindex="0">
            @include('vatssa.parts.internal-notes', [
                'scope' => \App\Models\Vatssa\InternalNote::SCOPE_USER,
                'notes' => \App\Models\Vatssa\InternalNote::where('user_id', $user->id)
                    ->where('scope', \App\Models\Vatssa\InternalNote::SCOPE_USER)
                    ->with('author')->latest()->get(),
                'action' => route('vatssa.notes.user', $user),
            ])

            @include('vatssa.parts.training-notes-collected', ['trainingNotes' => $trainingNotes])
                </div>
            @endisset

            {{-- Visiting and transferring --}}
            @isset($tabs['membership'])
                <div class="tab-pane fade @if($firstTab === 'membership') show active @endif"
                     id="pane-membership" role="tabpanel" aria-labelledby="tab-membership" tabindex="0">
            @include('vatssa.parts.membership-history', ['membershipRequests' => $membershipRequests])
                </div>
            @endisset

            {{-- Terminal log --}}
            @isset($tabs['terminal'])
                <div class="tab-pane fade @if($firstTab === 'terminal') show active @endif"
                     id="pane-terminal" role="tabpanel" aria-labelledby="tab-terminal" tabindex="0">
            @if($terminalHistory->isEmpty())
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 fw-bold text-white">Terminal history</h6>
                        <a href="{{ route('vatssa.terminal.index', ['cid' => $user->id]) }}"
                           class="btn btn-icon btn-light btn-sm">
                            <i class="fas fa-list"></i> All
                        </a>
                    </div>
                    <div class="card-body">
                        <p class="mb-0 text-muted">Nothing recorded on Terminal for this member.</p>
                    </div>
                </div>
            @else
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 fw-bold text-white">Terminal history</h6>
                        <a href="{{ route('vatssa.terminal.index', ['cid' => $user->id]) }}"
                           class="btn btn-icon btn-light btn-sm">
                            <i class="fas fa-list"></i> All
                        </a>
                    </div>
                    <div class="card-body">
                        @foreach($terminalHistory as $entry)
                            <div class="mb-3 pb-3 {{ ! $loop->last ? 'border-bottom' : '' }}">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <span class="badge text-bg-{{ $entry->type->color() }}">
                                        <i class="fas {{ $entry->type->icon() }}"></i>&nbsp;{{ $entry->type->label() }}
                                    </span>
                                    <span class="small text-muted">{{ $entry->performed_at->toEuropeanDate() }}</span>
                                </div>
                                <div class="fs-sm mt-1">
                                    {{ $entry->reason->label() }}
                                    @if($entry->ratingFrom || $entry->ratingTo)
                                        &middot; {{ $entry->ratingFrom->name ?? '—' }} &rarr; {{ $entry->ratingTo->name ?? '—' }}
                                    @endif
                                </div>
                                @if($entry->isDisciplinaryCheck())
                                    <div class="fs-sm mt-1">
                                        @if($entry->discipline_found)
                                            <span class="badge text-bg-danger">History found</span>
                                        @else
                                            {{-- A clean check is a RESULT. "We looked and
                                                 there was nothing" is what you need six
                                                 months later. --}}
                                            <span class="badge text-bg-success">Checked, clean</span>
                                        @endif
                                    </div>
                                @endif
                                <div class="fs-sm text-muted mt-1">by {{ $entry->actorLabel() }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
                </div>
            @endisset

            {{-- Access --}}
            @isset($tabs['access'])
                <div class="tab-pane fade @if($firstTab === 'access') show active @endif"
                     id="pane-access" role="tabpanel" aria-labelledby="tab-access" tabindex="0">
            <div class="row">
                <div class="col-xl-4 col-lg-6 col-md-12">
                    @livewire('user-roles', ['user' => $user])
                </div>
            </div>
                </div>
            @endisset
        </div>
    </div>
</div>

@endsection

@section('js')

    {{-- Remember which tab was open.

         A profile is a page people reload -- after granting a role, after
         writing a note -- and landing back on Divisional history every time
         makes the tabs feel like they lost your place. The fragment is written
         with replaceState rather than by setting location.hash, because
         assigning the hash makes the browser jump to the element and the page
         scrolls itself away from the masthead. --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var strip = document.querySelector('[role="tablist"]');
            if (!strip || !window.bootstrap) return;

            var wanted = window.location.hash.replace('#tab-', '');
            if (wanted) {
                var trigger = document.getElementById('tab-' + wanted);
                // Only a tab that exists for THIS reader. A stale or hand-typed
                // fragment naming a tab they may not see must do nothing.
                if (trigger) {
                    bootstrap.Tab.getOrCreateInstance(trigger).show();
                }
            }

            strip.addEventListener('shown.bs.tab', function (event) {
                var id = event.target.id.replace('tab-', '');
                history.replaceState(null, '', '#tab-' + id);
            });
        });
    </script>


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
