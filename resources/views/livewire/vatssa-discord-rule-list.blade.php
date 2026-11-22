<div>
    @include('vatssa.discord._style')

    @if($status)
        <div class="alert alert-success alert-dismissible" role="status">{{ $status }}
            <button type="button" class="btn-close" wire:click="$set('status', null)" aria-label="Close"></button></div>
    @endif
    @if($error)
        <div class="alert alert-danger alert-dismissible" role="alert">{{ $error }}
            <button type="button" class="btn-close" wire:click="$set('error', null)" aria-label="Close"></button></div>
    @endif

    @php($paused = $bot && $bot['online'] && ($bot['paused_removals'] || $bot['paused_adds']))
    <div class="card shadow mb-3" wire:poll.15s>
        <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
            @if(! $bot || ! $bot['online'])
                <span><span class="vd-dot"></span>Bot offline{{ $bot ? ', last seen ' . \Illuminate\Support\Carbon::parse($bot['seen_at'])->diffForHumans() : '' }}</span>
            @elseif($paused)
                <span><span class="vd-dot warn"></span><strong>Paused by a safety limit:</strong>
                    {{ collect(['role removals' => $bot['paused_removals'], 'role additions' => $bot['paused_adds']])->filter()->keys()->implode(' and ') }}</span>
            @else
                <span><span class="vd-dot on"></span>Bot online</span>
            @endif
            @if($bot)
                <span class="text-muted">{{ ['report' => 'Report mode: nothing is applied', 'adds' => 'Adds only', 'live' => 'Live'][$bot['mode']] ?? $bot['mode'] }}</span>
                @if($bot['frozen'])<span class="text-muted">{{ $bot['frozen'] }} frozen {{ Str::plural('member', $bot['frozen']) }}</span>@endif
            @endif
            <span class="text-muted">{{ $rules->where('enabled', true)->count() }} of {{ $rules->count() }} rules on</span>
            @if($paused && $canManage)
                <button type="button" class="btn btn-sm btn-success ms-auto" wire:click="approve" wire:confirm="Apply the held changes now? The next sweep ignores the hourly limits once.">Approve and resume</button>
            @endif
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h6 class="m-0 fw-bold text-white">Rules</h6>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-icon btn-light" wire:click="$set('showHistory', true)"><i class="fas fa-clock-rotate-left"></i> History</button>
                @if($canManage)
                    <a href="{{ route('vatssa.discord.rules.create') }}" class="btn btn-icon btn-light d-inline-flex align-items-center gap-1"><i class="fas fa-plus"></i> New rule</a>
                @endif
            </div>
        </div>

        @if($rules->isEmpty())
            <div class="card-body"><p class="mb-0">No rules yet. The bot changes nobody's roles until there is one.</p></div>
        @else
            <div class="card-body border-bottom py-2">
                <input type="search" class="form-control form-control-sm" style="max-width: 18rem" placeholder="Search roles and rules" aria-label="Search roles and rules" wire:model.live.debounce.200ms="search">
            </div>
            @if($byRole->isEmpty())
                <div class="card-body"><p class="mb-0">Nothing matches that search.</p></div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover table-leftpadded vd-table mb-0" width="100%" cellspacing="0">
                        <thead class="table-light"><tr><th>Discord role</th><th>Given when</th><th>Rule</th>@if($canManage)<th class="text-end">On</th><th></th>@endif</tr></thead>
                        @foreach($byRole as $role)
                            <tbody wire:key="role-{{ $role['key'] }}">
                                @foreach($role['rules'] as $rule)
                                    <tr class="{{ $rule->enabled ? '' : 'vd-off' }}" wire:key="role-{{ $role['key'] }}-rule-{{ $rule->id }}">
                                        @if($loop->first)
                                            <td class="vd-role" rowspan="{{ count($role['rules']) }}">{{ $role['name'] }}</td>
                                        @endif
                                        <td>
                                            @unless($loop->first)<span class="vd-join">or</span>@endunless
                                            @foreach($rule->words as $word)
                                                <span class="vd-chip">{{ $word }}</span>@unless($loop->last)<span class="vd-join">and</span>@endunless
                                            @endforeach
                                        </td>
                                        <td class="vd-rule-name">{{ $rule->name }}</td>
                                        @if($canManage)
                                            <td class="text-end">
                                                <div class="form-check form-switch d-inline-block mb-0">
                                                    <input class="form-check-input" type="checkbox" role="switch" @checked($rule->enabled) wire:click="toggle({{ $rule->id }})" wire:loading.attr="disabled" aria-label="{{ $rule->enabled ? 'Switch off' : 'Switch on' }} {{ $rule->name }}">
                                                </div>
                                            </td>
                                            <td class="text-end"><a href="{{ route('vatssa.discord.rules.edit', $rule->id) }}" class="btn btn-sm btn-icon btn-light d-inline-flex align-items-center" aria-label="Edit {{ $rule->name }}"><i class="fas fa-pen"></i></a></td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
            @endif
        @endif
    </div>

    @if($showHistory)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" role="dialog" aria-modal="true" aria-labelledby="ruleHistoryLabel" wire:keydown.escape.window="$set('showHistory', false)">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title" id="ruleHistoryLabel">Rule history</h5>
                        <button type="button" class="btn-close" wire:click="$set('showHistory', false)" aria-label="Close"></button></div>
                    <div class="modal-body p-0">
                        @if($audit->isEmpty())
                            <p class="m-3">No changes yet.</p>
                        @else
                            <table class="table table-sm table-leftpadded vd-table mb-0" width="100%" cellspacing="0">
                                <thead class="table-light"><tr><th>When</th><th>Rule</th><th>Change</th><th>By</th>@if($canManage)<th></th>@endif</tr></thead>
                                <tbody>
                                @foreach($audit as $entry)
                                    <tr wire:key="audit-{{ $entry->id }}">
                                        <td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('j M Y H:i') }}</td>
                                        <td>{{ $entry->rule_name }}</td>
                                        <td>{{ ucfirst($entry->action) }}</td>
                                        <td>{{ $entry->by ?? 'System' }}</td>
                                        @if($canManage)
                                            <td class="text-end">@if($entry->before)
                                                <button type="button" class="btn btn-sm btn-icon btn-light" wire:click="restore({{ $entry->id }})" wire:confirm="Put this rule back as it was before this change?">Restore</button>
                                            @endif</td>
                                        @endif
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
