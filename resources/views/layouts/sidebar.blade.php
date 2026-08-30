<nav>

    <ul class="navbar-nav sidebar" id="sidebar">

        {{-- Sidebar - Brand --}}
        <a class="sidebar-brand d-flex align-items-center" href="{{ route('dashboard') }}">
            <div class="sidebar-brand-icon">
                {!! file_get_contents(public_path('images/control-tower.svg')) !!}
            </div>

            <div class="sidebar-brand-text mx-3">{{ config('app.name') }}</div>

            <button type="button" id="sidebar-button-close" class="sidebar-button-close ms-auto">
                <i class="fas fa-times"></i>
            </button>
        </a>

        {{-- Divider --}}
        <div class="sidebar-divider my-0"></div>

        <x-sidebar.item :href="route('dashboard')" icon="fa-table-columns" title="Dashboard" :active="Route::is('dashboard')" />

        {{-- VATSSA: your own profile. Reachable before only by searching for
             yourself, which is an odd thing to have to do. UserPolicy::view
             already allows `$user->is($model)`, so this needs no gate. --}}
        <x-sidebar.item :href="route('user.show', Auth::id())" icon="fa-id-card" title="My profile"
                        :active="Route::is('user.show') && request()->route('user')?->id === Auth::id()" />

        @can('update', [\App\Models\Task::class])
            @php
                // VATSSA: what is on YOUR DESKS, not what carries your name.
                // A request belongs to a desk; the assignee column exists only
                // because it is NOT NULL. Counting it would tell a coordinator
                // their queue was empty while their desk was not.
                $myDesks = \App\Models\Vatssa\RequestTarget::desksFor(\Auth::user());
                $pendingTaskCount = \App\Models\Task::where(function ($q) use ($myDesks) {
                        $q->where('assignee_user_id', \Auth::id());
                        $q->orWhere(fn ($inner) => \App\Models\Vatssa\RequestTarget::scopeToDesks($inner, $myDesks));
                    })
                    ->where('status', \App\Helpers\TaskStatus::PENDING)
                    ->count();
            @endphp

            <x-sidebar.item :href="route('tasks')" icon="fa-list" title="Tasks" :active="Route::is('tasks')">
                @if($pendingTaskCount)
                    <span class="badge text-bg-danger">{{ $pendingTaskCount }}</span>
                @endif
            </x-sidebar.item>
        @endcan

        @can('view', \App\Models\Booking::class)
            <x-sidebar.item :href="route('booking')" icon="fa-calendar" title="Booking" :active="Route::is('booking*')" />
        @endcan

        @if(Setting::get('linkMoodle') && Setting::get('linkMoodle') != "")
            <li class="nav-item">
            <a class="nav-link" href="{{ Setting::get('linkMoodle') }}" target="_blank">
                <i class="fas fa-graduation-cap"></i>
                <span>Moodle</span></a>
            </li>
        @endif

        @canany(['training.mentor-dashboard.view', 'bookings.sweatbox.use', 'fir.management.reports.view'])

            {{-- Divider --}}
            <div class="sidebar-divider"></div>

            {{-- Heading --}}
            <div class="sidebar-heading">
            Training
            </div>

            @can('training.mentor-dashboard.view')
                <x-sidebar.item :href="route('mentor')" icon="fa-chalkboard-teacher" title="My students" :active="Route::is('mentor')" />
            @endcan

            @can('bookings.sweatbox.use')
                <x-sidebar.item :href="route('sweatbook')" icon="fa-calendar-alt" title="Sweatbox Calendar" :active="Route::is('sweatbook')" />
            @endcan

            @can('fir.management.reports.view')

                {{-- Nav Item - Pages Collapse Menu --}}
                <x-sidebar.section icon="fa-flag" title="Requests" :active="Route::is('requests') || Route::is('requests.history')" id="collapseReq">
                    <x-sidebar.item :href="route('requests')" title="Open Requests" collapse />
                    <x-sidebar.item :href="route('requests.history')" title="Closed Requests" collapse />
                </x-sidebar.section>
            @endcan

        @endcanany

        {{-- VATSSA: the heading only appears if something sits under it.

             Upstream's Members heading was unconditional while everything below
             it was gated, so an ordinary member saw a section title with an
             empty space beneath. Two of the three items that used to fill it
             are now gated too -- the roster came out of the nav, and the
             endorsement rosters became staff-only -- which made an existing
             oversight visible on every account. --}}
        @canany(['users.manage', 'endorsements.rosters.view'])

        {{-- Divider --}}
        <div class="sidebar-divider"></div>

        {{-- Heading --}}
        <div class="sidebar-heading">
        Members
        </div>

        @can('users.manage')

            {{-- Nav Item - Pages Collapse Menu --}}
            <x-sidebar.section icon="fa-users" title="Users" :active="Route::is('users') || Route::is('users.other')" id="collapseMem">
                <x-sidebar.item :href="route('users')" title="Member Overview" collapse />
                <x-sidebar.item :href="route('users.other')" title="Other Users" collapse />
            </x-sidebar.section>

        @endif

        {{-- VATSSA: the ATC roster is not in the sidebar.

             Upstream lists one roster per area, which is right for a division
             where each area has its own controllers. VATSSA's rule is that
             active in one area is active everywhere, so a per-area list is a
             misleading answer to the question people are asking, and four
             sidebar entries for four views of the same set is noise.

             The per-area pages still exist and the `roster` route is untouched,
             so a bookmarked or shared link still works -- they are simply no
             longer advertised. The roster people actually read is the public
             one on vatssa.com, served by `/api/vatssa/roster`. --}}

        {{-- VATSSA: the endorsement rosters are staff tools, not a member menu.

             Solo and visiting endorsements belong on the PUBLIC roster at
             vatssa.com, where members actually look, rather than behind a login
             here. Examiners are not published at all.

             The pages still exist for the staff who work from them --
             pipeline coordinator, ATC training manager and admin -- and are now
             gated on `endorsements.rosters.view`, which upstream does not have:
             its three index methods carry no authorize() call whatsoever. --}}
        @can('endorsements.rosters.view')
            <x-sidebar.section icon="fa-check-square" title="Endorsements" :active="Route::is('endorsements.*')" id="collapseEndorsements">
                <x-sidebar.item :href="route('endorsements.solos')" title="Solo" collapse />
                <x-sidebar.item :href="route('endorsements.examiners')" title="Examiner" collapse />
                <x-sidebar.item :href="route('endorsements.visiting')" title="Visiting" collapse />
            </x-sidebar.section>
        @endcan

        @endcanany



        @can('fir.management.reports.view')
            {{-- Divider --}}
            <div class="sidebar-divider"></div>

            {{-- Nav Item - Pages Collapse Menu --}}
            <x-sidebar.section icon="fa-clipboard-list" title="Reports" :active="Route::is('reports.trainings') || Route::is('reports.training.area') || Route::is('reports.activities') || Route::is('reports.activities.area') || Route::is('reports.mentors') || Route::is('reports.access') || Route::is('reports.feedback') || Route::is('vatssa.action-log')" id="collapseTwo">

                @can('training.statistics.view')
                    <x-sidebar.item :href="route('reports.trainings')" title="Training Statistics" collapse />
                @endcan
                @can('training.activities.view')
                    <x-sidebar.item :href="route('reports.activities')" title="Training Activities" collapse />
                @endcan

                <x-sidebar.item :href="route('reports.mentors')" title="Mentors" collapse />

                @can('viewAccessReport', \App\Models\ManagementReport::class)
                    <x-sidebar.item :href="route('reports.access')" title="Access" collapse />
                @endcan

                <x-sidebar.item :href="route('reports.feedback')" title="Feedback" collapse />

                {{-- VATSSA: what the automation did, and what it noticed and
                     left alone. A report rather than an admin page: the
                     people who need it are the ones working the queue. --}}
                <x-sidebar.item :href="route('vatssa.action-log')" title="Automation log" collapse />

            </x-sidebar.section>
        @endif

        @if(auth()->user()->canAny(['system.health.view', 'users.manage']) || auth()->user()->can('viewAny', App\Models\Position::class))

            {{-- Nav Item - Utilities Collapse Menu --}}
            <x-sidebar.section icon="fa-cogs" title="Administration" :active="Route::is('admin.*') || Route::is('positions.*') || Route::is('vote.overview')" id="collapseUtilities">
                @can('system.health.view')
                    <x-sidebar.item :href="route('admin.settings')" title="Settings" collapse />
                    <x-sidebar.item :href="route('vote.overview')" title="Votes" collapse />
                    <x-sidebar.item :href="route('admin.logs')" title="Logs" collapse />
                @endcan

                @can('users.manage')
                    <x-sidebar.item :href="route('admin.templates')" title="Notification templates" collapse />
                @endcan
                @can('viewAny', App\Models\Position::class)
                    <x-sidebar.item :href="route('positions.index')" title="Positions" collapse />
                @endcan
                {{-- VATSSA: what the training pipeline says, and which Moodle
                     course each rating sits. Both change more often than the
                     code does, so neither should need a deploy. --}}
                @can('system.settings.manage')
                    <x-sidebar.item :href="route('vatssa.admin.routing')" title="Request routing" collapse />
                    <x-sidebar.item :href="route('vatssa.admin.mentorship')" title="Mentorship" collapse />
                    <x-sidebar.item :href="route('vatssa.admin.templates')" title="Pipeline templates" collapse />
                    <x-sidebar.item :href="route('vatssa.admin.courses')" title="Moodle courses" collapse />
                @endcan
            </x-sidebar.section>

        @endif

        {{-- Divider --}}
        <div class="sidebar-divider d-none d-md-block"></div>

        @if(Config::get('app.env') != "production")
            <div class="alert alert-warning mt-2 fs-sm" role="alert">
                Development Env
            </div>
        @endif

        {{--  Logo and version element --}}
        <div class="d-flex flex-column align-items-center mt-auto mb-3">
            <a href="{{ Setting::get('linkHome') }}" class="d-block"><img class="logo" src="{{ asset('images/logos/'.Config::get('app.logo')) }}"></a>
            <a href="https://github.com/Vatsim-Scandinavia/controlcenter" target="_blank" class="version">Control Center v{{ config('app.version') }}</a>
        </div>

    </ul>

</nav>
