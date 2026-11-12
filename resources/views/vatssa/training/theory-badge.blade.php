{{-- VATSSA: theory mark, shown where upstream showed the pre-training tick. --}}
@if($training->theory_completed)
    {{-- Label drawn by CSS from data-label, so table filters that read the cell's text ignore it. --}}
    <span class="badge text-bg-success ms-1 vatssa-flag" title="Theory completed" aria-label="Theory completed" data-label="{{ ($short ?? false) ? 'Theory' : 'Theory completed' }}"><i class="fas fa-graduation-cap"></i></span>
@endif
{{-- VATSSA: the attention and stalled flags ride on this include, so upstream views need no new line. --}}
@include('vatssa.training.flag-badges')
