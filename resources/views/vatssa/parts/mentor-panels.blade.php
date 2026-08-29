{{--
    VATSSA: capacity and resources, on the mentor's own page.

    These were the reason a separate mentor portal was going to exist. They did
    not justify one: three authentication layers and a second source of truth,
    to show a number and half a dozen links, on a page Control Center already
    has and already fills with the right students.

    Expects: $user
--}}
@php
    $capacityLimit = \App\Models\Vatssa\MentorCapacity::limitFor($user->id);
    $capacityLoad = \App\Models\Vatssa\MentorCapacity::loadFor($user);
    $resources = \App\Models\Vatssa\Resource::forAudience();
    $capacityRequested = \App\Models\Task::where('subject_user_id', $user->id)
        ->where('type', \App\Tasks\Types\MentorCapacityRequest::class)
        ->where('status', \App\Helpers\TaskStatus::PENDING)
        ->exists();
@endphp

<div class="row">
    <div class="col-xl-5 col-lg-12 col-md-12">
        <div class="card shadow mb-4">
            <div class="card-header bg-primary py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-white">
                    <i class="fas fa-users"></i>&nbsp;Your capacity
                </h6>
                @if($capacityLimit !== null)
                    <span class="badge {{ $capacityLoad >= $capacityLimit ? 'bg-warning text-dark' : 'bg-light text-dark' }}">
                        {{ $capacityLoad }} of {{ $capacityLimit }}
                    </span>
                @endif
            </div>
            <div class="card-body">
                @if($capacityLimit === null)
                    <p class="mb-3">
                        You are running <strong>{{ $capacityLoad }}</strong>
                        {{ Str::plural('student', $capacityLoad) }}. No limit is set.
                    </p>
                @elseif($capacityLoad >= $capacityLimit)
                    <p class="mb-3">
                        You are at your limit of <strong>{{ $capacityLimit }}</strong>.
                        {{-- A limit, not a rule. Nothing blocks an assignment --
                             blocking one two people already agreed to in person
                             is not software's business. --}}
                        Nothing stops a coordinator assigning another; this is so
                        they can see you are full before they ask.
                    </p>
                @else
                    <p class="mb-3">
                        You have room for
                        <strong>{{ $capacityLimit - $capacityLoad }}</strong>
                        more.
                    </p>
                @endif

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
                                      placeholder="More, fewer, or a different rating — and roughly why."></textarea>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Send to the training manager</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-xl-7 col-lg-12 col-md-12">
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
                        Administration &rarr; Mentor resources.
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
