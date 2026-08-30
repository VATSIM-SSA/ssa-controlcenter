<?php

namespace App\Http\Controllers\Vatssa;

use App\Http\Controllers\Controller;
use App\Models\Vatssa\AvailabilityPoll;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        ]);
    }

    public function show(AvailabilityPoll $poll): View
    {
        $poll->load('responses.user', 'training.user');

        // A poll is a list of when named members are at home. Not secret, not
        // nothing either -- see AvailabilityPoll::isVisibleTo.
        abort_unless($poll->isVisibleTo(Auth::user()), 403);

        return view('vatssa.availability.show', ['poll' => $poll]);
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
            'purpose' => 'required|in:cpt,mentoring,meeting',
            'description' => 'nullable|string|max:1000',
            'starts_on' => 'nullable|date',
            'weeks' => 'nullable|integer|min:1|max:8',
            'training_id' => 'nullable|exists:trainings,id',
        ]);

        $from = CarbonImmutable::parse($data['starts_on'] ?? 'tomorrow')->startOfDay();

        $poll = AvailabilityPoll::create([
            'title' => $data['title'],
            'purpose' => $data['purpose'],
            'description' => $data['description'] ?? null,
            'starts_on' => $from,
            'ends_on' => $from->addWeeks($data['weeks'] ?? 4)->subDay(),
            'training_id' => $data['training_id'] ?? null,
            'created_by' => Auth::id(),
            // Half an hour for a session or an exam; an hour for a meeting,
            // where nobody schedules to the half hour and the finer grid is
            // just more cells to drag across.
            'slot_minutes' => $data['purpose'] === AvailabilityPoll::MEETING ? 60 : 30,
        ]);

        return redirect()->route('vatssa.availability.show', $poll)
            ->with('success', 'Ask away. Send people the link on this page.');
    }
}
