{{--
    VATSSA: what this member has been to the division, and since when.

    Every value is derived -- from the VATSIM division field, the roster and the
    transfer/visit system -- so this panel reports and never asserts. Where an
    exception is needed it is made in the system that owns the fact, which is
    why there is nothing to edit here.

    Both axes in one list, because the question a reader has is "what has
    happened to this person" and the answer interleaves: became a visitor, went
    on the roster, transferred, became home.

    Expects: $user, $relationship, $approvedController, $statusHistory
--}}
<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-white">Divisional history</h6>
        <span class="badge text-bg-light">
            <i class="fas {{ $relationship->icon() }}"></i>&nbsp;{{ $relationship->label() }}
        </span>
    </div>

    <div class="card-body">
        {{-- What they are TODAY, before any history.

             The derivation is the truth; the rows below are only a record of
             when it changed. Putting today first stops a reader inferring the
             current state from the top row of a table, which would be wrong on
             any profile whose history has not been recorded yet. --}}
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="fs-sm text-muted">Standing</div>
                    <div class="fw-bold">
                        <span class="badge text-bg-{{ $relationship->color() }}">
                            <i class="fas {{ $relationship->icon() }}"></i>&nbsp;{{ $relationship->label() }}
                        </span>
                    </div>
                    <div class="fs-sm text-muted mt-1">{{ $relationship->description() }}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="fs-sm text-muted">Roster</div>
                    <div class="fw-bold">
                        <span class="badge text-bg-{{ $approvedController ? 'success' : 'secondary' }}">
                            {{ $approvedController ? 'Approved controller' : 'Not on the roster' }}
                        </span>
                    </div>
                    <div class="fs-sm text-muted mt-1">
                        {{ $approvedController
                            ? 'Active on the roster, so they may control.'
                            : 'Not currently active on the roster.' }}
                    </div>
                </div>
            </div>
        </div>

        @if($statusHistory->isEmpty())
            {{-- Not an error, and not "no history" either.

                 Control Center stored the roster as current state with no
                 events behind it, and the division field arrives from VATSIM
                 with nothing attached. There was nothing to backfill from, so
                 the record genuinely starts the day this shipped -- and saying
                 so is better than an empty table implying nothing ever
                 happened to this member. --}}
            <p class="text-muted mb-0 fs-sm">
                Nothing recorded yet. History is kept from the day a change is
                first noticed &mdash; there was no earlier record to build it from.
            </p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-leftpadded mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>From</th>
                            <th>Axis</th>
                            <th>Became</th>
                            <th>Why</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($statusHistory as $entry)
                            <tr>
                                <td class="text-nowrap">{{ $entry->effective_from->toEuropeanDate() }}</td>
                                <td class="text-nowrap fs-sm text-muted">{{ $entry->axis->label() }}</td>
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
