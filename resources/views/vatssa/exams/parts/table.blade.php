{{--
    VATSSA: a list of exams. Expects: $exams.

    The stage carries the colour and the row does not. Colouring the row would
    make every open exam shout equally, which is the same as none of them
    shouting -- and the one thing that MUST shout is an exam inside the notice
    period with paperwork outstanding, which is what the seven-day rule exists
    to catch.
--}}
@php
    $variant = fn (string $tone) => match ($tone) {
        'brand' => 'bg-primary',
        'good' => 'bg-success',
        'warn' => 'bg-warning text-dark',
        default => 'bg-secondary',
    };
@endphp

<div class="table-responsive">
    <table class="table table-sm table-hover table-leftpadded mb-0">
        <thead class="table-light">
            <tr>
                <th>Student</th>
                <th>Rating</th>
                <th>Stage</th>
                <th>Waiting on</th>
                <th>When</th>
                <th>Raised</th>
            </tr>
        </thead>
        <tbody>
            @foreach($exams as $exam)
                <tr>
                    <td>
                        <a href="{{ route('vatssa.exams.show', $exam) }}">
                            {{ $exam->training?->user?->name ?? 'Unknown' }}
                        </a>
                        @if($exam->noticeBreached())
                            <small class="d-block text-danger">
                                inside {{ \App\Models\Vatssa\Exam::NOTICE_DAYS }} days and not published
                            </small>
                        @endif
                    </td>
                    <td>{{ $exam->training?->ratings->pluck('name')->join(' + ') ?: '—' }}</td>
                    <td>
                        <span class="badge {{ $variant($exam->stage->tone()) }}">
                            {{ $exam->stage->label() }}
                        </span>
                    </td>
                    <td class="text-muted">{{ $exam->stage->waitingOn() ?? '—' }}</td>
                    <td>
                        @if($exam->scheduled_for)
                            {{ $exam->scheduled_for->format('D j M · H:i') }}z
                        @else
                            <span class="text-muted">not booked</span>
                        @endif
                    </td>
                    <td class="text-muted">{{ $exam->created_at?->diffForHumans(null, true) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
