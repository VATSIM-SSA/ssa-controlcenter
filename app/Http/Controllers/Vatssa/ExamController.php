<?php

namespace App\Http\Controllers\Vatssa;

use App\Helpers\ExamStage;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TrainingActivityController;
use App\Models\Position;
use App\Models\Training;
use App\Models\Vatssa\ActionLog;
use App\Models\Vatssa\AvailabilityPoll;
use App\Models\Vatssa\AvailabilityResponse;
use App\Models\Vatssa\Exam;
use App\Services\Vatssa\Discord;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * VATSSA: booking a practical exam.
 *
 * ## The problem this replaces
 *
 * Arranging a CPT took five people, a Discord thread and a fortnight, and at
 * any moment nobody could say whose turn it was. The mentor asked, the training
 * manager approved somewhere, the student was asked for times in a DM, the
 * events team was asked whether the calendar was clear, an examiner had to
 * volunteer, and then somebody had to remember the banner and myVATSIM. Every
 * one of those is a handoff, and a handoff nobody owns is where the fortnight
 * goes.
 *
 * ## One row, one stage, one question
 *
 * Every action here does exactly one handoff and moves the stage exactly one
 * step. `ExamStage::waitingOn()` then answers the only question anybody has,
 * on every page, without anybody reading a thread.
 *
 * ## Each action is gated on the party whose turn it is
 *
 * Not on "staff". The events team cannot confirm an examiner's slot, an
 * examiner cannot clear the calendar, and the ATC training manager does not
 * pick the date. Those are different jobs, and a workflow where any of them can
 * do any step is a status field with extra clicking.
 *
 * ## The seven-day rule is enforced, not written down
 *
 * Everything settled at least seven days out -- examiner confirmed, events team
 * told, myVATSIM uploaded. Slots inside the window are never offered to an
 * examiner, so the rule cannot be broken by somebody being helpful.
 */
class ExamController extends Controller
{
    /**
     * Every exam in flight, for whoever is looking.
     *
     * ## Why one page for everybody
     *
     * The examiner wants the ones they could take, the events team wants the
     * ones waiting on them, and a coordinator wants to know why their student
     * has not sat yet. Three pages would be three places to keep in step and
     * three chances for one of them to disagree; the answer to all three
     * questions is the same list, sorted by whose turn it is.
     */
    public function index(): View
    {
        $this->authorize('viewAny', Exam::class);

        $exams = Exam::with('training.user', 'training.ratings', 'examiner', 'position', 'poll.responses')
            ->open()
            ->get()
            // Ascending stage, so the ones nobody has touched sit at the top.
            // An exam waiting on authorisation has been waiting longest by
            // definition, and a list that buries the oldest thing is a list
            // that grows an oldest thing.
            ->sortBy(fn (Exam $e) => [$e->stage->value, $e->created_at?->timestamp]);

        return view('vatssa.exams.index', [
            'exams' => $exams,
            'mine' => $exams->filter(fn (Exam $e) => $this->isMyTurn($e)),
        ]);
    }

    public function show(Exam $exam): View
    {
        $this->authorize('view', $exam);

        return view('vatssa.exams.show', [
            'exam' => $exam->load('training.user', 'training.ratings', 'examiner',
                'position', 'poll.responses.user', 'requester', 'authoriser'),
            'positions' => Position::orderBy('callsign')->get(),
            'slots' => $exam->offerableSlots(),
        ]);
    }

    // -----------------------------------------------------------------
    // 1. The mentor asks
    // -----------------------------------------------------------------

    public function store(Request $request, Training $training): RedirectResponse
    {
        $this->authorize('create', [Exam::class, $training]);

        // One open exam per training. A second would split the availability,
        // the clearances and the examiner across two rows and leave everybody
        // reading the wrong one.
        if (Exam::where('training_id', $training->id)->open()->exists()) {
            return back()->withErrors('This student already has an exam being arranged.');
        }

        $exam = Exam::create([
            'training_id' => $training->id,
            'stage' => ExamStage::REQUESTED,
            'requested_by' => Auth::id(),
        ]);

        $this->note($exam, 'A practical exam was requested by ' . Auth::user()->name . '.');

        return redirect()->route('vatssa.exams.show', $exam)
            ->with('success', 'Requested. The ATC training manager authorises it next.');
    }

