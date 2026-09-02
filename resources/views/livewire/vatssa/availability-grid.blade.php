{{--
    VATSSA: the availability grid.

    Painting happens entirely in Alpine and only the result is sent. A Livewire
    round trip per cell would make dragging feel broken on a slow connection,
    and half this division is on one.

    ## Why this one keeps its own CSS

    Everything else in the fork is Bootstrap with the restyle over it. Bootstrap
    has no paintable calendar, so there is nothing to restyle -- the classes
    below are scoped `.availability-grid__*` in `_migration.scss` and built on
    the same variables as everything around them. Scoped, so a rule here can
    never reach a component upstream ships later.
--}}
<div class="card shadow mb-4 availability-grid"
     x-data="availabilityGrid(@js($selected), $wire)"
     @pointerup.window="finish()"
     @pointercancel.window="finish()"
     @pointermove.window="track($event)"
     {{-- The server clearing slots has to reach the Set, which is the only
          thing that decides what a cell looks like. Without this the cells
          stayed painted after "Clear this week" until a reload. --}}
     @availability-cleared.window="resync($event.detail.slots)">

    {{-- Week navigation. The month is the question; the week is what fits. --}}
    <div class="card-header bg-primary py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span class="d-flex align-items-center gap-1">
            <button type="button" wire:click="previousWeek"
                    class="btn btn-sm btn-outline-light border-0" aria-label="Previous week">
                <i class="fas fa-chevron-left"></i>
            </button>
            <span class="fw-bold text-white text-center availability-grid__range">
                {{ $days->first()?->format('j M') }} &ndash; {{ $days->last()?->format('j M Y') }}
                {{-- How far through the poll this week is. One week on screen
                     reads as the whole question; a student who marks it and
                     stops has answered a fifth of a five-week poll and had
                     nothing on the page to tell them so. --}}
                <small class="d-block fw-normal opacity-75">
                    week {{ $poll->weekIndex($weekStart) }} of {{ $poll->weekCount() }}
                </small>
            </span>
            <button type="button" wire:click="nextWeek"
                    class="btn btn-sm btn-outline-light border-0" aria-label="Next week">
                <i class="fas fa-chevron-right"></i>
            </button>
        </span>

        <span class="d-flex align-items-center gap-2">
            {{-- The timezone, said out loud, from config.
                 VATSSA spans four hours of time zones and the worst bug this
                 workflow has is a CPT confirmed for the wrong hour. "Zulu"
                 alone is read as local time by roughly everybody who has not
                 controlled yet, and the students are the ones answering. --}}
            <span class="badge bg-light text-dark">
                All times {{ \App\Models\Vatssa\AvailabilityPoll::timezoneLabel() }}
            </span>
            @unless($readOnly)
                <button type="button" wire:click="clearWeek"
                        class="btn btn-sm btn-outline-light border-0">
                    Clear this week
                </button>
            @endunless
        </span>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="availability-grid__table">
                <thead>
                    <tr>
                        <th class="availability-grid__gutter"></th>
                        @foreach($days as $day)
                            <th class="availability-grid__day">
                                <small class="text-muted d-block text-uppercase">{{ $day->format('D') }}</small>
                                <span>{{ $day->format('j') }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($times as $time)
                        @php $onHour = str_ends_with($time, ':00'); @endphp
                        <tr @class(['availability-grid__hour' => $onHour])>
                            <td class="availability-grid__gutter text-muted">
                                {{ $onHour ? $time : '' }}
                            </td>

                            @foreach($days as $day)
                                @php
                                    $slot = $day->setTimeFromTimeString($time)->toIso8601String();
                                    $overlap = count($others[$slot] ?? []);
                                @endphp
                                <td class="availability-grid__cell">
                                    <div
                                        data-slot="{{ $slot }}"
                                        @unless($readOnly)
                                            @pointerdown.prevent="start('{{ $slot }}')"
                                        @endunless
                                        :class="has('{{ $slot }}') ? 'is-mine' : '{{ $overlap ? 'is-shared' : '' }}'"
                                        @class([
                                            'availability-grid__slot',
                                            'is-editable' => ! $readOnly,
                                        ])
                                        @if($overlap)
                                            title="{{ $overlap }} other {{ Str::plural('person', $overlap) }} free"
                                        @endif
                                    ></div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-footer d-flex flex-wrap align-items-center gap-3 small text-muted">
        <span class="d-flex align-items-center gap-2">
            <span class="availability-grid__key is-mine"></span> You are free
        </span>
        <span class="d-flex align-items-center gap-2">
            <span class="availability-grid__key is-shared"></span> Somebody else is free
        </span>
        <span x-text="`${count()} slots marked`"></span>
        @if($participants > 1)
            <span>{{ $participants }} people have answered</span>
        @endif
    </div>
</div>

{{--
    Registered on `alpine:init`, not in Livewire's @script.

    @script runs AFTER Alpine has initialised, so an Alpine.data registered
    there arrives too late for the x-data already on the page -- the grid
    renders and then does nothing, with no error. This script tag sits in the
    body above @livewireScripts, so it runs before Alpine boots.

    Guarded, because two grids on one page would otherwise register twice.
--}}
<script>
    document.addEventListener('alpine:init', () => {
        if (window.__vatssaAvailabilityGrid) return;
        window.__vatssaAvailabilityGrid = true;

        Alpine.data('availabilityGrid', (initial, wire) => ({
            // A Set, because the hit test runs on every cell of every render and
            // an array scan over a month of slots is visibly slow while dragging.
            slots: new Set(initial),
            painting: false,
            // What the drag is doing. Decided by the FIRST cell: starting on a
            // marked slot erases, starting on an empty one fills. Anything else
            // means a drag across a mixed region toggles each cell and leaves a
            // stripe nobody asked for.
            mode: null,

            has(slot) { return this.slots.has(slot); },
            count() { return this.slots.size; },

            start(slot) {
                this.painting = true;
                this.mode = this.slots.has(slot) ? 'erase' : 'fill';
                this.apply(slot);
            },

            // Hit-tested rather than driven by pointerenter on each cell.
            //
            // Two reasons, and both are the difference between working and not.
            // A touch drag captures the pointer to the element it started on, so
            // pointerenter never fires anywhere else -- the grid would paint one
            // cell on a phone and look broken. And elementFromPoint keeps working
            // when the drag leaves the table and comes back, which a per-cell
            // listener does not.
            track(event) {
                if (!this.painting) return;
                const under = document.elementFromPoint(event.clientX, event.clientY);
                const slot = under?.dataset?.slot;
                if (slot) this.apply(slot);
            },

            apply(slot) {
                this.mode === 'fill' ? this.slots.add(slot) : this.slots.delete(slot);
            },

            // Replace the whole Set from a server-authoritative list.
            //
            // Replace, not merge. A merge would resurrect exactly what the
            // server just removed, which is the failure this exists to fix.
            resync(slots) {
                this.slots = new Set(slots || []);
            },

            finish() {
                if (!this.painting) return;
                this.painting = false;
                // One save per drag, not per cell.
                wire.paint([...this.slots]);
            },
        }));
    });
</script>
