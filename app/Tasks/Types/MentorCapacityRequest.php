<?php

namespace App\Tasks\Types;

use App\Models\Task;

/**
 * VATSSA: a mentor asking to take more students, or fewer.
 *
 * The one request in the whole system that is about the MENTOR rather than a
 * student, which is why it was going to live on a separate portal. It does not
 * need to: it is a request like any other, it goes to a desk like any other,
 * and putting it here means one queue instead of two.
 *
 * Fewer students is a request rather than a button on purpose. A mentor cannot
 * drop a student they are already running by changing a number -- somebody has
 * to pick that student up.
 *
 * Always the ATC training manager's desk; see `config/vatssa.php`.
 */
class MentorCapacityRequest extends Types
{
    public function getName()
    {
        return 'Mentor Capacity';
    }

    public function getIcon()
    {
        return 'fa-users-gear';
    }

    public function getText(Task $model)
    {
        return 'Change mentor capacity' . ($model->message ? ': ' . $model->message : '');
    }

    public function getLink(Task $model)
    {
        return $model->subject_user_id ? route('user.show', $model->subject_user_id) : false;
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
