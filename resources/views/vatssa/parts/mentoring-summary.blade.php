{{--
    VATSSA: what this person mentors, on their profile.

    ## What this replaced

    "Division Exams", which came from `DivisionApi::getUserExams()` — VATEUD's
    theory record, fetched over HTTP on every profile view. Two problems with
    it. It answered the same question as the Moodle theory panel two rows down,
    from a worse source; and a VATSSA CPT never reaches it, because our
    practicals are ours, so a member with a full VATSSA history read as somebody
    who had never sat anything.

    Removing it also takes an external API call out of every profile load.

    ## Why mentoring goes here instead

    The rest of this page answers "who is this person to us" — their trainings,
    their theory, their endorsements. That somebody is a mentor, what they may
    teach and how loaded they are, is the same kind of fact and had no place on
    the page at all: it lived only on the mentor's own dashboard, which nobody
    else opens.

    READ-ONLY, like `mentor-panels`. Capacity changes go through the request
    desk and the ATC training manager decides. A number you can edit beside a
    request you have to make would make the request pointless.

    Renders NOTHING for somebody who is not a mentor, so the column collapses
    and Trainings takes the full width rather than sitting beside a hole.

    Expects: $user
--}}
@if($user->hasRole('mentor'))
    @php
        $ceiling = \App\Models\Vatssa\MentorCeiling::find($user->id);
        $teachable = \App\Models\Vatssa\MentorCeiling::ratingsFor($user->id);
        $load = \App\Models\Vatssa\MentorCapacity::loadFor($user);
    @endphp

    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 fw-bold text-white">Mentoring</h6>
            @if($ceiling?->total_limit)
                {{-- Load against the ceiling, not load alone. "4" means nothing
                     without the number it is being measured against. --}}
                <span class="badge {{ $load >= $ceiling->total_limit ? 'bg-warning text-dark' : 'bg-light text-dark' }}">
                    {{ $load }} / {{ $ceiling->total_limit }}
                </span>
            @else
                <span class="badge bg-light text-dark">{{ $load }}</span>
            @endif
        </div>

        <div class="card-body">
            <dl class="mb-0">
                <dt class="fs-sm fw-bold text-uppercase text-gray-600">Current students</dt>
                <dd>
                    {{ $load }}
                    @if($ceiling?->total_limit)
                        <span class="text-muted">of {{ $ceiling->total_limit }}</span>
                    @else
                        <span class="text-muted">— no ceiling set</span>
                    @endif
                </dd>

                <dt class="fs-sm fw-bold text-uppercase text-gray-600">May teach</dt>
                <dd class="mb-0">
                    @forelse($teachable as $rating)
                        <span class="badge bg-secondary">{{ $rating->name }}</span>
                    @empty
                        {{-- Not the same as "none". Nobody has set a maximum
                             rating for this mentor, which is a thing for the
                             training manager to notice rather than a fact about
                             the mentor. --}}
                        <span class="text-muted">Not set</span>
                    @endforelse
                </dd>
            </dl>
        </div>
    </div>
@endif
