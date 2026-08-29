<?php

namespace App\Tasks\Types;

use App\Models\Task;
use App\Models\Vatssa\RequestTarget;

/**
 * VATSSA: pause a training.
 *
 * Control Center has no leave of absence. It has a "Paused" checkbox on the
 * training, which only somebody with `training.update` can tick -- so a student
 * who needs to step away, or a mentor who knows they have, had no way to ask
 * except a free-text Custom Request that nobody could act on systematically.
 *
 * The pause is what freezes the 90-day theory clock and the queue calculation,
 * so getting one recorded matters well beyond politeness.
 *
 * The message is where the reason and the expected return date go. Neither has
 * a field on the training: `paused_at` is a bare timestamp, which is why a
 * pause nobody unticks stays frozen indefinitely.
 */
class LeaveOfAbsence extends Types
{
    public function getName()
    {
        return 'Leave of Absence';
    }

    public function getIcon()
    {
        return 'fa-pause';
    }

    public function getText(Task $model)
    {
        return 'Pause training' . ($model->message ? ': ' . $model->message : '');
    }

    public function getLink(Task $model)
    {
        return route('training.show', $model->subject_training_id);
    }

    public function allowMessage()
    {
        return true;
    }

    /** The coordinator ticks the box, so the coordinator gets the request. */
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
