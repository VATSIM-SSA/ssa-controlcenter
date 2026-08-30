{{--
    VATSSA: capacity and resources, on the mentor's own page.

    These were the reason a separate mentor portal was going to exist. They did
    not justify one: three authentication layers and a second source of truth,
    to show a table and half a dozen links, on a page Control Center already has
    and already fills with the right students.

    EVERYTHING HERE IS READ-ONLY. A mentor asks for a change through the request
    desk and the ATC training manager decides. A self-service number beside that
    request would have made the request pointless.

    Expects: $user
--}}
@php
    $ceiling = \App\Models\Vatssa\MentorCeiling::find($user->id);
    $teachable = \App\Models\Vatssa\MentorCeiling::ratingsFor($user->id);
    $totalLoad = \App\Models\Vatssa\MentorCapacity::loadFor($user);
    $resources = \App\Models\Vatssa\Resource::forAudience();
    $capacityRequested = \App\Models\Task::where('subject_user_id', $user->id)
        ->where('type', \App\Tasks\Types\MentorCapacityRequest::class)
        ->where('status', \App\Helpers\TaskStatus::PENDING)
        ->exists();
@endphp

<div class="row">
    <div class="col-xl-8 col-lg-12 col-md-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-users"></i>&nbsp;Your capacity
                </h6>
                <span class="badge bg-light text-dark">
                    {{ $totalLoad }}{{ $ceiling?->total_limit !== null ? ' of ' . $ceiling->total_limit : '' }} students
                </span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-leftpadded mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Rating</th>
                                <th>Running</th>
                                <th>Limit</th>
                                <th>Room</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($teachable as $rating)
                                @php
                                    $limit = \App\Models\Vatssa\MentorCapacity::where('user_id', $user->id)
                                        ->where('rating_id', $rating->id)->value('student_limit');
                                    $load = \App\Models\Vatssa\MentorCapacity::loadFor($user, $rating->id);
                                    $room = \App\Models\Vatssa\MentorCapacity::roomFor($user, $rating->id);
                                @endphp
                                <tr>
                                    <td>{{ $rating->name }}</td>
                                    <td>{{ $load }}</td>
                                    <td>{{ $limit ?? '—' }}</td>
                                    <td>
                                        @if($room === null)
                                            <span class="text-muted">no limit</span>
                                        @elseif($room === 0)
                                            <span class="badge bg-warning text-dark">full</span>
                                        @else
                                            {{ $room }}
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-muted">
                                        No ratings are open to you yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-body">
                {{-- The ceiling, stated plainly. It is the thing a mentor most
                     often does not know about themselves, and the reason a
                     rating they expected to see is missing from the table. --}}
                <p class="mb-2">
                    <strong>{{ \App\Models\Vatssa\MentorCeiling::describeFor($user->id) }}.</strong>
                    @if($ceiling?->maxRating)
                        Ratings above this are not open to you. The ATC training
                        manager raises it.
                    @else
                        Every rating is open to you.
                    @endif
                </p>

                @if($capacityRequested)
                    <span class="badge bg-info text-dark">Capacity change already requested</span>
                @else
                    <form method="POST" action="{{ route('vatssa.requests.store') }}">
                        @csrf
                        <input type="hidden" name="type" value="{{ \App\Tasks\Types\MentorCapacityRequest::class }}">
                        <input type="hidden" name="desk" value="{{ \App\Models\Vatssa\RequestTarget::TRAINING_MANAGER }}">
                        <input type="hidden" name="subject_user_id" value="{{ $user->id }}">
                        <div class="mb-2">
                            <label class="form-label" for="capacityMessage">Ask to change it</label>
                            <textarea class="form-control" id="capacityMessage" name="message" rows="2"
                                      minlength="3" maxlength="256"
                                      placeholder="A higher ceiling, a different per-rating limit, or fewer students — and roughly why."></textarea>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Send to the training manager</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-xl-4 col-lg-12 col-md-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-folder-open"></i>&nbsp;Resources
                </h6>
            </div>
            <div class="card-body {{ $resources->isEmpty() ? '' : 'p-0' }}">
                @if($resources->isEmpty())
                    <p class="mb-0 text-muted">
                        No resources listed yet. An admin adds them under
                        Administration &rarr; Mentorship.
                    </p>
                @else
                    <div class="list-group list-group-flush">
                        @foreach($resources as $resource)
                            <a class="list-group-item list-group-item-action"
                               href="{{ $resource->url }}" target="_blank" rel="noopener">
                                <i class="fas {{ $resource->icon }}"></i>&nbsp;
                                {{ $resource->label }}
                                @if($resource->description)
                                    <small class="d-block text-muted">{{ $resource->description }}</small>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
