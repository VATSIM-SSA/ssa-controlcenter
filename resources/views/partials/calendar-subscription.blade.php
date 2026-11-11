{{-- Calendar subscription card on the user settings page. --}}
@inject('calendarFeed', 'App\Services\CalendarFeedService')
@php($calendarLinks = $calendarFeed->subscriptionLinks($user))
@php($eventsCalendarLinks = $calendarFeed->eventsSubscriptionLinks())

<div class="card shadow mb-4" id="calendar-subscription">
    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-tertiary">Calendar subscription</h6>
    </div>
    <div class="card-body">
        <h5>Your bookings</h5>
        <p>Add your ATC and sweatbox bookings to Google Calendar, Outlook or Apple Calendar. Your calendar app checks this link for changes on its own, usually within a few hours.</p>

        @if($eventsCalendarLinks)
            <form action="{{ route('user.settings.calendar') }}" method="POST" class="mb-3">
                @csrf
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="setting_calendar_include_events" name="setting_calendar_include_events" value="1" {{ $user->setting_calendar_include_events ? 'checked' : '' }} onchange="this.form.submit()">
                    <label class="form-check-label" for="setting_calendar_include_events">Also show all {{ config('app.owner_name_short') }} events in this calendar</label>
                </div>
                <small class="form-text text-muted">For one calendar with everything. The link stays the same, so there is no need to subscribe again.</small>
                <noscript><button class="btn btn-sm btn-success mt-2" type="submit">Save</button></noscript>
            </form>
        @endif

        @include('partials.calendar-links', ['links' => $calendarLinks, 'id' => 'calendar-feed', 'label' => 'Your calendar link'])

        <p class="small text-muted">Anyone with this link can see your bookings, so keep it private. Resetting the link stops every calendar already subscribed to it.</p>

        <form action="{{ route('user.settings.calendar.reset') }}" method="POST" onsubmit="return confirm('Reset the calendar link? Calendars subscribed to the old link will stop updating.')">
            @csrf
            <button class="btn btn-outline-danger btn-sm" type="submit">Reset link</button>
        </form>

        @if($eventsCalendarLinks)
            <hr>

            <h5>All {{ config('app.owner_name_short') }} events</h5>
            <p>Prefer events in a calendar of their own, with their own colour and easy to hide? Subscribe to this one as well. It is the same for everyone and safe to share.</p>

            @include('partials.calendar-links', ['links' => $eventsCalendarLinks, 'id' => 'calendar-events', 'label' => 'Events calendar link'])

            <p class="small text-muted mb-0">Either way, your own event bookings are named after their event. Use this calendar or the switch above, not both, or each event shows twice.</p>
        @endif
    </div>
</div>

<script>
    document.querySelectorAll('#calendar-subscription .calendar-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var label = button.querySelector('span');
            var done = function () {
                label.textContent = 'Copied';
                setTimeout(function () { label.textContent = 'Copy'; }, 1500);
            };

            if (navigator.clipboard) {
                navigator.clipboard.writeText(button.dataset.copy).then(done);
            } else {
                // The Clipboard API needs HTTPS; select the link so it can be copied by hand.
                button.previousElementSibling.select();
            }
        });
    });
</script>
