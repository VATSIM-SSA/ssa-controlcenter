@extends('layouts.vatssa')

@section('title', 'Message templates')

@section('content')

{{--
    VATSSA: the bot's messages, edited here rather than in its container.

    Control Center's own template editor cannot carry these. It is append-only,
    on three emails, per area -- you add a paragraph, you cannot rewrite the
    body, which is hardcoded in Blade. These are seventeen full messages.

    Converted to Tailwind. Field names unchanged: subject, body.

    The design idea: one template open at a time. Seventeen stacked cards, each
    with a ten-row textarea, made this page thousands of pixels tall and the one
    template you came to edit impossible to find. A details/summary list turns
    it into a list you can scan, and the browser handles the toggling with no
    JavaScript at all.
--}}
<div class="space-y-6">

    <div class="max-w-3xl">
        <h2 class="text-xl font-semibold tracking-tight">Message templates</h2>
        <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
            The training pipeline's emails. Placeholders in
            <code class="font-mono text-xs">{braces}</code> are filled in when the message is
            sent — the pipeline
            <span class="text-neutral-800 dark:text-neutral-200">refuses to send</span>
            rather than emailing a raw brace, so only use the ones listed under each template.
        </p>
    </div>

    @if($templates->isEmpty())
        <p class="rounded-xl border border-dashed border-neutral-300 px-4 py-16 text-center text-sm
                  text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
            No templates loaded yet. The pipeline seeds these on its first run against the bridge.
        </p>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white
                dark:border-neutral-800 dark:bg-neutral-900">
        @foreach($templates as $template)
            <details class="group border-b border-neutral-100 last:border-0 dark:border-neutral-800">
                <summary class="flex cursor-pointer items-center gap-3 px-5 py-4
                                hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                    <svg class="h-4 w-4 shrink-0 text-neutral-400 transition-transform group-open:rotate-90"
                         fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" d="m9 5 7 7-7 7"/>
                    </svg>

                    <span class="min-w-0 flex-1">
                        <span class="font-mono text-xs text-neutral-400">{{ $template->key }}</span>
                        <span class="ml-2 text-sm font-medium">{{ $template->name }}</span>
                        @if($template->description)
                            <span class="mt-0.5 block truncate text-xs text-neutral-500 dark:text-neutral-400">
                                {{ $template->description }}
                            </span>
                        @endif
                    </span>

                    <span class="shrink-0 rounded-md bg-neutral-100 px-2 py-1 text-xs font-medium
                                 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                        {{ $template->channel }}
                    </span>
                </summary>

                <form method="POST" action="{{ route('vatssa.admin.templates.update', $template->key) }}"
                      class="space-y-4 border-t border-neutral-100 px-5 py-5 dark:border-neutral-800">
                    @csrf
                    @method('PATCH')

                    @if($template->channel === 'email')
                        <label class="block">
                            <span class="text-sm font-medium">Subject</span>
                            <input type="text" name="subject" maxlength="255"
                                   value="{{ old('subject', $template->subject) }}"
                                   class="mt-1.5 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2
                                          text-sm focus:border-brand-500
                                          dark:border-neutral-700 dark:bg-neutral-950">
                        </label>
                    @endif

                    <label class="block">
                        <span class="text-sm font-medium">Body</span>
                        <textarea name="body" rows="10"
                                  class="mt-1.5 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2
                                         font-mono text-[13px] leading-relaxed focus:border-brand-500
                                         dark:border-neutral-700 dark:bg-neutral-950">{{ old('body', $template->body) }}</textarea>
                    </label>

                    @if($template->placeholders())
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-xs text-neutral-500 dark:text-neutral-400">Available:</span>
                            @foreach($template->placeholders() as $placeholder)
                                <code class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-[11px]
                                             text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                    {{ '{' . $placeholder . '}' }}
                                </code>
                            @endforeach
                        </div>
                    @endif

                    <button type="submit"
                            class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white
                                   hover:bg-brand-600">
                        Save {{ $template->key }}
                    </button>
                </form>
            </details>
        @endforeach
    </div>
</div>

@endsection