    // -----------------------------------------------------------------
    // 2. The training manager authorises, and the student is asked
    // -----------------------------------------------------------------

    public function authorise(Exam $exam): RedirectResponse
    {
        $this->authorize('authorise', $exam);

        if (! $exam->moveTo(ExamStage::AWAITING_AVAILABILITY)) {
            return back()->withErrors('That exam has already been authorised.');
        }

        // The availability grid is created HERE rather than at request time, so
        // an exam nobody authorised never asks the student for anything. Being
        // asked for a month of your time and then told no is worse than
        // waiting a day.
        $poll = AvailabilityPoll::create([
            'purpose' => AvailabilityPoll::CPT,
            'title' => ($exam->student()?->name ?? 'Student') . ' — practical exam',
            'description' => 'Mark every time you could sit your exam, not just your '
                . 'preferred one. More options means a shorter wait.',
            // Opens after the notice period. Marking a slot that could never be
            // used is wasted effort and reads as the form not knowing its own
            // rules.
            'starts_on' => CarbonImmutable::now()->addDays(Exam::NOTICE_DAYS),
            'ends_on' => CarbonImmutable::now()->addDays(Exam::NOTICE_DAYS)->addWeeks(6),
            'training_id' => $exam->training_id,
            'created_by' => Auth::id(),
            'slot_minutes' => 30,
        ]);

        // Response rows are the invitation: they exist before anybody has
        // marked anything, which is what lets AvailabilityPoll::isVisibleTo
        // recognise who was asked.
        foreach ([
            [$exam->training?->user_id, AvailabilityPoll::ROLE_STUDENT],
        ] as [$userId, $role]) {
            if ($userId) {
                AvailabilityResponse::firstOrCreate(
                    ['poll_id' => $poll->id, 'user_id' => $userId],
                    ['slots' => [], 'role' => $role],
                );
            }
        }

        $exam->update([
            'poll_id' => $poll->id,
            'authorised_by' => Auth::id(),
            'authorised_at' => now(),
        ]);

        $this->note($exam, 'The exam was authorised. ' . ($exam->student()?->name ?? 'The student')
            . ' has been asked for their availability.');

        // No ping. The only person who has to act is the student, and they get
        // an email plus the item on their own page -- posting "somebody has
        // been asked for their availability" to a staff channel is noise that
        // teaches people to mute it.

        return back()->with('success', 'Authorised. The student has been asked for availability.');
    }

    // -----------------------------------------------------------------
    // 3. The student says they are done
    // -----------------------------------------------------------------

    public function submitAvailability(Exam $exam): RedirectResponse
    {
        $this->authorize('submitAvailability', $exam);

        $marked = $exam->poll?->responses
            ->firstWhere('user_id', $exam->training?->user_id)?->slots ?? [];

        // A grid submitted empty is the commonest way this stalls: the student
        // presses the button before painting anything and then waits for a
        // ping that is waiting for them.
        if ($marked === []) {
            return back()->withErrors(
                'Mark at least one time you could make before submitting.'
            );
        }

        if (! $exam->moveTo(ExamStage::AWAITING_EVENTS)) {
            return back()->withErrors('That availability has already been submitted.');
        }

        $exam->update(['availability_submitted_at' => now()]);

        $this->note($exam, ($exam->student()?->name ?? 'The student')
            . ' submitted ' . count($marked) . ' possible times. With the events team now.');

        $this->ping('events', ($exam->student()?->name ?? 'A student')
            . ' has given ' . count($marked) . ' possible times for their '
            . ($exam->training?->ratings->pluck('name')->join(' + ') ?: '') . ' practical exam. '
            . 'Mark which ones are clear of division plans: ' . route('vatssa.exams.show', $exam),
            $exam);

        return back()->with('success', 'Sent to the events team.');
    }

    // -----------------------------------------------------------------
    // 4. The events team clears what does not clash
    // -----------------------------------------------------------------

