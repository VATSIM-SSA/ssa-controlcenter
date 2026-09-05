{{--
    VATSSA: every training note about this member, in one place.

    Read-only, and gathered rather than written here. A training note belongs to
    a training and is written on it; this panel exists because the question
    "what have we recorded about this person" was previously only answerable by
    opening each of their trainings in turn and remembering what was in the
    last one.

    ## The permission is the training note's own

    `training.notes.view` -- the ATC training manager and admins, exactly who
    could already read these notes on the training pages. Collecting notes in a
    new place must not widen who can read them: the whole value of an internal
    note is that the person writing it knows the audience before they type.

    That is also why this is NOT merged with the member notes above it. Member
    notes are admins only. One combined list would either leak admin notes to
    the training manager or hide training notes from them, and both are worse
    than two lists.

    Expects: $trainingNotes
--}}
@can(\App\Models\Vatssa\InternalNote::permissionFor(\App\Models\Vatssa\InternalNote::SCOPE_TRAINING))
    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 fw-bold text-white">
                <i class="fas fa-lock"></i>&nbsp;Training notes, collected
            </h6>
            <span class="badge text-bg-light">
                {{ \App\Models\Vatssa\InternalNote::audienceFor(\App\Models\Vatssa\InternalNote::SCOPE_TRAINING) }}
            </span>
        </div>

        <div class="card-body">
            <p class="fs-sm text-muted">
                Every note written on this member's trainings. Notes are added on
                the training itself, not here.
            </p>

            @forelse($trainingNotes as $note)
                <div class="mb-3 pb-3 {{ ! $loop->last ? 'border-bottom' : '' }}">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        {{-- Which training, as a link.

                             A note without its training is a sentence with no
                             subject -- "struggled with the phraseology" means
                             something different on an S1 than on a C1. --}}
                        <span class="fs-sm">
                            @if($note->training)
                                <a href="{{ route('training.show', $note->training) }}">
                                    Training #{{ $note->training->id }}
                                </a>
                                @if($note->training->ratings->isNotEmpty())
                                    <span class="text-muted">
                                        &middot; {{ $note->training->ratings->pluck('name')->join(', ') }}
                                    </span>
                                @endif
                            @else
                                <span class="text-muted">Training removed</span>
                            @endif
                        </span>
                        <span class="fs-sm text-muted text-nowrap">
                            {{ $note->created_at->toEuropeanDate() }}
                        </span>
                    </div>

                    <div class="mt-1" style="white-space: pre-wrap;">{{ $note->body }}</div>

                    <div class="fs-sm text-muted mt-1">
                        by {{ $note->author?->name ?? 'Unknown' }}
                    </div>
                </div>
            @empty
                <p class="text-muted mb-0">No training notes.</p>
            @endforelse
        </div>
    </div>
@endcan
