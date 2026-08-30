@extends('layouts.vatssa')

@section('title', 'Raise a request')

@section('content')

{{--
    VATSSA: raise a request, from the mirror.

    ## The one write the preview allows

    Everything else here is read only on purpose: a mirror that writes is a
    second code path to the same tables with none of the guards the real
    controllers have grown. This is the exception because a request queue you
    cannot add to is not a queue anybody can judge -- half of what decides
    whether the desk model works is what it feels like to send something to
    one.

    ## It writes nothing itself

    The form posts to `vatssa.requests.store`, the same controller the real
    task screen posts to, through the same validation, the same policy and the
    same routing observer. A request raised here is byte-for-byte a request
    raised from the real page, which is what keeps the comparison honest.

    Field names are therefore fixed by that controller: desk, type, message,
    subject_user_id, subject_training_id.
--}}
<form method="POST" action="{{ route('vatssa.requests.store') }}" class="max-w-2xl space-y-6">
    @csrf

    <div>
        <h2 class="text-xl font-semibold tracking-tight">Raise a request</h2>
        <p class="mt-1 text-sm text-ink-soft">
            A request goes to a <strong class="font-medium text-ink">desk</strong>, not to a
            person. Everybody at that desk sees it and any of them can act, so it does not
            sit unread because one person is away.
        </p>
    </div>

    <div class="space-y-5 rounded-xl border border-line bg-card p-6">

        <label class="block">
            <span class="text-sm font-medium">Which desk</span>
            <select name="desk" required
                    class="mt-1.5 w-full rounded-lg border border-line bg-card px-3 py-2 text-sm
                           focus:border-brand">
                @foreach($desks as $key => $choice)
                    <option value="{{ $key }}">{{ $choice['label'] }}</option>
                @endforeach
            </select>
            <span class="mt-1 block text-xs text-ink-soft">
                Start with the pipeline coordinator for the rating. Leadership is rarely the
                right first stop.
            </span>
        </label>

        <label class="block">
            <span class="text-sm font-medium">Kind of request</span>
            <select name="type" required
                    class="mt-1.5 w-full rounded-lg border border-line bg-card px-3 py-2 text-sm
                           focus:border-brand">
                @foreach($types as $type)
                    <option value="{{ $type::class }}">{{ $type->getName() }}</option>
                @endforeach
            </select>
            <span class="mt-1 block text-xs text-ink-soft">
                Some kinds have a fixed desk and ignore the choice above — a rating upgrade
                always goes to membership.
            </span>
        </label>

        <label class="block">
            <span class="text-sm font-medium">What is being asked</span>
            <textarea name="message" rows="3" minlength="3" maxlength="256" required
                      placeholder="Enough that somebody picking this up cold knows what to do."
                      class="mt-1.5 w-full rounded-lg border border-line bg-card px-3 py-2 text-sm
                             focus:border-brand"></textarea>
        </label>

        {{-- Both optional, and that is the point. Upstream can only create a
             task from a training page, so anything not about one student had
             nowhere to go and happened in Discord instead. --}}
        <div class="grid gap-5 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium">
                    About a member <span class="font-normal text-ink-faint">(optional)</span>
                </span>
                <input type="number" name="subject_user_id" placeholder="CID, or leave blank"
                       class="mt-1.5 w-full rounded-lg border border-line bg-card px-3 py-2 text-sm
                              focus:border-brand">
            </label>

            <label class="block">
                <span class="text-sm font-medium">
                    About a training <span class="font-normal text-ink-faint">(optional)</span>
                </span>
                <select name="subject_training_id"
                        class="mt-1.5 w-full rounded-lg border border-line bg-card px-3 py-2 text-sm
                               focus:border-brand">
                    <option value="">Not about one training</option>
                    @foreach($trainings as $training)
                        <option value="{{ $training->id }}"
                            @selected((string) $selected === (string) $training->id)>
                            {{ $training->user?->name ?? 'CID ' . $training->user_id }}
                            — {{ $training->status->label() }}
                        </option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit"
                class="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white
                       hover:bg-brand-strong">
            Raise request
        </button>
        <a href="{{ route('vatssa.preview.tasks') }}" class="text-sm text-ink-soft hover:text-ink">
            Cancel
        </a>
    </div>

    @include('vatssa.preview.parts.notice')
</form>

@endsection
