{{--
    VATSSA: raise a request from the task screen.

    Upstream can only create a task from a training page, which means anything
    not about one student has nowhere to go and happens in Discord instead --
    "review the S2 syllabus", "check why the mentor index is stale", "this
    member wants to visit".

    Both the student and the training are optional here. That is the point.
--}}
<div class="modal fade" id="vatssaNewRequest" tabindex="-1" aria-labelledby="vatssaNewRequestLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="vatssaNewRequestLabel">Raise a request</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="{{ route('vatssa.requests.store') }}">
                @csrf
                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label" for="newRequestDesk">Which desk</label>
                        <select class="form-select" id="newRequestDesk" name="desk" required>
                            @foreach(\App\Models\Vatssa\RequestTarget::allChoices() as $key => $choice)
                                <option value="{{ $key }}">{{ $choice['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="newRequestType">Kind of request</label>
                        <select class="form-select" id="newRequestType" name="type" required>
                            @foreach(\App\Http\Controllers\TaskController::getTypes() as $type)
                                <option value="{{ $type::class }}">{{ $type->getName() }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">
                            Some kinds have a fixed desk and will ignore the choice above —
                            a rating upgrade always goes to membership.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="newRequestMessage">What is being asked</label>
                        <textarea class="form-control" id="newRequestMessage" name="message"
                                  rows="3" minlength="3" maxlength="256" required
                                  placeholder="Enough that somebody picking this up cold knows what to do."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="newRequestSubject">About a member (optional)</label>
                        <input class="form-control" type="number" id="newRequestSubject"
                               name="subject_user_id" placeholder="CID, or leave blank">
                        <small class="text-muted">
                            Leave blank for anything that is not about one person.
                        </small>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Raise request</button>
                </div>
            </form>
        </div>
    </div>
</div>
