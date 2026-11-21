{{-- VATSSA: staff-only training flags (Requires attention, Stalled). Included from vatssa/training/theory-button.blade.php. --}}
@can('toggleTrainingFlags', $training)
    {{-- The timeline version this page saw: a change from an older page is refused. --}}
    @php
        $vatssaFlagVersion = app(\App\Services\VatssaTrainingFlags::class)->version($training);
    @endphp
    <form method="POST" action="{{ route('training.action.attention', $training->id) }}" class="d-inline">
        @csrf
        <input type="hidden" name="version" value="{{ $vatssaFlagVersion }}">
        @if($training->attention_at)
            <input type="hidden" name="attention" value="0">
            <button type="submit" onclick="return confirm('Clear the requires attention flag on this training?')" class="btn btn-outline-warning"><i class="fas fa-check"></i> Clear attention</button>
        @else
            <input type="hidden" name="attention" value="1">
            <button type="submit" onclick="return confirm('Mark this training as requiring attention?')" class="btn btn-warning"><i class="fas fa-triangle-exclamation"></i> Mark as requiring attention</button>
        @endif
    </form>
    <form method="POST" action="{{ route('training.action.stalled', $training->id) }}" class="d-inline">
        @csrf
        <input type="hidden" name="version" value="{{ $vatssaFlagVersion }}">
        @if($training->stalled_at)
            <input type="hidden" name="stalled" value="0">
            <button type="submit" onclick="return confirm('Clear the stalled flag on this training?')" class="btn btn-outline-danger"><i class="fas fa-play"></i> Clear stalled</button>
        @else
            <input type="hidden" name="stalled" value="1">
            <button type="submit" onclick="return confirm('Mark this training as stalled?')" class="btn btn-danger"><i class="fas fa-hourglass-half"></i> Mark training as stalled</button>
        @endif
    </form>
@endcan
