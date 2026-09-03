@extends('layouts.app')

@php
    use App\Helpers\Vatssa\MembershipRequestType;

    $queueTitles = [
        'open' => 'Open requests',
        'training' => 'Pending training',
        'closed' => 'Closed requests',
    ];
@endphp

@section('title', $queueTitles[$queue])

@section('content')

{{--
    VATSSA: the membership desk, one queue at a time.

    The three queues live in the sidebar dropdown, not in tabs on this page.
    Putting them in both meant the same navigation in two places, and two menus
    for one thing eventually disagree about which is current -- with the page's
    copy being the one that cannot show you where else you might go.

    So this page says which queue it is and how many are in it, and the sidebar
    says where else to go.
--}}

<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-white">
            {{ $queueTitles[$queue] }}
            <span class="badge text-bg-light text-dark">{{ $counts[$queue] }}</span>
        </h6>
        @can('membership.requests.manage')
            <button type="button" class="btn btn-icon btn-light btn-sm"
                    data-bs-toggle="modal" data-bs-target="#membership-create-modal">
                <i class="fas fa-plus"></i> Add by hand
            </button>
        @endcan
    </div>

    <div class="card-body p-0">
        {{-- No tabs here.

             The three queues used to be tabs across the top of this card, which
             put the same navigation in two places: the sidebar dropdown and the
             page. Two menus for one thing eventually disagree about which is
             current, and the page's copy is the one that cannot show you where
             else you might go.

             The sidebar owns navigation. This card shows one queue, says which,
             and says how many are in the other two without offering to take you
             there. --}}
        <div class="p-3 border-bottom">
            <form method="GET" class="row g-2">
                <input type="hidden" name="queue" value="{{ $queue }}">
                <div class="col-md-3">
                    <select class="form-select form-select-sm" name="type" onchange="this.form.submit()">
                        <option value="">All types</option>
                        @foreach(MembershipRequestType::cases() as $type)
                            <option value="{{ $type->value }}" @selected(request('type') === $type->value)>
                                {{ $type->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-hover table-leftpadded mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Member</th>
                        <th>Type</th>
                        <th>Asked</th>
                        <th>Disciplinary</th>
                        <th>State</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($requests as $request)
                        <tr role="button" onclick="window.location='{{ route('vatssa.membership.show', $request) }}'">
                            <td>
                                <a href="{{ route('vatssa.membership.show', $request) }}">
                                    {{ $request->user->name }} ({{ $request->user_id }})
                                </a>
                            </td>
                            <td class="text-nowrap">
                                <i class="fas {{ $request->type->icon() }}"></i>&nbsp;{{ $request->type->label() }}
                            </td>
                            <td class="text-nowrap">{{ $request->created_at->toEuropeanDate() }}</td>
                            <td class="text-nowrap">
                                {{-- Three outcomes, not two. "Not checked" is not
                                     "clean", and the visiting endorsement is gated
                                     on the difference. --}}
                                @if(! $request->disciplinaryChecked())
                                    <span class="badge text-bg-secondary">Not checked</span>
                                @elseif($request->disciplinary_clean)
                                    <span class="badge text-bg-success">Clean</span>
                                @else
                                    <span class="badge text-bg-danger">Finding</span>
                                @endif
                            </td>
                            <td class="text-nowrap">
                                <span class="badge text-bg-{{ $request->state->color() }}">
                                    {{ $request->state->label() }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Nothing in this queue.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($requests->hasPages())
        <div class="card-footer">{{ $requests->links() }}</div>
    @endif
</div>

@can('membership.requests.manage')
    <div class="modal fade" id="membership-create-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('vatssa.membership.admin.store') }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Add a request by hand</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted fs-sm">
                            For anything that arrived by email or was done on Terminal
                            directly. All five types, because the three the member never
                            files can only ever be entered here.
                        </p>
                        <div class="mb-3">
                            <label class="form-label" for="mr-cid">Member CID</label>
                            <input type="number" class="form-control" id="mr-cid" name="user_id" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="mr-type">Type</label>
                            <select class="form-select" id="mr-type" name="type" required>
                                @foreach(MembershipRequestType::cases() as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="mr-note">Note</label>
                            <textarea class="form-control" id="mr-note" name="note" rows="3" maxlength="2000"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan

@endsection
