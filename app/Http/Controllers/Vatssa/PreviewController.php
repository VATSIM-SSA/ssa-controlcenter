<?php

namespace App\Http\Controllers\Vatssa;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\ActivityLevel;
use App\Helpers\TaskStatus;
use App\Helpers\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TrainingController;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Endorsement;
use App\Models\Position;
use App\Models\Rating;
use App\Models\Task;
use App\Models\Training;
use App\Models\TrainingExamination;
use App\Models\TrainingInterest;
use App\Models\TrainingReport;
use App\Models\User;
use App\Models\Vatssa\InternalNote;
use App\Models\Vatssa\MessageLog;
use App\Models\Vatssa\RequestTarget;
use App\Models\Vatssa\TheoryAttempt;
use App\Models\Vatssa\UserPlatform;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * VATSSA: Control Center, mirrored in Tailwind.
 *
 * ## This is a look, not a rewrite
 *
 * Every page here is a PARALLEL copy under `/vatssa/preview`. It reads the
 * same data through the same models and writes nothing: the upstream
 * dashboard, profiles, tables and reports are untouched and keep working
 * exactly as they do today.
 *
 * That is the entire point. Restyling the real pages means editing 554 blades
 * that upstream also edits, which is a merge conflict on every one of them for
 * ever, maintained by one person who also has school. This shows the
 * destination without paying for the journey.
 *
 * ## Why the list pages share a template
 *
 * Control Center is mostly tables. Twenty bespoke blades would mean twenty
 * places to fix a spacing bug and a preview that fails at the one thing it
 * exists to demonstrate: what a CONSISTENT Tailwind Control Center feels like.
 * So the lists are data, rendered by `preview/list.blade.php`, and only the
 * pages that are genuinely not tables get their own file.
 *
 * ## READ ONLY, deliberately
 *
 * No form on any preview page posts anywhere. A mirror that can also write is
 * two code paths to the same table, and the second one has no tests, no
 * policies of its own and none of the guards the real controllers have grown.
 * Everything links back to the real page to act.
 *
 * ## How to revert
 *
 * Delete this controller, `resources/views/vatssa/preview/`, the preview route
 * group in `routes/vatssa-web.php`, and the marked block in
 * `resources/views/vatssa/parts/nav.blade.php`. That is the whole footprint.
 * The Tailwind entry point and layout stay -- the availability tool uses them
 * for real.
 */
class PreviewController extends Controller
{
    /**
     * How many rows any one preview page will render.
     *
     * The real tables paginate. Mirroring pagination would mean mirroring the
     * query strings, the sorting and the filters -- a lot of work to look at
     * page two of a page nobody is going to keep. A cap says the same thing
     * about the design and stops a 4,000-row table taking the browser down.
     */
    private const CAP = 100;

    // -----------------------------------------------------------------
    // Pages that are not tables
    // -----------------------------------------------------------------

    public function dashboard(): View
    {
        $user = Auth::user();

        return view('vatssa.preview.dashboard', [
            'user' => $user,
            'training' => Training::where('user_id', $user->id)->latest()->first(),
            // The three numbers a coordinator opens the dashboard to see. On
            // the real dashboard they are three separate pages.
            'queueDepth' => Training::where('status', TrainingStatus::IN_QUEUE)->count(),
            'awaitingMentor' => Training::where('status', TrainingStatus::AWAITING_MENTOR)->count(),
            'inTraining' => Training::where('status', TrainingStatus::ACTIVE_TRAINING)->count(),
            'myTasks' => Task::where('assignee_user_id', $user->id)
                ->where('status', TaskStatus::PENDING)->count(),
        ]);
    }

    public function profile(User $user): View
    {
        return view('vatssa.preview.profile', [
            // roleAssignments, not roles -- User has no roles relation, and an
            // eager load of one that does not exist throws at render time.
            'user' => $user->load('endorsements.ratings', 'roleAssignments'),
            'trainings' => Training::where('user_id', $user->id)
                ->with('ratings', 'mentors')->latest()->get(),
        ]);
    }

