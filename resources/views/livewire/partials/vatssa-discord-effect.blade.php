@if(isset($effect['hint']))
    <span class="text-muted">{{ $effect['hint'] }}</span>
@else
    @foreach($effect['adds'] as $row)<div class="mb-1">+ {{ $row['role'] }}: {{ $row['members'] }} {{ Str::plural('member', $row['members']) }}</div>@endforeach
    @foreach($effect['removes'] as $row)<div class="mb-1">− {{ $row['role'] }}: {{ $row['members'] }} {{ Str::plural('member', $row['members']) }}</div>@endforeach
    @if(! $effect['adds'] && ! $effect['removes'])<div class="mb-1">No role changes.</div>@endif
    <div class="small text-muted mt-2">From {{ $effect['counted'] }} accounts. People with no CC account are not counted.</div>
    @if($effect['dropped'])
        <div class="mt-3 pt-3 border-top">
            <label class="form-label" for="dropped">No rule gives {{ collect($effect['dropped'])->map(fn ($r) => $r['role'] . ' (' . $r['members'] . ')')->implode(', ') }} any more. For members who have it:</label>
            <select class="form-select form-select-sm" id="dropped" wire:model="dropped">
                <option value="keep">Keep it on them</option>
                <option value="remove">Remove it</option>
            </select>
        </div>
    @endif
@endif
