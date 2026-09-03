<?php

namespace App\Http\Controllers;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\FeedbackSentiment;
use App\Helpers\FeedbackStatus;
use App\Http\Requests\ActionFeedbackRequest;
use App\Http\Requests\StoreFeedbackRequest;
use App\Http\Requests\UpdateFeedbackRequest;
use App\Models\Feedback;
use App\Models\Position;
use App\Models\User;
use App\Notifications\FeedbackForwardedNotification;
use App\Notifications\FeedbackNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class FeedbackController extends Controller
{
    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {

        if (! Setting::get('feedbackEnabled')) {
            return redirect()->route('dashboard')->withErrors('Feedback is currently disabled.');
        }

        $positions = Position::all();
        $controllers = User::getActiveAtcMembers();

        return view('feedback.create', compact('positions', 'controllers'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(StoreFeedbackRequest $request)
    {

        if (! Setting::get('feedbackEnabled')) {
            return redirect()->route('dashboard')->withErrors('Feedback is currently disabled.');
        }

        $data = $request->validated();

        $position = isset($data['position']) ? Position::where('callsign', $data['position'])->get()->first() : null;
        $controller = isset($data['controller']) ? User::find($data['controller']) : null;
        $feedback = $data['feedback'];

        $submitter = auth()->user();

        $feedback = Feedback::create([
            'feedback' => $feedback,
            'submitter_user_id' => $submitter->id,
            'reference_user_id' => isset($controller) ? $controller->id : null,
            'reference_position_id' => isset($position) ? $position->id : null,
        ]);

        // Forward email if configured
        if (Setting::get('feedbackForwardEmail')) {
            $feedback->notify(new FeedbackNotification($feedback));
        }

        return redirect()->route('dashboard')->with('success', 'Feedback submitted!');

    }

    /**
     * Update the reference controller/position of an existing feedback entry.
     * Authorization is enforced by UpdateFeedbackRequest.
     */
    public function update(UpdateFeedbackRequest $request, Feedback $feedback): RedirectResponse
    {
        $data = $request->validated();

        $position = ! empty($data['position']) ? Position::where('callsign', $data['position'])->first() : null;
        $controller = ! empty($data['controller']) ? User::find($data['controller']) : null;

        $feedback->update([
            'reference_user_id' => $controller?->id,
            'reference_position_id' => $position?->id,
        ]);

        return redirect()->route('reports.feedback')->with('success', 'Feedback updated successfully!');
    }

    /**
     * Record the staff decision on a piece of feedback.
     *
     * Authorization is enforced by ActionFeedbackRequest, which asks the policy
     * for `action` on THIS feedback -- so the area scope applies per row rather
     * than once for the page.
     */
    public function action(ActionFeedbackRequest $request, Feedback $feedback): RedirectResponse
    {
        $data = $request->validated();

        $status = FeedbackStatus::from($data['status']);
        $sentiment = ! empty($data['sentiment'])
            ? FeedbackSentiment::from($data['sentiment'])
            : null;

        // Whether it was ALREADY forwarded, read before the decision is
        // written. Re-actioning to correct a note must not send the controller
        // a second copy of feedback they have already had.
        $wasForwarded = $feedback->status === FeedbackStatus::FORWARDED;

        $feedback->action($status, $request->user(), $sentiment, $data['staff_note'] ?? null);

        if ($status === FeedbackStatus::FORWARDED && ! $wasForwarded && $feedback->referenceUser) {
            $feedback->referenceUser->notify(new FeedbackForwardedNotification($feedback));
        }

        return redirect()->route('reports.feedback')->with(
            'success',
            $status === FeedbackStatus::FORWARDED
                ? 'Feedback forwarded to the controller.'
                : 'Feedback closed.'
        );
    }

    /**
     * The feedback a controller has been shown about themselves.
     *
     * Only what staff have forwarded, and never the submitter's name. Ungated
     * beyond being logged in: it is a page about you, showing you only what
     * somebody decided you should see.
     */
    public function received(): View
    {
        $feedback = Feedback::forwardedTo(auth()->user())
            ->with('referencePosition')
            ->latest('actioned_at')
            ->paginate(25);

        return view('feedback.received', compact('feedback'));
    }
}
