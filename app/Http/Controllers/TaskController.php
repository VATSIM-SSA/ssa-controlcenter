<?php

namespace App\Http\Controllers;

use App\Helpers\TaskStatus;
use App\Helpers\Vatssa\MembershipRequestType;
use App\Models\Area;
use App\Models\Rating;
use App\Models\Task;
use App\Models\User;
use App\Models\Vatssa\MembershipRequest;
use App\Models\Vatssa\RequestTarget;
use App\Rules\ValidTaskType;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TaskController extends Controller
{
    /**
     * Show the application task dashboard.
     */
    public function index(Authenticatable $user, ?string $activeFilter = null): View
    {
        $this->authorize('update', Task::class);

        // VATSSA: two questions, not one list of tabs.
        //
        //   which desk   -- yours, one you sit at, every desk, or what you sent
        //   which state  -- pending or archived
        //
        // Upstream mixed the two into a single filter ("open", "sent",
        // "archived"), which cannot express "the archive of the S2 desk". Both
        // come off the query string so a link to any combination is shareable.
        $desks = RequestTarget::desksFor($user);
        // What they may LOOK AT, which is a ladder rather than a
        // permission: leadership sees every desk, the training manager
        // sees theirs and every pipeline, a coordinator sees only their
        // own rating. tasks.overview used to mean 'see everything', which
        // handed a coordinator the leadership queue -- requests escalated
        // past the training staff, often about the training staff.
        $visible = RequestTarget::visibleDesksFor($user);
        $canSeeAll = $visible->count() > $desks->count();

        $desk = request('desk', 'mine');
        $state = request('state') === 'archived' ? 'archived' : 'pending';

        // Sent is a desk option rather than a state, because "things I asked
        // for" is a different collection, not a different view of the same one.
        if ($desk === 'sent') {
            $query = Task::where('creator_user_id', $user->id);
        } elseif ($desk === 'all' && $canSeeAll) {
            // Every desk they may see, NOT every desk that exists.
            $query = RequestTarget::scopeToDesks(Task::query(), $visible);
        } elseif (str_contains((string) $desk, ':') || RequestTarget::isTier($desk)) {
            [$tier, $ratingId] = array_pad(explode(':', (string) $desk, 2), 2, null);
            $one = collect([['tier' => $tier, 'rating_id' => $ratingId ? (int) $ratingId : null]]);

            // Checked against the ladder, not against the picker. Hiding a
            // button is not a permission -- the query string is what a
            // curious person actually edits.
            $allowed = RequestTarget::canSee($user, $tier, $ratingId ? (int) $ratingId : null);

            $query = $allowed
                ? RequestTarget::scopeToDesks(Task::query(), $one)
                : Task::whereRaw('1 = 0');
        } else {
            $desk = 'mine';
            // Your desks, plus anything addressed to you personally -- upstream
            // tasks carry no tier and would otherwise be invisible.
            $query = Task::where(function ($q) use ($user, $desks) {
                $q->where('assignee_user_id', $user->id);
                $q->orWhere(fn ($inner) => RequestTarget::scopeToDesks($inner, $desks));
            });
        }

        $closed = [TaskStatus::COMPLETED->value, TaskStatus::DECLINED->value];

        $tasks = $state === 'archived'
            ? $query->whereIn('status', $closed)->with('creator', 'subject', 'subjectTraining')->get()->sortByDesc('closed_at')
            : $query->where('status', TaskStatus::PENDING->value)->with('creator', 'subject', 'subjectTraining')->get()->sortBy('created_at');

        return view('tasks.index', [
            'tasks' => $tasks,
            'activeFilter' => $activeFilter,
            'canSeeAll' => $canSeeAll,
            'desk' => $desk,
            'state' => $state,
            // The buttons offer every desk they may READ, not only the
            // ones they sit at -- an ATM works from the pipeline queues.
            'myDesks' => $visible,
            'ratings' => Rating::whereNotNull('vatsim_rating')->orderBy('vatsim_rating')->get(),
        ]);
    }

    /**
     * Store a newly created task in storage.
     */
    public function store(Request $request, Authenticatable $user): RedirectResponse
    {

        $this->authorize('create', Task::class);

        $data = $request->validate([
            'type' => ['required', new ValidTaskType],
            'message' => 'sometimes|min:3|max:256',
            // VATSSA: nullable. Plenty of work is not about one training --
            // "review the S2 syllabus", "this member wants to visit" -- and
            // requiring a training is why that work happens in Discord instead.
            'subject_user_id' => 'nullable|exists:users,id',
            'subject_training_id' => 'nullable|exists:trainings,id',
            'subject_training_rating_id' => 'nullable|exists:ratings,id',
            'assignee_user_id' => 'required|exists:users,id',
            // VATSSA: the desk this was addressed to. validate() drops anything
            // it is not told about, so without this line the tier never reaches
            // Task::create() and the observer has nothing to route on. Rule::in
            // over the tier list, so a hand-crafted POST cannot invent a desk.
            // REQUIRED, not `sometimes`. A form that omits the desk used to
            // create a task on nobody's queue; the observer now fills one in,
            // but a request whose desk was chosen by a fallback is a request
            // somebody guessed at, and the form has always shown the field.
            'vatssa_tier' => ['required', Rule::in(array_keys(RequestTarget::tiers(true)))],
            'vatssa_rating_id' => 'sometimes|nullable|exists:ratings,id',
        ]);

        $data['creator_user_id'] = $user->id;
        $data['created_at'] = now();

        // VATSSA: a type with a fixed desk cannot be redirected. The form
        // posts the tier too, but a hand-crafted POST must not be able to send
        // a rating upgrade anywhere other than membership.
        $fixed = config('vatssa.fixed_desks.' . $data['type']);
        if ($fixed !== null) {
            $data['vatssa_tier'] = $fixed;
        }

        // VATSSA: some request types are MEMBERSHIP work, not tasks.
        //
        // A rating upgrade is the clear case. It ends on VATSIM Terminal, it
        // needs the Terminal log and the audit trail behind it, and a Task
        // carries none of that -- it is a message with a tick box. Raising a
        // membership request instead puts it on the desk that actually does the
        // work, in the queue where the rest of that work already is.
        //
        // The button on the training page does not change. What it produces
        // does.
        //
        // Mapped in config rather than checked against a class name here, so
        // adding another one is a line of config and this method stays the one
        // place the redirection happens.
        $asMembership = config('vatssa.membership_request_types.' . $data['type']);
        if ($asMembership !== null) {
            $membershipType = MembershipRequestType::from($asMembership);
            $subject = User::findOrFail($data['subject_user_id'] ?? $user->id);

            $membershipRequest = MembershipRequest::open($membershipType, $subject, $user, [
                'rating_id' => $data['subject_training_rating_id'] ?? null,
                'training_id' => $data['subject_training_id'] ?? null,
                'note' => $data['message'] ?? null,
            ]);

            return redirect()->back()->with(
                'success',
                'Raised with the membership desk as a ' . strtolower($membershipRequest->type->label()) . '.'
            );
        }

        // Check if recipient is mentor or above
        $recipient = User::findOrFail($data['assignee_user_id']);

        // Policy check if recpient can recieve a task
        if ($recipient->can('receive', Task::class)) {
            // Create the model
            $task = Task::create($data);

            // Run the create method on the task type to trigger type specific actions on creation
            $task->type()->create($task);

            return redirect()->back()->with('success', 'Task created successfully.');
        }

        return redirect()->back()->withErrors('Recipient is not allowed to receive tasks.');

    }

    /**
     * Close the specified task with a given status.
     */
    protected function close(Task $task, TaskStatus $status): void
    {
        $task->status = $status;
        $task->assignee_notified = true;
        $task->closed_at = now();
        $task->save();
    }

    /**
     * Complete the specified task.
     */
    public function complete(Request $request, Task|int $task): RedirectResponse
    {
        $this->authorize('update', Task::class);
        $task = Task::findOrFail($task);

        $error = $task->type()->complete($task);
        if (isset($error)) {
            return redirect()->back()->withErrors($error);
        }

        self::close($task, TaskStatus::COMPLETED);

        // VATSSA: null-safe. subject_user_id and creator_user_id are both
        // nullable now -- a request can be about nobody, and upstream already
        // allowed a null creator for system-raised tasks. Dereferencing either
        // was a 500 on the happy path.
        return redirect()->back()->with('success', sprintf(
            'Completed request%s%s.',
            $task->subject ? ' regarding ' . $task->subject->name : '',
            $task->creator ? ' from ' . $task->creator->name : ''
        ));
    }

    /**
     * Decline the specified task.
     */
    public function decline(Request $request, Task|int $task): RedirectResponse
    {
        $this->authorize('update', Task::class);
        $task = Task::findOrFail($task);

        $error = $task->type()->decline($task);
        if (isset($error)) {
            return redirect()->back()->withErrors($error);
        }

        self::close($task, TaskStatus::DECLINED);

        return redirect()->back()->with('success', sprintf(
            'Declined request%s%s.',
            $task->subject ? ' regarding ' . $task->subject->name : '',
            $task->creator ? ' from ' . $task->creator->name : ''
        ));
    }

    /**
     * Return the task type classes
     *
     * @return array
     */
    public static function getTypes()
    {
        // Specify the directory where your subclasses are located
        $subclassesDirectory = app_path('Tasks/Types');

        // Initialize an array to store the subclasses
        $subclasses = [];

        // Get all PHP files in the directory
        $files = File::files($subclassesDirectory);

        foreach ($files as $file) {
            // Get the class name from the file path
            $className = 'App\\Tasks\\Types\\' . pathinfo($file, PATHINFO_FILENAME);

            // Check if the class exists and is a subclass of Types
            if (class_exists($className) && is_subclass_of($className, 'App\\Tasks\\Types\\Types')) {
                $subclasses[] = new $className();
            }
        }

        return $subclasses;
    }

    /**
     * Get popular task Assignees
     *
     * @return Illuminate\Database\Eloquent\Collection;
     */
    public static function getPopularAssignees(Area $area)
    {
        $users = User::whereHas('tasks', function ($query) use ($area) {
            $query->whereHas('subjectTraining', function ($query) use ($area) {
                $query->where('area_id', $area->id);
            });
        })->withCount('tasks')->orderBy('tasks_count', 'desc')->limit(10)->get();

        // Filter out users who no longer can receive tasks and end up with 3
        $users = $users->filter(function ($user) {
            return $user->hasPermission('tasks.suggested-recipient');
        })->take(3);

        return $users;
    }

    /**
     * Check if a task type is valid
     *
     * @param  string  $type
     * @return bool
     */
    public static function isValidType($type)
    {
        $types = self::getTypes();

        foreach ($types as $taskType) {
            if ($taskType::class == $type) {
                return true;
            }
        }

        return false;
    }
}
