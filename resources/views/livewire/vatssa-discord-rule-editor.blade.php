<form wire:submit="save">
    @include('vatssa.discord._style')

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow mb-3">
                <div class="card-header bg-primary py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-white">{{ $ruleId ? 'Edit rule' : 'New rule' }}</h6>
                    <a href="{{ route('vatssa.discord.rules.index') }}" class="btn btn-icon btn-light d-inline-flex align-items-center">All rules</a>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-bold" for="name">Name</label>
                            <input id="name" maxlength="80" required class="form-control @error('name') is-invalid @enderror" wire:model.live.debounce.400ms="name" placeholder="ATC TWR: home">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-bold" for="description">Description <span class="fw-normal text-muted">(optional)</span></label>
                            <input id="description" maxlength="255" class="form-control" wire:model.blur="description">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow mb-3">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Given when all of these are true</span>
                    <button type="button" class="btn btn-sm btn-icon btn-light" wire:click="addCondition" @disabled(count($conditions) >= 10)><i class="fas fa-plus"></i> Add condition</button>
                </div>
                <div class="card-body">
                    @foreach($conditions as $i => $c)
                        <div wire:key="{{ $c['key'] }}">
                            @unless($loop->first)<div class="vd-and">AND</div>@endunless
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <select class="form-select form-select-sm w-auto" wire:model.live="conditions.{{ $i }}.negated" aria-label="If or if not">
                                    <option value="0">If</option><option value="1">If not</option>
                                </select>
                                <select class="form-select form-select-sm w-auto" wire:model.live="conditions.{{ $i }}.type" aria-label="Condition">
                                    @foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                </select>

                                @if($c['type'] === 'division')
                                    <select class="form-select form-select-sm w-auto" wire:model.live="conditions.{{ $i }}.division" aria-label="Division">
                                        <option value="home">Home member</option><option value="visitor">Visitor</option>
                                    </select>
                                @elseif($c['type'] === 'rating')
                                    <select class="form-select form-select-sm w-auto" wire:model.live="conditions.{{ $i }}.rating_mode" aria-label="How to compare">
                                        <option value="in">is one of</option><option value="min">at least</option><option value="max">at most</option>
                                    </select>
                                    @if($c['rating_mode'] !== 'in')
                                        <select class="form-select form-select-sm w-auto" wire:model.live="conditions.{{ $i }}.rating_value" aria-label="Rating">
                                            @foreach($ratings as $r)<option value="{{ $r['value'] }}">{{ $r['name'] }}</option>@endforeach
                                        </select>
                                    @endif
                                @elseif($c['type'] === 'cc_role')
                                    <select class="form-select form-select-sm w-auto" wire:model.live="conditions.{{ $i }}.role" aria-label="CC role">
                                        @foreach($ccRoles as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach
                                    </select>
                                @elseif($c['type'] === 'endorsement')
                                    <select class="form-select form-select-sm w-auto" wire:model.live="conditions.{{ $i }}.endorsement" aria-label="Endorsement">
                                        @foreach($endorsements as $e)<option value="{{ $e }}">{{ ucfirst(strtolower($e)) }}</option>@endforeach
                                    </select>
                                    <select class="form-select form-select-sm w-auto" wire:model.live="conditions.{{ $i }}.compare" aria-label="Compare rating">
                                        <option value="any">with rating</option><option value="highest">highest is</option><option value="at_least">highest at least</option>
                                    </select>
                                    <select class="form-select form-select-sm w-auto" wire:model.live="conditions.{{ $i }}.endorsement_rating" aria-label="Endorsement rating">
                                        <option value="">any</option>
                                        @foreach($ratingNames as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
                                    </select>
                                @elseif($c['type'] === 'group')
                                    <select class="form-select form-select-sm w-auto" wire:model.live="conditions.{{ $i }}.group" aria-label="Group">
                                        @forelse($groups as $group)<option value="{{ $group['id'] }}">{{ $group['label'] }}</option>@empty<option value="">No groups yet</option>@endforelse
                                    </select>
                                @endif

                                @if(in_array($c['type'], ['cc_role', 'roster', 'training'], true))
                                    <select class="form-select form-select-sm w-auto" wire:model.live="conditions.{{ $i }}.area" aria-label="Area">
                                        <option value="any">any area</option>
                                        @if($c['type'] === 'cc_role')<option value="global">global</option>@endif
                                        @foreach($areas as $area)<option value="{{ $area->id }}">{{ $area->name }}</option>@endforeach
                                    </select>
                                @endif

                                <button type="button" class="btn btn-sm btn-icon btn-light ms-auto" wire:click="removeCondition({{ $i }})" @disabled(count($conditions) === 1) aria-label="Remove condition"><i class="fas fa-xmark"></i></button>
                            </div>
                            @if($c['type'] === 'rating' && $c['rating_mode'] === 'in')
                                <div class="vd-rating-picker d-flex flex-wrap gap-1 mt-2" role="group" aria-label="Ratings">
                                    @foreach($ratings as $r)
                                        <input type="checkbox" class="btn-check" id="rating{{ $c['key'] }}_{{ $r['value'] }}" value="{{ $r['value'] }}" wire:model.live="conditions.{{ $i }}.ratings" autocomplete="off">
                                        <label class="btn btn-sm btn-outline-secondary" for="rating{{ $c['key'] }}_{{ $r['value'] }}">{{ $r['name'] }}</label>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                @error('conditions')<div class="card-footer text-danger">{{ $message }}</div>@enderror
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
                <button class="btn btn-success" wire:loading.attr="disabled" wire:target="save">Save rule</button>
                <a href="{{ route('vatssa.discord.rules.index') }}" class="btn btn-icon btn-light d-inline-flex align-items-center">Cancel</a>
                @if($ruleId)
                    <button type="button" class="btn btn-icon btn-outline-danger ms-auto" wire:click="$set('confirmingDelete', true)"><i class="fas fa-trash"></i> Delete rule</button>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="vd-sticky">
                <div class="card shadow mb-3">
                    <div class="card-header py-3 fw-bold">Gives these Discord roles</div>
                    <div class="card-body">
                        <input type="search" class="form-control form-control-sm mb-2" placeholder="Search roles" aria-label="Search roles" wire:model.live.debounce.150ms="roleSearch" wire:keydown.enter.prevent>
                        <div class="vd-role-list">
                            @forelse($roleOptions as $role)
                                <div class="form-check" wire:key="role-{{ $role['id'] }}">
                                    <input class="form-check-input" type="checkbox" id="role{{ $role['id'] }}" value="{{ $role['id'] }}" wire:model.live="roles">
                                    <label class="form-check-label" for="role{{ $role['id'] }}">{{ $role['name'] }}</label>
                                </div>
                            @empty
                                <p class="text-muted mb-0">{{ $roleSearch !== '' ? 'No role matches.' : "The bot has not reported the server's roles yet." }}</p>
                            @endforelse
                        </div>
                        @error('roles')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="card shadow mb-3">
                    <div class="card-header py-3 fw-bold d-flex justify-content-between">What this changes
                        <span class="spinner-border spinner-border-sm" wire:loading aria-label="Working"></span></div>
                    <div class="card-body">
                        @unless($confirmingDelete)
                            @include('livewire.partials.vatssa-discord-effect', ['effect' => $effect])
                        @endunless
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($confirmingDelete)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" role="dialog" aria-modal="true" aria-labelledby="deleteRuleLabel">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title" id="deleteRuleLabel">Delete {{ $name }}?</h5>
                        <button type="button" class="btn-close" wire:click="$set('confirmingDelete', false)" aria-label="Close"></button></div>
                    <div class="modal-body">@include('livewire.partials.vatssa-discord-effect', ['effect' => $effect])</div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-icon btn-light" wire:click="$set('confirmingDelete', false)">Cancel</button>
                        <button type="button" class="btn btn-danger" wire:click="delete">Delete rule</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</form>
