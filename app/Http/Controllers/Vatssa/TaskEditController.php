<?php

namespace App\Http\Controllers\Vatssa;

use App\Helpers\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Vatssa\RequestTarget;
use App\Rules\ValidTaskType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * VATSSA: the three things upstream cannot do to a request once it exists.
 *
 * Upstream's task is write-once and close-once: create it, then complete or
 * decline it, and that is the end. Three consequences, all of which push work
 * back into Discord:
 *
 *   1. A typo or a missing detail cannot be fixed, so a second request gets
 *      raised and the first is declined.
 *   2. A request closed by mistake, or one that turns out not to be finished,
 *      cannot be reopened -- so a new one is raised and the history splits.
 *   3. A request cannot be moved to the right desk when it was sent to the
 *      wrong one; it gets declined with "ask X instead".
 *
 * All three are recorded on the task's own message, so what changed and why is
 * visible rather than being a silently different row.
 */
class TaskEditController extends Controller
{
    /**
     * Whether this person may act on THIS request, not just on requests.
     *
     * `TaskPolicy::update()` takes no Task -- it asks "may you manage tasks at
     * all", which every mentor can. Class-level alone, any mentor who knew a
     * task id could edit, move or reopen ANY request in the division, including
     * one on the leadership desk. The buttons were gated; the routes were not,
     * and a hidden button is not a permission.
     *
     * The desk ladder is the object-level check: you may act on a request whose
     * desk you can read. A request with no desk -- upstream's own, and anything
     * raised before the desks existed -- falls back to being yours or your
     * having the overview.
     */
    private function mayActOn(Task $task): bool
    {
        $user = Auth::user();

        if ($task->vatssa_tier !== null) {
            return RequestTarget::canSee($user, $task->vatssa_tier, $task->vatssa_rating_id);
        }

        return $task->assignee_user_id === $user->id
            || $task->creator_user_id === $user->id
            || $user->hasPermission('tasks.overview');
    }

    /**
     * Edit the free text and, if it needs to move, the desk.
     */
    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', Task::class);
        abort_unless($this->mayActOn($task), 403);

        $data = $request->validate([
            'message' => 'nullable|string|max:256',
            'vatssa_tier' => ['nullable', Rule::in(array_keys(RequestTarget::tiers(true)))],
            'vatssa_rating_id' => 'nullable|exists:ratings,id',
        ]);

        $task->message = $data['message'] ?? null;

        // Moving a desk re-resolves the assignee, or the row keeps pointing at
        // somebody on the desk it just left.
        if (! empty($data['vatssa_tier']) && $data['vatssa_tier'] !== $task->vatssa_tier) {
            $ratingId = RequestTarget::isPerRating($data['vatssa_tier'])
                ? ($data['vatssa_rating_id'] ?? $task->vatssa_rating_id)
                : null;

            // The DESTINATION has to be readable too. Otherwise a coordinator
            // could push a request onto the leadership desk -- a queue they
            // cannot see, so they could neither follow it up nor take it back.
            abort_unless(
                RequestTarget::canSee(Auth::user(), $data['vatssa_tier'], $ratingId),
                403
            );

            $task->vatssa_tier = $data['vatssa_tier'];
            $task->vatssa_rating_id = $ratingId;

            $target = RequestTarget::nextAt($data['vatssa_tier'], $ratingId);
            if ($target !== null) {
                $task->assignee_user_id = $target->id;
            }
        }

        $task->save();

        return redirect()->back()->with('success', 'Request updated.');
    }

    /**
     * Put a closed request back into the queue.
     *
     * For the two cases that happen constantly and currently produce a
     * duplicate: closed by mistake, and "done" that turned out not to be.
     * Reopening keeps one request with one history instead of two that each
     * tell half the story.
     */
    public function reopen(Task $task): RedirectResponse
    {
        $this->authorize('update', Task::class);
        abort_unless($this->mayActOn($task), 403);

        if ($task->status === TaskStatus::PENDING) {
            return redirect()->back()->with('success', 'That request is already open.');
        }

        $task->status = TaskStatus::PENDING;
        $task->closed_at = null;
        // Both sides get told again when it closes for real.
        $task->assignee_notified = false;
        $task->creator_notified = false;
        $task->save();

        return redirect()->back()->with('success', 'Request reopened.');
    }

    /**
     * Raise a request from the task screen, against any desk.
     *
     * Deliberately allows no student and no training. A great deal of what
     * staff owe each other is not about one student -- "review the S2
     * syllabus", "check why the mentor index is stale" -- and requiring a
     * training is precisely why that work lives in Discord instead.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Task::class);

        $data = $request->validate([
            'type' => ['required', new ValidTaskType],
            'message' => 'required|string|min:3|max:256',
            'desk' => 'required|string',
            'subject_user_id' => 'nullable|exists:users,id',
            'subject_training_id' => 'nullable|exists:trainings,id',
        ]);

        // "coordinator:14" or a bare global tier.
        [$tier, $ratingId] = array_pad(explode(':', $data['desk'], 2), 2, null);

        if (! RequestTarget::isTier($tier)) {
            return redirect()->back()->withErrors('That is not a desk.');
        }

        $fixed = config('vatssa.fixed_desks.' . $data['type']);
        if ($fixed !== null) {
            $tier = $fixed;
            $ratingId = null;
        }

        $ratingId = RequestTarget::isPerRating($tier) && $ratingId ? (int) $ratingId : null;

        $task = Task::create([
            'type' => $data['type'],
            'message' => $data['message'],
            'vatssa_tier' => $tier,
            'vatssa_rating_id' => $ratingId,
            'subject_user_id' => $data['subject_user_id'] ?? null,
            'subject_training_id' => $data['subject_training_id'] ?? null,
            // The observer replaces this with whoever staffs the desk. It is
            // here because the column is NOT NULL, not because it means
            // anything.
            'assignee_user_id' => Auth::id(),
            'creator_user_id' => Auth::id(),
            'created_at' => now(),
        ]);

        $task->type()->create($task);

        return redirect()->back()->with('success', 'Request raised.');
    }
}