    /**
     * The training page, which is the one that matters most.
     *
     * It is where a coordinator spends their day, it is the densest page in
     * the application, and it is the page the timeline lives on. If a
     * migration would not improve this one, it is not worth doing.
     */
    public function training(Training $training): View
    {
        $this->authorize('view', $training);

        // Reports and examinations, merged and newest first, exactly as the
        // real page assembles them -- they are one story about a student, and
        // splitting them into two lists means reading the training in two
        // passes to find out what happened when.
        //
        // Gate::allows('view', ...) per item, NOT once for the training. Report
        // visibility is per report upstream (a draft is the author's until it
        // is filed), and a mirror that showed drafts the real page hides would
        // be a data leak wearing a preview's clothes.
        $reports = TrainingReport::where('training_id', $training->id)
            ->with('author')->get();
        $exams = TrainingExamination::where('training_id', $training->id)
            ->with('examiner', 'position')->get();

        $reportsAndExams = $reports->merge($exams)
            ->filter(fn ($item) => Gate::allows('view', $item))
            ->sortByDesc(fn ($item) => $item instanceof TrainingReport
                ? Carbon::parse($item->report_date)
                : Carbon::parse($item->examination_date))
            ->values();

        // Everything the real page needs to render its forms. Mirroring a
        // page means mirroring what it can DO, not only what it says: a
        // training page you cannot edit, comment on or close is a screenshot.
        return view('vatssa.preview.training', [
            'training' => $training->load('user', 'ratings', 'mentors', 'area'),
            // Mentors eligible for THIS training's area and ratings, exactly as
            // upstream picks them. Offering every mentor in the division would
            // let somebody assign a C1 mentor to an S1 student.
            'trainingMentors' => $training->area->mentors->sortBy('name'),
            'statuses' => TrainingStatus::inLifecycleOrder(),
            'notes' => InternalNote::where('scope', InternalNote::SCOPE_TRAINING)
                ->where('training_id', $training->id)
                ->with('author')->latest()->get(),
            'requestTypes' => TaskController::getTypes(),
            'desks' => RequestTarget::choicesForTraining($training),
            'activities' => $training->activities()->latest()->limit(40)->get(),
            'tasks' => Task::where('subject_training_id', $training->id)
                ->with('assignee', 'creator')->latest()->get(),
            'reportsAndExams' => $reportsAndExams,
            'interests' => TrainingInterest::where('training_id', $training->id)
                ->latest()->get(),
            'types' => TrainingController::$types,
            // The VATSSA panels. Read straight from the models rather than
            // through the Bootstrap partials, which cannot be reused here --
            // they render inside a page that loads app.scss and this one does
            // not.
            'platforms' => UserPlatform::find($training->user_id),
            'attempts' => TheoryAttempt::where('user_id', $training->user_id)
                ->orderByDesc('taken_at')->get(),
            'messages' => MessageLog::where('training_id', $training->id)
                ->orderByDesc('sent_at')->limit(20)->get(),
        ]);
    }

    // -----------------------------------------------------------------
    // Everything that is a table
    // -----------------------------------------------------------------

    /**
     * Trainings a person has to work.
     *
     * The queue and theory are NOT here -- they are the system's, and mixed in
     * they were most of the list. See TrainingController::systemRequests().
     */
    public function trainings(): View
    {
        return $this->trainingList(
            'Open requests',
            [TrainingStatus::AWAITING_MENTOR, TrainingStatus::ACTIVE_TRAINING,
                TrainingStatus::AWAITING_EXAM],
            'Trainings that need a person: awaiting a mentor, being mentored, or waiting '
                . 'on a CPT. Students still in the queue or in theory are with the system.',
            'Nothing needs you.',
        );
    }

    /**
     * Trainings the pipeline is handling on its own.
     *
     * In-queue and theory. The bot enrols them, chases them and moves them on;
     * a coordinator changes nothing by looking. They cross to the open list the
     * moment they pass theory and need a mentor, which is the exact point the
     * work stops being automatic.
     */
    public function systemRequests(): View
    {
        return $this->trainingList(
            'System requests',
            [TrainingStatus::IN_QUEUE, TrainingStatus::PRE_TRAINING],
            'In the queue or working through theory. The pipeline handles these by '
                . 'itself — there is nothing here to decide.',
            'Nobody is in the queue.',
        );
    }

