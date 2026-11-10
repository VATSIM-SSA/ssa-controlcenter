{{-- Feedback on a profile.
     - Your own profile: "Feedback about you", full width, lists everything
       staff sent you from any area, in full and without the submitter.
     - With feedback permissions, on any profile including your own:
       "Feedback received" and "Feedback submitted", within your feedback
       scope, side by side on wide screens (one alone takes the full width).
       Text is cut short; the entry's page shows it in full.
     Cards show even when empty, so it is clear there is nothing yet.
     Members without feedback permissions see nothing on other profiles. --}}
@php
    $feedbackTables = array_filter([
        $canViewFeedback ? ['id' => 'feedback-received', 'title' => 'Feedback received', 'entries' => $feedbackReceived, 'otherLabel' => 'From', 'other' => 'submitter', 'empty' => 'No feedback received.'] : null,
        $canViewFeedback ? ['id' => 'feedback-submitted', 'title' => 'Feedback submitted', 'entries' => $feedbackSubmitted, 'otherLabel' => 'About', 'other' => 'referenceUser', 'empty' => 'No feedback submitted.'] : null,
    ]);
    $feedbackCardClass = count($feedbackTables) > 1 ? 'col-xl-6 col-12' : 'col-12';
@endphp

@if($ownProfile || $feedbackTables !== [])
<div class="row">
    @if($ownProfile)
        <div class="col-12">
            <div class="card shadow mb-4" id="feedback-about-you">
                <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 fw-bold text-white">Feedback about you</h6>
                    <span class="badge bg-light text-dark">{{ $feedbackAboutYou->count() }}</span>
                </div>
                <div class="card-body">
                    @forelse($feedbackAboutYou as $entry)
                        <div class="{{ $loop->last ? '' : 'border-bottom pb-3 mb-3' }}">
                            <div class="small text-muted mb-1">
                                {{ $entry->acknowledgementContext() }}
                                @if($entry->latestAcknowledgement?->isPending())
                                    <span class="badge bg-warning text-dark ms-1">New</span>
                                @endif
                            </div>
                            <p class="mb-0 text-break">{!! nl2br(e($entry->sentText())) !!}</p>
                        </div>
                    @empty
                        <p class="mb-0 text-muted">No feedback has been sent to you.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    @foreach($feedbackTables as $section)
        <div class="{{ $feedbackCardClass }}">
            <div class="card shadow mb-4" id="{{ $section['id'] }}">
                <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 fw-bold text-white">{{ $section['title'] }}</h6>
                    <span class="badge bg-light text-dark">{{ $section['entries']->count() }}</span>
                </div>
                @if($section['entries']->isEmpty())
                    <div class="card-body">
                        <p class="mb-0 text-muted">{{ $section['empty'] }}</p>
                    </div>
                @else
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-leftpadded mb-0 align-middle" width="100%" cellspacing="0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>{{ $section['otherLabel'] }}</th>
                                        <th>Status</th>
                                        <th>Feedback</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($section['entries'] as $entry)
                                        <tr>
                                            <td class="text-nowrap small">{{ $entry->created_at->toEuropeanDate() }}</td>
                                            <td class="small">
                                                {{ $entry->{$section['other']}?->name ?? '—' }}
                                                @if($position = $entry->referencePosition?->callsign ?? $entry->session_callsign)
                                                    <span class="d-block text-muted">{{ $position }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-{{ $entry->status->color() }}">{{ $entry->status->label() }}</span>
                                                @if($entry->sentiment)
                                                    <span class="badge bg-{{ $entry->sentiment->color() }}">{{ $entry->sentiment->label() }}</span>
                                                @endif
                                                @if($entry->wasSent())
                                                    <small class="d-block text-muted">Sent · {{ $entry->latestAcknowledgement?->acknowledged_at ? 'acknowledged' : 'not acknowledged' }}</small>
                                                @endif
                                            </td>
                                            <td class="small text-break" style="min-width: 7rem">{{ \Illuminate\Support\Str::limit($entry->feedback, 60) }}</td>
                                            <td class="text-end pe-2"><a href="{{ route('feedback.show', $entry) }}" class="btn btn-sm btn-outline-primary">View</a></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endif
