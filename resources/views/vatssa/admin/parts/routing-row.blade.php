{{--
    VATSSA: one desk row on the request-routing page.

    A plain multi-select rather than anything clever. Several people per desk is
    the normal case, not an edge one, and a control that makes picking two feel
    like a workaround gets used to pick one.

    Expects: $key (the form key, "tier" or "tier:ratingId"), $label, $selected,
             $candidates. Optional: $help
--}}
<div class="mb-3 row align-items-start">
    <label class="col-sm-2 col-form-label" for="target-{{ Str::slug($key) }}">
        {{ $label }}
    </label>
    <div class="col-sm-10">
        <select class="form-select" multiple size="4"
                id="target-{{ Str::slug($key) }}"
                name="targets[{{ $key }}][]">
            @foreach($candidates as $candidate)
                <option value="{{ $candidate->id }}" @selected(in_array($candidate->id, $selected))>
                    {{ $candidate->name }} ({{ $candidate->id }})
                </option>
            @endforeach
        </select>
        <small class="text-muted">
            {{ $help ?? 'Hold Ctrl or Cmd to pick more than one. Nobody selected means this desk is empty.' }}
        </small>
    </div>
</div>
