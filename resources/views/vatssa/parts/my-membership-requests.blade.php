{{--
    VATSSA: a member's own membership requests.

    ## Visiting and transfer only

    Rating upgrade, staff inquiry and other are Terminal work the desk records
    ABOUT somebody. Nobody filed them. Listing one back as "your request" would
    show a member something they never asked for and invite them to ask why it
    is taking so long.

    That rule lives in `MembershipRequest::scopeFiledBy()` rather than in this
    view, so the query cannot be copied somewhere else without it.

    ## No internal detail

    State, type, date. Not the disciplinary finding, not the staff note, not who
    decided it -- those are the desk's record of its own reasoning, and a member
    page is not where they belong.

    Renders NOTHING when there is nothing, so it does not become an empty card
    on every dashboard in the division.

    Expects: $user
--}}
@php($myRequests = \App\Models\Vatssa\MembershipRequest::filedBy($user)->latest()->get())

@if($myRequests->isNotEmpty())
    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3">
            <h6 class="m-0 fw-bold text-white">Your membership requests</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-leftpadded mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Request</th>
                            <th>Asked</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($myRequests as $request)
                            <tr>
                                <td>
                                    <i class="fas {{ $request->type->icon() }}"></i>&nbsp;{{ $request->type->label() }}
                                </td>
                                <td>{{ $request->created_at->toEuropeanDate() }}</td>
                                <td>
                                    <span class="badge text-bg-{{ $request->state->color() }}">
                                        {{ $request->state->label() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
