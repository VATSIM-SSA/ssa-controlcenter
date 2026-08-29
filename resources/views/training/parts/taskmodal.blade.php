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
                         holding any role, plus the three most-used shortcuts.
                         That works while everybody knows the org chart and
                         fails quietly afterwards -- the request lands on
                         whoever came to mind and sits unread.

                         Who currently sits at each desk is set in
                         Administration -> Request routing. VatssaTaskObserver
                         resolves it on the way in, so a cached form cannot send
                         a request to somebody who left.

                         assignee_user_id is still posted because upstream's
                         validation requires a real user and the column is NOT
                         NULL. It is the requester, and the observer replaces
                         it. If a desk has nobody, the request stays with the
                         requester rather than vanishing. --}}
                    <div class="mt-3">
                        <label class="form-label">Send request to</label>
                        @php $tierField = Str::camel($requestType->getName()) . 'Tier'; @endphp

                        @foreach(\App\Models\Vatssa\RequestTarget::TIERS as $tierKey => $tier)
                            <div class="form-check">
                                <input class="form-check-input" type="radio" required
                                       name="vatssa_tier" value="{{ $tierKey }}"
                                       id="{{ $tierField }}{{ $loop->index }}"
                                       @checked($loop->first)>
                                <label class="form-check-label" for="{{ $tierField }}{{ $loop->index }}">
                                    {{ $tier['label'] }}
                                    <small class="d-block text-muted">{{ $tier['hint'] }}</small>
                                </label>
                            </div>
                        @endforeach

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