    /**
     * @param  array<int, TrainingStatus>  $statuses
     */
    private function trainingList(string $heading, array $statuses,
        string $blurb, string $empty): View
    {
        $trainings = Training::with('user', 'ratings', 'mentors')
            ->whereIn('status', $statuses)
            ->limit(self::CAP)->get()
            ->sortBy(fn (Training $t) => $t->status->lifecycleOrder());

        return $this->list($heading, [
            'Student', 'Rating', 'Stage', 'Mentor', ['label' => 'Waiting', 'align' => 'right'],
        ], $trainings->map(fn (Training $t) => [
            'cells' => [
                $this->link(route('vatssa.preview.training', $t), $t->user?->name ?? 'Unknown')
                    . $this->sub($t->user_id),
                e($t->ratings->pluck('name')->join(' + ')) ?: '—',
                $this->stage($t->status),
                $t->mentors->isNotEmpty()
                    ? e($t->mentors->pluck('name')->join(', '))
                    : $this->muted('nobody'),
                e($t->created_at?->diffForHumans(null, true)),
            ],
            // The two questions actually asked of this list: which rating, and
            // which stage. "S2 students awaiting a mentor" is one query, not a
            // scroll.
            'meta' => [
                'rating' => $t->ratings->pluck('name')->join(' + '),
                'stage' => $t->status->label(),
                'mentored' => $t->mentors->isNotEmpty() ? 'Has a mentor' : 'No mentor',
            ],
        ])->values()->all(),
            $empty,
            $blurb,
            // Options come from what is ON the list, not from every rating that
            // exists. A dropdown offering a filter that can only return nothing
            // is a dropdown that teaches people not to trust it.
            [
                ['key' => 'rating', 'label' => 'Rating',
                    'options' => $trainings->pluck('ratings')->flatten()->pluck('name')
                        ->unique()->sort()->values()->all()],
                ['key' => 'stage', 'label' => 'Stage',
                    'options' => $trainings->map(fn (Training $t) => $t->status)
                        ->unique()->sortBy(fn ($s) => $s->lifecycleOrder())
                        ->map(fn ($s) => $s->label())->values()->all()],
                ['key' => 'mentored', 'label' => 'Mentor',
                    'options' => ['Has a mentor', 'No mentor']],
            ]);
    }

    public function closedTrainings(): View
    {
        return $this->list('Closed requests', [
            'Student', 'Rating', 'Outcome', ['label' => 'Closed', 'align' => 'right'],
        ], Training::with('user', 'ratings')
            ->where('status', '<', TrainingStatus::IN_QUEUE)
            ->latest('closed_at')->limit(self::CAP)->get()
            ->map(fn (Training $t) => [
                'cells' => [
                    $this->link(route('vatssa.preview.training', $t), $t->user?->name ?? 'Unknown')
                        . $this->sub($t->user_id),
                    e($t->ratings->pluck('name')->join(' + ')) ?: '—',
                    $this->stage($t->status),
                    e($t->closed_at?->format('j M Y') ?? '—'),
                ],
                'meta' => [
                    'rating' => $t->ratings->pluck('name')->join(' + '),
                    'outcome' => $t->status->label(),
                ],
            ])->values()->all(),
            'Nothing closed yet.',
            null,
            [
                ['key' => 'rating', 'label' => 'Rating',
                    'options' => Rating::whereNotNull('vatsim_rating')
                        ->orderBy('vatsim_rating')->pluck('name')->all()],
                ['key' => 'outcome', 'label' => 'Outcome',
                    'options' => collect(TrainingStatus::cases())
                        ->filter(fn ($s) => $s->isClosed())
                        ->map(fn ($s) => $s->label())->values()->all()],
            ]);
    }

    public function users(): View
    {
        return $this->list('Member overview', [
            'Member', 'Rating', 'Roles', ['label' => 'Last seen', 'align' => 'right'],
        ], User::with('roleAssignments')
            ->orderBy('first_name')->limit(self::CAP)->get()
            ->map(fn (User $u) => [
                'cells' => [
                    $this->link(route('vatssa.preview.profile', $u), $u->name) . $this->sub($u->id),
                    e($u->rating?->name ?? '—'),
                    $u->roleAssignments->isNotEmpty()
                        ? $this->pills($u->roleAssignments->pluck('role')->unique()->all())
                        : $this->muted('member'),
                    e($u->last_login?->diffForHumans() ?? 'never'),
                ],
                'meta' => [
                    'rating' => $u->rating?->name ?? '—',
                    'role' => $u->roleAssignments->pluck('role')->first() ?? 'member',
                ],
            ])->values()->all(),
            null, null,
            [
                ['key' => 'role', 'label' => 'Role',
                    'options' => collect(config('roles.roles'))->keys()->push('member')->all()],
            ]);
    }

