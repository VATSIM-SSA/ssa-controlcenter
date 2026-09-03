<?php

namespace App\Http\Controllers\Vatssa;

use App\Helpers\Vatssa\MembershipRequestType;
use App\Helpers\Vatssa\TerminalLogReason;
use App\Helpers\Vatssa\TerminalLogType;
use App\Http\Controllers\Controller;
use App\Models\Rating;
use App\Models\Vatssa\MembershipRequest;
use App\Models\Vatssa\TerminalComment;
use App\Models\Vatssa\TerminalLogEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * VATSSA: everything anybody has done on VATSIM Terminal.
 *
 * ## This is the audit surface, and it is read more than it is written
 *
 * The list is the point. CERT access has to be justifiable after the fact, so
 * the questions this page has to answer are "who looked at this person, and
 * why" and "what changed, and who changed it" -- which is why the filters are
 * type, member and actor rather than a search box.
 *
 * ## Nothing is written without somebody pressing a button
 *
 * Control Center cannot see Terminal, so it cannot know an action happened
 * there. Writing a row off the back of a Control Center action would be
 * recording a belief rather than an event. The pre-fill from a membership
 * request is a convenience; the confirmation is the record.
 */
class TerminalLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('membership.terminal.view');

        $entries = TerminalLogEntry::query()
            ->with(['user', 'actor', 'recordedBy', 'ratingFrom', 'ratingTo'])
            ->when(
                TerminalLogType::tryFrom((string) $request->query('type')) !== null,
                fn ($q) => $q->where('type', $request->query('type'))
            )
            ->when(
                TerminalLogReason::tryFrom((string) $request->query('reason')) !== null,
                fn ($q) => $q->where('reason', $request->query('reason'))
            )
            // A CID rather than a name search: this page is opened to answer a
            // question about one person, and a name search returns the wrong
            // Smith at exactly the wrong moment.
            ->when(
                $request->filled('cid'),
                fn ($q) => $q->where('user_id', $request->query('cid'))
            )
            ->orderByDesc('performed_at')
            ->paginate(50)
            ->withQueryString();

        // Pre-fill, when somebody arrived here from a membership request.
        // Empty otherwise, so the view has one thing to check.
        $prefill = $this->prefillFrom(
            $request->filled('request')
                ? MembershipRequest::find($request->query('request'))
                : null
        );

        return view('vatssa.terminal.index', [
            'entries' => $entries,
            'comments' => TerminalComment::offered()->get(),
            'ratings' => Rating::whereNotNull('vatsim_rating')->orderBy('vatsim_rating')->get(),
            'prefill' => $prefill,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('membership.terminal.log');

        $data = $request->validate([
            'type' => ['required', Rule::in(array_column(TerminalLogType::cases(), 'value'))],
            'reason' => ['required', Rule::in(array_column(TerminalLogReason::cases(), 'value'))],
            'user_id' => 'required|exists:users,id',

            // WHO DID IT, and the two ways of saying so.
            //
            // Either an account or a typed name, and the model throws without
            // one. Validated here as well so somebody who forgets gets a form
            // error rather than a stack trace -- the model guard exists because
            // the seeder and the bridge write these too, not because the form
            // is trusted to be the only caller.
            'actor_user_id' => 'nullable|exists:users,id',
            'actor_name' => 'required_without:actor_user_id|nullable|string|max:100',

            'membership_request_id' => 'nullable|exists:vatssa_membership_requests,id',
            'comment_code' => 'nullable|exists:vatssa_terminal_comments,code',
            'rating_from_id' => 'nullable|exists:ratings,id',
            'rating_to_id' => 'nullable|exists:ratings,id',

            // Null means this row was not a disciplinary check at all, which is
            // different from a check that found nothing.
            'discipline_found' => 'nullable|boolean',
            'discipline_context' => 'required_if:discipline_found,1|nullable|string|max:2000',

            'notes' => 'nullable|string|max:2000',
            // When it HAPPENED, which for a backfilled row is not today.
            'performed_at' => 'nullable|date|before_or_equal:now',
        ]);

        TerminalLogEntry::create([
            ...$data,
            // Never from the form. This is the answer to "who says this
            // happened", and it is not the same question as who did it.
            'recorded_by' => Auth::id(),
            'performed_at' => $data['performed_at'] ?? now(),
        ]);

        return back()->with('success', 'Logged.');
    }

    /**
     * A row pre-filled from a membership request.
     *
     * SERVER-SIDE, through a query parameter, rather than the JSON endpoint
     * this used to be. That endpoint was never called: the button that would
     * have called it did not exist, so it was an untested public surface
     * sitting behind a permission check for nothing.
     *
     * Offered, not written. Control Center cannot see Terminal, so it cannot
     * know the action happened -- the pre-fill is a convenience and the
     * confirmation is the record.
     *
     * @return array<string, mixed>
     */
    private function prefillFrom(?MembershipRequest $request): array
    {
        if ($request === null) {
            return [];
        }

        return [
            'user_id' => $request->user_id,
            'membership_request_id' => $request->id,
            'type' => match ($request->type) {
                MembershipRequestType::TRANSFER, MembershipRequestType::VISITING => TerminalLogType::TRANSFER_IN->value,
                default => TerminalLogType::CHANGE->value,
            },
            'reason' => match ($request->type) {
                MembershipRequestType::TRANSFER, MembershipRequestType::VISITING => TerminalLogReason::TRANSFER->value,
                MembershipRequestType::RATING_UPGRADE => TerminalLogReason::RATING_UPDATE->value,
                MembershipRequestType::STAFF_INQUIRY => TerminalLogReason::STAFF_CHECK->value,
                default => TerminalLogReason::DUPE_CHECK->value,
            },
            'rating_to_id' => $request->rating_id,
        ];
    }
}
