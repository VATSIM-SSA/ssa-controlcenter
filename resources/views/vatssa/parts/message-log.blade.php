{{--
    VATSSA: every email this student has received, on their training.

    Control Center emails students for its own lifecycle events and the pipeline
    bot emails them for the pipeline's. Neither knew what the other had sent, so
    until now nobody could answer "what has this student actually been told?"

    Both send through Brevo, so the bot polls Brevo's event feed and writes what
    it finds here -- Control Center's own mail included.

    SUBJECTS ONLY, NEVER BODIES. The log records that an email arrived, what
    kind it was and when. The CPT mark in particular must never appear: the
    result is emailed, and this says only that it was sent.

    The value is the awkward conversation. When a student says they were never
    told, the answer is a list with dates rather than somebody's memory.

    Expects: $training
--}}
@php
    $messages = \App\Models\Vatssa\MessageLog::where('training_id', $training->id)
        ->orderByDesc('sent_at')
        ->limit(50)
        ->get();
@endphp

<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-white">
            <i class="fas fa-envelope"></i>&nbsp;Emails sent
        </h6>
        @if($messages->isNotEmpty())
            <span class="badge bg-light text-dark">{{ $messages->count() }}</span>
        @endif
    </div>
    <div class="card-body {{ $messages->isEmpty() ? '' : 'p-0' }}">
        @if($messages->isEmpty())
            <p class="mb-0 text-muted">
                Nothing logged yet. The pipeline writes this from the mail
                provider's delivery feed, so it fills in shortly after an email
                actually leaves.
            </p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover table-leftpadded mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Sent</th>
                            <th>Subject</th>
                            <th>Kind</th>
                            <th>From</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($messages as $message)
                            <tr>
                                <td>
                                    <span data-bs-toggle="tooltip" title="{{ $message->sent_at->toEuropeanDateTime() }}">
                                        {{ $message->sent_at->diffForHumans() }}
                                    </span>
                                </td>
                                <td>{{ $message->subject }}</td>
                                <td><span class="badge bg-secondary">{{ $message->kindLabel() }}</span></td>
                                <td>
                                    {{-- Which system sent it. Worth showing: a
                                         student chased twice usually means both
                                         did, and that is a configuration bug. --}}
                                    @if($message->sentByBot())
                                        <span class="badge bg-info text-dark">Pipeline</span>
                                    @else
                                        <span class="badge bg-light text-dark">Control Center</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
