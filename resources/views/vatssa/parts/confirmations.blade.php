{{--
    VATSSA: every deadline this student has been given, and whether they met it.

    ## One table out of two sources

    Upstream's `training_interests` covers the interest confirmation. The three
    platform deadlines the pipeline enforces -- join Discord, join Moodle, pass
    the theory -- live in `vatssa_confirmations`. They are unioned here rather
    than merged in the database, because copying interest rows across would
    create two records of one fact that can disagree.

    The block used to be called "Training Interest Confirmations" and showed a
    quarter of the picture, which is worse than showing none: a coordinator read
    an empty-looking history and concluded nobody had been chased.

    ## The type column is the point

    Four rows all saying "confirmed 3 May" are unreadable without it, and the
    order of the list is the order a student actually meets them, so the table
    reads as a journey rather than as a pile of records.

    Expects: $training, $trainingInterests
--}}
@php
    use App\Models\Vatssa\Confirmation;

    // Interest rows, mapped into the shape the table draws. Not saved, not
    // Confirmation models -- a plain object, so nothing here can ever write
    // back to a table it does not own.
    $rows = $trainingInterests->map(fn ($i) => (object) [
        'label' => 'Interest',
        'sent_at' => $i->created_at,
        'deadline' => $i->deadline,
        'confirmed_at' => $i->confirmed_at,
        'expired' => (int) $i->expired,
        'reminders' => null,
    ]);

    $rows = $rows->concat(
        Confirmation::where('training_id', $training->id)->get()->map(fn ($c) => (object) [
            'label' => $c->label(),
            'sent_at' => $c->sent_at,
            'deadline' => $c->deadline,
            'confirmed_at' => $c->confirmed_at,
            'expired' => $c->expired,
            'reminders' => $c->reminders,
        ])
    )->sortByDesc('sent_at')->values();
@endphp

<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-white">
            <i class="fas fa-clipboard-check"></i>&nbsp;Confirmations
        </h6>
        <span class="badge bg-light text-dark">{{ $rows->count() }}</span>
    </div>

    <div class="card-body {{ $rows->isEmpty() ? '' : 'p-0' }}">
        @if($rows->isEmpty())
            <p class="mb-0 text-muted">Nothing has been asked of this student yet.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-leftpadded mb-0" width="100%" cellspacing="0">
                    <thead class="table-light">
                        <tr>
                            <th>Type</th>
                            <th>Sent</th>
                            <th>Deadline</th>
                            <th>Confirmed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td>
                                    {{ $row->label }}
                                    @if($row->reminders)
                                        {{-- How many times we chased. The first
                                             question asked when somebody appeals
                                             a removal, and the one nobody could
                                             answer without the bot's owner. --}}
                                        <small class="text-muted d-block">
                                            chased {{ $row->reminders }}&times;
                                        </small>
                                    @endif
                                </td>
                                <td>{{ $row->sent_at->toEuropeanDate() }}</td>
                                <td>
                                    {{ $row->deadline->toEuropeanDate() }}
                                    @if(! $row->confirmed_at && $row->expired === 0 && $row->deadline->isPast())
                                        {{-- Past its deadline but not yet swept.
                                             Saying "missed" here would claim the
                                             sweep is instant; saying nothing
                                             would hide the row that most needs
                                             looking at today. --}}
                                        <small class="text-warning d-block">overdue</small>
                                    @endif
                                </td>
                                <td>
                                    @if($row->confirmed_at)
                                        <i class="fas fa-check text-success"></i>&nbsp;{{ $row->confirmed_at->toEuropeanDate() }}
                                    @elseif($row->expired === 1)
                                        <i class="fas fa-times text-warning"></i>&nbsp;Invalidated
                                    @elseif($row->expired)
                                        <i class="fas fa-times text-danger"></i>&nbsp;Not confirmed
                                    @else
                                        <i class="fas fa-hourglass text-warning"></i>&nbsp;Awaiting
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
