<?php

namespace App\Http\Controllers;

use App\Helpers\TaskStatus;
use App\Models\Area;
use App\Models\Rating;
use App\Models\Task;
use App\Models\User;
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
        $canSeeAll = $user->hasPermission('tasks.overview');

        $desk = request('desk', 'mine');
        $state = request('state') === 'archived' ? 'archived' : 'pending';

        // Sent is a desk option rather than a state, because "things I asked
        // for" is a different collection, not a different view of the same one.
        if ($desk === 'sent') {
            $query = Task::where('creator_user_id', $user->id);
        } elseif ($desk === 'all' && $canSeeAll) {
            $query = Task::query();
        } elseif (str_contains((string) $desk, ':') || RequestTarget::isTier($desk)) {
            [$tier, $ratingId] = array_pad(explode(':', (string) $desk, 2), 2, null);
            $one = collect([['tier' => $tier, 'rating_id' => $ratingId ? (int) $ratingId : null]]);

            // Only desks you actually sit at, unless you can see everything.
            // Otherwise the picker is a suggestion and the query string is the
            // real permission check.
            $allowed = $canSeeAll || $desks->contains(
                fn ($d) => $d['tier'] === $tier
                    && ($d['rating_id'] === null || (string) $d['rating_id'] === (string) $ratingId)
            );

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
            'myDesks' => $desks,
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
            'vatssa_tier' => ['sometimes', 'required', Rule::in(array_keys(RequestTarget::TIERS))],
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

        return redirect()->back()->with('success', sprintf('Completed task regarding %s from %s.', $task->subject->name, $task->creator->name));
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

        return redirect()->back()->with('success', sprintf('Declined task regarding %s from %s.', $task->subject->name, $task->creator->name));
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
