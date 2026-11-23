<label class="form-label" for="dropped">No rule gives {{ implode(', ', $droppedRoles) }} any more. For members who have it:</label>
<select class="form-select form-select-sm" id="dropped" wire:model="dropped">
    <option value="keep">Keep it on them</option>
    <option value="remove">Remove it</option>
</select>
