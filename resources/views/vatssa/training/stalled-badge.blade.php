{{-- VATSSA: "Stalled" badge, included from vatssa/training/theory-badge.blade.php.
     Shown to the same people who see staff comments: the training's mentors
     and staff with training.activities.view in its area. Not to the student. --}}
@if($training->stalled_at && $training->status->isOpen() && auth()->check() && (auth()->user()->hasPermission('training.activities.view', $training->area) || $training->mentors->contains(auth()->user())))
    @php($vatssaStalledSince = \Illuminate\Support\Carbon::parse($training->stalled_at))
    <span class="badge text-bg-danger ms-1" title="Stalled since {{ $vatssaStalledSince->toEuropeanDate() }}"><i class="fas fa-hourglass-half"></i> {{ ($short ?? false) ? 'Stalled' : 'Stalled since ' . $vatssaStalledSince->toEuropeanDate() }}</span>
@endif
