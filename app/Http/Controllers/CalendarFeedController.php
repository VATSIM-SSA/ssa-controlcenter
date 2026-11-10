<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Helpers\VatsimRating;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CalendarFeedService;
use Illuminate\Http\RedirectResponse;
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
