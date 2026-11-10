<?php

namespace App\Http\Controllers;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\FeedbackActivityType;
use App\Helpers\FeedbackSentiment;
use App\Http\Requests\SendFeedbackRequest;
use App\Http\Requests\StoreFeedbackRequest;
use App\Http\Requests\UpdateFeedbackRequest;
use App\Models\Area;
use App\Models\Feedback;
use App\Models\Position;
use App\Models\User;
use App\Notifications\FeedbackNotification;
use App\Services\FeedbackDeliveryService;
use App\Services\FeedbackDiscordPoster;
use App\Services\PilotFlightHistoryService;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;

class FeedbackController extends Controller
{
    /**
     * Show the feedback form. With more than one area the submitter picks the
     * area first.
     */
    public function create(Request $request, PilotFlightHistoryService $flightHistory): View|RedirectResponse
    {
        if (! Setting::get('feedbackEnabled')) {
            return redirect()->route('dashboard')->withErrors('Feedback is currently disabled.');
        }

        $areas = Area::orderBy('name')->get();
        $area = $areas->count() === 1
            ? $areas->first()
            : $areas->firstWhere('id', (int) $request->query('area', old('area')));

        if ($area === null && $areas->count() > 1) {
            return view('feedback.choose-area', compact('areas'));
        }

        $flights = $flightHistory->recentFlights(auth()->id());

        return view('feedback.create', compact('area', 'areas', 'flights'));
    }

    /**
     * Store a newly submitted feedback. The submitter does not see it again;
     * it goes to staff.
     */
    public function store(StoreFeedbackRequest $request): RedirectResponse
    {
        if (! Setting::get('feedbackEnabled')) {
            return redirect()->route('dashboard')->withErrors('Feedback is currently disabled.');
        }

        $data = $request->validated();

        // A picked session names its callsign; use it as the position when CC knows it.
        $positionCallsign = $data['position'] ?? $data['session_callsign'] ?? null;
        $position = $positionCallsign ? Position::where('callsign', $positionCallsign)->first() : null;

        // A position carries its own area, and the two are kept mutually
        // exclusive (see UpdateFeedbackRequest), so the explicit area is only
        // stored when no position is known.
        $feedback = Feedback::create([
            'feedback' => $data['feedback'],
            'submitter_user_id' => $request->user()->id,
            'reference_user_id' => (int) $data['controller'],
            'reference_position_id' => $position?->id,
            'area_id' => $position === null && ! empty($data['area']) ? (int) $data['area'] : null,
            'session_callsign' => $data['session_callsign'] ?? null,
            'session_started_at' => $data['session_started_at'] ?? null,
            'session_ended_at' => $data['session_ended_at'] ?? null,
            'pilot_callsign' => $data['pilot_callsign'] ?? null,
            'event_date' => $data['event_date'] ?? null,
            'departure_airport' => $data['departure_airport'] ?? null,
            'arrival_airport' => $data['arrival_airport'] ?? null,
        ]);

        if (Setting::get('feedbackForwardEmail')) {
            $feedback->notify(new FeedbackNotification($feedback));
        }

        return redirect()->route('dashboard')->with('success', 'Thank you. Your feedback has been sent to our staff.');
    }

    /**
     * Staff view of one entry: the original, what was sent, and the timeline.
     */
    public function show(Request $request, Feedback $feedback, FeedbackDiscordPoster $discord): View
    {
        $this->authorize('view', $feedback);

        $feedback->load([
            'submitter', 'referenceUser', 'referencePosition.area', 'explicitArea',
            'closedBy', 'sentBy', 'latestAcknowledgement', 'activities.triggeredBy',
        ]);

        $activities = $feedback->activities->sortByDesc('id')->values();
        $canEdit = $request->user()->can('update', $feedback);

        return view('feedback.show', [
            'feedback' => $feedback,
            'activities' => $activities,
            'canEdit' => $canEdit,
            'canReview' => $request->user()->can('review', $feedback),
            'discordAvailable' => $discord->isConfigured(),
            'editControllers' => $canEdit ? User::whereIn('id', User::getFeedbackControllerIds())->orderBy('first_name')->orderBy('last_name')->get() : collect(),
            'editPositions' => $canEdit ? Position::orderBy('callsign')->get() : collect(),
            'editAreas' => $canEdit ? Area::orderBy('name')->get() : collect(),
        ]);
    }

