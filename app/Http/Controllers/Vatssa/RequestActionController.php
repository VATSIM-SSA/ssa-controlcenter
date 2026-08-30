<?php

namespace App\Http\Controllers\Vatssa;

use App\Helpers\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TrainingActivityController;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * VATSSA: do the thing the request is asking for, from the request.
 *
 * Upstream's task types offer a LINK -- a solo endorsement request sends you to
 * the endorsement form, a rating upgrade sends you to the user. That is right
 * where the action needs a form. It is wrong where the action is one field:
 *
 *   leave of absence   tick Paused
 *   return from leave  untick Paused
 *
 * For those, the link is a trip to another page to click one checkbox and come
 * back, and the request is then closed by hand as a separate step -- three
 * actions for one decision, and two chances to do half of it.
 *
 * ## Doing both halves together is the point
 *
 * Every action here performs the change AND closes the request, in one place,
 * so a paused training and a completed request cannot disagree. The commonest
 * failure of a request system is exactly that drift: the work is done and the
 * queue still shows it open, or the queue is clear and nobody did the thing.
 *
 * Both halves are written to the training's activity log, so the timeline shows
 * what happened and who decided it -- not just that a task closed.
 */
class RequestActionController extends Controller
{
    /**
     * Pause or resume the training a request is about.
     */
    public function pause(Task $task, string $mode): RedirectResponse
    {
        $this->authorize('update', Task::class);

        $training = $task->subjectTraining;

        if ($training === null) {
            return redirect()->back()->withErrors('That request is not about a training.');
        }

        // The policy that guards the checkbox guards this too. A shortcut must
        // never be a way around a permission -- it is a faster route to the
        // same decision, not a different one.
        $this->authorize('update', $training);

        $resuming = $mode === 'resume';

        if ($resuming) {
            if ($training->paused_at === null) {
                return redirect()->back()->withErrors('That training is not paused.');
            }

            // Upstream's own arithmetic: the frozen time is banked into
            // paused_length so the 90-day clock resumes where it stopped.
            $training->paused_length += (int) Carbon::create($training->paused_at)
                ->diffInSeconds(Carbon::now(), true);
            $training->paused_at = null;
        } else {
            if ($training->paused_at !== null) {
                return redirect()->back()->withErrors('That training is already paused.');
            }

            $training->paused_at = now();
        }

        $training->save();

        TrainingActivityController::create(
            $training->id,
            'PAUSE',
            $resuming ? 0 : 1,
            $resuming ? 1 : 0,
            Auth::id(),
            $resuming ? 'Resumed from a request' : 'Paused from a request'
        );

        // And close the request, so the queue and the training agree. Leaving
        // it open is how a done thing stays on somebody's list.
        $task->status = TaskStatus::COMPLETED;
        $task->closed_at = now();
        $task->assignee_notified = false;
        $task->creator_notified = false;
        $task->save();

        return redirect()->back()->with(
            'success',
            $resuming ? 'Training resumed and the request closed.' : 'Training paused and the request closed.'
        );
    }
}
