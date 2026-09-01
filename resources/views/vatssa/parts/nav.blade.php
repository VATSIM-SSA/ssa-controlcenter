{{--
    VATSSA: navigation for the Tailwind pages.

    ## What these pages are, now that the preview is gone

    Availability, practical exams, the automation log and the four admin pages.
    Features Control Center does not have, built on their own layout rather than
    retrofitted into Bootstrap markup.

    Everything upstream already had keeps its own page and its own look; the
    restyle in `resources/sass/_migration.scss` handles that, and it handles all
    of it at once rather than one blade at a time. The `/vatssa/preview` mirror
    that used to hang off this file existed to answer whether a migration was
    worth it. It was, the restyle is how, and a read-only copy of pages that
    already work is a second thing to keep in step.

    ## The order is Control Center's own

    Same sequence as `layouts/sidebar.blade.php`, so moving between a Tailwind
    page and a Bootstrap one does not mean relearning where anything lives.

    THIS FILE AND `layouts/sidebar.blade.php` MUST STAY IN STEP -- same
    sections, same items, same order, same permission on each. They are not
    generated from a shared definition, so adding an item to either means adding
    it to both. That duplication is the one loose thread left in this design and
    it is worth closing; until then, this comment is the seam.

    A Tailwind page linking out to a Bootstrap one is normal and expected. What
    is NOT acceptable is the menu changing shape when you cross between them:
    somebody hunting for Positions should find it in the same place on both, or
    they learn to distrust the sidebar on one of them.

    Only what this person can actually reach. A sidebar that shows doors you
    cannot open teaches people to ignore the sidebar.
--}}
@php
    $user = Auth::user();

    $link = 'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors';
    $idle = 'text-ink-soft hover:bg-card-header hover:text-ink';
    $on = 'bg-brand-wash text-brand-strong';

    $top = [
        ['Dashboard', route('dashboard'), 'bolt', true],
        ['My profile', route('user.show', $user->id), 'user', true],
        ['My availability', route('vatssa.availability'), 'calendar', true],
        ['Practical exams', route('vatssa.exams.index'), 'clock', true],
        ['Tasks', route('tasks'), 'inbox', $user->can('tasks.manage')],
        ['Booking', route('booking'), 'calendar', true],
        ['My students', route('mentor'), 'academic', $user->can('training.mentor')],
        ['Sweatbox Calendar', route('sweatbook'), 'calendar', $user->can('training.mentor')],
    ];

    $groups = [
        'Requests' => [
            ['Open Requests', route('requests'), 'academic', $user->can('training.view')],
            ['System Requests', route('requests.system'), 'bolt', $user->can('training.view')],
            ['Closed Requests', route('requests.history'), 'clock', $user->can('training.view')],
        ],
        'Users' => [
            ['Member Overview', route('users'), 'users', $user->can('users.manage')],
            ['Other Users', route('users.other'), 'users', $user->can('users.manage')],
        ],
        'Endorsements' => [
            ['Solo', route('endorsements.solos'), 'check',
                $user->can('endorsements.rosters.view')],
            ['Examiner', route('endorsements.examiners'), 'check',
                $user->can('endorsements.rosters.view')],
            ['Visiting', route('endorsements.visiting'), 'check',
                $user->can('endorsements.rosters.view')],
        ],
        'Reports' => [
            ['Training Statistics', route('reports.trainings'), 'academic',
                $user->can('training.statistics.view')],
            ['Training Activities', route('reports.activities'), 'clock',
                $user->can('training.activities.view')],
            ['Mentors', route('reports.mentors'), 'users',
                $user->can('fir.management.reports.view')],
            ['Access', route('reports.access'), 'users',
                $user->can('viewAccessReport', \App\Models\ManagementReport::class)],
            ['Feedback', route('reports.feedback'), 'check',
                $user->can('fir.management.reports.view')],
            ['Automation log', route('vatssa.action-log'), 'bolt',
                $user->can('fir.management.reports.view')],
        ],
        'Administration' => [
            ['Settings', route('admin.settings'), 'check', $user->can('system.health.view')],
            ['Votes', route('vote.overview'), 'check', $user->can('system.health.view')],
            ['Logs', route('admin.logs'), 'clock', $user->can('system.health.view')],
            ['Notification templates', route('admin.templates'), 'inbox',
                $user->can('users.manage')],
            ['Positions', route('positions.index'), 'bolt',
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

{{-- These pages are a handful in an application of hundreds, and an island
     with no bridge is a trap. --}}
<div class="border-t border-line pt-4">
    <a href="{{ route('dashboard') }}" class="{{ $link }} {{ $idle }}">
        <span class="shrink-0 text-ink-faint">
            @include('vatssa.parts.icon', ['name' => 'back'])
        </span>
        Control Center
    </a>
</div>
