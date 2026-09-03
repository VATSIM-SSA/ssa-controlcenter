@extends('layouts.app')

@section('title', $request->type->label())

@section('content')

{{--
    VATSSA: one membership request.

    Laid out so the thing that BLOCKS everything else is the first thing on the
    page. A visiting endorsement cannot be assigned until the Terminal
    disciplinary check is done, so that panel sits at the top rather than in a
    row of equal boxes -- a gate rendered like everything else is a gate people
    walk past.
--}}

<div class="row">
    <div class="col-xl-8 col-lg-12">

        {{-- The gate. --}}
        <div class="card shadow mb-4 {{ $request->disciplinaryChecked() ? '' : 'border-start border-4 border-warning' }}">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">Terminal disciplinary check</h6>
            </div>
            <div class="card-body">
                @if(! $request->disciplinaryChecked())
                    <p class="mb-3">
                        <strong>Nobody has checked yet.</strong> This is not the same as a
                        clean record, and nothing downstream may proceed on it &mdash; a
                        visiting endorsement is gated on this check having been done.
                    </p>
                @elseif($request->disciplinary_clean)
                    <p class="mb-3">
                        <span class="badge text-bg-success">Clean</span>
                        Checked by {{ $request->disciplinaryCheckedBy?->name ?? 'somebody' }},
                        {{ $request->disciplinary_checked_at?->toEuropeanDateTime() }}.
                    </p>
                @else
                    <div class="alert alert-danger">
                        <strong>Disciplinary history found.</strong>
                        <div class="mt-2" style="white-space: pre-wrap;">{{ $request->disciplinary_context }}</div>
                        <div class="fs-sm mt-2">
                            Recorded by {{ $request->disciplinaryCheckedBy?->name ?? 'somebody' }},
                            {{ $request->disciplinary_checked_at?->toEuropeanDateTime() }}.
                        </div>
                    </div>
                    <p class="fs-sm text-muted">
                        A finding within the last twelve months is one of the three grounds
                        TVCP 5.4 allows for refusing. It is not automatic &mdash; the date
                        matters, and so does what it was.
                    </p>
                @endif

                @can('membership.terminal.log')
                    <form method="POST" action="{{ route('vatssa.membership.check', $request) }}"
                          x-data="{ clean: '1' }">
                        @csrf
                        <div class="mb-2">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="clean" value="1"
                                       id="clean-yes" x-model="clean" checked>
                                <label class="form-check-label" for="clean-yes">Nothing found</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="clean" value="0"
                                       id="clean-no" x-model="clean">
                                <label class="form-check-label" for="clean-no">History found</label>
                            </div>
                        </div>

                        {{-- Required when there IS a finding. "We looked and there was
                             something" with no note is worse than not having looked:
                             the next person can neither act on it nor tell whether
                             anybody did. --}}
                        <div class="mb-2" x-show="clean === '0'" x-cloak>
                            <label class="form-label" for="check-context">What was found</label>
                            <textarea class="form-control" id="check-context" name="context"
                                      rows="3" maxlength="2000"></textarea>
                            @error('context')
                                <span class="text-danger">{{ $errors->first('context') }}</span>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-sm btn-primary">
                            {{ $request->disciplinaryChecked() ? 'Record a new check' : 'Record the check' }}
                        </button>
                    </form>
                @endcan
            </div>
        </div>

        {{-- The member, and where they stand. --}}
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">{{ $request->user->name }} ({{ $request->user_id }})</h6>
                <a href="{{ route('user.show', $request->user_id) }}" class="btn btn-icon btn-light btn-sm">
                    <i class="fas fa-id-card"></i> Profile
                </a>
            </div>
            <div class="card-body">
                <dl class="row mb-3">
                    <dt class="col-sm-4">Rating</dt>
                    <dd class="col-sm-8">{{ $request->user->rating->name ?? '—' }}</dd>
                    <dt class="col-sm-4">Region</dt>
                    <dd class="col-sm-8">{{ $request->user->region ?? '—' }}</dd>
                    <dt class="col-sm-4">Division</dt>
                    <dd class="col-sm-8">{{ $request->user->division ?? '—' }}</dd>
                </dl>

                @if($request->note)
                    <div class="mb-3">
                        <strong>What they wrote</strong>
                        <div class="border rounded p-2 mt-1" style="white-space: pre-wrap;">{{ $request->note }}</div>
                    </div>
                @endif

                {{-- Live requirements, and the snapshot beside them.

                     Both, because they answer different questions: the snapshot
                     says what was true WHEN THEY ASKED, and the list says what is
                     true now. A decision six weeks later needs to be able to tell
                     those apart. --}}
                <strong>Where they stand now</strong>
                <div class="mt-2">
                    @include('vatssa.parts.requirements', ['requirements' => $requirements])
                </div>

                @if($request->checks)
                    <details class="mt-3">
                        <summary class="fs-sm text-muted">What was true when they asked</summary>
                        <ul class="list-unstyled fs-sm mt-2 mb-0">
                            @foreach($request->checks as $label => $met)
                                <li>
                                    <i class="fas {{ $met ? 'fa-check text-success' : 'fa-times text-warning' }}"></i>
                                    {{ $label }}
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        </div>
    </div>

    <div class="col-xl-4 col-lg-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">This request</h6>
            </div>
            <div class="card-body">
                <dl class="mb-3">
                    <dt class="fs-sm text-uppercase text-gray-600">Type</dt>
                    <dd><i class="fas {{ $request->type->icon() }}"></i>&nbsp;{{ $request->type->label() }}</dd>

                    <dt class="fs-sm text-uppercase text-gray-600">State</dt>
                    <dd>
                        <span class="badge text-bg-{{ $request->state->color() }}">{{ $request->state->label() }}</span>
                    </dd>

                    <dt class="fs-sm text-uppercase text-gray-600">Raised</dt>
                    <dd class="mb-0">
                        {{ $request->created_at->toEuropeanDateTime() }}
                        @if($request->createdBy && $request->created_by !== $request->user_id)
                            <span class="d-block fs-sm text-muted">by {{ $request->createdBy->name }}</span>
                        @endif
                    </dd>
                </dl>

                @can('membership.requests.manage')
                    <hr>
                    <form method="POST" action="{{ route('vatssa.membership.transition', $request) }}">
                        @csrf
                        <label class="form-label" for="state">Move to</label>
                        <select class="form-select form-select-sm mb-2" id="state" name="state" required>
                            {{-- The destinations come from the TYPE, so a rating
                                 upgrade cannot be pushed into a transfer state and a
                                 transfer cannot be dropped into the Terminal set. --}}
                            @foreach($request->type->states() as $state)
                                <option value="{{ $state->value }}" @selected($state === $request->state)>
                                    {{ $state->label() }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary">Move</button>
                    </form>
                @endcan

                @if($request->type === \App\Helpers\Vatssa\MembershipRequestType::VISITING)
                    <hr>
                    <div class="fs-sm">
                        <strong>Visiting endorsement</strong>
                        @if($request->mayAssignVisitingEndorsement())
                            <div class="text-success mt-1">
                                <i class="fas fa-check"></i>
                                Cleared to assign, once the familiarisation completes.
                            </div>
                        @else
                            <div class="text-muted mt-1">
                                <i class="fas fa-lock"></i>
                                Blocked until the disciplinary check is done and clean.
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@endsection
