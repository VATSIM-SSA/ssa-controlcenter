{{--
    VATSSA: what this training is, across the top of the page.

    The same move as the member profile: upstream ran these facts as a tall card
    down the left, which made them a COLUMN competing with the timeline beside
    it. Across the top they are a masthead instead -- read once, and everything
    below is about the training it named.

    Grouped by the question each answers rather than by the order the rows
    happened to be in: what stage is this at, who is it for, who runs it, and
    when did it happen. State leads because it changes what every tab under it
    means.

    Expects: $training, $types, $requestTypes, $showCompletionControl,
             $canCompletePartially
--}}
<div class="card shadow mb-4">
    <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between gap-3">
        <h6 class="m-0 fw-bold text-white">
            <i class="fas fa-flag"></i>&nbsp;{{ $training->user->first_name }}'s training for {{ $training->getInlineRatings() }}
        </h6>

        {{-- Every action for this training in one place.

             They were split between the card header and the bottom of the card
             body, which put "Request" and "Complete training" three hundred
             pixels apart on a page where they are the two things a coordinator
             most often came to do. --}}
        <span class="d-flex align-items-center gap-2">
                @can('create', [\App\Models\Task::class])
                    <button class="btn btn-light btn-icon dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-hand"></i> Request
                    </button>
                    <div class="dropdown">
                        <div class="dropdown-menu" aria-labelledby="dropdownMenuButton">
                            @foreach($requestTypes as $requestType)
                                {{-- VATSSA: and only the types that apply to
                                     THIS rating. An S1 controller holds no solo
                                     endorsement, so a solo request on an S1
                                     training can only ever be declined. See
                                     config/vatssa.php request_ratings. --}}
                                @php
                                    $onlyFor = config('vatssa.request_ratings.' . $requestType::class);
                                    $appliesHere = $onlyFor === null
                                        || $training->ratings->pluck('name')->intersect($onlyFor)->isNotEmpty();
                                @endphp
                                @if($appliesHere && ($requestType->allowNonVatsimRatings() == true || ($requestType->allowNonVatsimRatings() == false && $training->hasVatsimRatings() == true)))
                                    <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#{{ Str::camel($requestType->getName()) }}">
                                        <i class="fas {{ $requestType->getIcon() }}"></i>&nbsp;
                                        {{ $requestType->getName() }}
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endcan

            <div class="btn-group" role="group" aria-label="Training actions">
                @can('edit', [\App\Models\Training::class, $training])
                    <a href="{{ route('training.edit', $training->id) }}" class="btn btn-outline-primary btn-icon"><i class="fas fa-pencil"></i>&nbsp;Edit training</a>
                @endcan

                @if($showCompletionControl)
                    @can('update', $training)
                        <div class="btn-group" role="group">
                            <button class="btn btn-outline-success btn-icon dropdown-toggle" type="button" id="completionMenuButton" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-check me-1"></i>Complete training
                            </button>
                            <div class="dropdown-menu" aria-labelledby="completionMenuButton">
                                @if($canCompletePartially)
                                    <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#completePartialTraining">
                                        <i class="fas fa-list-check me-1"></i>Complete partial training
                                    </button>
                                @endif
                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#completeWholeTraining">
                                    <i class="fas fa-check me-1"></i>Mark training as completed
                                </button>
                            </div>
                        </div>
                    @endcan
                @endif
            </div>
        </span>
    </div>

    <div class="card-body">
        <div class="row g-4">

            {{-- Where this training has got to. --}}
            <div class="col-xl-3 col-md-6">
                <dl class="mb-0 copyable">
                    <dt>State</dt>
                    <dd>
                        <i class="{{ $training->status->icon() }} text-{{ $training->status->color() }}"></i>
                        {{ $training->status->label() }}
                        {{ isset($training->paused_at) ? ' (PAUSED)' : '' }}
                    </dd>

                    <dt>Type</dt>
                    <dd><i class="{{ $types[$training->type]["icon"] }} text-primary"></i>&ensp;{{ $types[$training->type]["text"] }}</dd>

                    <dt>Level</dt>
                    <dd class="mb-0">
                        @foreach($training->ratings as $rating)
                            <div>
                                @if($rating->pivot->completed_at)
                                    <i class="fas fa-check text-success"></i>&nbsp;{{ $rating->name }}
                                    <span class="text-muted">({{ \Carbon\Carbon::parse($rating->pivot->completed_at)->toEuropeanDate() }})</span>
                                @else
                                    {{ $rating->name }}
                                @endif
                            </div>
                        @endforeach
                    </dd>
                </dl>
            </div>

            {{-- Who it is for. --}}
            <div class="col-xl-3 col-md-6">
                <dl class="mb-0 copyable">
                    <dt>Vatsim ID</dt>
                    <dd>
                        <a href="{{ route('user.show', $training->user->id) }}">
                            {{ $training->user->id }}
                        </a>
                        <button type="button" onclick="navigator.clipboard.writeText('{{ $training->user->id }}')"><i class="fas fa-copy"></i></button>
                        <a href="https://stats.vatsim.net/stats/{{ $training->user->id }}" target="_blank" title="VATSIM Stats" class="link-btn me-1"><i class="fas fa-chart-simple"></i></a>
                        @if($training->user->division == 'EUD')
                            <a href="https://core.vateud.net/manage/controller/{{ $training->user->id }}/view" target="_blank" title="VATEUD Core Profile" class="link-btn"><i class="fa-solid fa-earth-europe"></i></a>
                        @endif
                    </dd>

                    <dt>Name</dt>
                    <dd><a href="{{ route('user.show', $training->user->id) }}">{{ $training->user->name }}</a><button type="button" onclick="navigator.clipboard.writeText('{{ $training->user->first_name.' '.$training->user->last_name }}')"><i class="fas fa-copy"></i></button></dd>

                    {{-- VATSSA: can we actually reach this person.
                         Beside their rating and their name, because that is
                         where it gets read. The full card is gone from this
                         page -- same facts, and one of them had to be the one
                         people trust. --}}
                    @include('vatssa.parts.platform-lines', ['user' => $training->user])
                </dl>
            </div>

            {{-- Who runs it. --}}
            <div class="col-xl-3 col-md-6">
                <dl class="mb-0 copyable">
                    <dt>Area</dt>
                    <dd>{{ $training->area->name }}</dd>

                    <dt>Mentor</dt>
                    <dd class="mb-0">{{ !empty($training->getInlineMentors()) ? $training->getInlineMentors() : '-' }}</dd>
                </dl>
            </div>

            {{-- When. --}}
            <div class="col-xl-3 col-md-6">
                <dl class="mb-0 copyable">
                    <dt>Period</dt>
                    <dd>
                        @if ($training->started_at == null && $training->closed_at == null)
                            Training not started
                        @elseif ($training->closed_at == null)
                            {{ $training->started_at->toEuropeanDate() }} -
                        @elseif ($training->started_at != null)
                            {{ $training->started_at->toEuropeanDate() }} - {{ $training->closed_at->toEuropeanDate() }}
                        @else
                            N/A
                        @endif
                    </dd>

                    <dt>Applied</dt>
                    <dd>{{ $training->created_at->toEuropeanDate() }}</dd>

                    <dt>Closed</dt>
                    <dd class="mb-0">
                        @if ($training->closed_at != null)
                            {{ $training->closed_at->toEuropeanDate() }}
                        @else
                            -
                        @endif
                    </dd>
                </dl>
            </div>

        </div>
    </div>
</div>
