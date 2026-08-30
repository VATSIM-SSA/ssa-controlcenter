@extends('layouts.vatssa')

@section('title', 'Request desks')

@section('content')

{{--
    VATSSA: who sits at each request desk.

    Converted to Tailwind. One of our own pages, so no merge conflict -- see
    layouts/vatssa.blade.php for why that decides what gets converted.

    The form contract is unchanged: `targets[tier][]` and
    `targets[tier:ratingId][]`, exactly as the Bootstrap version posted. A
    restyle that quietly renames a field is a restyle that breaks a controller.

    Checkboxes instead of a multi-select. "Hold Ctrl to pick more than one" is
    an instruction people do not read on a control they use twice a year, and
    the result was desks with one person on them because picking a second felt
    like a trick.
--}}
<form method="POST" action="{{ route('vatssa.admin.routing.update') }}" class="space-y-8">
    @csrf

    <div class="max-w-3xl">
        <h2 class="text-xl font-semibold tracking-tight">Request desks</h2>
        <p class="mt-1 text-sm text-ink-soft">
            A request goes to a desk, not to a person. Everybody at a desk sees the
            same queue and any of them can act, so a coordinator going on leave does
            not take their requests with them.
        </p>
        <p class="mt-2 text-sm text-ink-soft">
            <span class="text-ink">An empty desk is not a safe default.</span>
            Requests sent to one stay with whoever raised them and a warning goes to the
            automation log. Fill in at least the coordinator row for every rating you train.
        </p>
    </div>

    @foreach($tiers as $tierKey => $tier)
        <section class="rounded-xl border border-line bg-card">
            <div class="border-b border-line-soft px-6 py-4">
                <h3 class="text-sm font-semibold tracking-tight">{{ $tier['label'] }}</h3>
                <p class="mt-0.5 text-sm text-ink-soft">{{ $tier['hint'] }}</p>
            </div>

            <div class="divide-y divide-line-soft">
                @if($tier['per_rating'])
                    {{-- One row per rating. VATSSA's pipelines are per rating, so
                         "the S2 coordinator" is a different person from "the C1
                         coordinator". A rating left empty has NO desk.

                         No catch-all row: "the pipeline coordinator" is not a
                         thing anybody can be, and a catch-all would put somebody
                         on every pipeline queue by accident. --}}
                    @foreach($ratings as $rating)
                        @include('vatssa.admin.parts.routing-row', [
                            'key' => $tierKey . ':' . $rating->id,
                            'label' => $rating->name,
                            'selected' => $targets->where('tier', $tierKey)
                                ->where('rating_id', $rating->id)->pluck('user_id')->all(),
                            'candidates' => $candidates,
                        ])
                    @endforeach
                @else
                    @include('vatssa.admin.parts.routing-row', [
                        'key' => $tierKey,
                        'label' => 'Assigned to',
                        'selected' => $targets->where('tier', $tierKey)->pluck('user_id')->all(),
                        'candidates' => $candidates,
                    ])
                @endif
            </div>
        </section>
    @endforeach

    <button type="submit"
            class="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-strong">
        Save routing
    </button>
</form>

@endsection
