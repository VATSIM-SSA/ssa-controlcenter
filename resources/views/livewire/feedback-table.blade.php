<div>

    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3">
            <h6 class="m-0 fw-bold text-white">Feedback</h6>
        </div>
        <div class="card-body p-0">
            <div class="p-3 border-bottom">
                <div class="row g-2">
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
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" wire:model.live="status" aria-label="Status">
                            @foreach(\App\Helpers\FeedbackStatus::cases() as $statusOption)
                                <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                            @endforeach
                            <option value="all">All statuses</option>
                        </select>
                    </div>
                    <div class="col">
                        <input type="text" class="form-control form-control-sm"
                            placeholder="Search feedback, callsign or airport…"
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
                            <th>Status</th>
                            <th>Feedback</th>
                            <th>Actions</th>
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
                                <td>{{ $f->area?->name ?? 'N/A' }}</td>
                                <td>
                                    <span class="badge bg-{{ $f->status->color() }}">{{ $f->status->label() }}</span>
                                    @if($f->sentiment)
                                        <span class="badge bg-{{ $f->sentiment->color() }}">{{ $f->sentiment->label() }}</span>
                                    @endif
                                    @if($f->wasSent())
                                        <small class="d-block text-muted text-nowrap">Sent · {{ $f->latestAcknowledgement?->acknowledged_at ? 'acknowledged' : 'not acknowledged' }}</small>
                                    @endif
                                </td>
                                {{-- Cut short so long entries do not stretch the table; the full text is on the entry's page. --}}
                                <td class="text-break">{{ \Illuminate\Support\Str::limit($f->feedback, 150) }}</td>
                                <td>
                                    <a href="{{ route('feedback.show', $f) }}" class="btn btn-sm {{ $f->isOpen() ? 'btn-primary' : 'btn-outline-primary' }}">
                                        {{ $f->isOpen() && auth()->user()->can('review', $f) ? 'Review' : 'View' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No feedback found.</td>
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
</div>
