@extends('layouts.app')

@section('title', 'Pipeline message templates')

@section('content')

{{--
    VATSSA: the bot's messages, edited here rather than in its container.

    Control Center's own template editor cannot carry these. It is append-only,
    on three emails, per area -- you add a paragraph, you cannot rewrite the
    body, which is hardcoded in Blade. These are seventeen full messages.
--}}

<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="alert alert-info" role="alert">
            <i class="fas fa-circle-info"></i>&nbsp;
            These are the training pipeline's emails. Placeholders in
            <code>{braces}</code> are filled in when the message is sent — the
            pipeline refuses to send rather than emailing a raw brace, so only
            use the ones listed under each template.
        </div>
    </div>
</div>

@if($templates->isEmpty())
    <div class="row">
        <div class="col-xl-12">
            <div class="card shadow mb-4">
                <div class="card-body">
                    <p class="mb-0 text-muted">
                        No templates loaded yet. The pipeline seeds these on its
                        first run against the bridge.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endif

{{--
    An accordion, not seventeen stacked cards.

    Every template is a form with a ten-row textarea, so the page opened at
    roughly nine screens of editors for the one somebody came to change. The
    key, the name, the channel and when it last changed are what you scan by;
    the body is what you scan FOR, and only ever one at a time.

    Each stays its own <form>, so saving one still posts one.
--}}
<div class="row">
    <div class="col-xl-12 col-md-12 mb-12">
        <div class="accordion" id="templateList">
            @foreach($templates as $template)
                @php $slug = Str::slug($template->key); @endphp
                <div class="accordion-item">
                    <h2 class="accordion-header" id="heading-{{ $slug }}">
                        <button class="accordion-button collapsed" type="button"
                                data-bs-toggle="collapse" data-bs-target="#collapse-{{ $slug }}"
                                aria-expanded="false" aria-controls="collapse-{{ $slug }}">
                            <span class="d-flex flex-wrap align-items-center gap-2 me-3">
                                <code>{{ $template->key }}</code>
                                <span>{{ $template->name }}</span>
                                <span class="badge bg-secondary">{{ $template->channel }}</span>
                                @if($template->updated_at)
                                    <small class="text-muted">
                                        changed {{ $template->updated_at->diffForHumans() }}
                                    </small>
                                @endif
                            </span>
                        </button>
                    </h2>

                    <div id="collapse-{{ $slug }}" class="accordion-collapse collapse"
                         aria-labelledby="heading-{{ $slug }}" data-bs-parent="#templateList">
                        <div class="accordion-body">
                            @if($template->description)
                                <p class="text-muted">{{ $template->description }}</p>
                            @endif

                            <form method="POST" action="{{ route('vatssa.admin.templates.update', $template->key) }}">
                                @csrf
                                @method('PATCH')

                                @if($template->channel === 'email')
                                    <div class="mb-3">
                                        <label class="form-label" for="subject-{{ $slug }}">Subject</label>
                                        <input type="text" class="form-control" id="subject-{{ $slug }}"
                                               name="subject" value="{{ old('subject', $template->subject) }}"
                                               maxlength="255">
                                    </div>
                                @endif

                                <div class="mb-3">
                                    <label class="form-label" for="body-{{ $slug }}">Body</label>
                                    <textarea class="form-control font-monospace" id="body-{{ $slug }}"
                                              name="body" rows="10">{{ old('body', $template->body) }}</textarea>
                                </div>

                                @if($template->placeholders())
                                    <p class="mb-3">
                                        <small class="text-muted">
                                            Placeholders in use:
                                            @foreach($template->placeholders() as $placeholder)
                                                <code>&#123;{{ $placeholder }}&#125;</code>@if(! $loop->last), @endif
                                            @endforeach
                                        </small>
                                    </p>
                                @endif

                                <div class="d-flex align-items-center justify-content-between">
                                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                    @if($template->updated_at)
                                        <small class="text-muted">
                                            Last changed {{ $template->updated_at->diffForHumans() }}@isset($template->editor) by {{ $template->editor->name }}@endisset
                                        </small>
                                    @endif
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

@endsection
