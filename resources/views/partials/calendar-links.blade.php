{{-- Copy field and one-click subscribe buttons for a calendar feed. Expects $links (feed, webcal, google, outlook) and $id. --}}
<label class="form-label" for="{{ $id }}-url">{{ $label ?? 'Calendar link' }}</label>
<div class="input-group mb-3">
    <input type="text" class="form-control font-monospace" id="{{ $id }}-url" value="{{ $links['feed'] }}" readonly onclick="this.select()">
    <button class="btn btn-outline-secondary calendar-copy" type="button" data-copy="{{ $links['feed'] }}">
        <i class="fas fa-copy"></i> <span>Copy</span>
    </button>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ $links['google'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
        <i class="fab fa-google"></i> Google Calendar
    </a>
    <a href="{{ $links['outlook'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
        <i class="fab fa-microsoft"></i> Outlook
    </a>
    <a href="{{ $links['webcal'] }}" class="btn btn-outline-primary btn-sm">
        <i class="fab fa-apple"></i> Apple Calendar
    </a>
</div>
