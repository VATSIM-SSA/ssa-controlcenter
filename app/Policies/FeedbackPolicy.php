<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FeedbackPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can ACTION feedback: close it or forward it.
     *
     * Separate from `update`, which re-points a submission at a different
     * controller or position -- a correction. Actioning is a judgement about
     * the content, and forwarding puts words in front of the person they are
     * about, so a division may reasonably want a narrower group doing it.
     *
     * Scoped the same way as update, and for the same reason: the area a piece
     * of feedback belongs to is the area whose staff should be deciding it.
     */
    public function action(User $user, ?Feedback $feedback = null): bool
    {
        if ($feedback === null) {
            return $user->hasPermission('feedback.action');
        }

        if ($feedback->referencePosition) {
            return $user->hasPermission('feedback.action', $feedback->referencePosition->area);
        }

        // Uncorrelated feedback names no position, so there is no area to scope
        // to. Whoever may READ the uncorrelated pile may action it; anybody
        // else would be deciding on something they cannot see.
        return $user->hasPermission('feedback.action')
            && $user->accessibleAreasForPermission('feedback.uncorrelated.view')->hasAccess();
    }

    /**
     * Determine whether the user can update feedback in general.
     */
    public function update(User $user, ?Feedback $feedback = null): bool
    {
        if ($feedback === null) {
            return $user->hasPermission('feedback.update');
        }

        if ($feedback->referencePosition) {
            return $user->hasPermission('feedback.update', $feedback->referencePosition->area);
        }

        return $user->hasPermission('feedback.update')
            && $user->accessibleAreasForPermission('feedback.uncorrelated.view')->hasAccess();
    }
}
