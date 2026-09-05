{{--
    VATSSA: every visiting and transferring request this member has filed.

    The requests that produce a change of standing, which is why this sits near
    the divisional history rather than among the training panels. A transfer
    request is the EVENT; "home member since 2 June" is its consequence.

    Only the two membership types. A rating upgrade is also a membership request
    in this system, but it says nothing about where somebody belongs and it
    already has a home in the training section -- listing it here would put the
    same fact on one page twice under two different headings.

    Expects: $membershipRequests
--}}
<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-white">Visiting &amp; transferring</h6>
        @can('membership.requests.view')
            <a href="{{ route('vatssa.membership.index') }}" class="btn btn-icon btn-light btn-sm">
                <i class="fas fa-list"></i> All requests
            </a>
        @endcan
    </div>

    <div class="card-body {{ $membershipRequests->isEmpty() ? '' : 'p-0' }}">
        @if($membershipRequests->isEmpty())
            <p class="mb-0 text-muted">No visiting or transferring requests.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-leftpadded mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Filed</th>
                            <th>Type</th>
                            <th>State</th>
                            <th>Closed</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($membershipRequests as $request)
                            <tr>
                                <td class="text-nowrap">{{ $request->created_at->toEuropeanDate() }}</td>
                                <td class="text-nowrap">
                                    <i class="fas {{ $request->type->icon() }}"></i>
                                    {{ $request->type->label() }}
                                </td>
                                <td>
                                    <span class="badge text-bg-{{ $request->state->color() }}">
                                        {{ $request->state->label() }}
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    {{ $request->closed_at?->toEuropeanDate() ?? '—' }}
                                </td>
                                <td class="text-end">
                                    @can('membership.requests.view')
                                        <a href="{{ route('vatssa.membership.show', $request) }}"
                                           class="btn btn-sm btn-outline-secondary">Open</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
