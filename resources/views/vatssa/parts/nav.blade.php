{{--
    VATSSA: navigation for the Tailwind pages.

    ## The order here is Control Center's own

    Section for section, item for item, in the same sequence as
    `layouts/sidebar.blade.php`: Dashboard, My profile, Tasks, Booking, My
    students, Sweatbox, Requests, Users, Endorsements, Reports, Administration.

    It used to be grouped by "who you are" -- Mine / Training / Members /
    Running it -- which reads better in isolation and was the wrong call.
    Somebody moving between the two versions has to relearn where everything
    lives, and a preview that reorganises the menu is answering a question
    nobody asked while making the one comparison it exists for harder. If the
    grouping is worth changing, change it in BOTH and change it deliberately.

    THIS FILE AND `layouts/sidebar.blade.php` MUST STAY IN STEP. They are not
    generated from a shared definition yet; that is the right fix and it is a
    bigger change than this one. Until then, adding an item to either means
    adding it to both.

    Only what this person can actually reach. A sidebar that shows doors you
    cannot open teaches people to ignore the sidebar.
--}}
@php
    $user = Auth::user();

    $link = 'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors';
    $idle = 'text-ink-soft hover:bg-card-header hover:text-ink';
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

    // Ungrouped, above everything, exactly as upstream has them.
    $top = [
        ['Dashboard', $to('vatssa.preview.dashboard', 'dashboard'), 'bolt', true],
        ['My profile', $to('vatssa.preview.profile', 'user.show', $user->id), 'user', true],
        ['My availability', route('vatssa.availability'), 'calendar', true],
        ['Tasks', $to('vatssa.preview.tasks', 'tasks'), 'inbox', $user->can('tasks.manage')],
        ['Booking', $to('vatssa.preview.bookings', 'booking'), 'calendar', true],
        ['My students', $to('vatssa.preview.mentor', 'mentor'), 'academic',
            $user->can('training.mentor')],
        // Everybody: a student finds their own exam here, an examiner finds
        // work, the events team finds what is waiting on them.
        ['Practical exams', route('vatssa.exams.index'), 'clock', true],
    ];

    $groups = [
        'Requests' => [
            ['Open Requests', $to('vatssa.preview.trainings', 'requests'), 'academic',
                $user->can('training.view')],
            // The queue and theory, split off the open list: the bot is
            // handling them and there is nothing to decide.
            ['System Requests', $to('vatssa.preview.trainings.system', 'requests.system'), 'bolt',
                $user->can('training.view')],
            ['Closed Requests', $to('vatssa.preview.trainings.closed', 'requests.history'), 'clock',
                $user->can('training.view')],
        ],
        'Users' => [
            ['Member Overview', $to('vatssa.preview.users', 'users'), 'users',
                $user->can('users.manage')],
        ],
        'Endorsements' => [
            ['Solo', $preview ? route('vatssa.preview.endorsements', 'solo') : route('endorsements.solos'),
                'check', $user->can('endorsements.rosters.view')],
            ['Examiner', $preview ? route('vatssa.preview.endorsements', 'examiner') : route('endorsements.examiners'),
                'check', $user->can('endorsements.rosters.view')],
            ['Visiting', $preview ? route('vatssa.preview.endorsements', 'visiting') : route('endorsements.visiting'),
                'check', $user->can('endorsements.rosters.view')],
        ],
        'Reports' => [
            ['Automation log', route('vatssa.action-log'), 'bolt',
                $user->can('fir.management.reports.view')],
            ['Activity log', $to('vatssa.preview.logs', 'admin.logs'), 'clock',
                $user->can('system.settings.manage')],
        ],
        'Administration' => [
            ['Settings', $to('vatssa.preview.settings', 'admin.settings'), 'check',
                $user->can('system.settings.manage')],
            ['Positions', $to('vatssa.preview.positions', 'positions.index'), 'bolt',
                $user->can('fir.positions.view')],
            ['Request routing', route('vatssa.admin.routing'), 'inbox',
                $user->can('system.settings.manage')],
            ['Mentorship', route('vatssa.admin.mentorship'), 'users',
                $user->can('training.mentor-dashboard.view')],
            ['Pipeline templates', route('vatssa.admin.templates'), 'check',
                $user->can('system.settings.manage')],
            ['Moodle courses', route('vatssa.admin.courses'), 'academic',
                $user->can('system.settings.manage')],
        ],
    ];

    $active = fn (string $href) => Request::url() === strtok($href, '?');
@endphp

<div class="space-y-0.5">
    @foreach(array_filter($top, fn ($i) => $i[3]) as [$label, $href, $icon, $allowed])
        <a href="{{ $href }}" class="{{ $link }} {{ $active($href) ? $on : $idle }}">
            <span class="shrink-0 {{ $active($href) ? '' : 'text-ink-faint' }}">
                @include('vatssa.parts.icon', ['name' => $icon])
            </span>
            {{ $label }}
        </a>
    @endforeach
</div>

@foreach($groups as $heading => $items)
    @php $visible = array_values(array_filter($items, fn ($i) => $i[3])); @endphp

    @if($visible)
        <div>
            <p class="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-ink-faint">
                {{ $heading }}
            </p>

            <div class="space-y-0.5">
                @foreach($visible as [$label, $href, $icon, $allowed])
                    <a href="{{ $href }}" class="{{ $link }} {{ $active($href) ? $on : $idle }}">
                        <span class="shrink-0 {{ $active($href) ? '' : 'text-ink-faint' }}">
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
        <a href="{{ $preview ? route('dashboard') : route('vatssa.preview.dashboard') }}"
           class="{{ $link }} {{ $idle }}">
            <span class="shrink-0 text-ink-faint">
                @include('vatssa.parts.icon', ['name' => $preview ? 'back' : 'bolt'])
            </span>
            {{ $preview ? 'Leave the preview' : 'Tailwind preview' }}
        </a>
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
