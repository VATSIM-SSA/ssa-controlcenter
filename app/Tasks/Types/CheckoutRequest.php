<?php

namespace App\Tasks\Types;

use App\Models\Task;
use App\Models\Vatssa\RequestTarget;

/**
 * VATSSA: ask for a CPT or checkout.
 *
 * The step that ends a standard training, and the one with the most moving
 * parts -- a student, an examiner and the events calendar all have to agree on
 * a time. Scheduling that is deliberately manual for now (see
 * `decisions/open.md`, 2026-08-27); this is the request that starts it, so at
 * least the asking is recorded rather than happening in a Discord message
 * nobody can find later.
 *
 * Goes to the coordinator, who holds `examinations.manage` and runs the
 * pipeline this student is in.
 */
class CheckoutRequest extends Types
{
    public function getName()
    {
        return 'CPT or Checkout';
    }

    public function getIcon()
    {
        return 'fa-user-graduate';
    }

    public function getText(Task $model)
    {
        return 'Arrange CPT or checkout' . ($model->message ? ': ' . $model->message : '');
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

    /**
     * Which rating is being tested is the whole point of the request, so the
     * form shows it and the routing uses it to find the right coordinator.
     */
    public function showConnectedRatings()
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

    public function allowNonVatsimRatings()
    {
        return false;
    }
}
