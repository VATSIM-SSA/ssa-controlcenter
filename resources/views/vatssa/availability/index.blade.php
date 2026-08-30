@extends('layouts.vatssa')

@section('title', 'My availability')

@section('content')

<div class="space-y-8" x-data="{ asking: false }">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="max-w-2xl">
            <h2 class="text-xl font-semibold tracking-tight">Availability</h2>
            <p class="mt-1 text-sm text-ink-soft">
                One grid for practical exams, mentoring sessions and meetings.
                Mark when you could be there and everybody sees the overlap
                straight away, instead of a chain of messages working it out.
            </p>
        </div>

        <button type="button" @click="asking = ! asking"
                class="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white
 transition-colors hover:bg-brand-strong">
            Ask a group
        </button>
    </div>

    {{-- The form is folded away, because most visits here are to answer
         somebody else's question rather than to ask one. --}}
    <form method="POST" action="{{ route('vatssa.availability.store') }}" x-show="asking" x-cloak
          class="space-y-5 rounded-xl border border-line bg-card p-6">
        @csrf

        <div class="grid gap-5 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium">What is it for</span>
                <select name="purpose"
                        class="mt-1.5 w-full rounded-lg border border-line bg-card px-3 py-2 text-sm
 focus:border-brand">
                    <option value="mentoring">Mentoring session</option>
                    <option value="cpt">Practical exam</option>
                    <option value="meeting">Meeting</option>
                </select>
            </label>

            <label class="block">
                <span class="text-sm font-medium">How far ahead</span>
                <select name="weeks"
                        class="mt-1.5 w-full rounded-lg border border-line bg-card px-3 py-2 text-sm
 focus:border-brand">
                    <option value="2">2 weeks</option>
                    <option value="4" selected>4 weeks</option>
                    <option value="8">8 weeks</option>
                </select>
            </label>
        </div>

        <label class="block">
            <span class="text-sm font-medium">Title</span>
            <input type="text" name="title" required maxlength="120"
                   placeholder="S2 practical exam — Web One"
                   class="mt-1.5 w-full rounded-lg border border-line bg-card px-3 py-2 text-sm
 focus:border-brand">
        </label>

        <label class="block">
            <span class="text-sm font-medium">Anything people should know <span class="text-ink-faint">(optional)</span></span>
            <textarea name="description" rows="2" maxlength="1000"
                      class="mt-1.5 w-full rounded-lg border border-line bg-card px-3 py-2 text-sm
 focus:border-brand"></textarea>
        </label>

        <button type="submit"
                class="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-strong">
            Create
        </button>
    </form>

    <section>
        <h3 class="text-sm font-semibold tracking-tight">Waiting on an answer</h3>

        @if($open->isEmpty())
            <p class="mt-3 rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm
 text-ink-soft">
                Nothing open. Nobody is waiting on you.
            </p>
        @else
            <div class="mt-3 space-y-2">
                @foreach($open as $poll)
                    @include('vatssa.availability.parts.row', ['poll' => $poll])
                @endforeach
            </div>
        @endif
    </section>

    @if($settled->isNotEmpty())
        <section>
            <h3 class="text-sm font-semibold tracking-tight">Settled</h3>
            <div class="mt-3 space-y-2">
                @foreach($settled as $poll)
                    @include('vatssa.availability.parts.row', ['poll' => $poll])
                @endforeach
            </div>
        </section>
    @endif
</div>

@endsection