    public function tasks(): View
    {
        return $this->list('Tasks', [
            'What', 'About', 'Desk', 'State', ['label' => 'Raised', 'align' => 'right'],
        ], Task::with('subject', 'assignee', 'subjectTraining')
            ->latest()->limit(self::CAP)->get()
            ->map(fn (Task $t) => [
                'cells' => [
                    e($t->type()->getName()),
                    $t->subject ? e($t->subject->name) : $this->muted('nobody in particular'),
                    $t->vatssa_tier
                        ? e(RequestTarget::label($t->vatssa_tier))
                        : $this->muted('unrouted'),
                    $this->taskState($t->status),
                    e($t->created_at?->diffForHumans(null, true)),
                ],
                'meta' => [
                    'desk' => $t->vatssa_tier
                        ? RequestTarget::label($t->vatssa_tier)
                        : 'Unrouted',
                    'state' => match ($t->status) {
                        TaskStatus::COMPLETED => 'Done',
                        TaskStatus::DECLINED => 'Declined',
                        default => 'Open',
                    },
                    'kind' => $t->type()->getName(),
                ],
            ])->values()->all(),
            'No tasks.',
            'A request belongs to a desk, not to a person. Everybody at a desk sees the same queue.',
            [
                ['key' => 'state', 'label' => 'State', 'options' => ['Open', 'Done', 'Declined']],
                ['key' => 'desk', 'label' => 'Desk',
                    'options' => collect(RequestTarget::TIERS)
                        ->pluck('label')->push('Unrouted')->all()],
                ['key' => 'kind', 'label' => 'Kind',
                    'options' => collect(TaskController::getTypes())
                        ->map(fn ($type) => $type->getName())->sort()->values()->all()],
            ],
            // The one write the mirror allows. See newRequest().
            ['Raise a request' => route('vatssa.preview.request')]);
    }

    public function endorsements(string $type): View
    {
        $label = ucfirst($type);

        return $this->list($label . ' endorsements', [
            'Member', 'Ratings', 'Valid from', ['label' => 'Valid to', 'align' => 'right'],
        ], Endorsement::with('user', 'ratings')
            ->where('type', strtoupper($type))
            ->where('revoked', false)
            ->orderBy('valid_to')->limit(self::CAP)->get()
            ->map(fn (Endorsement $e) => [
                'cells' => [
                    $e->user
                        ? $this->link(route('vatssa.preview.profile', $e->user), $e->user->name)
                        : $this->muted('Unknown'),
                    e($e->ratings->pluck('name')->join(', ')) ?: '—',
                    e($e->valid_from?->format('j M Y') ?? '—'),
                    $e->valid_to
                        ? $this->expiry($e->valid_to)
                        : $this->muted('no expiry'),
                ],
                'meta' => [
                    'rating' => $e->ratings->pluck('name')->join(', '),
                    // Expiring is the reason anybody opens this page, so it is
                    // a filter rather than something to spot by eye.
                    'window' => $e->valid_to === null
                        ? 'No expiry'
                        : (Carbon::parse($e->valid_to)->lessThan(now()->addDays(30))
                            ? 'Within 30 days' : 'Later'),
                ],
            ])->values()->all(),
            'None granted.',
            null,
            [
                ['key' => 'rating', 'label' => 'Rating',
                    'options' => Rating::whereNotNull('vatsim_rating')
                        ->orderBy('vatsim_rating')->pluck('name')->all()],
                ['key' => 'window', 'label' => 'Expiry',
                    'options' => ['Within 30 days', 'Later', 'No expiry']],
            ]);
    }

    public function bookings(): View
    {
        return $this->list('Bookings', [
            'Controller', 'Position', 'Kind', 'From', ['label' => 'To', 'align' => 'right'],
        ], Booking::with('user', 'position')
            ->where('time_end', '>=', now()->subDays(7))
            ->orderBy('time_start')->limit(self::CAP)->get()
            ->map(fn (Booking $b) => [
                e($b->user?->name ?? $b->name ?? 'Unknown') . $this->sub($b->cid),
                e($b->position?->callsign ?? $b->callsign ?? '—'),
                $this->pills(array_values(array_filter([
                    $b->training ? 'training' : null,
                    $b->exam ? 'exam' : null,
                    $b->event ? 'event' : null,
                ])) ?: ['booking']),
                e(Carbon::parse($b->time_start)->format('j M · H:i') . 'z'),
                e(Carbon::parse($b->time_end)->format('H:i') . 'z'),
            ])->values()->all(),
            'Nothing booked.',
            'All times Zulu, as they are everywhere else in this application.');
    }

