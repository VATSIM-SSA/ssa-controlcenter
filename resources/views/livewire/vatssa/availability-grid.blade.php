{{--
    VATSSA: the availability grid.

    Painting happens entirely in Alpine and only the result is sent. A Livewire
    round trip per cell would make dragging feel broken on a slow connection,
    and half this division is on one.
--}}
<div class="space-y-4"
     x-data="availabilityGrid(@js($selected), $wire)"
     @pointerup.window="finish()"
     @pointercancel.window="finish()"
     @pointermove.window="track($event)">

    {{-- Week navigation. The month is the question; the week is what fits. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-1">
            <button type="button" wire:click="previousWeek"
                    class="rounded-lg p-2 text-ink-soft hover:bg-card-header
 disabled:opacity-30">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" d="m15 19-7-7 7-7"/>
                </svg>
            </button>
            <span class="min-w-[11rem] text-center text-sm font-medium tabular-nums">
                {{ $days->first()?->format('j M') }} – {{ $days->last()?->format('j M Y') }}
            </span>
            <button type="button" wire:click="nextWeek"
                    class="rounded-lg p-2 text-ink-soft hover:bg-card-header
 disabled:opacity-30">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" d="m9 5 7 7-7 7"/>
                </svg>
            </button>
        </div>

        <div class="flex items-center gap-3 text-xs text-ink-soft">
            {{-- All times Zulu, said out loud. VATSSA spans four hours of time
                 zones and the worst bug in this workflow is a CPT confirmed for
                 the wrong hour. --}}
            <span class="rounded-md bg-card-header px-2 py-1 font-medium">
                All times Zulu (UTC)
            </span>
            @unless($readOnly)
                <button type="button" wire:click="clearWeek" class="hover:text-ink">
                    Clear this week
                </button>
            @endunless
        </div>
    </div>

    {{-- The grid. touch-none so a drag paints instead of scrolling the page. --}}
    <div class="overflow-x-auto rounded-xl border border-line bg-card">
        <table class="w-full border-collapse select-none text-sm" style="touch-action: none">
            <thead>
                <tr>
                    <th class="sticky left-0 z-10 w-16 bg-card p-2"></th>
                    @foreach($days as $day)
                        <th class="border-l border-line-soft p-2 text-center font-medium">
                            <div class="text-[11px] uppercase tracking-wide text-ink-faint">
                                {{ $day->format('D') }}
                            </div>
                            <div class="text-[15px] tabular-nums">{{ $day->format('j') }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($times as $time)
                    @php $onHour = str_ends_with($time, ':00'); @endphp
                    <tr>
                        <td class="sticky left-0 z-10 bg-card pr-2 text-right align-top text-[11px]
 tabular-nums text-ink-faint">
                            {{ $onHour ? $time : '' }}
                        </td>

                        @foreach($days as $day)
                            @php
                                $slot = $day->setTimeFromTimeString($time)->toIso8601String();
                                $overlap = count($others[$slot] ?? []);
                            @endphp
                            <td class="border-l border-line-soft p-0
 {{ $onHour ? 'border-t border-t-line-soft' : '' }}">
                                <div
                                    data-slot="{{ $slot }}"
                                    @unless($readOnly)
                                        @pointerdown.prevent="start('{{ $slot }}')"
                                    @endunless
                                    :class="has('{{ $slot }}')
 ? 'bg-brand'
                                        : '{{ $overlap ? 'bg-brand-wash' : '' }}'"
                                    class="h-5 w-full transition-colors
 {{ $readOnly ? '' : 'cursor-pointer hover:bg-brand-wash' }}"
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

    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-ink-soft">
        <span class="flex items-center gap-1.5">
            <span class="h-3 w-3 rounded-sm bg-brand"></span> You are free
        </span>
        <span class="flex items-center gap-1.5">
            <span class="h-3 w-3 rounded-sm bg-brand-wash"></span> Somebody else is free
        </span>
        <span x-text="`${count()} slots marked`" class="tabular-nums"></span>
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

            finish() {
                if (!this.painting) return;
                this.painting = false;
                // One save per drag, not per cell.
                wire.paint([...this.slots]);
            },
        }));
    });
</script>
