{{--
    VATSSA: navigation for the Tailwind pages.

    Mirrors Control Center's own sidebar, section for section, so the preview
    can be judged as a whole application rather than as three loose pages.

    Only what this person can actually reach. A sidebar that shows doors you
    cannot open teaches people to ignore the sidebar, which is the bug behind
    "members still see a Member section" -- the same mistake, one layout up.

    Grouped by WHO you are rather than by what the feature is called. Somebody
    hunting for a page thinks "that's about me" or "that's about a student",
    never "that's in the endorsements module".
--}}
@php
    $user = Auth::user();

    $link = 'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors';
    $idle = 'text-ink-soft hover:bg-card-header hover:text-ink '
        . '';
    $on = 'bg-brand-wash text-brand-strong';

    $preview = Route::is('vatssa.preview.*');

    // The preview mirrors Control Center; the live pages are the real thing.
    // One nav, two destinations, chosen by where you already are -- otherwise
    // clicking around the mirror drops you back into Bootstrap at random, and
    // the point of the mirror is to see it whole.
    $to = function (string $previewRoute, string $realRoute, ...$args) use ($preview) {
        return $preview && Route::has($previewRoute)
            ? route($previewRoute, $args)
            : route($realRoute, $args);
    };

    $groups = [
        'Mine' => [
            ['My profile', $to('vatssa.preview.profile', 'user.show', $user->id), 'user', true],
            ['My availability', route('vatssa.availability'), 'calendar', true],
            ['My students', $to('vatssa.preview.mentor', 'mentor'), 'academic',
                $user->can('training.mentor')],
        ],
        'Training' => [
            // Upstream names these routes 'requests' and 'tasks'. Labelled here
            // as they read, not as they are routed.
            ['Open requests', $to('vatssa.preview.trainings', 'requests'), 'academic',
                $user->can('training.view')],
            // The queue and theory, split off the open list: the bot is
            // handling them and there is nothing to decide.
            ['System requests', $to('vatssa.preview.trainings.system', 'requests.system'), 'bolt',
                $user->can('training.view')],
            ['Closed requests', $to('vatssa.preview.trainings.closed', 'requests.history'), 'clock',
                $user->can('training.view')],
            ['Tasks', $to('vatssa.preview.tasks', 'tasks'), 'inbox', $user->can('tasks.manage')],
            ['Bookings', $to('vatssa.preview.bookings', 'booking'), 'calendar', true],
        ],
        'Members' => [
            ['Member overview', $to('vatssa.preview.users', 'users'), 'users',
                $user->can('users.manage')],
            ['Solo endorsements', $preview
                ? route('vatssa.preview.endorsements', 'solo')
                : route('endorsements.solos'), 'check', $user->can('endorsements.rosters.view')],
            ['Examiner endorsements', $preview
                ? route('vatssa.preview.endorsements', 'examiner')
                : route('endorsements.examiners'), 'check', $user->can('endorsements.rosters.view')],
            ['Visiting endorsements', $preview
                ? route('vatssa.preview.endorsements', 'visiting')
                : route('endorsements.visiting'), 'check', $user->can('endorsements.rosters.view')],
        ],
        'Running it' => [
            ['Automation log', route('vatssa.action-log'), 'bolt',
                $user->can('fir.management.reports.view')],
            ['Mentorship', route('vatssa.admin.mentorship'), 'users',
                $user->can('training.mentor-dashboard.view')],
            ['Request desks', route('vatssa.admin.routing'), 'inbox',
                $user->can('system.settings.manage')],
            ['Pipeline templates', route('vatssa.admin.templates'), 'check',
                $user->can('system.settings.manage')],
            ['Moodle courses', route('vatssa.admin.courses'), 'academic',
                $user->can('system.settings.manage')],
            ['Positions', $to('vatssa.preview.positions', 'positions.index'), 'bolt',
                $user->can('fir.positions.view')],
            ['Settings', $to('vatssa.preview.settings', 'admin.settings'), 'check',
                $user->can('system.settings.manage')],
            ['Activity log', $to('vatssa.preview.logs', 'admin.logs'), 'clock',
                $user->can('system.settings.manage')],
        ],
    ];
@endphp

@foreach($groups as $heading => $items)
    @php $visible = array_values(array_filter($items, fn ($i) => $i[3])); @endphp

    @if($visible)
        <div>
            <p class="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-ink-faint">
                {{ $heading }}
            </p>

            <div class="space-y-0.5">
                @foreach($visible as [$label, $href, $icon, $allowed])
                    @php $active = Request::url() === strtok($href, '?'); @endphp
                    <a href="{{ $href }}" class="{{ $link }} {{ $active ? $on : $idle }}">
                        <span class="shrink-0 {{ $active ? '' : 'text-ink-faint' }}">
                            @include('vatssa.parts.icon', ['name' => $icon])
                        </span>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@endforeach

{{-- THE TAILWIND PREVIEW -- DELETE THIS BLOCK TO REVERT. --}}
@can('fir.management.reports.view')
    <div class="border-t border-line pt-4">
        @if($preview)
            <a href="{{ route('dashboard') }}" class="{{ $link }} {{ $idle }}">
                <span class="shrink-0 text-ink-faint">
                    @include('vatssa.parts.icon', ['name' => 'back'])
                </span>
                Leave the preview
            </a>
        @else
            <a href="{{ route('vatssa.preview.dashboard') }}" class="{{ $link }} {{ $idle }}">
                <span class="shrink-0 text-ink-faint">
                    @include('vatssa.parts.icon', ['name' => 'bolt'])
                </span>
                Tailwind preview
            </a>
        @endif
    </div>
@endcan

{{-- Back to the rest of Control Center. These pages are an island until the
     migration finishes, and an island with no bridge is a trap. --}}
@unless($preview)
    <div class="border-t border-line pt-4">
        <a href="{{ route('dashboard') }}" class="{{ $link }} {{ $idle }}">
            <span class="shrink-0 text-ink-faint">
                @include('vatssa.parts.icon', ['name' => 'back'])
            </span>
            Control Center
        </a>
    </div>
@endunless
