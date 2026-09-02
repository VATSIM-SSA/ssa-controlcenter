@extends('layouts.app')

@section('title', 'Mentor Admin')

@section('content')

{{--
    VATSSA: mentor capacity and the resource links.

    Both were going to live on a separate portal at mentors.vatssa.com, behind
    Cloudflare Access, with its own VATSIM login and its own copy of who mentors
    whom. They are a number and a list of links.
--}}

<form method="POST" action="{{ route('vatssa.admin.mentorship.update') }}">
    @csrf

    <div class="row">
        <div class="col-xl-12 col-lg-12 mb-12">
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3">
                    <h6 class="m-0 fw-bold text-white">
                        <i class="fas fa-users"></i>&nbsp;Mentor capacity
                    </h6>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Everything on this page is yours to set. A mentor asks
                        through the request desk and you decide -- there is no
                        self-service field anywhere, because one beside the
                        request would make the request pointless.
                    </p>
                    <p class="text-muted mb-3">
                        <strong>Up to</strong> is the ceiling: the highest rating
                        they may mentor at all. A rating above it does not appear
                        to them. <strong>Total</strong> caps students across
                        every rating; the per-rating numbers cap each one. Both
                        apply, and the smaller wins -- a total of 5 with an S2
                        limit of 4 means four S2s and one of something else.
                    </p>
                    <p class="text-muted mb-3">
                        Blank is no limit. <strong>0</strong> means they take
                        nobody for that rating. Those are different instructions.
                    </p>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Mentor</th>
                                    <th style="min-width: 9rem">Up to</th>
                                    <th style="min-width: 5rem">Total</th>
                                    @foreach($ratings as $rating)
                                        <th style="min-width: 4.5rem">{{ $rating->name }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($mentors as $mentor)
                                    @php $ceiling = $ceilings->get($mentor->id); @endphp
                                    <tr>
                                        <td>
                                            {{ $mentor->name }}
                                            <small class="text-muted d-block">
                                                {{ $mentor->id }} — running
                                                {{ \App\Models\Vatssa\MentorCapacity::loadFor($mentor) }}
                                            </small>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm"
                                                    name="max_rating[{{ $mentor->id }}]">
                                                <option value="">No ceiling</option>
                                                @foreach($ratings as $rating)
                                                    <option value="{{ $rating->id }}"
                                                        @selected($ceiling?->max_rating_id === $rating->id)>
                                                        {{ $rating->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" min="0" max="99"
                                                   class="form-control form-control-sm"
                                                   name="total[{{ $mentor->id }}]"
                                                   value="{{ $ceiling?->total_limit }}"
                                                   placeholder="—">
                                        </td>
                                        @foreach($ratings as $rating)
                                            @php
                                                $row = $capacity->where('user_id', $mentor->id)
                                                    ->where('rating_id', $rating->id)->first();
                                            @endphp
                                            <td>
                                                <input type="number" min="0" max="99"
                                                       class="form-control form-control-sm"
                                                       name="capacity[{{ $mentor->id }}][{{ $rating->id }}]"
                                                       value="{{ $row?->student_limit }}"
                                                       placeholder="—">
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ 3 + $ratings->count() }}" class="text-muted">
                                            Nobody holds the mentor role.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-12 col-lg-12 mb-12">
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3">
                    <h6 class="m-0 fw-bold text-white">
                        <i class="fas fa-folder-open"></i>&nbsp;Mentor resources
                    </h6>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        The syllabus, the sweatbox files, the exam template.
                        Editable here so a moved folder is a one-minute fix by
                        whoever moved it, rather than a pinned Discord message
                        nobody can find.
                    </p>

                    {{-- A fixed number of rows rather than add/remove controls.
                         Six links is the realistic ceiling, and a blank row
                         costs nothing where a JavaScript row-builder costs a
                         maintenance burden forever. --}}
                    @for($i = 0; $i < 8; $i++)
                        @php $resource = $resources->values()->get($i); @endphp
                        <div class="row g-1 mb-2">
                            <div class="col-sm-4">
                                <input type="text" class="form-control form-control-sm"
                                       name="resources[{{ $i }}][label]"
                                       value="{{ $resource?->label }}" placeholder="Label">
                            </div>
                            <div class="col-sm-5">
                                <input type="url" class="form-control form-control-sm"
                                       name="resources[{{ $i }}][url]"
                                       value="{{ $resource?->url }}" placeholder="https://">
                            </div>
                            <div class="col-sm-3">
                                <input type="text" class="form-control form-control-sm"
                                       name="resources[{{ $i }}][icon]"
                                       value="{{ $resource?->icon }}" placeholder="fa-link">
                            </div>
                        </div>
                    @endfor

                    <small class="text-muted">
                        A row with no label or no address is dropped. Icons are
                        Font Awesome names, such as <code>fa-book</code>.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-12 mb-4">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </div>
</form>

@endsection
