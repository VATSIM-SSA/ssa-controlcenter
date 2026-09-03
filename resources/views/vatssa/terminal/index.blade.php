@extends('layouts.app')

@section('title', 'Terminal management')

@section('content')

@php
    use App\Helpers\Vatssa\TerminalLogReason;
    use App\Helpers\Vatssa\TerminalLogType;
@endphp

{{--
    VATSSA: everything anybody has done on VATSIM Terminal.

    Read far more than it is written, so the list comes first and the form is
    behind a button. The questions it exists to answer are "who looked at this
    person, and why" and "what changed, and who changed it" -- which is why the
    filters are type, reason and CID rather than a search box.
--}}

<div class="row">
    <div class="col-xl-4 col-lg-5">
        {{-- The catalogue, beside the log rather than on another page.

             Its output gets pasted into Terminal, so it is needed at exactly
             the moment somebody is logging what they did. A catalogue you have
             to navigate to is one people stop using by the third entry. --}}
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">Comment catalogue</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    @forelse($comments as $comment)
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <code class="small">{{ $comment->code }}</code>
                                    <div class="fw-bold fs-sm">{{ $comment->label }}</div>
                                </div>
                                {{-- The copy button IS the feature. --}}
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        title="Copy to clipboard"
                                        data-vatssa-copy="{{ $comment->compose() }}">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                            <div class="fs-sm text-muted mt-1" style="white-space: pre-wrap;">{{ $comment->compose() }}</div>
                            @if($comment->placeholders())
                                <div class="fs-sm mt-1">
                                    @foreach($comment->placeholders() as $placeholder)
                                        <span class="badge text-bg-light border">{{ '{' . $placeholder . '}' }}</span>
                                    @endforeach
                                    <span class="text-muted">&mdash; fill these in after pasting</span>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="list-group-item text-muted">No comments configured.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-8 col-lg-7">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">Terminal log</h6>
                @can('membership.terminal.log')
                    <button type="button" class="btn btn-icon btn-light btn-sm"
                            data-bs-toggle="modal" data-bs-target="#terminal-log-modal">
                        <i class="fas fa-plus"></i> Log an action
                    </button>
                @endcan
            </div>

            <div class="card-body p-0">
                <div class="p-3 border-bottom">
                    <form method="GET" class="row g-2">
                        <div class="col-md-3">
                            <select class="form-select form-select-sm" name="type" onchange="this.form.submit()">
                                <option value="">All types</option>
                                @foreach(TerminalLogType::cases() as $type)
                                    <option value="{{ $type->value }}" @selected(request('type') === $type->value)>
                                        {{ $type->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select form-select-sm" name="reason" onchange="this.form.submit()">
                                <option value="">All reasons</option>
                                @foreach(TerminalLogReason::cases() as $reason)
                                    <option value="{{ $reason->value }}" @selected(request('reason') === $reason->value)>
                                        {{ $reason->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            {{-- A CID, not a name. This page is opened to answer a
                                 question about one person, and a name search returns
                                 the wrong Smith at exactly the wrong moment. --}}
                            <input type="number" class="form-control form-control-sm" name="cid"
                                   placeholder="CID…" value="{{ request('cid') }}">
                        </div>
                        <div class="col-auto">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-leftpadded mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>When</th>
                                <th>Type</th>
                                <th>Member</th>
                                <th>Why</th>
                                <th>What</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($entries as $entry)
                                <tr>
                                    <td class="text-nowrap">{{ $entry->performed_at->toEuropeanDate() }}</td>
                                    <td class="text-nowrap">
                                        <span class="badge text-bg-{{ $entry->type->color() }}">
                                            <i class="fas {{ $entry->type->icon() }}"></i>&nbsp;{{ $entry->type->label() }}
                                        </span>
                                    </td>
                                    <td class="text-nowrap">
                                        <a href="{{ route('user.show', $entry->user_id) }}">
                                            {{ $entry->user->name }} ({{ $entry->user_id }})
                                        </a>
                                    </td>
                                    <td>{{ $entry->reason->label() }}</td>
                                    <td>
                                        @if($entry->ratingFrom || $entry->ratingTo)
                                            {{ $entry->ratingFrom->name ?? '—' }}
                                            &rarr;
                                            {{ $entry->ratingTo->name ?? '—' }}
                                        @endif
                                        @if($entry->isDisciplinaryCheck())
                                            @if($entry->discipline_found)
                                                <span class="badge text-bg-danger">History found</span>
                                                <div class="fs-sm text-muted" style="white-space: pre-wrap;">{{ $entry->discipline_context }}</div>
                                            @else
                                                {{-- A clean check is a RESULT. "We looked and
                                                     there was nothing" is what you need six
                                                     months later. --}}
                                                <span class="badge text-bg-success">Checked, clean</span>
                                            @endif
                                        @endif
                                        @if($entry->comment_code)
                                            <code class="small d-block">{{ $entry->comment_code }}</code>
                                        @endif
                                        @if($entry->notes)
                                            <div class="fs-sm text-muted" style="white-space: pre-wrap;">{{ $entry->notes }}</div>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        {{ $entry->actorLabel() }}
                                        {{-- Who typed it, when that is somebody else. The two
                                             are different questions and the log has to be able
                                             to answer both. --}}
                                        @if($entry->recorded_by !== $entry->actor_user_id)
                                            <div class="fs-sm text-muted">
                                                recorded by {{ $entry->recordedBy->name ?? 'unknown' }}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Nothing logged yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($entries->hasPages())
                <div class="card-footer">{{ $entries->links() }}</div>
            @endif
        </div>
    </div>
</div>

@can('membership.terminal.log')
    <div class="modal fade" id="terminal-log-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="{{ route('vatssa.terminal.store') }}"
                      x-data="{ type: 'query', discipline: '' }">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Log a Terminal action</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="tl-type">What kind</label>
                                <select class="form-select" id="tl-type" name="type" x-model="type" required>
                                    @foreach(TerminalLogType::cases() as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="tl-reason">Why</label>
                                <select class="form-select" id="tl-reason" name="reason" required>
                                    @foreach(TerminalLogReason::cases() as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="tl-cid">About which member (CID)</label>
                                <input type="number" class="form-control" id="tl-cid" name="user_id" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="tl-when">When it happened</label>
                                <input type="datetime-local" class="form-control" id="tl-when" name="performed_at">
                                <div class="form-text">Leave blank for now.</div>
                            </div>

                            {{-- WHO DID IT. Two fields, because half these rows are
                                 entered afterwards about somebody else's action, and a
                                 dropdown of Control Center accounts cannot say "it was
                                 done by somebody who is not in Control Center". --}}
                            <div class="col-md-6">
                                <label class="form-label" for="tl-actor">Done by (CID)</label>
                                <input type="number" class="form-control" id="tl-actor" name="actor_user_id"
                                       value="{{ auth()->id() }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="tl-actor-name">…or a name</label>
                                <input type="text" class="form-control" id="tl-actor-name" name="actor_name"
                                       maxlength="100" placeholder="Somebody without a CC account">
                            </div>

                            <div class="col-md-6" x-show="type === 'change'" x-cloak>
                                <label class="form-label" for="tl-from">Rating from</label>
                                <select class="form-select" id="tl-from" name="rating_from_id">
                                    <option value="">—</option>
                                    @foreach($ratings as $rating)
                                        <option value="{{ $rating->id }}">{{ $rating->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6" x-show="type === 'change'" x-cloak>
                                <label class="form-label" for="tl-to">Rating to</label>
                                <select class="form-select" id="tl-to" name="rating_to_id">
                                    <option value="">—</option>
                                    @foreach($ratings as $rating)
                                        <option value="{{ $rating->id }}">{{ $rating->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="tl-comment">Comment used</label>
                                <select class="form-select" id="tl-comment" name="comment_code">
                                    <option value="">—</option>
                                    @foreach($comments as $comment)
                                        <option value="{{ $comment->code }}">{{ $comment->code }} — {{ $comment->label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="tl-disc">Disciplinary check</label>
                                <select class="form-select" id="tl-disc" name="discipline_found" x-model="discipline">
                                    <option value="">Not a disciplinary check</option>
                                    <option value="0">Checked — nothing found</option>
                                    <option value="1">Checked — history found</option>
                                </select>
                            </div>

                            <div class="col-12" x-show="discipline === '1'" x-cloak>
                                <label class="form-label" for="tl-disc-context">What was found</label>
                                <textarea class="form-control" id="tl-disc-context" name="discipline_context"
                                          rows="2" maxlength="2000"></textarea>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="tl-notes">Notes</label>
                                <textarea class="form-control" id="tl-notes" name="notes" rows="2" maxlength="2000"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Log it</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan

@endsection

@section('js')
<script>
    // The copy button. Written here rather than pulled in, because it is six
    // lines and the alternative is a dependency for one interaction.
    document.querySelectorAll('[data-vatssa-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(button.dataset.vatssaCopy).then(function () {
                var icon = button.querySelector('i');
                icon.className = 'fas fa-check';
                setTimeout(function () { icon.className = 'fas fa-copy'; }, 1200);
            });
        });
    });
</script>
@endsection
