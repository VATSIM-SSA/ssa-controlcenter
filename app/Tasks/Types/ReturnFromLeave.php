<?php

namespace App\Tasks\Types;

use App\Models\Task;
use App\Models\Vatssa\RequestTarget;

/**
 * VATSSA: come back from a leave of absence.
 *
 * The other half of the pause, and the half that gets forgotten. Unticking
 * "Paused" is what adds the frozen time to `paused_length` and restarts the
 * clocks -- until somebody does, the training sits still and the student
 * assumes they are back.
 *
 * A separate type rather than a comment on the original request, because the
 * two are weeks or months apart and the first one is long since closed.
 */
class ReturnFromLeave extends Types
{
    public function getName()
    {
        return 'Return From Leave';
    }

    public function getIcon()
    {
        return 'fa-play';
    }

    public function getText(Task $model)
    {
        return 'Resume training' . ($model->message ? ': ' . $model->message : '');
    }

    public function getLink(Task $model)
    {
        return route('training.show', $model->subject_training_id);
    }

    public function allowMessage()
    {
        return true;
    }

    public function vatssaTier(): string
    {
        return RequestTarget::COORDINATOR;
    }

    public function create(Task $model)
    {
        parent::onCreated($model);
    }

    public function complete(Task $model)
    {
        parent::onCompleted($model);
    }

    public function decline(Task $model)
    {
        parent::onDeclined($model);
    }

    public function showConnectedRatings()
    {
        return false;
    }

    public function allowNonVatsimRatings()
    {
        return true;
    }
}