    /**
     * Tag an entry positive or negative, or clear the tag. Allowed at any time.
     */
    public function sentiment(Request $request, Feedback $feedback): RedirectResponse
    {
        $this->authorize('review', $feedback);

        $data = $request->validate(['sentiment' => ['nullable', Rule::enum(FeedbackSentiment::class)]]);

        $feedback->setSentiment(FeedbackSentiment::tryFrom($data['sentiment'] ?? ''), $request->user());

        return redirect()->route('feedback.show', $feedback);
    }

    /**
     * Add an internal comment to the timeline, whatever the entry's status.
     */
    public function comment(Request $request, Feedback $feedback): RedirectResponse
    {
        $this->authorize('review', $feedback);

        $data = $request->validate(['comment' => ['required', 'string', 'max:4000']]);

        $feedback->recordActivity(FeedbackActivityType::COMMENT, $request->user(), comment: $data['comment']);

        return redirect()->route('feedback.show', $feedback)->with('success', 'Comment added.');
    }

    /**
     * Close the review. This never sends anything: sending to the controller
     * is a separate action, available whether the review is open or closed.
     */
    public function close(Request $request, Feedback $feedback): RedirectResponse
    {
        $this->authorize('review', $feedback);

        if (! $feedback->isOpen()) {
            return redirect()->route('feedback.show', $feedback)->withErrors('This feedback is already closed.');
        }

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:4000']]);

        $feedback->close($request->user(), $data['comment'] ?? null);

        return redirect()->route('feedback.show', $feedback)->with('success', 'Review closed.');
    }

    /**
     * Send the feedback to its controller, whether the review is open or
     * closed. The controller holds one version at a time.
     */
    public function send(SendFeedbackRequest $request, Feedback $feedback, FeedbackDeliveryService $delivery): RedirectResponse
    {
        try {
            $posted = $delivery->send($feedback, $request->user(), $request->delivery(), $request->validated('rewritten'), $request->postToDiscord());
        } catch (LogicException $e) {
            // Another request sent it first, for example a double-click.
            return redirect()->route('feedback.show', $feedback)->withErrors($e->getMessage());
        }

        if (filled($request->validated('comment'))) {
            $feedback->recordActivity(FeedbackActivityType::COMMENT, $request->user(), comment: $request->validated('comment'));
        }

        return $this->afterSending($feedback, 'Feedback sent to ' . $feedback->referenceUser->name . '.', $posted);
    }

    /**
     * Take back what the controller was sent: it leaves their profile and
     * dashboard and its Discord post is deleted. The e-mail cannot be recalled.
     */
    public function withdraw(Request $request, Feedback $feedback, FeedbackDeliveryService $delivery): RedirectResponse
    {
        $this->authorize('review', $feedback);

        $hadDiscordPost = $feedback->discord_message_id !== null;

        try {
            $removed = $delivery->withdraw($feedback, $request->user());
        } catch (LogicException $e) {
            return redirect()->route('feedback.show', $feedback)->withErrors($e->getMessage());
        }

        $redirect = redirect()->route('feedback.show', $feedback)->with('success', 'Feedback withdrawn from ' . $feedback->referenceUser?->name . '.');

        return $hadDiscordPost && ! $removed
            ? $redirect->withErrors('The Discord post could not be deleted. Remove it in Discord by hand.')
            : $redirect;
    }

    /**
     * Put a closed entry back in the review queue. What the controller has stays as it is.
     */
    public function reopen(Request $request, Feedback $feedback): RedirectResponse
    {
        $this->authorize('review', $feedback);

        if ($feedback->isOpen()) {
            return redirect()->route('feedback.show', $feedback)->withErrors('This feedback is already open.');
        }

        $feedback->reopen($request->user());

        return redirect()->route('feedback.show', $feedback)->with('success', 'Feedback reopened.');
    }

    private function afterSending(Feedback $feedback, string $message, ?bool $posted): RedirectResponse
    {
        $redirect = redirect()->route('feedback.show', $feedback);

        return match ($posted) {
            true => $redirect->with('success', $message . ' Posted to Discord.'),
            false => $redirect->with('success', $message)->withErrors('Posting to Discord failed. Check the webhook in the global settings.'),
            null => $redirect->with('success', $message),
        };
    }

