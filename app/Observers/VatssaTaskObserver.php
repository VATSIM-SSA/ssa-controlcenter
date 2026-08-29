<?php

namespace App\Observers;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * VATSSA: send a request to the desk that handles it.
 *
 * Upstream leaves the assignee to whoever raises the task, helped by a list of
 * the three people who have received the most. That works when everybody knows
 * the org chart. It does not scale, and a request sent to the wrong person sits
 * unread until somebody complains.
 *
 * AN OBSERVER, NOT A CONTROLLER CHANGE. `TaskController::store()` calls
 * `Task::create()`, so an Eloquent `creating` hook reaches every task without
 * upstream's controller being touched at all -- including tasks created by the
 * bridge, which never go through the controller.
 *
 * ## It is inert until configured
 *
 * `config('vatssa.task_routing')` maps a task type class to the permission that
 * defines its desk. It ships EMPTY, and an empty map means this changes
 * nothing: existing behaviour, unchanged, until somebody decides where each
 * request should go. That is deliberate -- a routing table invented rather than
 * decided would send real requests to the wrong people, quietly.
 *
 * ## What it will not do
 *
 * Reroute a task that already names somebody holding the right permission. If
 * a coordinator deliberately sends a solo endorsement to a specific colleague,
 * that is a decision, not a mistake, and silently overriding it would be worse
 * than the problem being solved.
 */
class VatssaTaskObserver
{
    public function creating(Task $task): void
    {
        $routes = (array) config('vatssa.task_routing', []);

        if ($routes === [] || ! is_string($task->type)) {
            return;
        }

        $permission = $routes[$task->type] ?? null;
        if (! is_string($permission) || $permission === '') {
            return;
        }

        $area = $task->subjectTraining?->area;

        // Already on the right desk. Leave it alone.
        $current = $task->assignee_user_id
            ? User::find($task->assignee_user_id)
            : null;
        if ($current && $current->hasPermission($permission, $area)) {
            return;
        }

        $candidate = User::allWithPermission($permission, $area)
            ->filter(fn (User $user) => $user->hasPermission('tasks.suggested-recipient'))
            // Fewest open tasks first, so one person does not absorb the queue
            // simply by having been there longest.
            ->sortBy(fn (User $user) => $user->tasks()->count())
            ->first();

        if ($candidate === null) {
            // Nobody holds the permission in this area. Leave the task where it
            // was rather than dropping it: a request on the wrong desk is still
            // a request, and one assigned to nobody is lost.
            Log::warning('VATSSA task routing found no recipient', [
                'type' => $task->type,
                'permission' => $permission,
                'area' => $area?->id,
            ]);

            return;
        }

        $task->assignee_user_id = $candidate->id;
    }
}
