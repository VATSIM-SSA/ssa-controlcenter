@extends('layouts.app')

@section('title', 'User Settings')
@section('content')

<div class="row">
    <div class="col-xl-6 col-md-12 mb-12">

        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-tertiary">Settings</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-xl-12 col-md-12 mb-12">
                        <form action="{{ route('user.settings.store') }}" method="POST">
                            @csrf

                            <p><b>To change email or password, log into <a href="https://my.vatsim.net/" target="_blank">myVATSIM</a>.</b></p>

                            <div class="mb-3">
                                <label class="form-label" for="setting_theme">Theme Preference</label>
                                <select class="form-control" id="setting_theme" name="setting_theme">
                                    <option value="system" {{ $user->setting_theme == 'system' ? 'selected' : '' }}>System Default</option>
                                    <option value="light" {{ $user->setting_theme == 'light' ? 'selected' : '' }}>Light</option>
                                    <option value="dark" {{ $user->setting_theme == 'dark' ? 'selected' : '' }}>Dark</option>
                                </select>
                                <small class="form-text text-muted">Choose your preferred theme or let the system decide based on your device settings.</small>
                            </div>

                            <hr>

                            <h5>Notifications</h5>
                        
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="check0" name="setting_notify_newreport" {{ $user->setting_notify_newreport ? "checked" : "" }}>
                                <label class="form-check-label" for="check0">
                                    Send notification when my mentor publishes a new training report
                                </label>
                            </div>
                            
                            <div class="mb-3 mt-4">
                                <label class="form-label" for="active_email">Your VATSIM registered e-mail</label>
                                <input type="email" class="form-control" name="active_email" value="{{ $user->email }}" disabled>
                                @if(!$user->setting_workmail_address)
                                    <small class="form-text text-muted">We're sending notifications to this address.</small>
                                @endif
                            </div>

                            @can('tasks.manage')
                                <hr>

                                <h5>Mentor Notifications</h5>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="check4" name="setting_notify_tasks" {{ $user->setting_notify_tasks ? "checked" : "" }}>
                                    <label class="form-check-label" for="check4">
                                        Send notification of new tasks
                                    </label>
                                </div>

                            @endcan

                            @can('training.notifications.receive')
                                <hr>

                                <h5>Moderator Notifications</h5>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="check1" name="setting_notify_newreq" {{ $user->setting_notify_newreq ? "checked" : "" }}>
                                    <label class="form-check-label" for="check1">
                                        Send notification of new training requests
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="check2" name="setting_notify_closedreq" {{ $user->setting_notify_closedreq ? "checked" : "" }}>
                                    <label class="form-check-label" for="check2">
                                        Send notification of closed training requests
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="check3" name="setting_notify_newexamreport" {{ $user->setting_notify_newexamreport ? "checked" : "" }}>
                                    <label class="form-check-label" for="check3">
                                        Send notification of new examination reports
                                    </label>
                                </div>
                                
                                <div class="mb-3 mt-4">
                                    <label class="form-label" for="setting_workmail_address">Work e-mail</label>
                                    <input type="email" class="form-control @error('setting_workmail_address') is-invalid @enderror" id="setting_workmail_address" name="setting_workmail_address" value="{{ $user->setting_workmail_address ? $user->setting_workmail_address : "" }}">
                                    <small class="form-text text-muted">Enter a work e-mail address to send all notifications to instead. Has to be &#64;{{ Setting::get('linkDomain') }}</small>
                                    @error('setting_workmail_address')
                                        <span class="text-danger">{{ $errors->first('setting_workmail_address') }}</span>
                                    @enderror
                                </div>

                            @endcan

                            <button class="btn btn-success mt-3" type="submit">Save</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-6 col-md-12 mb-12">
        <div class="card shadow mb-4" id="calendar-subscription">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-tertiary">Calendar subscription</h6>
            </div>
            <div class="card-body">
                <h5>Your bookings</h5>
                <p>Add your ATC and sweatbox bookings to Google Calendar, Outlook or Apple Calendar. Your calendar app checks this link for changes on its own, usually within a few hours.</p>

                @if($calendarCanIncludeEvents)
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
    </div>

</div>
@endsection

@section('js')
<script>
    document.querySelectorAll('.calendar-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(button.dataset.copy).then(function () {
                button.querySelector('span').textContent = 'Copied';
                setTimeout(function () { button.querySelector('span').textContent = 'Copy'; }, 1500);
            });
        });
    });
</script>
@endsection