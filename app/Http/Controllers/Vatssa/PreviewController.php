<?php

namespace App\Http\Controllers\Vatssa;

use App\Helpers\TaskStatus;
use App\Helpers\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Endorsement;
use App\Models\Position;
use App\Models\Task;
use App\Models\Training;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
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

        return view('vatssa.preview.training', [
            'training' => $training->load('user', 'ratings', 'mentors', 'area'),
            'activities' => $training->activities()->latest()->limit(40)->get(),
            'tasks' => Task::where('subject_training_id', $training->id)
                ->with('assignee', 'creator')->latest()->get(),
        ]);
    }

    // -----------------------------------------------------------------
    // Everything that is a table
    // -----------------------------------------------------------------

    public function trainings(): View
    {
        return $this->list('Open requests', [
            'Student', 'Rating', 'Stage', 'Mentor', ['label' => 'Waiting', 'align' => 'right'],
        ], Training::with('user', 'ratings', 'mentors')
            ->whereNotIn('status', [TrainingStatus::COMPLETED])
            ->where('status', '>=', TrainingStatus::IN_QUEUE)
            ->limit(self::CAP)->get()
            ->sortBy(fn (Training $t) => $t->status->lifecycleOrder())
            ->map(fn (Training $t) => [
                $this->link(route('vatssa.preview.training', $t), $t->user?->name ?? 'Unknown')
                    . $this->sub($t->user_id),
                e($t->ratings->pluck('name')->join(' + ')) ?: '—',
                $this->stage($t->status),
                $t->mentors->isNotEmpty()
                    ? e($t->mentors->pluck('name')->join(', '))
                    : $this->muted('nobody'),
                e($t->created_at?->diffForHumans(null, true)),
            ])->values()->all(),
            'Nothing open.',
            'Every training that has not been closed or completed, in the order the stages happen.');
    }

    public function closedTrainings(): View
    {
        return $this->list('Closed requests', [
            'Student', 'Rating', 'Outcome', ['label' => 'Closed', 'align' => 'right'],
        ], Training::with('user', 'ratings')
            ->where('status', '<', TrainingStatus::IN_QUEUE)
            ->latest('closed_at')->limit(self::CAP)->get()
            ->map(fn (Training $t) => [
                $this->link(route('vatssa.preview.training', $t), $t->user?->name ?? 'Unknown')
                    . $this->sub($t->user_id),
                e($t->ratings->pluck('name')->join(' + ')) ?: '—',
                $this->stage($t->status),
                e($t->closed_at?->format('j M Y') ?? '—'),
            ])->values()->all(),
            'Nothing closed yet.');
    }

    public function users(): View
    {
        return $this->list('Member overview', [
            'Member', 'Rating', 'Roles', ['label' => 'Last seen', 'align' => 'right'],
        ], User::with('roleAssignments')
            ->orderBy('first_name')->limit(self::CAP)->get()
            ->map(fn (User $u) => [
                $this->link(route('vatssa.preview.profile', $u), $u->name) . $this->sub($u->id),
                e($u->rating?->name ?? '—'),
                $u->roleAssignments->isNotEmpty()
                    ? $this->pills($u->roleAssignments->pluck('role')->unique()->all())
                    : $this->muted('member'),
                e($u->last_login?->diffForHumans() ?? 'never'),
            ])->values()->all());
    }

    public function tasks(): View
    {
        return $this->list('Tasks', [
            'What', 'About', 'Desk', 'State', ['label' => 'Raised', 'align' => 'right'],
        ], Task::with('subject', 'assignee', 'subjectTraining')
            ->latest()->limit(self::CAP)->get()
            ->map(fn (Task $t) => [
                e($t->type()->getName()),
                $t->subject ? e($t->subject->name) : $this->muted('nobody in particular'),
                $t->vatssa_tier
                    ? e(\App\Models\Vatssa\RequestTarget::label($t->vatssa_tier))
                    : $this->muted('unrouted'),
                $this->taskState($t->status),
                e($t->created_at?->diffForHumans(null, true)),
            ])->values()->all(),
            'No tasks.',
            'A request belongs to a desk, not to a person. Everybody at a desk sees the same queue.');
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
                $e->user
                    ? $this->link(route('vatssa.preview.profile', $e->user), $e->user->name)
                    : $this->muted('Unknown'),
                e($e->ratings->pluck('name')->join(', ')) ?: '—',
                e($e->valid_from?->format('j M Y') ?? '—'),
                $e->valid_to
                    ? $this->expiry($e->valid_to)
                    : $this->muted('no expiry'),
            ])->values()->all(),
            'None granted.');
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
                e(\Carbon\Carbon::parse($b->time_start)->format('j M · H:i') . 'z'),
                e(\Carbon\Carbon::parse($b->time_end)->format('H:i') . 'z'),
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
    // Shared rendering
    // -----------------------------------------------------------------

    private function list(string $heading, array $columns, array $rows,
        ?string $empty = null, ?string $blurb = null): View
    {
        return view('vatssa.preview.list', compact('heading', 'columns', 'rows', 'empty', 'blurb'));
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
            TrainingStatus::AWAITING_MENTOR, TrainingStatus::IN_QUEUE
                => 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300',
            TrainingStatus::ACTIVE_TRAINING, TrainingStatus::PRE_TRAINING
                => 'bg-brand-50 text-brand-700 dark:bg-brand-950/60 dark:text-brand-300',
            TrainingStatus::COMPLETED
                => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
            default
                => 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300',
        };

        return '<span class="rounded-md px-2 py-1 text-xs font-medium ' . $tone . '">'
            . e($status->label()) . '</span>';
    }

    private function taskState(TaskStatus $status): string
    {
        [$tone, $label] = match ($status) {
            TaskStatus::COMPLETED => ['bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300', 'Done'],
            TaskStatus::DECLINED => ['bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300', 'Declined'],
            default => ['bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300', 'Open'],
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
        $soon = \Carbon\Carbon::parse($date)->lessThan(now()->addDays(30));

        return '<span class="' . ($soon ? 'font-medium text-amber-700 dark:text-amber-400' : '') . '">'
            . e(\Carbon\Carbon::parse($date)->format('j M Y')) . '</span>';
    }

    private function link(string $href, string $text): string
    {
        return '<a href="' . e($href) . '" class="font-medium hover:text-brand-600 '
            . 'dark:hover:text-brand-400">' . e($text) . '</a>';
    }

    private function sub(int|string|null $text): string
    {
        return $text === null ? ''
            : '<div class="text-xs tabular-nums text-neutral-400">' . e($text) . '</div>';
    }

    private function muted(string $text): string
    {
        return '<span class="text-neutral-400 dark:text-neutral-600">' . e($text) . '</span>';
    }

    /** @param array<int, string> $items */
    private function pills(array $items): string
    {
        return collect($items)->map(fn ($item) => '<span class="mr-1 inline-block rounded '
            . 'bg-neutral-100 px-1.5 py-0.5 text-[11px] font-medium text-neutral-600 '
            . 'dark:bg-neutral-800 dark:text-neutral-300">' . e($item) . '</span>')->join('');
    }
}
