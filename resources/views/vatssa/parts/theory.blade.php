{{--
    VATSSA: theory exam results.

    Used in two places, and the difference between them is the whole reason the
    results are keyed to PERSON PLUS RATING rather than to a training:

    * On a **profile**, unfiltered — every rating this person has ever sat, and
      every attempt. That is the global exam record.
    * On a **training**, filtered to that training's rating. A student's S3
      history is not what you are looking at when you open their S2 training.

    A result owned by a training would die with it: close the training, open a
    new one, and the pass is gone even though the person still knows the
    material. Because it is looked up rather than owned, every S2 training that
    person ever opens shows the same rows.

    **Latest, not best.** The rows are newest first, and the top one for a
    rating is the one that counts -- somebody who passed two years ago and
    failed a retake last week has not currently passed. The current standing is
    stated explicitly rather than left to be inferred from the order.

    Two permission tiers, from `config/roles.php`:

    * `training.results.view`   -- every attempt, and whether each passed
    * `training.results.grades` -- the actual marks

    Expects: $user
    Optional: $onlyRatings    (array of rating names) to filter to
              $panelTitle     (defaults to "Theory")
              $needsNoTheory  true for a track that sits none at all

    Both optional names are deliberately unusual. @include inherits the parent
    view's whole scope, so a partial that reads a variable called $ratings or
    $title would silently pick up whatever the host page happened to have --
    and a theory panel quietly filtering itself is not a bug anyone would spot.
--}}
@php
    $ratingFilter = collect($onlyRatings ?? [])->map(fn ($r) => strtoupper($r))->filter()->values();

    $attempts = \App\Models\Vatssa\TheoryAttempt::where('user_id', $user->id)
        ->when($ratingFilter->isNotEmpty(), fn ($q) => $q->whereIn('rating', $ratingFilter))
        ->orderByDesc('taken_at')
        ->orderByDesc('id')
        ->get();

    $showGrades = Auth::user()->can('training.results.grades');

    // The current standing per rating: the FIRST row for each, because the
    // query is newest first. Stated rather than inferred -- a reader scanning a
    // list of green and red badges should not have to work out which one wins.
    $standing = $attempts->groupBy('rating')->map->first();
@endphp

@can('training.results.view')
    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 fw-bold text-white">
                <i class="fas fa-file-pen"></i>&nbsp;{{ $panelTitle ?? 'Theory' }}
            </h6>
            @foreach($standing as $rating => $latest)
                <span class="badge {{ $latest->passed ? 'bg-success' : 'bg-danger' }}"
                      data-bs-toggle="tooltip"
                      title="Latest {{ $rating }} attempt, {{ $latest->taken_at->toEuropeanDate() }}">
                    {{ $rating }} {{ $latest->passed ? 'passed' : 'not passed' }}
                </span>
            @endforeach
        </div>
        {{-- Enrolment first, because it is the precondition for everything
             below it. A student registered on Moodle but never enrolled in a
             course has no attempts and never will, and "no attempts recorded"
             on its own reads as "has not got round to it" rather than "is
             stuck". --}}
        @php $platform = \App\Models\Vatssa\UserPlatform::find($user->id); @endphp
        @if($platform && $platform->on_moodle)
            <div class="card-body pb-0">
                @if($platform->isEnrolled())
                    <span class="badge bg-success">{{ $platform->enrolmentLabel() }}</span>
                @elseif($platform->moodle_enrolment === 'suspended')
                    <span class="badge bg-secondary">{{ $platform->enrolmentLabel() }}</span>
                    <small class="text-muted d-block mt-1">
                        Past attempts are kept — the pipeline suspends rather than
                        unenrols, so a returning student keeps every result.
                    </small>
                @else
                    <span class="badge bg-warning text-dark">{{ $platform->enrolmentLabel() }}</span>
                    <small class="text-muted d-block mt-1">
                        Registered on Moodle but in no course. The system will
                        enrol them within the next 24 hours.
                    </small>
                @endif
            </div>
        @endif

        <div class="card-body {{ $attempts->isEmpty() ? '' : 'p-0' }}">
            @if($needsNoTheory ?? false)
                {{-- Not a gap. Refresh, transfer, fast-track and familiarisation
                     students already hold the rating, so there is no theory
                     course for them to sit -- saying "no attempts recorded"
                     would read as missing data and send somebody looking. --}}
                <p class="mb-0 text-muted">
                    This track sits no theory. The student already holds the
                    rating.
                </p>
            @elseif($attempts->isEmpty())
                <p class="mb-0 text-muted">
                    @if($ratingFilter->isNotEmpty())
                        No {{ $ratingFilter->implode(', ') }} theory attempts recorded.
                    @else
                        No theory attempts recorded.
                    @endif
                </p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-leftpadded mb-0">
                        <thead class="table-light">
                            <tr>
                                @if($ratingFilter->count() !== 1)
                                    <th>Rating</th>
                                @endif
                                <th>Sat</th>
                                <th>Result</th>
                                @if($showGrades)
                                    <th>Mark</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($attempts as $attempt)
                                <tr>
                                    @if($ratingFilter->count() !== 1)
                                        <td>{{ $attempt->rating }}</td>
                                    @endif
                                    <td>
                                        <span data-bs-toggle="tooltip" title="{{ $attempt->taken_at->toEuropeanDate() }}">
                                            {{ $attempt->taken_at->diffForHumans() }}
                                        </span>
                                        @if($standing->get($attempt->rating)?->is($attempt))
                                            <span class="badge bg-light text-dark">latest</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($attempt->passed)
                                            <span class="badge bg-success">Passed</span>
                                        @else
                                            <span class="badge bg-danger">Failed</span>
                                        @endif
                                    </td>
                                    @if($showGrades)
                                        <td>{{ $attempt->grade === null ? '—' : rtrim(rtrim(number_format($attempt->grade, 1), '0'), '.') . '%' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endcan
