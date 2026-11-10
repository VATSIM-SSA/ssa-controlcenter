{{-- What to send the controller, for the send dialog. $prefix keeps element ids unique. --}}
@php($canSend = $feedback->referenceUser !== null)
@php($controllerName = $feedback->referenceUser?->name ?? 'The controller')

<p class="fw-bold mb-2">What should {{ $feedback->referenceUser?->name ?? 'the controller' }} receive?</p>

@unless($canSend)
    <div class="alert alert-warning py-2">No controller is set, so nothing can be sent. Use <b>Edit</b> to set one.</div>
@endunless

<div class="list-group mb-3">
    <label class="list-group-item d-flex gap-3 align-items-start {{ $canSend ? '' : 'disabled opacity-50' }}">
        <input class="form-check-input mt-1" type="radio" name="delivery" value="original" x-model="delivery" @disabled(! $canSend)>
        <span>
            <b>The feedback as written</b>
            <small class="d-block text-muted">They see the submitter's words, without the submitter's name.</small>
        </span>
    </label>
    <label class="list-group-item d-flex gap-3 align-items-start {{ $canSend ? '' : 'disabled opacity-50' }}">
        <input class="form-check-input mt-1" type="radio" name="delivery" value="rewritten" x-model="delivery" @disabled(! $canSend)>
        <span>
            <b>A rewritten version</b>
            <small class="d-block text-muted">Write what they should read, for example a respectful version of a rude comment. It stays linked to the original.</small>
        </span>
    </label>
</div>
@error('delivery')
    <div class="text-danger small mb-2">{{ $message }}</div>
@enderror

<div class="mb-3" x-show="delivery === 'rewritten'" x-cloak>
    <label class="form-label fw-bold" for="{{ $prefix }}-rewritten">Version to send</label>
    <textarea class="form-control @error('rewritten') is-invalid @enderror" id="{{ $prefix }}-rewritten" name="rewritten" rows="5" maxlength="16000">{{ old('rewritten', $feedback->feedback) }}</textarea>
    @error('rewritten')
        <div class="text-danger small">{{ $message }}</div>
    @enderror
</div>

<div>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="discord" value="1" id="{{ $prefix }}-discord" x-model="discord" @disabled(! $discordAvailable)>
        <label class="form-check-label {{ $discordAvailable ? '' : 'text-muted' }}" for="{{ $prefix }}-discord">
            <i class="fab fa-discord"></i> Also post it to Discord
            <small class="d-block text-muted">
                @if($discordAvailable)
                    Posts the version sent, with the controller's name and never the submitter's. Withdrawing deletes the post.
                @else
                    Not available: add a Discord webhook under Administration &rarr; Global settings &rarr; Feedback.
                @endif
            </small>
        </label>
    </div>

    <div class="alert alert-info py-2 small mb-3">
        <i class="fas fa-envelope"></i> {{ $controllerName }} gets an e-mail, and it stays on their dashboard until they acknowledge it.
        If you withdraw it later, the e-mail cannot be recalled.
    </div>
</div>
