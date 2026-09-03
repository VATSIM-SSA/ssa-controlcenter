<?php

namespace App\Http\Controllers\Vatssa;

use App\Helpers\Vatssa\MembershipRequestState;
use App\Helpers\Vatssa\MembershipRequestType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vatssa\MembershipRequest;
use App\Services\Vatssa\MembershipCheck;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * VATSSA: the membership desk.
 *
 * Three queues, and the split is not cosmetic:
 *
 *   open      what the desk has to act on
 *   training  approved, actioned on Terminal, waiting on familiarisation
 *   closed    finished, and withdrawn
 *
 * Pending training is deliberately NOT in the open queue. The request is alive,
 * but the next move belongs to the training pipeline -- mixing it in is how a
 * desk stops trusting its own queue length. Awaiting member feedback is off the
 * desk for the same reason from the other side.
 *
 * ## Reading and deciding are separate permissions
 *
 * `membership.requests.view` opens the queue and a request; `.manage` is
 * required to change anything. The ATC training manager holds the first and not
 * the second, because they may assign a visiting endorsement on the strength of
 * the membership team's Terminal check and so must be able to see whether it
 * came back clean -- without being able to decide the request themselves.
 */
class MembershipAdminController extends Controller
{
    /** The three queues, and the scope each one means. */
    private const QUEUES = ['open', 'training', 'closed'];

    public function index(Request $request, string $queue = 'open'): View
    {
        $this->authorize('membership.requests.view');

        abort_unless(in_array($queue, self::QUEUES, true), 404);

        $requests = MembershipRequest::query()
            ->with(['user', 'rating', 'disciplinaryCheckedBy'])
            ->when($queue === 'open', fn ($q) => $q->onTheDesk())
            ->when($queue === 'training', fn ($q) => $q->pendingTraining())
            ->when($queue === 'closed', fn ($q) => $q->finished())
            // A type filter, because the desk works transfers and Terminal work
            // on different days and the two do not read alike.
            ->when(
                MembershipRequestType::tryFrom((string) $request->query('type')) !== null,
                fn ($q) => $q->where('type', $request->query('type'))
            )
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('vatssa.membership.index', [
            'requests' => $requests,
            'queue' => $queue,
            'counts' => [
                'open' => MembershipRequest::onTheDesk()->count(),
                'training' => MembershipRequest::pendingTraining()->count(),
                'closed' => MembershipRequest::finished()->count(),
            ],
        ]);
    }

    public function show(MembershipRequest $membershipRequest): View
    {
        $this->authorize('membership.requests.view');

        $membershipRequest->load(['user', 'rating', 'training', 'createdBy', 'disciplinaryCheckedBy', 'decidedBy']);

        return view('vatssa.membership.show', [
            'request' => $membershipRequest,
            // Live, not the stored snapshot. Both are shown: the snapshot says
            // what was true when they asked, this says what is true now, and a
            // decision six weeks later needs to be able to tell them apart.
            'requirements' => MembershipCheck::for($membershipRequest->user),
        ]);
    }

    /**
     * Record the Terminal disciplinary check.
     *
     * The one gate on a visiting endorsement, so it is its own action rather
     * than a field on a general edit form -- who checked, and when, must not be
     * settable as a side effect of saving something else.
     */
    public function recordCheck(Request $request, MembershipRequest $membershipRequest): RedirectResponse
    {
        $this->authorize('membership.terminal.log');

        $data = $request->validate([
            'clean' => 'required|boolean',
            // Required by the model when the check is not clean; validated here
            // too so the person gets a form error rather than a 500.
            'context' => 'required_if:clean,0|nullable|string|max:2000',
        ]);

        $membershipRequest->recordDisciplinaryCheck(
            (bool) $data['clean'],
            Auth::user(),
            $data['context'] ?? null,
        );

        return back()->with('success', $data['clean']
            ? 'Recorded: no disciplinary history found.'
            : 'Recorded, with the finding.');
    }

    /**
     * Move a request along.
     *
     * The valid destinations come from the TYPE, so a rating upgrade cannot be
     * pushed into "pending transfer complete" and a transfer cannot be quietly
     * dropped into the Terminal set.
     */
    public function transition(Request $request, MembershipRequest $membershipRequest): RedirectResponse
    {
        $this->authorize('membership.requests.manage');

        $allowed = array_column($membershipRequest->type->states(), 'value');

        $data = $request->validate([
            'state' => ['required', Rule::in($allowed)],
            'note' => 'nullable|string|max:2000',
        ]);

        $state = MembershipRequestState::from($data['state']);

        $membershipRequest->state = $state;
        $membershipRequest->closed_at = $state->isFinished() ? now() : null;

        if (array_key_exists('note', $data) && $data['note'] !== null) {
            $membershipRequest->note = $data['note'];
        }

        $membershipRequest->save();

        return back()->with('success', 'Moved to ' . $state->label() . '.');
    }

    /**
     * Raise one by hand.
     *
     * A first-class path, not a fallback. Most of this happens outside the
     * system today -- an email, a Discord message, a Terminal action somebody
     * took last week -- and a desk that cannot enter its own backlog will keep
     * a spreadsheet beside the application instead of using it.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('membership.requests.manage');

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'type' => ['required', Rule::in(array_column(MembershipRequestType::cases(), 'value'))],
            'note' => 'nullable|string|max:2000',
        ]);

        $membershipRequest = MembershipRequest::open(
            MembershipRequestType::from($data['type']),
            User::findOrFail($data['user_id']),
            Auth::user(),
            ['note' => $data['note'] ?? null],
        );

        return redirect()
            ->route('vatssa.membership.show', $membershipRequest)
            ->with('success', 'Request created.');
    }
}
