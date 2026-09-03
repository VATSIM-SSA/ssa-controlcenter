<?php

namespace App\Http\Controllers\Vatssa;

use App\Helpers\Vatssa\MembershipRequestType;
use App\Http\Controllers\Controller;
use App\Models\Vatssa\MembershipRequest;
use App\Services\Vatssa\MembershipCheck;
use App\Services\Vatssa\Requirement;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * VATSSA: asking to transfer here, or to visit.
 *
 * ## No public route, and that is the point
 *
 * Somebody outside the division can already log in -- authentication is VATSIM
 * OAuth through Handover, and `LoginController::completeLogin()` creates the
 * account from whatever VATSIM returns. `isMember()` is simply false for them.
 *
 * So this needs no unauthenticated form. That matters: an open form is a spam
 * surface and an identity problem, and both are avoided by asking somebody to
 * sign in with the CID the request is about.
 *
 * ## The four checks that BLOCK
 *
 * Everything else in MembershipCheck is shown to staff as a tick or a cross and
 * left to the person deciding, because TVCP 5.4 is approve-by-default: a
 * request is approved unless one of exactly three grounds applies, and a rule
 * that blocks silently is a rule nobody can appeal.
 *
 * These four are different. They are not judgements, they are facts that make
 * the request meaningless: already a member, already visiting, suspended, or
 * one already open.
 */
class MembershipRequestController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $type = MembershipRequestType::tryFrom((string) $request->query('type'));

        // Only the two a person may file for themselves. The other three are
        // Terminal work the desk records about somebody.
        if ($type === null || ! $type->isMemberFiled()) {
            abort(404);
        }

        if ($blocked = $this->blockerFor($type)) {
            return redirect()->route('dashboard')->withErrors($blocked);
        }

        return view('vatssa.membership.create', [
            'type' => $type,
            'requirements' => MembershipCheck::for(Auth::user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_column(MembershipRequestType::memberFiled(), 'value'))],
            'motivation' => 'required|string|max:2000',
        ]);

        $type = MembershipRequestType::from($data['type']);

        // Re-checked here, not only on the form. The form is a courtesy; this
        // is the gate, and a stale page or a crafted POST reaches only this.
        if ($blocked = $this->blockerFor($type)) {
            return redirect()->route('dashboard')->withErrors($blocked);
        }

        MembershipRequest::open($type, Auth::user(), Auth::user(), [
            'note' => $data['motivation'],
            // What was true WHEN THEY ASKED, so a decision six weeks later can
            // be read in the context it was made in.
            'checks' => $this->snapshot(),
        ]);

        return redirect()->route('dashboard')->with(
            'success',
            'Your ' . strtolower($type->label()) . ' has been received. We will be in touch.'
        );
    }

    /**
     * The reason this person cannot file this request, or null.
     *
     * Deliberately returns ONE reason rather than a list: unlike the
     * requirement list, every one of these is final, so there is nothing for
     * the reader to act on and no value in showing them four.
     */
    private function blockerFor(MembershipRequestType $type): ?string
    {
        $user = Auth::user();

        if ($user->isMember()) {
            return 'You are already a member of ' . config('app.owner_name_short') . '.';
        }

        if ($type === MembershipRequestType::VISITING && $user->isVisiting()) {
            return 'You already hold a visiting endorsement with us.';
        }

        if (MembershipRequest::hasOpenFor($user, $type)) {
            return 'You already have one of these open. We will be in touch.';
        }

        return null;
    }

    /**
     * The requirement list as stored data.
     *
     * Labels and verdicts only. Not the Requirement objects themselves: those
     * carry instructions written for the person reading the page today, and a
     * snapshot is meant to be readable in six weeks by somebody else.
     *
     * @return array<string, bool>
     */
    private function snapshot(): array
    {
        return MembershipCheck::for(Auth::user())
            ->mapWithKeys(fn (Requirement $r) => [$r->label => $r->met])
            ->all();
    }
}
