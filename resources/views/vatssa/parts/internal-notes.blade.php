{{--
    VATSSA: staff notes about a person or a training.

    For the things that have to be recorded and must not be visible to the
    person they are about -- disciplinary history, why somebody was removed or
    refused, a complaint, the context behind a decision.

    THE AUDIENCE IS STATED ABOVE THE BOX, EVERY TIME. Somebody writing something
    sensitive has to know who will read it before they type. A note written in
    the belief it was admin-only, readable by a training manager, is worse than
    no note at all.

    Renders nothing at all without the permission -- not an empty card, not a
    locked one. A panel whose existence hints at hidden notes is itself a leak.

    Expects: $scope ('user'|'training'), $notes, $action
--}}
@php
    $permission = \App\Models\Vatssa\InternalNote::permissionFor($scope);
    $meta = \App\Models\Vatssa\InternalNote::SCOPES[$scope];
@endphp

@can($permission)
    {{-- Quiet. An amber block the width of the page shouted louder than the
         training it was attached to, and a panel that shouts gets ignored. The
         lock icon and the audience line carry the meaning; the colour does not
         need to. --}}
    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 fw-bold text-white">
                <i class="fas fa-lock"></i>&nbsp;{{ $meta['label'] }}s
                {{-- The audience, in the title.

                     It was a full-width grey alert above every note, three
                     lines of prose repeating what the padlock already says.
                     On a page that also carries theory, platforms, an exam and
                     an email log, it read as clutter and got skipped -- which
                     is the one outcome this text exists to prevent.

                     Short here, in full on the form below, where somebody is
                     about to type and the sentence earns its length. --}}
                <small class="fw-normal opacity-75">&middot; {{ $meta['audience_short'] }}</small>
            </h6>
            @if($notes->count())
                <span class="badge bg-light text-dark">{{ $notes->count() }}</span>
            @endif
        </div>
        <div class="card-body">
            @forelse($notes as $note)
                <div class="border-start border-3 border-secondary ps-3 mb-3">
                    <div style="white-space: pre-wrap">{{ $note->body }}</div>
                    <small class="text-muted d-block mt-1">
                        {{ $note->author?->name ?? 'Unknown' }} ·
                        <span data-bs-toggle="tooltip" title="{{ $note->created_at->toEuropeanDateTime() }}">
                            {{ $note->created_at->diffForHumans() }}
                        </span>
                        @if($scope === 'user' && $note->training_id)
                            · from training #{{ $note->training_id }}
                        @endif
                        <form method="POST" action="{{ route('vatssa.notes.destroy', $note) }}" class="d-inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-link btn-sm p-0 text-danger align-baseline"
                                    onclick="return confirm('Delete this note? There is no undo.')">delete</button>
                        </form>
                    </small>
                </div>
            @empty
                <p class="text-muted mb-3">Nothing recorded.</p>
            @endforelse

            <form method="POST" action="{{ $action }}">
                @csrf
                <div class="mb-2">
                    <textarea class="form-control" name="body" rows="3" maxlength="5000" required
                              placeholder="What happened, and when. Write it as if it will be read back to you."></textarea>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-secondary">Add note</button>
            </form>
        </div>
    </div>
@endcan