    public function clear(Exam $exam): RedirectResponse
    {
        $this->authorize('clear', $exam);

        $cleared = $exam->poll?->responses
            ->firstWhere('role', AvailabilityPoll::ROLE_EVENTS)?->slots ?? [];

        if ($cleared === []) {
            return back()->withErrors(
                'Mark the times that are clear of division plans before sending this on. '
                . 'If none of them work, cancel the exam instead and say why.'
            );
        }

        if (! $exam->moveTo(ExamStage::AWAITING_EXAMINER)) {
            return back()->withErrors('That has already been cleared.');
        }

        $exam->update(['events_cleared_at' => now()]);

        $offerable = count($exam->fresh()->offerableSlots());

        $this->note($exam, 'The events team cleared ' . $offerable
            . ' times. Waiting for an examiner to take it.');

        // Worth saying out loud rather than leaving an examiner to find an
        // empty list: the student and the calendar do not overlap anywhere
        // legal, which needs a person, not another ping.
        if ($offerable === 0) {
            ActionLog::noticed(
                'exam.no_workable_slot',
                ($exam->student()?->name ?? 'A student')
                    . ' has no time that works for both them and the calendar, at least seven days out.',
                $exam->training_id,
                $exam->training?->user_id,
                ['exam_id' => $exam->id],
            );
        }

        // Only worth pinging examiners when there is actually something to
        // take. A ping into an empty list is how a channel gets muted.
        if ($offerable > 0) {
            $this->ping('examiners', ($exam->student()?->name ?? 'A student')
                . ' needs a '
                . ($exam->training?->ratings->pluck('name')->join(' + ') ?: '')
                . ' practical exam. ' . $offerable . ' times work for them and the calendar. '
                . 'First examiner to take it books the slot: ' . route('vatssa.exams.show', $exam),
                $exam);
        }

        return back()->with('success', 'Cleared. Examiners can now take this.');
    }

    // -----------------------------------------------------------------
    // 5. An examiner takes it and names the slot, in one action
    // -----------------------------------------------------------------

    public function confirm(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('confirm', $exam);

        $data = $request->validate([
            'slot' => 'required|date',
            'position_id' => 'nullable|exists:positions,id',
        ]);

        // The slot must be one the workflow actually offered. The list came
        // from the browser, so it is an assertion: without this an examiner
        // could confirm a time the student never marked, or one inside the
        // seven-day window.
        if (! in_array($data['slot'], $exam->offerableSlots(), true)) {
            return back()->withErrors(
                'That time is not one the student marked and the events team cleared, '
                . 'or it is now inside the seven-day notice period.'
            );
        }

        if (! $exam->moveTo(ExamStage::CONFIRMED)) {
            return back()->withErrors('Somebody has already taken this exam.');
        }

        $exam->update([
            'examiner_id' => Auth::id(),
            'confirmed_at' => now(),
            'scheduled_for' => CarbonImmutable::parse($data['slot']),
            'position_id' => $data['position_id'] ?? null,
        ]);

        $exam->poll?->update([
            'confirmed_at' => now(),
            'confirmed_slot' => CarbonImmutable::parse($data['slot']),
            'confirmed_by' => Auth::id(),
        ]);

        $this->note($exam, Auth::user()->name . ' will examine on '
            . $exam->scheduled_for->format('j M Y') . ' at '
            . $exam->scheduled_for->format('H:i') . 'z.');

        // The events team, because the banner and myVATSIM are now theirs and
        // the seven-day clock is already running.
        $this->ping('events', 'CPT confirmed: ' . ($exam->student()?->name ?? 'a student')
            . ' with ' . Auth::user()->name . ' on '
            . $exam->scheduled_for->format('D j M') . ' at '
            . $exam->scheduled_for->format('H:i') . 'z. '
            . 'Banner, Discord, myVATSIM and social please: ' . route('vatssa.exams.show', $exam),
            $exam);

        return back()->with('success', 'Confirmed. The events team publish it next.');
    }

    // -----------------------------------------------------------------
    // 6. The events team publish it
    // -----------------------------------------------------------------

