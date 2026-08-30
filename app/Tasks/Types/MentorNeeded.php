<?php

namespace App\Tasks\Types;

use App\Models\Task;

/**
 * VATSSA: this student has no mentor.
 *
 * The action item half of the lost-mentor problem. Moving the training back to
 * "awaiting a mentor" makes the STATE truthful; it does not put the work on
 * anybody's list, and a queue nobody is asked to work is a queue nobody works.
 *
 * Raised automatically by `vatssa:orphaned-trainings` when a training is found
 * with no mentor attached, and raisable by hand by a coordinator who already
 * knows. One open item per training either way -- the daily run will not raise
 * a second while the first is still open, so a coordinator who is already on it
 * is not nagged.
 *
 * Always the coordinator desk for the training's own rating; see
 * `config/vatssa.php` fixed_desks. Finding a mentor is the whole of a pipeline
 * coordinator's job, and it is the one request that should never need a
 * decision about where to send it.
 */
class MentorNeeded extends Types
{
    public function getName()
    {
        return 'Mentor Needed';
    }

    public function getIcon()
    {
        return 'fa-user-slash';
    }

    public function getText(Task $model)
    {
        return 'Student needs a mentor' . ($model->message ? ': ' . $model->message : '');
    }

    public function getLink(Task $model)
    {
        // Straight to the training, because assigning the mentor is done there.
        return $model->subject_training_id
            ? route('training.show', $model->subject_training_id)
            : false;
    }

    public function allowMessage()
    {
        return true;
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
