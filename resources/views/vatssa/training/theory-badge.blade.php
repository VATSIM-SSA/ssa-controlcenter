{{-- VATSSA: theory mark, shown where upstream showed the pre-training tick. --}}
@if($training->theory_completed)
    <span class="badge text-bg-success ms-1" title="Theory completed"><i class="fas fa-graduation-cap"></i> {{ ($short ?? false) ? 'Theory' : 'Theory completed' }}</span>
@endif
{{-- VATSSA: the attention and stalled flags ride on this include, so upstream views need no new line. --}}
@include('vatssa.training.flag-badges')
