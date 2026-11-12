{{-- VATSSA: "Requires attention" (amber) and "Stalled" (red) badges, included from
     vatssa/training/theory-badge.blade.php. Open trainings only, and only for the
     people who see staff comments: the training's mentors and staff with
     training.activities.view in its area. Never the student.
     The label is drawn by CSS from data-label (.vatssa-flag in the theme), so the
     Open requests State filter, which reads the cell's text, lists only the status. --}}
@if(($training->attention_at || $training->stalled_at) && $training->status->isOpen() && auth()->check() && (auth()->user()->hasPermission('training.activities.view', $training->area) || $training->mentors->contains(auth()->user())))
    @if($training->attention_at)
        @php($vatssaAttention = 'Requires attention since ' . \Illuminate\Support\Carbon::parse($training->attention_at)->toEuropeanDate())
        <span class="badge text-bg-warning ms-1 vatssa-flag" title="{{ $vatssaAttention }}" aria-label="{{ $vatssaAttention }}" data-label="{{ ($short ?? false) ? 'Attention' : $vatssaAttention }}"><i class="fas fa-triangle-exclamation"></i></span>
    @endif
    @if($training->stalled_at)
        @php($vatssaStalled = 'Stalled since ' . \Illuminate\Support\Carbon::parse($training->stalled_at)->toEuropeanDate())
        <span class="badge text-bg-danger ms-1 vatssa-flag" title="{{ $vatssaStalled }}" aria-label="{{ $vatssaStalled }}" data-label="{{ ($short ?? false) ? 'Stalled' : $vatssaStalled }}"><i class="fas fa-hourglass-half"></i></span>
    @endif
@endif
