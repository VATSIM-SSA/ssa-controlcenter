{{--
    VATSSA: navigation for the Tailwind pages.

    Only what this person can actually reach. A sidebar that shows doors you
    cannot open teaches people to ignore the sidebar, which is the bug behind
    "members still see a Member section" -- the same mistake, one layout up.

    Grouped by WHO you are rather than by what the feature is called. "Mine"
    is everything about you; "Training" is everything about students; "Running
    it" is everything about the division. Somebody looking for a page thinks in
    those terms, not in module names.
--}}
@php
    $user = Auth::user();

    $link = 'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors';
    $idle = 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 '
        . 'dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100';
    $on = 'bg-brand-50 text-brand-700 dark:bg-brand-950/60 dark:text-brand-300';

    $groups = [
        'Mine' => [
            ['My profile', route('user.show', $user->id), 'user', false],
            ['My availability', route('vatssa.availability'), 'calendar', true],
        ],
        'Training' => [
            // Upstream calls the trainings list 'requests' and the task list
            // 'tasks'. Named here as they read, not as they are routed.
            ['Trainings', route('requests'), 'academic', $user->can('training.view')],
            ['Requests', route('tasks'), 'inbox', $user->can('tasks.manage')],
        ],
        'Running it' => [
            ['Automation log', route('vatssa.action-log'), 'bolt',
                $user->can('fir.management.reports.view')],
            ['Mentorship', route('vatssa.admin.mentorship'), 'users',
                $user->can('training.mentor-dashboard.view')],
        ],
    ];
@endphp

@foreach($groups as $heading => $items)
    @php $visible = array_values(array_filter($items, fn ($i) => $i[3])); @endphp

    @if($visible)
        <div>
            <p class="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-neutral-400
                      dark:text-neutral-500">
                {{ $heading }}
            </p>

            <div class="space-y-0.5">
                @foreach($visible as [$label, $href, $icon, $allowed])
                    @php $active = Request::url() === strtok($href, '?'); @endphp
                    <a href="{{ $href }}" class="{{ $link }} {{ $active ? $on : $idle }}">
                        <span class="shrink-0 {{ $active ? '' : 'text-neutral-400 dark:text-neutral-500' }}">
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
    <div>
        <p class="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-neutral-400
                  dark:text-neutral-500">
            Preview
        </p>
        <div class="space-y-0.5">
            @foreach([
                ['Dashboard', route('vatssa.preview.dashboard')],
                ['Trainings table', route('vatssa.preview.trainings')],
                ['Profile', route('vatssa.preview.profile', Auth::id())],
            ] as [$label, $href])
                <a href="{{ $href }}" class="{{ $link }} {{ Request::url() === $href ? $on : $idle }}">
                    <span class="shrink-0 text-neutral-400 dark:text-neutral-500">
                        @include('vatssa.parts.icon', ['name' => 'check'])
                    </span>
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>
@endcan

{{-- Back to the rest of Control Center. These pages are an island until the
     migration finishes, and an island with no bridge is a trap. --}}
<div class="border-t border-neutral-200 pt-4 dark:border-neutral-800">
    <a href="{{ route('dashboard') }}" class="{{ $link }} {{ $idle }}">
        <span class="shrink-0 text-neutral-400 dark:text-neutral-500">
            @include('vatssa.parts.icon', ['name' => 'back'])
        </span>
        Control Center
    </a>
</div>
