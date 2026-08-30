{{--
    VATSSA: line icons for the Tailwind pages.

    Inline SVG rather than Font Awesome, and stroked rather than solid. That
    single choice does more for the "this looks modern" feeling than any colour
    change: solid glyphs at 16px read as a 2015 admin panel, and every dashboard
    that looks current uses a 1.5px stroke.

    Deliberately few. An icon set that can name everything ends up with icons
    nobody recognises, and a label the reader has to decode is worse than a
    label on its own.

    Expects: $name.
--}}
@php
    $paths = [
        'user' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0"/>',
        'calendar' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/>',
        'academic' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.15v4.8c0 .9.51 1.73 1.34 2.09a13.9 13.9 0 0 0 12.8 0 2.25 2.25 0 0 0 1.34-2.09v-4.8M2.4 7.5 12 3l9.6 4.5-9.6 4.5L2.4 7.5Z"/>',
        'inbox' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86c.54 0 1.03.3 1.28.78l.72 1.44c.25.48.74.78 1.28.78h5.22c.54 0 1.03-.3 1.28-.78l.72-1.44c.25-.48.74-.78 1.28-.78h3.86M2.25 13.5V6.75A2.25 2.25 0 0 1 4.5 4.5h15a2.25 2.25 0 0 1 2.25 2.25v6.75m-19.5 0v3.75A2.25 2.25 0 0 0 4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V13.5"/>',
        'bolt' => '<path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25-3 8.25h6L6.75 21.75l3-8.25h-6Z"/>',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.1 9.1 0 0 0 3.74-.98A11.94 11.94 0 0 0 18 9.75a11.94 11.94 0 0 0-3.74 8A9.1 9.1 0 0 0 18 18.72Zm0 0V9.75M6 18.72a9.1 9.1 0 0 1-3.74-.98A11.94 11.94 0 0 1 6 9.75m9-3.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
        'back' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>',
        'clock' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
        'check' => '<path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>',
        'warning' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.3 3.38c-.87 1.5.21 3.37 1.95 3.37h14.7c1.74 0 2.82-1.87 1.95-3.37L13.95 3.38c-.87-1.5-3.03-1.5-3.9 0L2.7 16.13ZM12 15.75h.01v.01H12v-.01Z"/>',
    ];
@endphp

<svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.5"
     viewBox="0 0 24 24" aria-hidden="true">
    {!! $paths[$name] ?? $paths['check'] !!}
</svg>
