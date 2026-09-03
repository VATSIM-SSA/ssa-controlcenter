<?php

namespace App\Observers;

use App\Helpers\TrainingStatus;
use App\Helpers\Vatssa\MembershipRequestState;
use App\Models\Training;
use App\Models\Vatssa\ActionLog;
use App\Models\Vatssa\MembershipRequest;

/**
 * VATSSA: the two automatic transitions between a membership request and the
 * familiarisation training it opened.
 *
 * These are the whole reason the membership module is a workflow and not a
 * status dropdown. Without them a request sits in "pending training" for ever,
 * because the thing that would move it happens on a different page owned by
 * different people.
 *
 *   training COMPLETED           -> request Complete
 *   training closed any other way -> request back ON THE DESK, loudly
 *
 * ## Why the failure branch is the important one
 *
 * Under TVCP 4.7, failing to complete the Local Induction Plan means transfer
 * back to the Previous Allocation. That is a real consequence for a real
 * person, and the trigger for it is a training quietly closing. A silent return
 * to the queue would mean nobody acts on it -- so this writes to the action log
 * as well, where the desk actually looks.
 *
 * ## An observer, not a controller change
 *
 * A training can close from the status dropdown, the completion control, the
 * bridge, or a scheduled command. Hooking the model catches all four; hooking
 * one controller catches whichever path somebody happened to think of.
 */
class VatssaMembershipTrainingObserver
{
    public function updated(Training $training): void
    {
        // Only when the STATUS moved. A training saves for a dozen reasons and
        // most of them are not this one.
        if (! $training->wasChanged('status')) {
            return;
        }

        $status = $training->status;

        if (! $status->isClosed()) {
            return;
        }

        $request = MembershipRequest::where('training_id', $training->id)
            ->where('state', MembershipRequestState::PENDING_TRAINING)
            ->first();

        if ($request === null) {
            return;
        }

        if ($status === TrainingStatus::COMPLETED) {
            $request->moveTo(MembershipRequestState::COMPLETE);

            return;
        }

        // Anything else: withdrawn, closed by staff, closed by the system.
        //
        // Back to PENDING_TRANSFER rather than to the state it left, because
        // the desk has to decide something -- under 4.7 that is usually a
        // transfer back to the Previous Allocation, and it is a decision rather
        // than a step.
        $request->moveTo(MembershipRequestState::PENDING_TRANSFER);

        ActionLog::noticed(
            'membership.training_failed',
            'The familiarisation training for a ' . $request->type->label()
                . ' closed without completing, so the request is back on the membership desk. '
                . 'TVCP 4.7 usually means a transfer back to the previous allocation.',
            $training->id,
            $request->user_id,
            ['membership_request_id' => $request->id, 'status' => $status->value],
        );
    }
}
