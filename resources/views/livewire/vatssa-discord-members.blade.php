<div>
    @include('vatssa.discord._style')

    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3">
            <h6 class="m-0 fw-bold text-white">Discord members</h6>
        </div>
        <div class="card-body border-bottom py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <ul class="nav nav-pills">
                @foreach($tabs as $value => $label)
                    <li class="nav-item">
                        <button type="button" class="nav-link py-1 px-3 {{ $active === $value ? 'active' : '' }}" @if($active === $value) aria-current="page" @endif wire:click="setTab('{{ $value }}')">{{ $label }} <span class="opacity-75">{{ number_format($counts[$value]) }}</span></button>
                    </li>
                @endforeach
            </ul>
            <input type="search" class="form-control form-control-sm" style="width: 15rem" placeholder="Name, CID or Discord ID" aria-label="Search" wire:model.live.debounce.250ms="search">
        </div>

            <div class="table-responsive">
                <table class="table table-hover table-leftpadded vd-table mb-0" width="100%" cellspacing="0">
                    <thead class="table-light">
                        <tr><th>Discord</th><th>CID</th><th>Status</th><th>Managed roles</th><th>Roles checked</th><th></th></tr>
                        <tr>
                            <th><input type="search" class="form-control form-control-sm" placeholder="Filter" aria-label="Filter by Discord name" wire:model.live.debounce.250ms="filterName"></th>
                            <th><input type="search" inputmode="numeric" class="form-control form-control-sm" placeholder="Filter" aria-label="Filter by CID" wire:model.live.debounce.250ms="filterCid"></th>
                            <th><select class="form-select form-select-sm" aria-label="Filter by status" wire:model.live="filterStatus">
                                <option value="">All</option>
                                <option value="vatsim">VATSIM</option>
                                <option value="no_cc">VATSIM, no CC account</option>
                                <option value="not_checked">Not checked</option>
                                <option value="not_vatsim">Not VATSIM</option>
                                <option value="bot">Bot</option>
                            </select></th>
                            <th><select class="form-select form-select-sm" aria-label="Filter by managed role" wire:model.live="filterRole">
                                <option value="">All</option>
                                @foreach($roleFilters as $role)<option value="{{ $role['id'] }}">{{ $role['name'] }}</option>@endforeach
                            </select></th>
                            <th><select class="form-select form-select-sm" aria-label="Filter by roles checked" wire:model.live="filterChecked">
                                <option value="">All</option>
                                <option value="yes">Checked</option>
                                <option value="no">Not yet</option>
                            </select></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($members as $member)
                        @php($roles = collect(json_decode($member->role_ids ?? '[]', true) ?: [])->filter(fn ($id) => in_array((string) $id, $managed, true))->map(fn ($id) => $roleNames->get('r' . $id) ?? $id)->values())
                        <tr wire:key="member-{{ $member->discord_id }}">
                            <td><div class="fw-bold">{{ $member->name ?? 'Unknown name' }}</div><div class="small text-muted">{{ $member->discord_id }}</div></td>
                            <td>@if($member->user_id)<a href="{{ route('user.show', $member->user_id) }}">{{ $member->user_id }}</a>@elseif($member->vatsim_cid){{ $member->vatsim_cid }}@else<span class="text-muted">None</span>@endif</td>
                            <td class="text-nowrap">
                                @if($member->is_bot) Bot
                                @elseif($member->user_id) VATSIM
                                @elseif($member->vatsim_linked === null) <span class="text-muted">Not checked</span>
                                @elseif($member->vatsim_linked) VATSIM, no CC account
                                @else Not VATSIM
                                @endif
                                @unless($member->in_server)<div class="small text-muted">Left {{ \Illuminate\Support\Carbon::parse($member->left_at)->diffForHumans() }}</div>@endunless
                            </td>
                            <td>
                                @foreach($roles->take(3) as $role)<span class="vd-chip me-1">{{ $role }}</span>@endforeach
                                @if($roles->count() > 3)<span class="small text-muted" title="{{ $roles->slice(3)->implode(', ') }}">+{{ $roles->count() - 3 }}</span>@endif
                            </td>
                            <td class="text-nowrap small">{{ $member->last_synced_at ? \Illuminate\Support\Carbon::parse($member->last_synced_at)->diffForHumans() : 'Not yet' }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-icon btn-light" wire:click="openHistory('{{ $member->discord_id }}')" aria-label="History of {{ $member->name ?? $member->discord_id }}"><i class="fas fa-clock-rotate-left"></i></button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">{{ $filtered ? 'Nobody matches those filters.' : 'Nobody here yet.' }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @if($members->hasPages())<div class="card-footer">{{ $members->links() }}</div>@endif
    </div>

    @if($historyFor)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" role="dialog" aria-modal="true" aria-labelledby="memberHistoryLabel" wire:keydown.escape.window="closeHistory">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title" id="memberHistoryLabel">{{ $historyName }}</h5>
                        <button type="button" class="btn-close" wire:click="closeHistory" aria-label="Close"></button></div>
                    <div class="modal-body">
                        @forelse($history as $row)
                            <div class="mb-2"><div class="small text-muted">{{ $row['when'] }}</div><div>{{ $row['what'] }}</div></div>
                        @empty
                            No history yet.
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
