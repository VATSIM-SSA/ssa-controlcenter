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

@foreach($templates as $template)
    <div class="row">
        <div class="col-xl-12 col-md-12 mb-12">
            <div class="card shadow mb-4">
                <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 fw-bold text-white">
                        {{ $template->key }} — {{ $template->name }}
                    </h6>
                    <span class="badge bg-light text-dark">{{ $template->channel }}</span>
                </div>
                <div class="card-body">
                    @if($template->description)
                        <p class="text-muted">{{ $template->description }}</p>
                    @endif

                    <form method="POST" action="{{ route('vatssa.admin.templates.update', $template->key) }}">
                        @csrf
                        @method('PATCH')

                        @if($template->channel === 'email')
                            <div class="mb-3">
                                <label class="form-label" for="subject-{{ $template->key }}">Subject</label>
                                <input type="text" class="form-control" id="subject-{{ $template->key }}"
                                       name="subject" value="{{ old('subject', $template->subject) }}" maxlength="255">
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label" for="body-{{ $template->key }}">Body</label>
                            <textarea class="form-control font-monospace" id="body-{{ $template->key }}"
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
    </div>
@endforeach

@endsection
