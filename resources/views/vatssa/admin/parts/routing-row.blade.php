{{--
    VATSSA: one desk row on the request-routing page.

    ## Checkboxes, not a multi-select

    Several people per desk is the normal case, not an edge one. "Hold Ctrl or
    Cmd to pick more than one" is an instruction nobody reads on a control they
    touch twice a year, and the result was desks with one person on them because
    picking a second felt like a trick.

    ## The form contract is unchanged

    Still `targets[$key][]` with user ids. The Bootstrap version posted exactly
    this, and a restyle that quietly renames a field is a restyle that breaks a
    controller.

    ## The empty state says what empty MEANS

    `updateRouting` refuses a POST that would empty every desk, because browsers
    omit unchecked boxes entirely and an empty submit used to wipe the lot. That
    guard is in the controller; this says the same thing where somebody can read
    it before they hit save.

    Expects: $key (the form key, "tier" or "tier:ratingId"), $label, $selected,
             $candidates. Optional: $help
--}}
<div class="px-6 py-4">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="text-sm font-medium">{{ $label }}</p>
        <p class="text-xs {{ $selected ? 'text-ink-soft' : 'text-warn' }}">
            {{ $selected
                ? count($selected) . ' ' . Str::plural('person', count($selected))
                : 'Empty — requests will stay with whoever raised them' }}
        </p>
    </div>

    <div class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($candidates as $candidate)
            @php $on = in_array($candidate->id, $selected); @endphp
            <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm
 transition-colors hover:bg-card-header">
                <input type="checkbox"
                       name="targets[{{ $key }}][]"
                       value="{{ $candidate->id }}"
                       @checked($on)
                       class="h-4 w-4 shrink-0 rounded border-line text-brand
 focus:ring-brand">
                <span class="min-w-0 truncate">
                    {{ $candidate->name }}
                    <span class="text-xs tabular-nums text-ink-faint">{{ $candidate->id }}</span>
                </span>
            </label>
        @endforeach
    </div>

    @isset($help)
        <p class="mt-2 text-xs text-ink-soft">{{ $help }}</p>
    @endisset
</div>
