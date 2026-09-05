{{--
    VATSSA: are they on the roster, and when did that last change.

    The same roster axis the divisional history shows, on its own and in full,
    because the endorsements below it only mean anything for somebody who may
    control. A reader looking at an endorsement list wants the roster answer in
    the same glance.

    Expects: $user, $approvedController, $rosterHistory
--}}
<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-white">Roster</h6>
        <span class="badge text-bg-{{ $approvedController ? 'success' : 'secondary' }}">
            {{ $approvedController ? 'Active' : 'Not active' }}
        </span>
    </div>

    <div class="card-body">
        <p class="mb-3">
            @if($approvedController)
                <i class="fas fa-circle-check text-success"></i>
                <strong>Approved controller.</strong>
                Active on the roster and may control.
            @else
                <i class="fas fa-circle-xmark text-secondary"></i>
                <strong>Not on the roster.</strong>
                Not currently active, so they may not control.
            @endif
        </p>

        {{-- The roster warning is not a fact, it is a deadline, so it keeps its
             own alert rather than becoming a row in the list below. --}}
        @include('vatssa.parts.roster-warning', ['user' => $user])

        @if($rosterHistory->isEmpty())
            <p class="text-muted mb-0 fs-sm">
                No roster changes recorded. History is kept from the day a change
                is first noticed.
            </p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-leftpadded mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>From</th>
                            <th>Status</th>
                            <th>Why</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rosterHistory as $entry)
                            <tr>
                                <td class="text-nowrap">{{ $entry->effective_from->toEuropeanDate() }}</td>
                                <td>
                                    <span class="badge text-bg-{{ $entry->color() }}">{{ $entry->label() }}</span>
                                </td>
                                <td class="fs-sm text-muted">{{ $entry->note ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
