{{--
    VATSSA: the table, once.

    ## Why one partial and not twenty blades

    Control Center is mostly tables. Mirroring each one as its own file would
    mean twenty copies of the same markup, twenty places to fix a spacing bug,
    and a preview whose whole purpose -- showing what a consistent Tailwind
    Control Center feels like -- undermined by twenty slightly different tables.

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
      $rows     array of arrays of already-rendered HTML strings, same length.
      $empty    optional empty-state sentence.
--}}
<div class="overflow-hidden rounded-xl border border-neutral-200 bg-white
            dark:border-neutral-800 dark:bg-neutral-900">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-neutral-200 text-left dark:border-neutral-800">
                    @foreach($columns as $column)
                        @php
                            $label = is_array($column) ? $column['label'] : $column;
                            $right = is_array($column) && ($column['align'] ?? '') === 'right';
                        @endphp
                        <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-wider
                                   text-neutral-400 {{ $right ? 'text-right' : '' }}">
                            {{ $label }}
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                @forelse($rows as $row)
                    <tr class="transition-colors hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        @foreach($row as $i => $cell)
                            @php
                                $right = is_array($columns[$i] ?? null)
                                    && ($columns[$i]['align'] ?? '') === 'right';
                            @endphp
                            <td class="px-5 py-3 {{ $right ? 'text-right tabular-nums' : '' }}
                                       {{ $i === 0 ? '' : 'text-neutral-600 dark:text-neutral-400' }}">
                                {!! $cell !!}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) }}"
                            class="px-5 py-16 text-center text-neutral-500 dark:text-neutral-400">
                            {{ $empty ?? 'Nothing here.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
