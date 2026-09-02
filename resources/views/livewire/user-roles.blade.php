<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-white">
            Access
        </h6>
        @if (count($this->grantableRoles()) > 0)
            <button type="button" class="btn btn-icon btn-light" wire:click="openAddModal"><i class="fas fa-plus"></i> Add role</button>
        @endif
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
    <div class="mb-3">
        <strong>Roles</strong>
        @forelse ($globalAssignments as $a)
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
        @empty
            <div class="text-muted mt-1">No roles assigned</div>
        @endforelse
    </div>

    </div>{{-- /card-body --}}

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
