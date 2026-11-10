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
    /**
     * Serve a user's bookings as an iCalendar feed.
     *
     * Calendar clients cannot log in, so the token in the URL is the credential.
     */
    public function show(string $token, CalendarFeedService $feed): Response
    {
        $user = User::query()->where('calendar_feed_token', $token)->first();

        abort_if($user === null || $user->rating === VatsimRating::SUS, 404);

        return response($feed->forUser($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="bookings.ics"',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Serve every published Event Hub event as a calendar of its own.
     */
    public function events(CalendarFeedService $feed): Response
    {
        abort_unless($feed->canIncludeEvents(), 404);

        return response($feed->forEvents(), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="events.ics"',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * Choose whether the feed carries every Event Hub event, or only the user's own bookings.
     * The link stays the same, so subscribed calendars pick the change up on their next refresh.
     */
    public function update(Request $request, CalendarFeedService $feed): RedirectResponse
    {
        abort_unless($feed->canIncludeEvents(), 404);

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
        $user = Auth::user();
        $user->regenerateCalendarFeedToken();

        ActivityLogService::info(LogName::Access, 'Calendar feed link reset by ' . $user->id);

        return redirect(route('user.settings') . '#calendar-subscription')
            ->withSuccess('Calendar link reset. Subscribe again with the new link.');
    }
}