    public function positions(): View
    {
        return $this->list('Positions', [
            'Callsign', 'Name', 'Frequency', ['label' => 'Rating', 'align' => 'right'],
        ], Position::orderBy('callsign')->limit(self::CAP)->get()
            ->map(fn (Position $p) => [
                '<span class="font-mono">' . e($p->callsign) . '</span>',
                e($p->name ?? '—'),
                e($p->frequency ?? '—'),
                // rating is cast to a VatsimRating enum, and e() on an enum is
                // a TypeError rather than a string.
                e($p->rating?->name ?? '—'),
            ])->values()->all());
    }

    public function mentorStudents(): View
    {
        return $this->list('My students', [
            'Student', 'Rating', 'Stage', ['label' => 'With me', 'align' => 'right'],
        ], Auth::user()->teaches()->with('user', 'ratings')->get()
            ->map(fn (Training $t) => [
                $this->link(route('vatssa.preview.training', $t), $t->user?->name ?? 'Unknown')
                    . $this->sub($t->user_id),
                e($t->ratings->pluck('name')->join(' + ')) ?: '—',
                $this->stage($t->status),
                e($t->started_at?->diffForHumans(null, true) ?? '—'),
            ])->values()->all(),
            'You are not mentoring anybody.');
    }

    // -----------------------------------------------------------------
    // Administration
    // -----------------------------------------------------------------

    /**
     * Division settings, read only.
     *
     * Rendered rather than editable on purpose. Every one of these changes how
     * the whole application behaves -- the ATC activity requirement, the theory
     * window, the grace period -- and a second form writing them, with none of
     * the validation the real settings page has grown, is not a preview. It is
     * a way to break production from a mockup.
     */
    public function settings(): View
    {
        return $this->list('Settings', ['Setting', ['label' => 'Value', 'align' => 'right']],
            collect(Setting::all())
                ->sortKeys()
                ->map(fn ($value, $key) => [
                    'cells' => [
                        '<span class="font-mono text-xs">' . e($key) . '</span>',
                        e(is_bool($value) ? ($value ? 'true' : 'false')
                            : (is_scalar($value) ? (string) $value : json_encode($value))),
                    ],
                ])->values()->all(),
            'Nothing configured.',
            'Read only here. Changing any of these belongs on the real settings page, '
                . 'which has the validation this does not.',
            [],
            ['Open the real settings' => route('admin.settings')]);
    }

    /**
     * The activity log.
     *
     * Different from the automation log, and both are needed. This one records
     * what PEOPLE did; `vatssa_action_log` records what the pipeline did and
     * what it noticed and could not do.
     */
    public function logs(): View
    {
        // The real page authorises on the model, and so does this. A mirror
        // that is easier to reach than the page it mirrors is a hole.
        $this->authorize('index', ActivityLog::class);

        $logs = ActivityLog::with('causer')->latest()->limit(self::CAP)->get();

        return $this->list('Activity log',
            ['What', 'Who', 'Area', 'Level', ['label' => 'When', 'align' => 'right']],
            $logs->map(fn ($entry) => [
                'cells' => [
                    e($entry->description),
                    $entry->causer
                        ? $this->link(route('vatssa.preview.profile', $entry->causer), $entry->causer->name)
                        : $this->muted('system'),
                    e($entry->log_name ?? '—'),
                    e($entry->level?->value ?? '—'),
                    e($entry->created_at?->diffForHumans()),
                ],
                'meta' => [
                    'area' => $entry->log_name ?? '—',
                    'level' => $entry->level?->value ?? '—',
                ],
            ])->values()->all(),
            'Nothing logged.',
            'What PEOPLE did. What the pipeline did, and what it noticed and could not do, '
                . 'is on the automation log.',
            [
                ['key' => 'area', 'label' => 'Area',
                    'options' => $logs->pluck('log_name')->filter()->unique()->sort()->values()->all()],
                ['key' => 'level', 'label' => 'Level',
                    'options' => collect(ActivityLevel::cases())
                        ->map(fn ($case) => $case->value)->all()],
            ]);
    }

    // -----------------------------------------------------------------
    // The one write the mirror allows
    // -----------------------------------------------------------------

