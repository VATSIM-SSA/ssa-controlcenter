<?php

namespace App\Http\Controllers;

use App\Helpers\TaskStatus;
use App\Models\Area;
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

        // VATSSA: five tabs, not three.
        //
        // Upstream gives you your own open tasks, what you sent, and your own
        // archive. Two are missing and both matter: the whole board, and the
        // whole board's archive -- "what is outstanding across the division"
        // and "what did we do about that request in June" are the two questions
        // a single inbox cannot answer.
        $canSeeAll = $user->hasPermission('tasks.overview');

        // A REQUEST BELONGS TO A DESK, NOT TO A PERSON. The observer picks one
        // person so the row has an assignee -- the column is NOT NULL -- but
        // everybody at that desk should see it in their own list, or a
        // coordinator on leave takes their queue with them.
        $desks = \App\Models\Vatssa\RequestTarget::where('user_id', $user->id)
            ->get(['tier', 'rating_id']);

        $mine = function ($query) use ($user, $desks) {
            $query->where('assignee_user_id', $user->id);

            foreach ($desks as $desk) {
                $query->orWhere(function ($q) use ($desk) {
                    $q->where('vatssa_tier', $desk->tier);
                    if ($desk->rating_id !== null) {
                        $q->where(fn ($r) => $r->where('vatssa_rating_id', $desk->rating_id)
                            ->orWhereNull('vatssa_rating_id'));
                    }
                });
            }
        };

        $closed = [TaskStatus::COMPLETED->value, TaskStatus::DECLINED->value];

        if ($activeFilter == 'sent') {
            $tasks = Task::where('creator_user_id', $user->id)->get()->sortByDesc('created_at');
        } elseif ($activeFilter == 'archived') {
            $tasks = Task::where($mine)->whereIn('status', $closed)->get()->sortByDesc('closed_at');
        } elseif ($activeFilter == 'all' && $canSeeAll) {
            $tasks = Task::where('status', TaskStatus::PENDING->value)->with('creator', 'subject', 'assignee', 'subjectTraining')->get()->sortBy('created_at');
        } elseif ($activeFilter == 'all-archived' && $canSeeAll) {
            $tasks = Task::whereIn('status', $closed)->with('creator', 'subject', 'assignee', 'subjectTraining')->get()->sortByDesc('closed_at');
        } else {
            $tasks = Task::where($mine)->where('status', TaskStatus::PENDING->value)->with('creator', 'subject', 'assignee', 'subjectTraining')->get()->sortBy('created_at');
        }

        return view('tasks.index', compact('tasks', 'activeFilter', 'canSeeAll'));
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
            'subject_user_id' => 'required|exists:users,id',
            'subject_training_id' => 'required|exists:trainings,id',
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
