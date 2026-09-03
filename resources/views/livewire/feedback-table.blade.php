<div x-data="{ current: { submitter: '', submitted: '', feedback: '', controller: '', position: '', controllerLabel: '', positionLabel: '', updateUrl: '', actionUrl: '', status: '', sentiment: '', staffNote: '' } }">

    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3">
            <h6 class="m-0 fw-bold text-white">Feedback</h6>
        </div>
        <div class="card-body p-0">
            <div class="p-3 border-bottom">
                <div class="row g-2">
                    <div class="col-md-2">
                        {{-- First, and defaulted to Open. The queue somebody
                             opens this page to work is the open one; everything
                             else is history they go looking for. --}}
                        <select class="form-select form-select-sm" wire:model.live="status">
                            @foreach($statuses as $s)
                                <option value="{{ $s->value }}">{{ $s->label() }}</option>
                            @endforeach
                            <option value="">All statuses</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <livewire:combobox
                            wire:model.live="controller"
                            :provider="\App\Support\Comboboxes\FeedbackControllerOptions::class"
                            :min-chars="2"
                            placeholder="Controller…"
                            key="combo-controller" />
                    </div>
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" wire:model.live="area">
                            <option value="">All areas</option>
                            @foreach($areas as $a)
                                <option value="{{ $a->id }}">{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <livewire:combobox
                            wire:model.live="position"
                            :provider="\App\Support\Comboboxes\FeedbackPositionOptions::class"
                            :context="['area' => $area]"
                            :min-chars="1"
                            placeholder="Position…"
                            key="combo-position" />
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control form-control-sm"
                            placeholder="Submitter…"
                            wire:model.live.debounce.300ms="submitter">
                    </div>
                    <div class="col">
                        <input type="text" class="form-control form-control-sm"
                            placeholder="Search feedback text…"
                            wire:model.live.debounce.300ms="search">
                    </div>
                    @if($this->hasActiveFilters())
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                wire:click="clearFilters" title="Clear filters">
                                <i class="fas fa-xmark"></i> Clear
                            </button>
                        </div>
                    @endif
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-leftpadded mb-0" width="100%" cellspacing="0">
                    <thead class="table-light">
                        <tr>
                            <th role="button" wire:click="sortByReceived">
                                Received
                                <span aria-hidden="true">@if($sortDirection === 'desc') ↓ @else ↑ @endif</span>
                                <span class="visually-hidden">
                                    @if($sortDirection === 'desc') Sorted newest first @else Sorted oldest first @endif
                                </span>
                            </th>
                            <th>Submitter</th>
                            <th>Controller</th>
                            <th>Position</th>
                            <th>Area</th>
                            <th>Feedback</th>
                            <th>Status</th>
                            @canany(['update', 'action'], \App\Models\Feedback::class)
                                <th>Actions</th>
                            @endcanany
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($feedbacks as $f)
                            <tr wire:key="feedback-{{ $f->id }}">
                                <td>{{ $f->created_at->toEuropeanDateTime() }}</td>
                                <td><a href="{{ route('user.show', $f->submitter->id) }}">{{ $f->submitter->name }} ({{ $f->submitter_user_id }})</a></td>
                                <td>
                                    @isset($f->referenceUser)
                                        <a href="{{ route('user.show', $f->referenceUser) }}">{{ $f->referenceUser->name }} ({{ $f->referenceUser->id }})</a>
                                    @else
                                        N/A
                                    @endisset
                                </td>
                                <td>{{ $f->referencePosition?->callsign ?? 'N/A' }}</td>
                                <td>{{ $f->referencePosition?->area?->name ?? 'N/A' }}</td>
                                <td>{!! nl2br(e($f->feedback)) !!}</td>
                                <td class="text-nowrap">
                                    <span class="badge text-bg-{{ $f->status->color() }}">
                                        <i class="fas {{ $f->status->icon() }}"></i>&nbsp;{{ $f->status->label() }}
                                    </span>
                                    @if($f->sentiment)
                                        <span class="badge text-bg-{{ $f->sentiment->color() }}">
                                            <i class="fas {{ $f->sentiment->icon() }}"></i>&nbsp;{{ $f->sentiment->label() }}
                                        </span>
                                    @endif
                                    @if($f->staff_note)
                                        {{-- The note is staff-only context and is shown on hover
                                             rather than in the row: it is read when somebody is
                                             deciding what to do, not while scanning a queue. --}}
                                        <i class="fas fa-note-sticky text-muted" data-bs-toggle="tooltip"
                                           title="{{ $f->staff_note }}"></i>
                                    @endif
                                    @if($f->actionedBy)
                                        <div class="small text-muted">
                                            {{ $f->actionedBy->name }}, {{ $f->actioned_at?->toEuropeanDate() }}
                                        </div>
                                    @endif
                                </td>
                                {{-- One cell, two abilities. `update` re-points a
                                     submission at the right controller; `action`
                                     decides what happens to it. A division may
                                     reasonably grant one and not the other, so
                                     each button carries its own gate and the cell
                                     itself only needs either. --}}
                                @canany(['update', 'action'], $f)
                                    <td>
                                        @can('update', $f)
                                        <button type="button"
                                            class="btn btn-sm btn-primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#feedback-edit-modal"
                                            @click="current = @js([
                                                'submitter' => $f->submitter->name.' ('.$f->submitter_user_id.')',
                                                'submitted' => $f->created_at->toEuropeanDateTime(),
                                                'feedback' => $f->feedback,
                                                'controller' => $f->referenceUser?->id ?? '',
                                                'position' => $f->referencePosition?->callsign ?? '',
                                                'controllerLabel' => $f->referenceUser ? $f->referenceUser->name.' ('.$f->referenceUser->id.')' : 'N/A',
                                                'positionLabel' => $f->referencePosition?->callsign ?? 'N/A',
                                                'updateUrl' => route('feedback.update', $f->id),
                                            ])">
                                            Edit
                                        </button>
                                        @endcan
                                        @can('action', $f)
                                            <button type="button"
                                                class="btn btn-sm btn-outline-secondary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#feedback-action-modal"
                                                @click="current = @js([
                                                    'submitter' => $f->submitter->name.' ('.$f->submitter_user_id.')',
                                                    'submitted' => $f->created_at->toEuropeanDateTime(),
                                                    'feedback' => $f->feedback,
                                                    'controllerLabel' => $f->referenceUser ? $f->referenceUser->name.' ('.$f->referenceUser->id.')' : 'N/A',
                                                    'positionLabel' => $f->referencePosition?->callsign ?? 'N/A',
                                                    'status' => $f->status->value,
                                                    'sentiment' => $f->sentiment?->value ?? '',
                                                    'staffNote' => $f->staff_note ?? '',
                                                    'actionUrl' => route('feedback.action', $f->id),
                                                ])">
                                                Action
                                            </button>
                                        @endcan
                                    </td>
                                @endcanany
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ auth()->user()->canAny(['update', 'action'], \App\Models\Feedback::class) ? 8 : 7 }}" class="text-center text-muted py-4">No feedback found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <label class="me-2 mb-0 small">Per page</label>
                <select class="form-select form-select-sm w-auto" wire:model.live="perPage">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            @if($feedbacks->hasPages())
                <div class="flex-grow-1 ms-3">{{ $feedbacks->links() }}</div>
            @endif
        </div>
    </div>

    @can('action', \App\Models\Feedback::class)
        {{-- Action Feedback.

             One dialog for the whole staff decision, because it IS one
             decision: what this was, any context worth keeping, and whether the
             controller sees it. Splitting it into three controls on the row
             would let somebody set a sentiment and never choose an outcome,
             which is a piece of feedback that looks handled and is not.

             The feedback text is shown READ-ONLY. Staff need it in front of
             them to decide, and a submission is a record of what somebody said:
             a division that can edit it into something more palatable does not
             have feedback, it has a newsletter. --}}
        <div wire:ignore class="modal fade" id="feedback-action-modal" tabindex="-1"
            aria-labelledby="feedback-action-modal-label" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="feedback-action-modal-label">Action Feedback</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form :action="current.actionUrl" method="POST">
                        @csrf
                        @method('PATCH')
                        <div class="modal-body">
                            <dl class="row mb-3">
                                <dt class="col-sm-3">Submitted</dt>
                                <dd class="col-sm-9" x-text="current.submitted"></dd>
                                <dt class="col-sm-3">Controller</dt>
                                <dd class="col-sm-9" x-text="current.controllerLabel"></dd>
                                <dt class="col-sm-3">Position</dt>
                                <dd class="col-sm-9" x-text="current.positionLabel"></dd>
                            </dl>

                            <div class="mb-3">
                                <label class="form-label">Feedback</label>
                                <div class="border rounded p-2 bg-body-tertiary" style="white-space: pre-wrap;"
                                     x-text="current.feedback"></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="feedback-sentiment">How does this read?</label>
                                <select class="form-select" id="feedback-sentiment" name="sentiment"
                                        x-model="current.sentiment">
                                    <option value="">Not categorised</option>
                                    @foreach(\App\Helpers\FeedbackSentiment::cases() as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">
                                    Staff's reading of the feedback, kept separate from what you do
                                    with it &mdash; negative feedback is often worth forwarding, and
                                    positive feedback is sometimes not.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="feedback-staff-note">Internal note</label>
                                <textarea class="form-control" id="feedback-staff-note" name="staff_note"
                                          rows="3" maxlength="2000" x-model="current.staffNote"
                                          placeholder="What was done, or why nothing needed to be."></textarea>
                                <div class="form-text">
                                    Staff only. Never shown to the controller or the submitter.
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer justify-content-between">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button>
                            <div>
                                {{-- Two submits, one form. The outcome IS the button
                                     you press, so there is no separate status field to
                                     leave unset. --}}
                                <button type="submit" name="status" value="{{ \App\Helpers\FeedbackStatus::CLOSED->value }}"
                                        class="btn btn-secondary">
                                    <i class="fas fa-check"></i>&nbsp;Read and close
                                </button>
                                <button type="submit" name="status" value="{{ \App\Helpers\FeedbackStatus::FORWARDED->value }}"
                                        class="btn btn-success">
                                    <i class="fas fa-share"></i>&nbsp;Read and forward
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    @can('update', \App\Models\Feedback::class)
        {{-- Bootstrap owns this element's open/close state; wire:ignore keeps
             Livewire re-renders (the one that loads the datalists on open) from
             morphing away the `show` class mid-animation. The open/close events
             fire on this element, so the flag is toggled from here directly. --}}
        <div wire:ignore class="modal fade" id="feedback-edit-modal" tabindex="-1"
            aria-labelledby="feedback-edit-modal-label" aria-hidden="true"
            x-init="
                $el.addEventListener('show.bs.modal', () => $wire.set('showReferenceOptions', true));
                $el.addEventListener('hidden.bs.modal', () => $wire.set('showReferenceOptions', false));
            ">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="feedback-edit-modal-label">Edit Feedback</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form :action="current.updateUrl" method="POST">
                            @method('PATCH')
                            @csrf

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Submitter</label>
                                    <input class="form-control" type="text" x-model="current.submitter" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Submitted</label>
                                    <input class="form-control" type="text" x-model="current.submitted" disabled>
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-12">
                                    <label class="form-label">Feedback Text</label>
                                    <textarea class="form-control" rows="5" disabled :value="current.feedback"></textarea>
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label class="form-label" for="feedback-edit-controller">Controller
                                        <small class="form-text"> (Optional)</small></label>
                                    <input
                                        id="feedback-edit-controller"
                                        class="form-control @error('controller') is-invalid @enderror"
                                        type="text"
                                        name="controller"
                                        list="feedback-controllers-list"
                                        x-model="current.controller"
                                    >
                                    @error('controller')
                                        <span class="text-danger">{{ $errors->first('controller') }}</span>
                                    @enderror
                                    <small class="form-text text-muted">Current: <span x-text="current.controllerLabel"></span></small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="feedback-edit-position">Controller's position
                                        <small class="form-text"> (Optional)</small></label>
                                    <input
                                        id="feedback-edit-position"
                                        class="form-control @error('position') is-invalid @enderror"
                                        type="text"
                                        name="position"
                                        list="feedback-positions-list"
                                        x-model="current.position"
                                    >
                                    @error('position')
                                        <span class="text-danger">{{ $errors->first('position') }}</span>
                                    @enderror
                                    <small class="form-text text-muted">Current: <span x-text="current.positionLabel"></span></small>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">Update Feedback</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- Options are populated lazily once the modal opens; see the
             show.bs.modal / hidden.bs.modal handlers on the modal element. --}}
        <datalist id="feedback-controllers-list">
            @foreach($editControllers as $controller)
                @browser('isFirefox')
                    <option>{{ $controller->id }}</option>
                @else
                    <option value="{{ $controller->id }}">{{ $controller->name }}</option>
                @endbrowser
            @endforeach
        </datalist>

        <datalist id="feedback-positions-list">
            @foreach($editPositions as $position)
                @browser('isFirefox')
                    <option>{{ $position->callsign }}</option>
                @else
                    <option value="{{ $position->callsign }}">{{ $position->name }}</option>
                @endbrowser
            @endforeach
        </datalist>
    @endcan
</div>