    /**
     * Raise a request from the mirror.
     *
     * ## Why this one and nothing else
     *
     * The rest of the preview is read only, deliberately: a mirror that writes
     * is a second code path to the same tables with none of the guards the real
     * controllers have grown. This is the exception because a request queue you
     * cannot add to is not a queue you can judge -- half of what makes the desk
     * model work or not is what it feels like to send something to one.
     *
     * ## It does not write anything itself
     *
     * The form posts to `tasks.store`, upstream's own controller, through
     * upstream's own validation, policy and observer. Nothing here touches the
     * database. That is what keeps it honest: a request raised from the mirror
     * is byte-for-byte a request raised from the real page.
     */
    public function newRequest(): View
    {
        $this->authorize('create', Task::class);

        return view('vatssa.preview.request', [
            // Pre-selected when you arrive from a training page. Arriving with
            // the student already chosen is most of what makes raising a
            // request from their page quicker than from the queue.
            'selected' => request('training'),
            'desks' => RequestTarget::allChoices(),
            'types' => TaskController::getTypes(),
            'trainings' => Training::with('user')
                ->where('status', '>=', TrainingStatus::IN_QUEUE)
                ->limit(self::CAP)->get(),
        ]);
    }

    // -----------------------------------------------------------------
    // Shared rendering
    // -----------------------------------------------------------------

    private function list(string $heading, array $columns, array $rows,
        ?string $empty = null, ?string $blurb = null, array $filters = [],
        array $actions = []): View
    {
        return view('vatssa.preview.list',
            compact('heading', 'columns', 'rows', 'empty', 'blurb', 'filters', 'actions'));
    }

    /**
     * A stage pill.
     *
     * Colour carries meaning here and nowhere else on the row: amber is waiting
     * on us, brand is under way, neutral is waiting on somebody outside the
     * pipeline. Colour the whole row and every row shouts, which is the same as
     * none of them shouting.
     */
    private function stage(TrainingStatus $status): string
    {
        $tone = match ($status) {
            TrainingStatus::AWAITING_MENTOR, TrainingStatus::IN_QUEUE => 'bg-warn-wash text-warn',
            TrainingStatus::ACTIVE_TRAINING, TrainingStatus::PRE_TRAINING => 'bg-brand-wash text-brand-strong',
            TrainingStatus::COMPLETED => 'bg-good-wash text-good',
            default => 'bg-card-header text-ink-soft',
        };

        return '<span class="rounded-md px-2 py-1 text-xs font-medium ' . $tone . '">'
            . e($status->label()) . '</span>';
    }

    private function taskState(TaskStatus $status): string
    {
        [$tone, $label] = match ($status) {
            TaskStatus::COMPLETED => ['bg-good-wash text-good', 'Done'],
            TaskStatus::DECLINED => ['bg-card-header text-ink-soft', 'Declined'],
            default => ['bg-warn-wash text-warn', 'Open'],
        };

        return '<span class="rounded-md px-2 py-1 text-xs font-medium ' . $tone . '">' . $label . '</span>';
    }

    /**
     * An expiry date that says when it is close.
     *
     * The whole reason anybody opens an endorsement list is to find the ones
     * about to lapse, and a column of identical dates does not answer that.
     */
    private function expiry(\DateTimeInterface $date): string
    {
        $soon = Carbon::parse($date)->lessThan(now()->addDays(30));

        return '<span class="' . ($soon ? 'font-medium text-warn' : '') . '">'
            . e(Carbon::parse($date)->format('j M Y')) . '</span>';
    }

    private function link(string $href, string $text): string
    {
        return '<a href="' . e($href) . '" class="font-medium hover:text-brand-strong '
 . '">' . e($text) . '</a>';
    }

    private function sub(int|string|null $text): string
    {
        return $text === null ? ''
            : '<div class="text-xs tabular-nums text-ink-faint">' . e($text) . '</div>';
    }

    private function muted(string $text): string
    {
        return '<span class="text-ink-faint">' . e($text) . '</span>';
    }

    /** @param array<int, string> $items */
    private function pills(array $items): string
    {
        return collect($items)->map(fn ($item) => '<span class="mr-1 inline-block rounded '
 . 'bg-card-header px-1.5 py-0.5 text-[11px] font-medium text-ink-soft '
            . '">' . e($item) . '</span>')->join('');
    }
}