    public function publish(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('clear', $exam);

        // Each box is its own fact and they happen in parallel, so this saves
        // whatever is ticked rather than demanding all of them at once. The
        // stage only moves when the last one lands.
        $exam->update([
            'banner_made' => $request->boolean('banner_made'),
            'on_discord' => $request->boolean('on_discord'),
            'on_myvatsim' => $request->boolean('on_myvatsim'),
            'on_social' => $request->boolean('on_social'),
            'vatsim_approved' => $request->boolean('vatsim_approved'),
        ]);

        if ($exam->fresh()->checklistDone() && $exam->moveTo(ExamStage::PUBLISHED)) {
            $exam->update(['published_at' => now()]);
            $this->note($exam, 'Everything is published and VATSIM has approved it.');

            return back()->with('success', 'Published.');
        }

        return back()->with('success', 'Saved.');
    }

    // -----------------------------------------------------------------
    // Off the side
    // -----------------------------------------------------------------

    public function cancel(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('cancel', $exam);

        // A reason is required. A cancelled exam with no note is a row three
        // people will ask about and nobody can answer.
        $data = $request->validate(['reason' => 'required|string|max:255']);

        if (! $exam->moveTo(ExamStage::CANCELLED)) {
            return back()->withErrors('That exam is already closed.');
        }

        $exam->update(['outcome_note' => $data['reason']]);

        $this->note($exam, 'The exam was cancelled by ' . Auth::user()->name
            . ': ' . $data['reason']);

        // Both channels. An examiner who has held an evening and an events team
        // who have made a banner both need to know, and which of them is
        // affected depends on how far it had got.
        foreach (['examiners', 'events'] as $channel) {
            $this->ping($channel, 'CPT cancelled: ' . ($exam->student()?->name ?? 'a student')
                . ($exam->scheduled_for ? ' on ' . $exam->scheduled_for->format('D j M') : '')
                . ' — ' . $data['reason'], $exam);
        }

        return back()->with('success', 'Cancelled, and everybody involved can see why.');
    }

    // -----------------------------------------------------------------

    /**
     * Is this person the one being waited on?
     *
     * Drives the "needs you" list. Deliberately generous at the examiner stage:
     * every eligible examiner is being waited on, because the whole point of
     * that step is that any of them can take it.
     */
    private function isMyTurn(Exam $exam): bool
    {
        $user = Auth::user();

        return match ($exam->stage) {
            ExamStage::REQUESTED => $user->can('authorise', $exam),
            ExamStage::AWAITING_AVAILABILITY => $exam->training?->user_id === $user->id,
            ExamStage::AWAITING_EVENTS, ExamStage::CONFIRMED => $user->can('clear', $exam),
            ExamStage::AWAITING_EXAMINER => $user->can('confirm', $exam),
            default => false,
        };
    }

    /**
     * Tell a Discord channel, and record that it was told.
     *
     * Every ping goes through App\Services\Vatssa\Discord, which logs whether
     * it succeeded, failed or found no webhook. A ping nobody can account for
     * leaves "did the examiners get told?" with no answer, which is exactly the
     * question this workflow exists to end.
     */
    private function ping(string $channel, string $message, Exam $exam): void
    {
        app(Discord::class)->send(
            $channel, $message, $exam->training_id, $exam->training?->user_id
        );
    }

    /**
     * Onto the training's own timeline, and the division-wide log.
     *
     * The exam pages are where the workflow is worked; the training page is
     * where a coordinator asks "what has happened to this student". Both need
     * it, and an event that appears in neither may as well not have happened.
     */
    private function note(Exam $exam, string $text): void
    {
        TrainingActivityController::create(
            $exam->training_id, 'COMMENT', null, null, Auth::id(), $text
        );

        ActionLog::did(
            'exam.' . $exam->stage->name,
            $text,
            $exam->training_id,
            $exam->training?->user_id,
            ['exam_id' => $exam->id, 'stage' => $exam->stage->value],
            ActionLog::ACTOR_SYSTEM,
            mirror: false,
        );
    }
}
