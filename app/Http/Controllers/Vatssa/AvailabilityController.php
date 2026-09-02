<?php

namespace App\Http\Controllers\Vatssa;

use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Models\User;
use App\Models\Vatssa\AvailabilityPoll;
use App\Models\Vatssa\AvailabilityResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * VATSSA: availability, as a thing the division owns.
 *
 * ## Why not Rallly or Crab.fit
 *
 * Both are good and both are open source. Neither has a documented API, and
 * every use we have for availability is a use where something else READS it:
 * the CPT workflow pings examiners with a student's free slots, the events
 * team clears them against plans that are not published yet, and a
 * confirmation writes a booking. A tool the rest of the system cannot query is
 * a screenshot.
 *
 * Control Center also already knows who everybody is, holds the examiner
 * endorsements, and owns the bookings calendar. The integration work to make a
 * third party fit was larger than the feature.
 *
 * ## Three purposes, one grid
 *
 * A CPT, a mentoring session, a staff meeting. They differ in who is asked and
 * what happens when it is settled, not in how somebody says when they are
 * free -- so they share a grid and diverge afterwards.
 */
class AvailabilityController extends Controller
{
    /**
     * Everything this person is being asked about.
     */
    public function index(): View
    {
        $mine = AvailabilityPoll::query()
            ->whereHas('responses', fn ($q) => $q->where('user_id', Auth::id()))
            ->orWhere('created_by', Auth::id())
            ->orWhereHas('training', fn ($q) => $q->where('user_id', Auth::id()))
            ->with('training.user', 'responses')
            ->latest()
            ->get();

        return view('vatssa.availability.index', [
            'open' => $mine->filter->isOpen(),
            'settled' => $mine->reject->isOpen(),
            // For the "who to ask" picker. Ordered by name because that is how
            // somebody looks for a person; the CID is shown beside it because
            // that is how they confirm they found the right one.
            'members' => User::orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function show(AvailabilityPoll $poll): View
    {
        $poll->load('responses.user', 'training.user');

        // A poll is a list of when named members are at home. Not secret, not
        // nothing either -- see AvailabilityPoll::isVisibleTo.
        abort_unless($poll->isVisibleTo(Auth::user()), 403);

        return view('vatssa.availability.show', [
            'poll' => $poll,
            'members' => User::orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    /**
     * Ask a group when they are free.
     *
     * A month by default: long enough to find a slot, short enough that nobody
     * is guessing at a date they cannot picture.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            // Rule::in over the map, so adding a purpose is one line in the
            // model and one option in the form -- and a hand-crafted POST
            // cannot invent one.
            'purpose' => ['required', Rule::in(array_keys(AvailabilityPoll::PURPOSES))],
            'visibility' => ['nullable', Rule::in(array_keys(AvailabilityPoll::VISIBILITIES))],
            'description' => 'nullable|string|max:1000',
            'starts_on' => 'nullable|date',
            'weeks' => ['nullable', 'integer', 'min:1', 'max:' . config('vatssa.availability.max_weeks', 8)],
            'training_id' => 'nullable|exists:trainings,id',
            // Who is being asked. A response row is the invitation, so this is
            // also what "only these few people" means.
            'participants' => 'nullable|array|max:100',
            'participants.*' => 'integer|exists:users,id',
        ]);

        // A poll attached to a training becomes visible to that student and
        // their mentors, and shows on the student's own availability list.
        // `exists:trainings,id` only proves the id is real -- without this,
        // anybody could hang a poll with any title off any member's training.
        if (! empty($data['training_id'])) {
            $training = Training::find($data['training_id']);

            abort_unless($training && Auth::user()->can('view', $training), 403);
        }

        $from = CarbonImmutable::parse($data['starts_on'] ?? 'tomorrow')->startOfDay();

        $poll = AvailabilityPoll::create([
            'title' => $data['title'],
            'purpose' => $data['purpose'],
            'description' => $data['description'] ?? null,
            'starts_on' => $from,
            // (int), because form input is a STRING. Carbon's addWeeks()
            // declares int|float and throws a TypeError on "4" -- validation
            // said `integer`, which checks the value is numeric and does not
            // change its type.
            'ends_on' => $from->addWeeks((int) ($data['weeks'] ?? 4))->subDay(),
            'training_id' => $data['training_id'] ?? null,
            'created_by' => Auth::id(),
            // Half an hour for a session or an exam; an hour for a meeting,
            // where nobody schedules to the half hour and the finer grid is
            // just more cells to drag across.
            'slot_minutes' => (int) ($data['purpose'] === AvailabilityPoll::MEETING ? 60 : 30),
            'visibility' => $data['visibility'] ?? AvailabilityPoll::VISIBILITY_INVITED,
        ]);

        // The invitations. An empty response row is what makes somebody able to
        // open the poll at all under the default visibility, so this is the
        // difference between a link that works and a 403.
        $this->invite($poll, $data['participants'] ?? []);

        return redirect()->route('vatssa.availability.show', $poll)
            ->with('success', $poll->visibility === AvailabilityPoll::VISIBILITY_LINK
                ? 'Ask away. Anybody signed in who has the link can answer it.'
                : 'Ask away. Only the people you invited can open it &mdash; add more from this page.');
    }

    /**
     * Add people to a poll that already exists.
     *
     * Separate from creating one, because the usual way a poll goes wrong is
     * somebody being left off it -- and having to delete and recreate to fix
     * that is why people go back to asking in the group chat.
     */
    public function addParticipants(Request $request, AvailabilityPoll $poll): RedirectResponse
    {
        abort_unless($poll->isManageableBy(Auth::user()), 403);

        $data = $request->validate([
            'participants' => 'required|array|max:100',
            'participants.*' => 'integer|exists:users,id',
        ]);

        $added = $this->invite($poll, $data['participants']);

        return redirect()->route('vatssa.availability.show', $poll)
            ->with('success', $added === 0
                ? 'Everybody you picked was already on it.'
                : $added . ' ' . str('person')->plural($added) . ' added.');
    }

    /**
     * An empty response row per person: the invitation.
     *
     * `firstOrCreate`, so inviting somebody twice is not an error and does not
     * wipe the times they have already marked.
     *
     * @param  array<int, int>  $userIds
     * @return int how many were actually new
     */
    private function invite(AvailabilityPoll $poll, array $userIds): int
    {
        $added = 0;

        foreach (array_unique($userIds) as $id) {
            $response = AvailabilityResponse::firstOrCreate(
                ['poll_id' => $poll->id, 'user_id' => (int) $id],
                ['slots' => [], 'role' => AvailabilityPoll::ROLE_PARTICIPANT],
            );

            if ($response->wasRecentlyCreated) {
                $added++;
            }
        }

        return $added;
    }
}
