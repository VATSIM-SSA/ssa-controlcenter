@extends('layouts.app')

@section('title', 'Mentorship')

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
        <div class="col-xl-6 col-lg-12 mb-12">
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3">
                    <h6 class="m-0 fw-bold text-white">Mentor capacity</h6>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        How many students each mentor is willing to run.
                        <strong>Nothing enforces this.</strong> It is so a
                        coordinator can see who is full before asking, and so a
                        mentor can say they have room without it being a Discord
                        message that scrolls away.
                    </p>
                    <p class="text-muted">
                        {{-- A ternary rather than a conditional directive.
                             Blade will not compile a directive glued to a
                             preceding word character, so writing the opening
                             one straight after the word "default" left it as
                             literal text while its closing half still compiled:
                             an orphan else, and a parse error on the whole page.
                             No directive tokens in this comment either, for the
                             same reason. --}}
                        Blank means no opinion, and falls back to the division
                        default{{ $default !== null ? ' of ' . $default : ', which is not set' }}.
                        That is not the same as <strong>0</strong>, which means
                        they take nobody.
                    </p>

                    @forelse($mentors as $mentor)
                        @php
                            $row = $capacity->where('user_id', $mentor->id)->whereNull('rating_id')->first();
                            $load = \App\Models\Vatssa\MentorCapacity::loadFor($mentor);
                        @endphp
                        <div class="row align-items-center mb-2">
                            <label class="col-sm-7 col-form-label" for="cap-{{ $mentor->id }}">
                                {{ $mentor->name }}
                                <small class="text-muted d-block">
                                    {{ $mentor->id }} — running {{ $load }}
                                </small>
                            </label>
                            <div class="col-sm-5">
                                <input type="number" min="0" max="99"
                                       class="form-control form-control-sm"
                                       id="cap-{{ $mentor->id }}"
                                       name="capacity[{{ $mentor->id }}]"
                                       value="{{ $row?->student_limit }}"
                                       placeholder="default">
                            </div>
                        </div>
                    @empty
                        <p class="mb-0 text-muted">Nobody holds the mentor role.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-xl-6 col-lg-12 mb-12">
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3">
                    <h6 class="m-0 fw-bold text-white">Mentor resources</h6>
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
