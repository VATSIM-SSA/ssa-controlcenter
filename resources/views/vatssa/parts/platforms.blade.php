{{--
    VATSSA: platforms and theory, on the user profile.

    Control Center has no idea whether somebody is on Discord, and knows Moodle
    only as a link. Both are mandatory to train here, so "are they actually
    reachable" is a question staff ask constantly and could never answer without
    opening two other systems.

    Three deliberate details:

    * `checked_at` is always shown. The sweep is daily, so a flat "not on
      Discord" from a stale check is worse than no answer -- a reader who can
      see the age can judge it.
    * "Not a VATSIM member" is its own state, not a missing tick. It means the
      Discord account resolves to no CID: a bot, or a test account.
    * Theory results are keyed to the PERSON, not to a training. Somebody who
      passed S2, went inactive, had the training closed and came back shows the
      same pass -- the training looks the result up, it does not own it.

    Expects: $user
--}}
@php
    $platforms = \App\Models\Vatssa\UserPlatform::find($user->id);
    $attempts = Auth::user()->can('training.results.view')
        ? \App\Models\Vatssa\TheoryAttempt::where('user_id', $user->id)
            ->orderByDesc('taken_at')->get()
        : collect();
    $showGrades = Auth::user()->can('training.results.grades');
@endphp

<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-white">
            <i class="fas fa-plug"></i>&nbsp;Platforms
        </h6>
        @if($platforms?->checked_at)
            <span class="badge {{ $platforms->isStale() ? 'bg-warning text-dark' : 'bg-light text-dark' }}"
                  data-bs-toggle="tooltip"
                  title="{{ $platforms->checked_at->toEuropeanDateTime() }}{{ $platforms->isStale() ? ' — the daily check has not run recently, so treat this as unconfirmed' : '' }}">
                checked {{ $platforms->checked_at->diffForHumans() }}
            </span>
        @endif
    </div>
    <div class="card-body">
        @if($platforms === null)
            <p class="mb-0 text-muted">
                Not checked yet. The pipeline writes this on its daily sweep.
            </p>
        @elseif(! $platforms->vatsim_member)
            <p class="mb-0">
                <span class="badge bg-secondary">Not a VATSIM member</span>
            </p>
            <small class="text-muted">
                This Discord account resolves to no CID — a bot, or a test
                account. It holds no membership, position or roster roles.
            </small>
        @else
            <dl class="mb-0">
                <dt>Discord</dt>
                <dd>
                    @if($platforms->on_discord)
                        <span class="badge bg-success"><i class="fas fa-check"></i> On the server</span>
                        @if($platforms->discord_user_id)
                            <small class="text-muted d-block">{{ $platforms->discord_user_id }}</small>
                        @endif
                    @else
                        <span class="badge bg-danger"><i class="fas fa-xmark"></i> Not on the server</span>
                    @endif
                </dd>

                <dt>Moodle</dt>
                <dd class="mb-0">
                    @if($platforms->on_moodle)
                        <span class="badge bg-success"><i class="fas fa-check"></i> Registered</span>
                    @else
                        <span class="badge bg-danger"><i class="fas fa-xmark"></i> No account</span>
                    @endif
                </dd>
            </dl>
        @endif
    </div>
</div>

@can('training.results.view')
    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 fw-bold text-white">
                <i class="fas fa-file-pen"></i>&nbsp;Theory
            </h6>
        </div>
        <div class="card-body {{ $attempts->isEmpty() ? '' : 'p-0' }}">
            @if($attempts->isEmpty())
                <p class="mb-0 text-muted">No theory attempts recorded.</p>
            @else
                <table class="table table-sm table-striped table-leftpadded mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Rating</th>
                            <th>Sat</th>
                            <th>Result</th>
                            @if($showGrades)
                                <th>Mark</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Newest first, and the top row for each rating is the
                             one that counts: latest, not best. --}}
                        @foreach($attempts as $attempt)
                            <tr>
                                <td>{{ $attempt->rating }}</td>
                                <td>
                                    <span data-bs-toggle="tooltip" title="{{ $attempt->taken_at->toEuropeanDate() }}">
                                        {{ $attempt->taken_at->diffForHumans() }}
                                    </span>
                                </td>
                                <td>
                                    @if($attempt->passed)
                                        <span class="badge bg-success">Passed</span>
                                    @else
                                        <span class="badge bg-danger">Failed</span>
                                    @endif
                                </td>
                                @if($showGrades)
                                    <td>{{ $attempt->grade === null ? '—' : rtrim(rtrim(number_format($attempt->grade, 1), '0'), '.') . '%' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endcan
