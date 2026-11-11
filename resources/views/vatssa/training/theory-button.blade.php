{{-- VATSSA: staff-only theory mark. Included from training/show.blade.php. --}}
@can('toggleTheoryCompleted', $training)
    <form method="POST" action="{{ route('training.action.theory', $training->id) }}" class="d-inline">
        @csrf
        @if($training->theory_completed)
            <input type="hidden" name="completed" value="0">
            <button type="submit" onclick="return confirm('Mark the theory as not completed?')" class="btn btn-outline-secondary"><i class="fas fa-xmark"></i> Mark theory as not completed</button>
        @else
            <input type="hidden" name="completed" value="1">
            <button type="submit" onclick="return confirm('Mark the theory as completed?')" class="btn btn-success"><i class="fas fa-check"></i> Mark theory as completed</button>
        @endif
    </form>
@endcan
