<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-white">
            Access
        </h6>
        <span class="d-flex gap-2">
            @if (count($this->grantableRoles()) > 0)
                <button type="button" class="btn btn-icon btn-light btn-sm" wire:click="openAddModal"><i class="fas fa-plus"></i> Role</button>
            @endif
            @if ($this->canManageDesks() && count($this->deskOptions()) > 0)
                <button type="button" class="btn btn-icon btn-light btn-sm" wire:click="openDeskModal"><i class="fas fa-inbox"></i> Request desk</button>
            @endif
        </span>
    </div>
    <div class="card-body">
    @if ($status)
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ $status }}
            <button type="button" class="btn-close" wire:click="$set('status', null)"></button>
        </div>
    @endif
    @if ($error)
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ $error }}
            <button type="button" class="btn-close" wire:click="$set('error', null)"></button>
        </div>
    @endif

    @php($displayName = fn ($role) => $roles[$role]['name'] ?? $role)

    {{-- VATSSA: one list, headed "Roles".

         Upstream splits this into a global list and one section per area,
         because upstream has area staff. VATSSA has none -- UserPolicy refuses
         every area-scoped grant -- so the area sections could only ever be an
         empty "No area roles assigned", and the word "Global" only means
         something next to an "Area" it is being distinguished from. --}}
    {{-- VATSSA: Examiner appears in this list and is granted somewhere else.

         It is read off an active examiner endorsement rather than being a role
         anybody can tick. The endorsement is what actually decides whether
         somebody may examine, so a role beside it would be a second answer to
         the same question, and the two would disagree within a month of the
         first person granting one and forgetting the other.

         Resolved before the list so "No roles assigned" can account for it --
         that line printed under an Examiner badge would contradict the badge
         directly above it. --}}
    @php($isExaminer = $user->isExaminer())

    <div class="mb-3">
        <strong>Roles</strong>
        @foreach ($globalAssignments as $a)
            @php($manageable = $this->canManage($a->role, null))
            <span class="badge {{ $manageable ? 'bg-primary' : 'bg-secondary' }} d-flex justify-content-between align-items-center w-100 mt-1"
                  wire:key="g-{{ $a->role }}">
                <span>
                    {{ $displayName($a->role) }}
                </span>
                @if ($manageable)
                    <button type="button" class="btn-close btn-close-white"
                            style="font-size:.6rem"
                            aria-label="Remove role"
                            title="Remove {{ $displayName($a->role) }}"
                            wire:click="confirmRemoval('{{ $a->role }}', null)"></button>
                @endif
            </span>
        @endforeach

        {{-- Outlined rather than filled, and with no remove button, because
             neither is available here: this reports a fact owned elsewhere. --}}
        @if ($isExaminer)
            <span class="badge bg-transparent border border-primary text-primary d-flex justify-content-between align-items-center w-100 mt-1">
                <span>Examiner</span>
                <i class="fas fa-award" data-bs-toggle="tooltip"
                   title="From an active examiner endorsement, not a role"></i>
            </span>
        @endif

        @if ($globalAssignments->isEmpty() && ! $isExaminer)
            <div class="text-muted mt-1">No roles assigned</div>
        @endif
    </div>

    {{-- VATSSA: desks, in the same card as roles and still a separate list.

         They were their own read-only card with an Edit button pointing at a
         division-wide grid on another page -- two cards answering one question,
         and the half you could act on was somewhere else.

         Still two lists, though, because they are genuinely different things: a
         role grants permissions, a desk decides who receives the work. An ATC
         training manager holds every coordinator permission and is nobody's
         default coordinator. Merging them into one list would say otherwise. --}}
    @if ($this->canManageDesks())
        <div>
            <strong>Request desks</strong>
            @forelse ($desks as $desk)
                <span class="badge bg-secondary d-flex justify-content-between align-items-center w-100 mt-1"
                      wire:key="desk-{{ $desk->id }}">
                    <span>
                        {{ \App\Models\Vatssa\RequestTarget::label($desk->tier) }}@if ($desk->rating)
                            &mdash; {{ $desk->rating->name }}
                        @elseif (\App\Models\Vatssa\RequestTarget::isPerRating($desk->tier))
                            &mdash; all ratings
                        @endif
                    </span>
                    <button type="button" class="btn-close btn-close-white"
                            style="font-size:.6rem"
                            aria-label="Remove desk"
                            title="Remove this desk"
                            wire:click="removeDesk({{ $desk->id }})"></button>
                </span>
            @empty
                <div class="text-muted mt-1">On no request desk. They receive nothing automatically.</div>
            @endforelse
        </div>
    @endif

    </div>{{-- /card-body --}}

    @if ($showDeskModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add a request desk</h5>
                        <button type="button" class="btn-close" wire:click="closeDeskModal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">
                            Which queue this person receives work from. A desk is not a
                            permission &mdash; it decides who the request reaches, not what
                            they are allowed to do with it.
                        </p>
                        <select class="form-select" wire:model="selectedDesk">
                            <option value="">Choose a desk…</option>
                            @foreach ($this->deskOptions() as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-link" wire:click="closeDeskModal">Cancel</button>
                        <button type="button" class="btn btn-primary" wire:click="addDesk"
                                @disabled(! $selectedDesk)>Add</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showAddModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add role</h5>
                        <button type="button" class="btn-close" wire:click="closeAddModal"></button>
                    </div>
                    <div class="modal-body">
                      {{-- VATSSA: one step, not two.

                           Upstream asks WHERE the role applies. Here the answer
                           is always "everywhere" -- UserPolicy refuses any grant
                           carrying an area -- so the second column offered a
                           choice whose every non-default answer was rejected on
                           submit, and left Grant disabled until somebody found
                           the "Global" tick box. --}}
                      <div class="mb-3">
                          <label class="form-label fw-semibold mb-0">Select a role</label>
                          @foreach ($this->grantableRoles() as $key => $name)
                              <div class="form-check" wire:key="role-{{ $key }}">
                                  <input class="form-check-input" type="radio" name="selectedRole"
                                         id="role-{{ $key }}" value="{{ $key }}"
                                         wire:model.live="selectedRole">
                                  <label class="form-check-label" for="role-{{ $key }}">
                                      {{ $name }}
                                      @if (! empty($roles[$key]['description']))
                                          <span class="d-block text-muted small">{{ $roles[$key]['description'] }}</span>
                                      @endif
                                  </label>
                              </div>
                          @endforeach
                      </div>

                      {{-- VATSSA: said here, where somebody goes looking for it.

                           Examiner is absent from this list on purpose, and an
                           absence explains nothing on its own -- the reader
                           concludes the list is broken, or grants some other
                           role to compensate. --}}
                      <p class="text-muted small mb-0">
                          <i class="fas fa-circle-info"></i>
                          Examiners are not assigned here. An examiner endorsement
                          is what makes somebody an examiner, and the profile shows
                          it automatically.
                      </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeAddModal">Cancel</button>
                        <button type="button" class="btn btn-primary" wire:click="grant" @disabled(! $selectedRole)>
                            Grant
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($pendingRemoval)
        @php($isMentor = $pendingRemoval['role'] === 'mentor')
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Remove {{ $roles[$pendingRemoval['role']]['name'] ?? $pendingRemoval['role'] }}{{ $pendingRemoval['area_id'] !== null ? ' in ' . $this->pendingRemovalAreaName() : '' }}?</h5>
                        <button type="button" class="btn-close" wire:click="cancelRemoval"></button>
                    </div>
                    <div class="modal-body">
                        @if ($isMentor)
                            {{-- VATSSA: no mention of a Division API.

                                 VATSIM_DIVISION_API_DRIVER is unset here, so
                                 DivisionApiServiceProvider resolves NoOpAdapter
                                 and every DivisionApi:: call does nothing at
                                 all. Telling somebody an API will be notified
                                 when none exists is worse than saying nothing:
                                 it invents a consequence they cannot check.

                                 What IS true is the detach, so say only that. --}}
                            @if ($this->removalWillDetach() && $this->removalTrainingCount() > 0)
                                <div class="alert alert-danger mb-0">
                                    This will also detach this user's {{ $this->removalTrainingCount() }} training(s).
                                </div>
                            @endif
                        @else
                            <p class="mb-0">This will revoke the role immediately.</p>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cancelRemoval">Cancel</button>
                        <button type="button" class="btn {{ $isMentor ? 'btn-danger' : 'btn-primary' }}" wire:click="remove">Remove</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
