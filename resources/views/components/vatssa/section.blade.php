@props(['title', 'icon' => null, 'subtitle' => null])

{{--
    VATSSA: a named division of a long page.

    The profile is eight groups of cards. Without headers it is twenty cards in
    a column and the only way to find one is to read them all; with headers it
    is a document with a contents order somebody can learn once.

    Deliberately quiet -- a rule and small caps, not another coloured bar. The
    cards below already have blue headers, and a heavier heading above them
    would compete with the thing it is introducing rather than label it.
--}}
<div class="mt-2 mb-3">
    <div class="d-flex align-items-baseline gap-2 border-bottom pb-2 mb-3">
        <h6 class="m-0 fw-bold text-uppercase text-muted" style="letter-spacing:.05em">
            @if($icon)
                <i class="fas {{ $icon }}"></i>&nbsp;
            @endif
            {{ $title }}
        </h6>
        @if($subtitle)
            <span class="fs-sm text-muted">{{ $subtitle }}</span>
        @endif
    </div>

    {{ $slot }}
</div>
