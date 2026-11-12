{{-- VATSSA: "Requires attention" (amber) and "Stalled" (red) badges, included from
     vatssa/training/theory-badge.blade.php. Open trainings only, and only for the
     people who see staff comments: the training's mentors and staff with
     training.activities.view in its area. Never the student. --}}
@if(($training->attention_at || $training->stalled_at) && $training->status->isOpen() && auth()->check() && (auth()->user()->hasPermission('training.activities.view', $training->area) || $training->mentors->contains(auth()->user())))
    @if($training->attention_at)
        @php($vatssaAttentionSince = \Illuminate\Support\Carbon::parse($training->attention_at))
        <span class="badge text-bg-warning ms-1" title="Requires attention since {{ $vatssaAttentionSince->toEuropeanDate() }}"><i class="fas fa-triangle-exclamation"></i> {{ ($short ?? false) ? 'Attention' : 'Requires attention since ' . $vatssaAttentionSince->toEuropeanDate() }}</span>
    @endif
    @if($training->stalled_at)
        @php($vatssaStalledSince = \Illuminate\Support\Carbon::parse($training->stalled_at))
        <span class="badge text-bg-danger ms-1" title="Stalled since {{ $vatssaStalledSince->toEuropeanDate() }}"><i class="fas fa-hourglass-half"></i> {{ ($short ?? false) ? 'Stalled' : 'Stalled since ' . $vatssaStalledSince->toEuropeanDate() }}</span>
    @endif
@endif
