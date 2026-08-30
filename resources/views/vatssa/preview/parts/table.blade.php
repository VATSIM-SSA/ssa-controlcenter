{{--
    VATSSA: the table, once, with filtering.

    ## Why one partial and not twenty blades

    Control Center is mostly tables. Mirroring each one as its own file would
    mean twenty copies of the same markup, twenty places to fix a spacing bug,
    and a preview whose whole purpose -- showing what a consistent Tailwind
    Control Center feels like -- undermined by twenty slightly different tables.

    ## Filtering, client side

    Every row is already on the page (the lists are capped), so filtering means
    hiding rows rather than asking the server again. That makes it instant, and
    it keeps working on a connection a `wire:model` filter would stutter on.

    Rows carry their filterable values as `data-f-*` attributes rather than
    having them parsed back out of the rendered cells. Parsing cells would tie
    the filter to the exact HTML of a column, so restyling a badge would
    silently break it.

    ## What it fixes about the Bootstrap ones

    No vertical rules, horizontal only between rows. Boxing every cell makes a
    table look like a spreadsheet, and bootstrap-table's grey grid is doing more
    to date this application than the palette is.

    First column is the anchor and carries weight; everything else is secondary.
    The current tables give the CID, the name, the rating and the date identical
    weight, so there is nothing for the eye to scan by.

    Colour only where it means something. A status pill, never a coloured row --
    colour the row and every row shouts.

    Expects:
      $columns  array of header labels; wrap in ['label' => ..., 'align' => 'right']
                for anything numeric.
      $rows     array of ['cells' => [html, ...], 'meta' => ['key' => 'value']].
                A bare array of cells is still accepted.
      $filters  optional [['key' => 'rating', 'label' => 'Rating', 'options' => [...]]]
      $empty    optional empty-state sentence.
--}}
@php
    // Both shapes accepted, so a list that needs no filtering does not have to
    // wrap every row in a key it will never use.
    $normalised = collect($rows)->map(fn ($row) => array_key_exists('cells', $row)
        ? $row
        : ['cells' => $row, 'meta' => []]);

    $filters = $filters ?? [];
@endphp

<div x-data="previewTable()" class="space-y-3">

    @if($filters || count($normalised) > 8)
        {{-- Search first and widest. Most of the time you know the name and
             want that one row, and making somebody reach for a dropdown to do
             that is the difference between a table you work and one you
             scroll. --}}
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-0 flex-1 sm:max-w-xs">
                <input type="search" x-model="query" placeholder="Search this list"
                       class="w-full rounded-lg border border-line bg-card py-2 pl-9 pr-3 text-sm
                              focus:border-brand">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8"
                         viewBox="0 0 24 24">
                        <path stroke-linecap="round" d="m21 21-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z"/>
                    </svg>
                </span>
            </div>

            @foreach($filters as $filter)
                <select x-model="picked['{{ $filter['key'] }}']"
                        class="rounded-lg border border-line bg-card px-3 py-2 text-sm focus:border-brand">
                    <option value="">Any {{ Str::lower($filter['label']) }}</option>
                    @foreach($filter['options'] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            @endforeach

            <button type="button" x-show="dirty()" x-cloak @click="reset()"
                    class="rounded-lg px-3 py-2 text-sm text-ink-soft hover:text-ink">
                Clear
            </button>

            <span class="ml-auto text-xs tabular-nums text-ink-faint"
                  x-text="`${shown} of ${total}`"></span>
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-line bg-card">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left">
                        @foreach($columns as $column)
                            @php
                                $label = is_array($column) ? $column['label'] : $column;
                                $right = is_array($column) && ($column['align'] ?? '') === 'right';
                            @endphp
                            <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wider
                                       text-ink-faint {{ $right ? 'text-right' : '' }}">
                                {{ $label }}
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-line-soft">
                    @forelse($normalised as $row)
                        <tr data-filterable class="transition-colors hover:bg-card-header"
                            @foreach($row['meta'] as $key => $value) data-f-{{ $key }}="{{ $value }}" @endforeach>
                            @foreach($row['cells'] as $i => $cell)
                                @php
                                    $right = is_array($columns[$i] ?? null)
                                        && ($columns[$i]['align'] ?? '') === 'right';
                                @endphp
                                <td class="px-5 py-3 {{ $right ? 'text-right tabular-nums' : '' }}
                                           {{ $i === 0 ? '' : 'text-ink-soft' }}">
                                    {!! $cell !!}
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) }}"
                                class="px-5 py-16 text-center text-ink-soft">
                                {{ $empty ?? 'Nothing here.' }}
                            </td>
                        </tr>
                    @endforelse

                    {{-- The empty state for a filter that matched nothing. A
                         table that simply goes blank reads as broken, and the
                         way out has to be on screen. --}}
                    <tr x-show="shown === 0 && total > 0" x-cloak>
                        <td colspan="{{ count($columns) }}"
                            class="px-5 py-16 text-center text-sm text-ink-soft">
                            Nothing matches that.
                            <button type="button" @click="reset()"
                                    class="text-brand hover:underline">Clear the filters</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        if (window.__vatssaPreviewTable) return;
        window.__vatssaPreviewTable = true;

        Alpine.data('previewTable', () => ({
            query: '',
            picked: {},
            rows: [],
            shown: 0,
            total: 0,

            init() {
                // `[data-filterable]`, not "every tr". The two empty-state rows
                // are not data: counting them would make an empty list report
                // one row, and hiding them would hide the message that says
                // the list is empty.
                this.rows = [...this.$el.querySelectorAll('tbody tr[data-filterable]')];
                this.total = this.rows.length;

                // Seeded from the markup so a select with no matching row
                // still has a key to watch.
                for (const select of this.$el.querySelectorAll('select[x-model^="picked"]')) {
                    const key = select.getAttribute('x-model').match(/\[.(.+).\]/)?.[1];
                    if (key) this.picked[key] = '';
                }

                this.apply();
                this.$watch('query', () => this.apply());
                this.$watch('picked', () => this.apply());
            },

            apply() {
                const needle = this.query.trim().toLowerCase();
                let shown = 0;

                for (const row of this.rows) {
                    // Every dropdown must match, and a blank one matches
                    // everything. AND rather than OR, because "S2 students
                    // awaiting a mentor" is the question people actually ask.
                    const passesPicked = Object.entries(this.picked).every(([key, value]) => {
                        if (!value) return true;
                        const attr = 'f' + key.charAt(0).toUpperCase() + key.slice(1);
                        return row.dataset[attr] === value;
                    });

                    const passesText = !needle || row.textContent.toLowerCase().includes(needle);
                    const visible = passesPicked && passesText;

                    row.hidden = !visible;
                    if (visible) shown++;
                }

                this.shown = shown;
            },

            dirty() {
                return this.query !== '' || Object.values(this.picked).some((v) => v);
            },

            reset() {
                this.query = '';
                for (const key of Object.keys(this.picked)) this.picked[key] = '';
            },
        }));
    });
</script>
