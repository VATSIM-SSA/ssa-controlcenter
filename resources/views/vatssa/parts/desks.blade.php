{{--
    VATSSA: which request desks this person sits at.

    Distinct from their ROLES, and the difference is the point. A role grants
    permissions; a desk decides who receives work. An ATC training manager holds
    every coordinator permission and is nobody's default coordinator.

    Admin only. Deliberately not visible to the ATC training manager: who
    receives which requests is a leadership arrangement, and seeing it on every
    profile invites quiet reassignment. It is set in one place --
    Administration -> Request routing -- so there is one list to read.

    Expects: $user
--}}
@can('system.settings.manage')
    @php
        $rows = \App\Models\Vatssa\RequestTarget::with('rating')
            ->where('user_id', $user->id)->get();
    @endphp

    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 fw-bold text-white">
                <i class="fas fa-inbox"></i>&nbsp;Request desks
            </h6>
            <a href="{{ route('vatssa.admin.routing') }}" class="btn btn-icon btn-light btn-sm">
                <i class="fas fa-pen"></i> Edit
            </a>
        </div>
        <div class="card-body">
            @forelse($rows as $row)
                <span class="badge bg-secondary mb-1">
                    {{ \App\Models\Vatssa\RequestTarget::label($row->tier) }}@if($row->rating)
                        — {{ $row->rating->name }}
                    @elseif(\App\Models\Vatssa\RequestTarget::isPerRating($row->tier))
                        — all ratings
                    @endif
                </span>
            @empty
                <p class="mb-0 text-muted">
                    On no request desk. They receive nothing automatically.
                </p>
            @endforelse
        </div>
    </div>
@endcan
