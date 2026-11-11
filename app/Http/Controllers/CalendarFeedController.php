<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Helpers\VatsimRating;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CalendarFeedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class CalendarFeedController extends Controller
{
    private const HEADERS = [
        'Content-Type' => 'text/calendar; charset=utf-8',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    public function __construct(private CalendarFeedService $calendarFeed) {}

    /**
     * Serve a user's bookings as an iCalendar feed.
     *
     * Calendar apps cannot log in, so the token in the URL is the credential.
     */
    public function show(string $token): Response
    {
        $user = User::query()->where('calendar_feed_token', $token)->first();

        abort_if($user === null || $user->rating === VatsimRating::SUS, 404);

        return response($this->calendarFeed->forUser($user), 200, [
            ...self::HEADERS,
            'Content-Disposition' => 'inline; filename="bookings.ics"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Serve every published Event Hub event as a calendar of its own, the same for everyone.
     */
    public function events(): Response
    {
        abort_unless($this->calendarFeed->hasEventHub(), 404);

        $calendar = $this->calendarFeed->forEvents();

        // Tell calendar apps to try again later, so they keep the events they already show.
        abort_if($calendar === null, 503, headers: ['Retry-After' => '300']);

        return response($calendar, 200, [
            ...self::HEADERS,
            'Content-Disposition' => 'inline; filename="events.ics"',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * Choose whether the personal feed also carries every Event Hub event. The link stays the
     * same, so subscribed calendars pick the change up on their next refresh.
     */
    public function update(Request $request): RedirectResponse
    {
        abort_unless($this->calendarFeed->hasEventHub(), 404);

        $user = Auth::user();
        $user->setting_calendar_include_events = $request->boolean('setting_calendar_include_events');
        $user->save();

        return redirect(route('user.settings') . '#calendar-subscription')
            ->withSuccess($user->setting_calendar_include_events
                ? 'Your calendar now includes all events. Calendar apps show the change on their next refresh.'
                : 'Your calendar now shows only your own bookings. Calendar apps show the change on their next refresh.');
    }

    /**
     * Replace the user's feed token, which stops every existing subscription.
     */
    public function reset(): RedirectResponse
    {
        $this->calendarFeed->resetToken(Auth::user());

        ActivityLogService::info(LogName::Access, 'Calendar feed link reset by ' . Auth::id());

        return redirect(route('user.settings') . '#calendar-subscription')
            ->withSuccess('Calendar link reset. Subscribe again with the new link.');
    }
}
