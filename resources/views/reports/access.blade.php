@extends('layouts.app')

@section('title', 'Access Report')

@section('header')
    @vite(['resources/sass/bootstrap-table.scss', 'resources/js/bootstrap-table.js'])
@endsection

@section('content')

{{--
    VATSSA: one Roles column, plus desks.

    Upstream printed one "Access <area>" column per area and repeated every
    global role in all of them, because a null area_id matches every area.
    VATSSA grants every role globally, so the table was one list of roles copied
    sideways as many times as the division has areas -- wider on every screen,
    and saying nothing on the right that it had not already said on the left.

    Desks are here now too. "What access does this person have" is not answered
    by roles alone: a role grants permissions, a desk decides who receives the
    work, and the second half used to live on a different page. Somebody sitting
    on the membership desk is part of the answer to that question.
--}}

<div class="row">

    <div class="col-xl-12 col-md-12 mb-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">User's access</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-sm table-hover table-leftpadded mb-0" width="100%" cellspacing="0"
                        data-page-size="100"
                        data-toggle="table"
                        data-pagination="true"
                        data-filter-control="true"
                        data-sort-reset="true">
                        <thead class="table-light">
                            <tr>
                                <th data-field="id" data-sortable="true" data-filter-control="input" data-visible-search="true">Vatsim ID</th>
                                <th data-field="name" data-sortable="true" data-filter-control="input">Name</th>
                                <th data-field="roles" data-sortable="true" data-filter-control="input">Roles</th>
                                <th data-field="desks" data-sortable="true" data-filter-control="input">Desks</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($users as $user)
                                @php
                                    // Catalogue order, not assignment order. The roles box and
                                    // the grant picker both read config/roles.php in this order,
                                    // and three pages disagreeing about seniority is how somebody
                                    // reads the wrong one as the senior.
                                    $roles = $user->roleAssignments
                                        ->pluck('role')
                                        ->unique()
                                        ->sortBy(fn ($role) => array_search($role, $roleOrder, true))
                                        ->values();
                                @endphp
                                <tr>
                                    <td><a href="{{ route('user.show', $user->id) }}">{{ $user->id }}</a></td>
                                    <td><a href="{{ route('user.show', $user->id) }}">{{ $user->name }}</a></td>
                                    <td>
                                        @foreach($roles as $role)
                                            {{-- The catalogue's own name.
                                                 ucfirst('atc-training-manager') printed
                                                 "Atc-training-manager". --}}
                                            <span class="badge bg-secondary">{{ $roleNames[$role] ?? $role }}</span>
                                        @endforeach

                                        {{-- Examiner, read off the endorsement rather
                                             than the role table, and outlined so the
                                             difference stays visible in a column where
                                             everything else was granted by hand. --}}
                                        @if($user->isExaminer())
                                            <span class="badge bg-transparent border border-secondary text-body"
                                                  title="From an active examiner endorsement, not a role">Examiner</span>
                                        @endif
                                    </td>
                                    <td>
                                        @forelse($desks[$user->id] as $desk)
                                            <span class="badge bg-light text-dark border">{{ $desk }}</span>
                                        @empty
                                            <span class="text-muted">&mdash;</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach

                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
