{{--
    VATSSA: Discord and Moodle, on the user profile.

    Control Center has no idea whether somebody is on Discord, and knows Moodle
    only as a link. Both are mandatory to train here, so "are they actually
    reachable" is a question staff ask constantly and could never answer without
    opening two other systems.

    KEEP IT COMPACT -- two lines and a badge. It sits in a narrow column on both
    the profile and a training, beside `vatssa.parts.theory`, which is the wide
    one because it is a table.

    Two deliberate details:

    * `checked_at` is always shown. The sweep is daily, so a flat "not on
      Discord" from a stale check is worse than no answer -- a reader who can
      see the age can judge it.
    * "Not a VATSIM member" is its own state, not a missing tick. It means the
      Discord account resolves to no CID: a bot, or a test account.

    Expects: $user
--}}
@php
    $platforms = \App\Models\Vatssa\UserPlatform::find($user->id);
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
                Updated {{ $platforms->checked_at->diffForHumans() }}
            </span>
        @endif
    </div>
    <div class="card-body">
        @if($platforms === null)
            <p class="mb-0 text-muted">
                Not checked yet. The pipeline writes this on its daily sweep.
            </p>
        @elseif(! $platforms->vatsim_member)
            <span class="badge bg-secondary">Not a VATSIM member</span>
            <small class="text-muted d-block mt-2">
                Resolves to no CID — a bot, or a test account. Holds no
                membership, position or roster roles.
            </small>
        @else
            <dl class="mb-0">
                <dt>Discord</dt>
                <dd class="{{ $platforms->on_discord ? '' : 'mb-0' }}">
                    @if($platforms->on_discord)
                        <span class="badge bg-success"><i class="fas fa-check"></i> On the server</span>
                        @if($platforms->discord_user_id)
                            {{-- Labelled, because an unexplained eighteen-digit
                                 number means nothing to most people. text-break
                                 so it wraps rather than widening the column. --}}
                            <small class="text-muted d-block text-break">
                                Discord profile ID: {{ $platforms->discord_user_id }}
                            </small>
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
