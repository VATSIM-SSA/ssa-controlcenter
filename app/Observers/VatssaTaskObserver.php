<?php

namespace App\Observers;

use App\Models\Task;
use App\Models\Vatssa\ActionLog;
use App\Models\Vatssa\RequestTarget;
use Illuminate\Support\Facades\Log;

/**
 * VATSSA: send a request to the desk it was addressed to.
 *
 * Upstream asks the requester to name a person, offering a datalist of everyone
 * holding any role plus the three most-used shortcuts. That works while
 * everybody knows the org chart, and fails quietly afterwards: the request
 * lands on whoever came to mind and sits unread.
 *
 * The form now asks for a DESK -- coordinator for this rating, ATC training
 * manager, VATSSA1, VATSSA2 -- and this resolves it to a person.
 *
 * AN OBSERVER, NOT A CONTROLLER CHANGE. `TaskController::store()` calls
 * `Task::create()`, so an Eloquent `creating` hook catches every task including
 * ones the bridge makes, and upstream's controller keeps its own logic.
 *
 * ## What happens when a desk is empty
 *
 * The task stays with whoever the form sent it to -- in practice the requester
 * -- and a warning is logged. That is deliberate. A request that visibly landed
 * in the wrong place gets chased; one silently assigned to an arbitrary
 * role-holder looks handled and is not. `tasks.assignee_user_id` is NOT NULL,
 * so there is no third option.
 *
 * ## The tier is kept on the task
 *
 * Resolving a desk to a person loses the desk, and then a request in somebody's
 * inbox cannot say what it was addressed to. `vatssa_tier` keeps it, which is
 * what lets a whole desk see its own queue rather than only the one person the
 * round-robin picked.
 */
class VatssaTaskObserver
{
    public function creating(Task $task): void
    {
        if (! RequestTarget::isTier($task->vatssa_tier)) {
            return;     // upstream's own tasks, and anything created directly
        }

        // The rating this request is about, so a coordinator request reaches
        // the coordinator for THAT pipeline. Falls back to the training's own
        // rating when the form did not say.
        $ratingId = $task->vatssa_rating_id
            ?? $task->subject_training_rating_id
            ?? $task->subjectTraining?->ratings->first()?->id;

        $task->vatssa_rating_id = RequestTarget::isPerRating($task->vatssa_tier)
            ? $ratingId
            : null;

        $target = RequestTarget::nextAt($task->vatssa_tier, $ratingId);

        if ($target === null) {
            Log::warning('VATSSA request routing: nobody sits at this desk', [
                'tier' => $task->vatssa_tier,
                'rating_id' => $ratingId,
                'training_id' => $task->subject_training_id,
            ]);

            // And somewhere a person will actually see it. This was already
            // known at the moment it mattered and buried in a container log,
            // which is the exact failure the action log exists to end.
            ActionLog::noticed(
                'request.desk_empty',
                'A request was sent to the '
                    . RequestTarget::label($task->vatssa_tier)
                    . ' desk, but nobody is assigned to it. It stayed with whoever raised it.',
                $task->subject_training_id,
                $task->subject_user_id,
                ['tier' => $task->vatssa_tier, 'rating_id' => $ratingId]
            );

            return;
        }

        $task->assignee_user_id = $target->id;
    }
}