    /**
     * Correct an entry: everything except the submitter and their text.
     *
     * The controller, position and area are replaced wholesale, so an omitted
     * or empty field clears its column. The session and flight details are
     * changed only when the request carries them. Validation and authorization
     * are enforced by UpdateFeedbackRequest, the latter against the entry as
     * it stands before the update. Changes are recorded on the timeline.
     */
    public function update(UpdateFeedbackRequest $request, Feedback $feedback): RedirectResponse
    {
        $data = $request->validated();

        $position = ! empty($data['position']) ? Position::where('callsign', $data['position'])->first() : null;
        $controller = ! empty($data['controller']) ? User::find($data['controller']) : null;
        $area = ! empty($data['area']) ? Area::find((int) $data['area']) : null;

        // A sent version belongs to the controller who got it; take it back before naming someone else.
        if ($feedback->wasSent() && $controller?->id !== $feedback->reference_user_id) {
            return back()->withErrors(['controller' => 'Withdraw what was sent to the current controller before changing the controller.'])->withInput();
        }

        $feedback->loadMissing(['referenceUser', 'referencePosition', 'explicitArea']);
        $changes = [
            $this->change('Controller', $feedback->referenceUser, $controller, fn (User $user): string => "{$user->name} ({$user->id})"),
            $this->change('Position', $feedback->referencePosition, $position, fn (Position $position): string => $position->callsign),
            $this->change('Area', $feedback->explicitArea, $area, fn (Area $area): string => $area->name),
        ];

        $attributes = [
            'reference_user_id' => $controller?->id,
            'reference_position_id' => $position?->id,
            'area_id' => $area?->id,
        ];

        $details = [
            'session_callsign' => ['Session callsign', 'text'],
            'session_started_at' => ['Session start', 'datetime'],
            'session_ended_at' => ['Session end', 'datetime'],
            'pilot_callsign' => ['Pilot callsign', 'text'],
            'event_date' => ['Date of event', 'date'],
            'departure_airport' => ['Departure', 'text'],
            'arrival_airport' => ['Arrival', 'text'],
        ];

        foreach ($details as $field => [$label, $kind]) {
            if (! $request->has($field)) {
                continue;
            }

            $new = filled($data[$field] ?? null) ? (string) $data[$field] : null;

            $changes[] = $this->detailChange($label, $feedback->getAttribute($field), $new, $kind);
            $attributes[$field] = $new;
        }

        $feedback->update($attributes);
        $changes = array_values(array_filter($changes));

        if ($changes !== []) {
            $feedback->recordActivity(FeedbackActivityType::EDITED, $request->user(), ['changes' => $changes]);
        }

        // Moving an entry to another area can take it out of the editor's reach.
        $feedback->unsetRelations();
        $target = $request->user()->can('view', $feedback) ? route('feedback.show', $feedback) : route('reports.feedback');

        return redirect($target)->with('success', 'Feedback updated.');
    }

    /**
     * Describe one detail change for the timeline, or null when unchanged.
     *
     * @return array{label: string, from: string, to: string}|null
     */
    private function detailChange(string $label, mixed $old, ?string $new, string $kind): ?array
    {
        $format = function (mixed $value) use ($kind): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            return match ($kind) {
                'date' => Carbon::parse($value)->toEuropeanDate(),
                'datetime' => Carbon::parse($value)->utc()->format('j M Y, Hi') . 'z',
                default => (string) $value,
            };
        };

        $from = $format($old);
        $to = $format($new);

        return $from === $to ? null : ['label' => $label, 'from' => $from ?? 'None', 'to' => $to ?? 'None'];
    }

    /**
     * Describe one reference change for the timeline, or null when unchanged.
     *
     * @return array{label: string, from: string, to: string}|null
     */
    private function change(string $label, ?Model $old, ?Model $new, Closure $describe): ?array
    {
        if ($old?->getKey() === $new?->getKey()) {
            return null;
        }

        return [
            'label' => $label,
            'from' => $old ? $describe($old) : 'None',
            'to' => $new ? $describe($new) : 'None',
        ];
    }
}
