{{--
    VATSSA: one desk row on the request-routing page.

    ## Checkboxes, not a multi-select

    Several people per desk is the normal case, not an edge one. "Hold Ctrl or
    Cmd to pick more than one" is an instruction nobody reads on a control they
    touch twice a year, and the result was desks with one person on them because
    picking a second felt like a trick.

    The form contract is unchanged -- still `targets[$key][]` carrying user ids,
    exactly as the multi-select posted -- so `updateRouting` needs no change.

    ## Empty says what empty means

    A browser omits unchecked boxes entirely, so an empty submit used to wipe
    every desk. `updateRouting` refuses that POST; this says so where somebody
    can read it before they press save rather than after.

    Expects: $key (the form key, "tier" or "tier:ratingId"), $label, $selected,
             $candidates. Optional: $help
--}}
<div class="mb-4 row">
    <div class="col-sm-2">
        <label class="col-form-label fw-medium">{{ $label }}</label>
    </div>

    <div class="col-sm-10">
        <div class="row">
            @foreach($candidates as $candidate)
                <div class="col-lg-4 col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                               id="target-{{ Str::slug($key) }}-{{ $candidate->id }}"
                               name="targets[{{ $key }}][]"
                               value="{{ $candidate->id }}"
                               @checked(in_array($candidate->id, $selected))>
                        <label class="form-check-label"
                               for="target-{{ Str::slug($key) }}-{{ $candidate->id }}">
                            {{ $candidate->name }}
                            <small class="text-muted">{{ $candidate->id }}</small>
                        </label>
                    </div>
                </div>
            @endforeach

            @if($candidates->isEmpty())
                <div class="col-12">
                    <small class="text-muted">Nobody holds a role that can staff this desk.</small>
                </div>
            @endif
        </div>

        <small class="{{ $selected ? 'text-muted' : 'text-warning' }}">
            @if($selected)
                {{ $help ?? count($selected) . ' ' . Str::plural('person', count($selected))
                    . ' on this desk.' }}
            @else
                Empty &mdash; requests here stay with whoever raised them.
            @endif
        </small>
    </div>
</div>
