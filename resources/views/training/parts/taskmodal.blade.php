<div class="modal fade" id="{{ Str::camel($requestType->getName()) }}" tabindex="-1" aria-labelledby="{{ Str::camel($requestType->getName()) }}Label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="{{ Str::camel($requestType->getName()) }}Label">Request</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">

                <form action="{{ route('task.store') }}" method="POST">

                    @csrf
                
                    <div class="alert alert-primary">
                        @if($requestType->showConnectedRatings())
                            <i class="fas {{ $requestType->getIcon() }}"></i> {{ $requestType->getName() }} for <b>{{ $training->getInlineRatings(true) }}</b> rating
                        @else
                            <i class="fas {{ $requestType->getIcon() }}"></i> {{ $requestType->getName() }}
                        @endif
                    </div>

                    {{-- VATSSA: a DESK, not a name.

                         Upstream asks who to send this to and offers every user
                         holding any role. That works while everybody knows the
                         org chart and fails quietly afterwards -- the request
                         lands on whoever came to mind and sits unread.

                         ONLY THE DESKS THIS TRAINING MAY USE. The global desks
                         (membership, training manager, leadership) always, plus
                         the coordinator for this student's OWN rating. Offering
                         the S1 coordinator from an S2 training is how a request
                         reaches somebody with no business with that student.

                         Some types have no choice at all: a rating upgrade is
                         always membership work.

                         assignee_user_id is still posted because upstream's
                         validation requires a real user and the column is NOT
                         NULL. It is the requester, and the observer replaces
                         it. --}}
                    @php
                        $tierField = Str::camel($requestType->getName()) . 'Tier';
                        $fixedTier = config('vatssa.fixed_desks.' . $requestType::class);
                        $choices = \App\Models\Vatssa\RequestTarget::choicesForTraining($training);
                    @endphp

                    <div class="mt-3">
                        @if($fixedTier)
                            <label class="form-label">Goes to</label>
                            <div class="alert alert-secondary py-2 mb-0">
                                <i class="fas fa-arrow-right"></i>&nbsp;
                                <strong>{{ \App\Models\Vatssa\RequestTarget::label($fixedTier) }}</strong>
                                <small class="d-block">This request always goes here.</small>
                            </div>
                            <input type="hidden" name="vatssa_tier" value="{{ $fixedTier }}">
                        @else
                            <label class="form-label">Send request to</label>
                            @foreach($choices as $choiceKey => $choice)
                                @php [$tierOnly, $ratingOnly] = array_pad(explode(':', $choiceKey, 2), 2, null); @endphp
                                <input type="radio" class="btn-check" required
                                       name="vatssa_tier" value="{{ $tierOnly }}"
                                       id="{{ $tierField }}{{ $loop->index }}"
                                       data-rating="{{ $ratingOnly }}"
                                       autocomplete="off"
                                       @checked($loop->first)>
                                <label class="btn btn-outline-primary btn-sm mb-1 text-start w-100"
                                       for="{{ $tierField }}{{ $loop->index }}">
                                    {{ $choice['label'] }}
                                    <small class="d-block fw-normal">{{ $choice['hint'] }}</small>
                                </label>
                            @endforeach
                        @endif

                        <div class="mt-3">
                            <input type="hidden" name="type" value="{{ $requestType::class }}">
                            <input type="hidden" name="subject_user_id" value="{{ $training->user->id }}">
                            <input type="hidden" name="subject_training_id" value="{{ $training->id }}">
                            <input type="hidden" name="assignee_user_id" value="{{ Auth::id() }}">
                        </div>
                    </div>

                    @if($requestType->requireCheckboxConfirmation() !== false)
                        <div class="mt-1">
                            <input class="form-check-input" type="checkbox" id="{{ Str::camel($requestType->getName()) }}Checkbox" required>
                            <label class="form-check-label" for="{{ Str::camel($requestType->getName()) }}Checkbox">
                                {{ $requestType->requireCheckboxConfirmation() }}
                            </label>
                        </div>
                    @endif

                    @if($requestType->requireRatingSelection() !== false && $training->ratings->whereNotNull('vatsim_rating')->count() > 1)
                        <div class="mt-3">
                            <label class="form-label" for="{{ Str::camel($requestType->getName()) }}Rating">Choose {{ Str::lower($requestType->getName()) }}</label>
                            <select class="form-select" id="{{ Str::camel($requestType->getName()) }}Rating" name="subject_training_rating_id" required>
                                @foreach($training->ratings->whereNotNull('vatsim_rating') as $rating)
                                    <option value="{{ $rating->id }}">{{ $rating->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @if($requestType->allowMessage())
                        <div class="mt-3">
                            <label class="form-label" for="{{ Str::camel($requestType->getName()) }}Message">Message</label>
                            <input type="text" class="form-control" id="{{ Str::camel($requestType->getName()) }}Message" name="message" minlength="3" maxlength="255" required>
                        </div>
                    @endif

                    <div class="modal-footer border-top-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Send request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>